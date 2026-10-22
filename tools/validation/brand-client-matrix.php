<?php

/** Isolated, finite brand/client validation. Prepare never sends; send requires one immutable reviewed record ID. */
$options = getopt('', ['fixture:', 'phase:', 'ack-captures:', 'record:']);
$root = realpath((string) ($options['fixture'] ?? ''));
$phase = $options['phase'] ?? '';
$repo = dirname(__DIR__, 2);
$source = dirname($repo, 2).'/outputs/wphub-local-gmail-4483c123ccd53998';
if (PHP_SAPI !== 'cli' || function_exists('mail') || ! $root || $root === realpath($source)
    || ! in_array($phase, ['snapshot', 'prepare', 'send', 'evidence'], true)
    || ! str_starts_with(strtolower(str_replace('\\', '/', $root)), strtolower(str_replace('\\', '/', dirname($repo, 2).'/outputs/')))) {
    throw new RuntimeException('A NEW private local fixture, explicit phase, and disabled native mail are required.');
}
// Host-only Windows metadata workaround; actual readable I/O is still required.
if (! function_exists('is_readable')) {
    function is_readable($path): bool
    {
        global $root, $repo;
        if (! is_string($path)) {
            return false;
        }
        $path = realpath($path);
        if (! $path) {
            return false;
        }
        $normalized = strtolower(str_replace('\\', '/', $path));
        if (! str_starts_with($normalized, strtolower(str_replace('\\', '/', $root)).'/')
            && ! str_starts_with($normalized, strtolower(str_replace('\\', '/', $repo)).'/')) {
            return false;
        }
        $stream = is_dir($path) ? @opendir($path) : @fopen($path, 'rb');
        if (! $stream) {
            return false;
        }
        is_dir($path) ? closedir($stream) : fclose($stream);

        return true;
    }
}
require __DIR__.'/local-fixture-bootstrap.php';
[$app, $root, $state] = wphubLocalFixture($root);
if ($state['transport'] !== 'capture' || \App\Models\Website::count() !== 1
    || \App\Models\WebsiteDocumentProfile::where('workflow_enabled', true)->exists()
    || \Illuminate\Support\Facades\DB::table('jobs')->count() !== 0) {
    throw new RuntimeException('Target must remain a disabled, capture-default isolated fixture with no jobs.');
}
$recipients = wphubLocalApprovedRecipients();
$expected = [8 => 'contact@reservationpourvisa.com', 9 => 'contact@pre-reservation.fr', 10 => 'contact@reservationvisa.com',
    11 => 'contact@dummytickets.flights', 12 => 'support@confirmedtkt.com'];
function matrixWrite(string $path, array $data): void
{
    $bytes = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
        throw new RuntimeException('Could not persist private matrix state.');
    }
}
function matrixJson(string $path): array
{
    return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}
if ($phase === 'snapshot') {
    if (is_file($root.'/brand-profiles.json') || \App\Models\GmailConnection::exists()) {
        throw new RuntimeException('Snapshot is one-time only; never overwrite an existing matrix.');
    }
    // Narrow query-only donor read. No donor bootstrap, updates, secret export, or production data.
    try {
        $manifest = matrixJson($source.'/fixture.json');
        if (($manifest['format'] ?? '') !== 'wphub-local-gmail-fixture-v1' || ($manifest['origin'] ?? '') !== 'http://localhost:8799') {
            throw new RuntimeException;
        }
        if (! is_file($source.'/fixture.sqlite')) {
            throw new RuntimeException;
        }
        $db = new PDO('sqlite:'.$source.'/fixture.sqlite', null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA query_only = ON');
        $db->beginTransaction();
        $setting = $db->query('SELECT client_id, client_secret FROM gmail_app_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $connections = $db->query("SELECT email, access_token, refresh_token, app_fingerprint, expires_at, connected_at, aliases FROM gmail_connections WHERE email='visabookinghelp@gmail.com' AND connected_at IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        if (count($connections) !== 1 || ($setting['client_id'] ?? '') !== '677430872479-6ccjjtfhi4mmu12ccobj89ht9fus26b9.apps.googleusercontent.com') {
            throw new RuntimeException;
        }
        $connection = $connections[0];
        $aliases = json_decode($connection['aliases'], true, flags: JSON_THROW_ON_ERROR);
        $profiles = [];
        $columns = ['brand_name', 'logo_url', 'primary_color', 'accent_color', 'background_color', 'text_color', 'support_email', 'support_phone',
            'website_url', 'footer', 'sender_email', 'sender_name', 'reply_to', 'default_language', 'service_label', 'templates', 'config_version'];
        foreach ($expected as $sourceId => $sender) {
            $query = $db->prepare('SELECT p.'.implode(',p.', $columns).' FROM website_document_profiles p JOIN websites w ON p.website_id=w.id WHERE w.slug=? AND p.workflow_enabled=0');
            $query->execute(['local-brand-draft-source-'.$sourceId]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1 || $rows[0]['sender_email'] !== $sender || ! in_array($sender, array_column($aliases, 'email'), true)) {
                throw new RuntimeException;
            }
            $profile = $rows[0];
            $profile['templates'] = json_decode($profile['templates'], true, flags: JSON_THROW_ON_ERROR);
            if (! filter_var($profile['logo_url'], FILTER_VALIDATE_URL) || parse_url($profile['logo_url'], PHP_URL_SCHEME) !== 'https') {
                throw new RuntimeException;
            }
            $profiles[] = ['source_website_id' => $sourceId, 'profile' => $profile, 'cached_alias_verified' => true];
        }
        $db->commit();
        $decrypt = new \Illuminate\Encryption\Encrypter(base64_decode(substr($manifest['key'], 7), true), 'AES-256-CBC');
        $secret = $decrypt->decryptString($setting['client_secret']);
        $access = $decrypt->decryptString($connection['access_token']);
        $refresh = $decrypt->decryptString($connection['refresh_token']);
        if (! hash_equals(hash('sha256', $setting['client_id'].':'.$secret), $connection['app_fingerprint'])) {
            throw new RuntimeException;
        }
        \App\Models\GmailAppSetting::create(['id' => 1, 'client_id' => $setting['client_id'], 'client_secret' => $secret]);
        \App\Models\GmailConnection::create(['user_id' => \App\Models\User::sole()->id, 'email' => $connection['email'],
            'connection_key' => (string) \Illuminate\Support\Str::uuid(), 'app_fingerprint' => app(\App\Services\GmailClient::class)->fingerprint(),
            'access_token' => $access, 'refresh_token' => $refresh, 'expires_at' => $connection['expires_at'], 'connected_at' => $connection['connected_at'], 'aliases' => $aliases]);
        unset($manifest, $db, $setting, $connections, $connection, $decrypt, $secret, $access, $refresh);
    } catch (Throwable) {
        fwrite(STDERR, "Snapshot/import stopped; no credentials or private exception details printed. Inspect partial target without recreating it.\n");
        exit(1);
    }
    $report = ['format' => 'wphub-brand-client-matrix-v1', 'id' => (string) \Illuminate\Support\Str::uuid(), 'created_at' => gmdate(DATE_ATOM),
        'source_access' => 'Exact local donor, query-only transaction; no before/after donor hash claim', 'recipients' => $recipients,
        'profiles' => $profiles, 'excluded' => [['source_website_id' => 13, 'reason' => 'Canada has no verified sender; remains disabled']],
        'emails_planned' => 20, 'emails_sent' => 0, 'notes' => 'Five brands; EN Gmail with one PDF, FR Outlook with two PDFs; actual Woo acknowledgements separately captured. All profiles remain disabled.'];
    matrixWrite($root.'/brand-profiles.json', $report);
    echo json_encode(['phase' => 'snapshot', 'fixture' => $root, 'brands' => 5, 'emails_sent' => 0])."\n";
    exit;
}
$profileSet = matrixJson($root.'/brand-profiles.json');
if ($profileSet['format'] !== 'wphub-brand-client-matrix-v1' || $profileSet['recipients'] !== $recipients
    || array_column($profileSet['profiles'], 'source_website_id') !== array_keys($expected)) {
    throw new RuntimeException('Exact fixed matrix scope required.');
}
if ($phase === 'prepare') {
    if (is_file($root.'/brand-matrix.json') || is_dir($root.'/brand-mail')) {
        throw new RuntimeException('Preparation is one-time only; inspect partial state instead of overwriting.');
    }
    $ackPath = realpath((string) ($options['ack-captures'] ?? ''));
    if (! $ackPath || ! str_starts_with(strtolower(str_replace('\\', '/', $ackPath)), strtolower(str_replace('\\', '/', dirname($repo, 2).'/outputs/')))) {
        throw new RuntimeException('Private real Woo acknowledgement capture required.');
    }
    $acks = matrixJson($ackPath);
    if (($acks['format'] ?? '') !== 'wphub-actual-woo-brand-acks-v1' || ($acks['profile_sha256'] ?? '') !== hash_file('sha256', $root.'/brand-profiles.json')
        || count($acks['messages'] ?? []) !== 10 || ($acks['actual_network_emails'] ?? -1) !== 0) {
        throw new RuntimeException('Acknowledgement provenance does not match frozen profiles.');
    }
    mkdir($root.'/brand-mail', 0700);
    $records = [];
    foreach ($profileSet['profiles'] as $entry) {
        $sourceId = $entry['source_website_id'];
        $profile = new \App\Models\WebsiteDocumentProfile($entry['profile']);
        foreach (['en' => $recipients[0], 'fr' => $recipients[1]] as $language => $recipient) {
            $ack = collect($acks['messages'])->where('source_website_id', $sourceId)->where('language', $language)->sole();
            if ($ack['recipient'] !== $recipient || $ack['sender_email'] !== $expected[$sourceId]
                || $ack['sender_name'] !== $profile->sender_name || $ack['reply_to'] !== $profile->reply_to
                || ($ack['stock_template_locale'] ?? '') !== ($language === 'fr' ? 'fr_FR' : 'en_US')
                || ! str_contains($ack['html'], (string) ($ack['stock_order_summary'] ?? '__missing__'))
                || ($language === 'fr' && ($ack['stock_order_summary'] ?? '') === 'Order summary')) {
                throw new RuntimeException('Woo identity mismatch.');
            }
            $order = ['customer_first_name' => 'Alexandria Éléonore Morgan-Van-Der-Linden de la Fontaine FICTIONAL',
                'order_number' => (string) $ack['order_number'], 'language' => $language];
            $documents = [];
            for ($n = 1; $n <= ($language === 'en' ? 1 : 2); $n++) {
                $pdf = new \Dompdf\Dompdf;
                $pdf->set_option('isRemoteEnabled', false);
                $pdf->loadHtml('<h1>FICTIONAL EMAIL VALIDATION</h1><p>NOT VALID FOR TRAVEL. No real booking, customer or payment.</p><p>Brand: '.htmlspecialchars($profile->brand_name).'</p><p>Sample '.$n.' / '.$language.'</p>');
                $pdf->render();
                $name = 'Fictional-'.$sourceId.'-'.$language.'-'.$n.'-Alexandria-Morgan-Van-Der-Linden-approved-sample.pdf';
                $bytes = $pdf->output();
                file_put_contents($root.'/brand-mail/'.$name, $bytes);
                $documents[] = ['name' => $name, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'bytes' => $bytes];
            }
            foreach (['acknowledgement', 'delivery'] as $kind) {
                $id = (string) \Illuminate\Support\Str::uuid();
                $selected = $kind === 'delivery' ? $documents : [];
                $attachments = array_map(fn ($document) => array_diff_key($document, ['bytes' => true]), $selected);
                $rendered = $kind === 'delivery'
                    ? app(\App\Services\BrandedDocumentRenderer::class)->render($profile, $order, $attachments, 'delivery', $language)
                    : ['subject' => $ack['subject'], 'html' => $ack['html'], 'text' => $ack['text']];
                $subject = '[TEST '.$sourceId.' '.$language.' '.$kind.'] '.$rendered['subject'];
                $notice = $language === 'fr' ? 'TEST FICTIF : aucune réservation, aucun paiement réel.' : 'FICTIONAL TEST: no real reservation or payment.';
                $html = preg_replace('/(<body\b[^>]*>)/i', '$1<p style="padding:12px;background:#fff4cc;color:#111">'.$notice.'</p>', $rendered['html'], 1);
                if ($html === $rendered['html']) {
                    $html = '<p>'.$notice.'</p>'.$html;
                }
                $text = $notice."\n\n".$rendered['text'];
                $mail = (new \Symfony\Component\Mime\Email)->from(new \Symfony\Component\Mime\Address($expected[$sourceId], $profile->sender_name))
                    ->to($recipient)->replyTo($profile->reply_to)->subject($subject)->text($text)->html($html);
                $mail->getHeaders()->addIdHeader('Message-ID', $id.'@wphub-validation.invalid');
                $mail->getHeaders()->addTextHeader('X-WPHub-Validation', $profileSet['id']);
                foreach ($selected as $document) {
                    $mail->attach($document['bytes'], $document['name'], 'application/pdf');
                }
                $mime = $mail->toString();
                app(\App\Services\DocumentValidationSafety::class)->assertRecipients($mime);
                $stem = 'brand-mail/'.$id;
                file_put_contents($root.'/'.$stem.'.eml', $mime);
                file_put_contents($root.'/'.$stem.'.html', $html);
                file_put_contents($root.'/'.$stem.'.txt', $text);
                $records[] = ['id' => $id, 'source_website_id' => $sourceId, 'brand' => $profile->brand_name, 'kind' => $kind, 'language' => $language,
                    'recipient' => $recipient, 'sender_email' => $expected[$sourceId], 'sender_name' => $profile->sender_name, 'reply_to' => $profile->reply_to,
                    'logo_url' => $profile->logo_url, 'subject' => $subject, 'attachments' => $attachments, 'mime_path' => $stem.'.eml',
                    'mime_sha256' => hash('sha256', $mime), 'html_sha256' => hash('sha256', $html), 'text_sha256' => hash('sha256', $text),
                    'order_number' => $order['order_number'], 'origin' => $kind === 'delivery' ? 'Actual app renderer; fictional MIME test' : 'Actual Woo paid-processing trigger and companion content; Gmail bridge, not stock SMTP'];
            }
        }
    }
    matrixWrite($root.'/brand-matrix.json', ['format' => 'wphub-brand-client-matrix-v1', 'id' => $profileSet['id'],
        'profile_sha256' => hash_file('sha256', $root.'/brand-profiles.json'), 'ack_capture_sha256' => hash_file('sha256', $ackPath),
        'acknowledgement_runtime' => $acks['software'], 'acknowledgement_hpos' => $acks['hpos'],
        'acknowledgement_email_improvements' => $acks['email_improvements'],
        'recipients' => $recipients, 'records' => $records, 'emails_sent' => 0]);
    matrixWrite($root.'/brand-send-ledger.json', array_fill_keys(array_column($records, 'id'), ['status' => 'prepared', 'attempts' => 0]));
    echo json_encode(['phase' => 'prepare', 'records' => count($records), 'pdf_instances' => 15, 'emails_sent' => 0, 'fixture' => $root])."\n";
    exit;
}
$matrix = matrixJson($root.'/brand-matrix.json');
$ledgerPath = $root.'/brand-send-ledger.json';
if ($phase === 'evidence') {
    echo json_encode(['fixture' => $root, 'matrix_sha256' => hash_file('sha256', $root.'/brand-matrix.json'), 'records' => count($matrix['records']),
        'send_ledger' => matrixJson($ledgerPath), 'transport_default' => $state['transport'], 'production_actions' => 0], JSON_PRETTY_PRINT)."\n";
    exit;
}
// Root/operator invokes one exact record after independent pre-send review. No bulk or retry flag exists.
if (is_file($root.'/brand-send-blocked.json')) {
    throw new RuntimeException('This immutable matrix was withdrawn after review; transmission is permanently blocked.');
}
$id = (string) ($options['record'] ?? '');
$review = matrixJson($root.'/brand-pre-send-review.json');
$approval = matrixJson($root.'/brand-send-approval.json');
if (($review['status'] ?? '') !== 'passed' || ($review['matrix_sha256'] ?? '') !== hash_file('sha256', $root.'/brand-matrix.json')
    || ($approval['matrix_sha256'] ?? '') !== $review['matrix_sha256'] || ($approval['manifest_reviewed'] ?? false) !== true
    || ($approval['foreign_logo_fetch_passed'] ?? false) !== true
    || count($matrix['records']) !== 20 || $matrix['recipients'] !== $recipients) {
    throw new RuntimeException('Independent immutable-matrix review and separate operator logo/manifest approval are required.');
}
$record = collect($matrix['records'])->where('id', $id)->sole();
if (! preg_match('/^[a-f0-9-]{36}$/D', $id) || $record['mime_path'] !== 'brand-mail/'.$id.'.eml'
    || $record['sender_email'] !== $expected[$record['source_website_id']] || ! in_array($record['recipient'], $recipients, true)) {
    throw new RuntimeException('Exact approved record required.');
}
$mime = file_get_contents($root.'/'.$record['mime_path']);
if (! hash_equals($record['mime_sha256'], hash('sha256', $mime))) {
    throw new RuntimeException('Reviewed MIME changed; refusing transmission.');
}
$lock = fopen($root.'/brand-send.lock', 'c');
if (! flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Another matrix action is running.');
}
$ledger = matrixJson($ledgerPath);
if (($ledger[$id]['status'] ?? '') !== 'prepared' || ($ledger[$id]['attempts'] ?? -1) !== 0) {
    throw new RuntimeException('This record already started; inspect the saved result and mailbox, never automatically resend.');
}
$ledger[$id] = ['status' => 'validating', 'attempts' => 1, 'started_at' => gmdate(DATE_ATOM)];
matrixWrite($ledgerPath, $ledger);
config(['document_delivery.validation.mail_transport' => 'gmail']);
$started = false;
try {
    $gmail = app(\App\Services\GmailClient::class);
    $connection = \App\Models\GmailConnection::sole();
    $deadline = hrtime(true) / 1_000_000_000 + 20;
    $aliases = $gmail->aliases($connection, $deadline);
    if (! in_array($record['sender_email'], array_column($aliases, 'email'), true)) {
        throw new \App\Exceptions\GmailDeliveryException('Frozen sender is no longer a verified alias.');
    }
    $ledger[$id]['status'] = 'sending';
    matrixWrite($ledgerPath, $ledger);
    $started = true;
    $provider = $gmail->send($connection, $mime, null, $deadline);
    $ledger[$id] += ['provider_message_id' => $provider, 'sent_at' => gmdate(DATE_ATOM)];
    $ledger[$id]['status'] = 'sent';
} catch (\App\Exceptions\GmailDeliveryException $exception) {
    $ledger[$id]['status'] = $exception->uncertain ? 'uncertain' : 'failed_no_submission_or_definite_rejection';
} catch (Throwable) {
    $ledger[$id]['status'] = $started ? 'uncertain' : 'failed_before_submission';
}
matrixWrite($ledgerPath, $ledger);
flock($lock, LOCK_UN);
fclose($lock);
echo json_encode(['record' => $id, 'recipient' => $record['recipient'], 'result' => $ledger[$id], 'note' => 'Provider acceptance is not received-client evidence. No retry is permitted by this runner.'], JSON_PRETTY_PRINT)."\n";
