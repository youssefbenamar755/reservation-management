<?php

/** Two fictional ConfirmedTKT controls. Preparation never sends; each approved immutable ID is attempted once. */
$options = getopt('', ['fixture:', 'phase:', 'record:']);
$repo = dirname(__DIR__, 2);
$root = realpath((string) ($options['fixture'] ?? ''));
$phase = $options['phase'] ?? '';
$outputs = realpath(dirname($repo, 2).'/outputs');
if (PHP_SAPI !== 'cli' || function_exists('mail') || ! $root || ! $outputs
    || ! str_starts_with(strtolower(str_replace('\\', '/', $root)).'/', strtolower(str_replace('\\', '/', $outputs)).'/')
    || ! in_array($phase, ['prepare', 'send', 'evidence'], true)) {
    throw new RuntimeException('Explicit isolated fixture/phase and native mail disabled required.');
}
// Same Windows sandbox metadata workaround as the existing isolated matrix runner.
if (! function_exists('is_readable')) {
    function is_readable($path): bool
    {
        global $root, $repo;
        $path = is_string($path) ? realpath($path) : false;
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
    || \Illuminate\Support\Facades\DB::table('jobs')->count() !== 0
    || ! is_file($root.'/brand-profiles.json') || is_file($root.'/brand-matrix.json')
    || \App\Models\GmailConnection::count() !== 1) {
    throw new RuntimeException('Fresh snapshotted capture fixture with disabled workflows, no prior matrix and no jobs required.');
}
function diagnosticRead(string $path): array
{
    return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}
function diagnosticWrite(string $path, array $value): void
{
    $bytes = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
        throw new RuntimeException('Could not persist diagnostic state.');
    }
}
$recipient = 'benamar-y14@outlook.com';
$sender = 'support@confirmedtkt.com';
$profileSet = diagnosticRead($root.'/brand-profiles.json');
$entry = collect($profileSet['profiles'] ?? [])->where('source_website_id', 12)->sole();
$profile = new \App\Models\WebsiteDocumentProfile($entry['profile']);
if ($profile->sender_email !== $sender || $profile->reply_to !== $sender || $profile->brand_name !== 'ConfirmedTKT'
    || $profile->logo_url !== 'https://wphub.website/images/email-brands/confirmedtkt.png'
    || $profile->website_url !== 'https://confirmedtkt.com/'
    || ! in_array($recipient, wphubLocalApprovedRecipients(), true)) {
    throw new RuntimeException('Frozen own-brand identity and exact approved recipient required.');
}
$matrixPath = $root.'/confirmed-diagnostic.json';
$ledgerPath = $root.'/confirmed-diagnostic-ledger.json';
if ($phase === 'prepare') {
    if (file_exists($matrixPath) || file_exists($ledgerPath) || is_dir($root.'/confirmed-diagnostic-mail')) {
        throw new RuntimeException('Preparation is one-time only.');
    }
    mkdir($root.'/confirmed-diagnostic-mail', 0700);
    $records = [];
    $batch = (string) \Illuminate\Support\Str::uuid();
    foreach (['plain-control', 'branded-document'] as $kind) {
        $id = (string) \Illuminate\Support\Str::uuid();
        $subject = '[TEST CTKT '.substr($batch, 0, 8).' '.$kind.'] Vérification de réception — échantillon fictif';
        $text = "Bonjour,\n\nCeci est le test de réception ConfirmedTKT demandé par Youssef. Il ne correspond à aucune réservation, commande, transaction ni demande de paiement réelle.\n\nCe message de contrôle contient uniquement du texte, sans lien, image ou pièce jointe. Nous vérifions seulement le classement du message dans la messagerie Outlook approuvée. Aucune action n’est requise.\n\nMerci,\nL’équipe ConfirmedTKT";
        $html = null;
        $attachments = [];
        $mail = (new \Symfony\Component\Mime\Email)->from(new \Symfony\Component\Mime\Address($sender, $profile->sender_name))
            ->to($recipient)->replyTo($sender)->subject($subject);
        if ($kind === 'branded-document') {
            $pdf = new \Dompdf\Dompdf;
            $pdf->set_option('isRemoteEnabled', false);
            $pdf->loadHtml('<h1>FICTIONAL EMAIL VALIDATION</h1><p>NOT VALID FOR TRAVEL.</p><p>No real booking, customer or payment.</p><p>ConfirmedTKT diagnostic sample.</p>');
            $pdf->render();
            $bytes = $pdf->output();
            $filename = 'ConfirmedTKT-fictional-diagnostic-sample.pdf';
            $attachments = [['name' => $filename, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]];
            $rendered = app(\App\Services\BrandedDocumentRenderer::class)->render($profile,
                ['customer_first_name' => 'Youssef FICTIONAL', 'order_number' => 'DIAGNOSTIC-'.substr($batch, 0, 8), 'language' => 'fr'],
                $attachments, 'delivery', 'fr');
            $notice = 'TEST FICTIF : aucune réservation, aucun paiement réel. Échantillon demandé pour vérifier la réception.';
            $html = preg_replace('/(<body\b[^>]*>)/i', '$1<p style="padding:12px;background:#fff4cc;color:#111">'.$notice.'</p>', $rendered['html'], 1);
            if ($html === $rendered['html']) {
                $html = '<p>'.$notice.'</p>'.$html;
            }
            $text = $notice."\n\n".$rendered['text'];
            $mail->html($html)->attach($bytes, $filename, 'application/pdf');
            file_put_contents($root.'/confirmed-diagnostic-mail/'.$filename, $bytes);
        }
        $mail->text($text);
        $mail->getHeaders()->addIdHeader('Message-ID', $id.'@wphub-validation.invalid');
        $mail->getHeaders()->addTextHeader('X-WPHub-Validation', $batch);
        $mime = $mail->toString();
        app(\App\Services\DocumentValidationSafety::class)->assertRecipients($mime);
        $stem = 'confirmed-diagnostic-mail/'.$id;
        file_put_contents($root.'/'.$stem.'.eml', $mime);
        file_put_contents($root.'/'.$stem.'.txt', $text);
        if ($html !== null) {
            file_put_contents($root.'/'.$stem.'.html', $html);
        }
        $records[] = ['id' => $id, 'kind' => $kind, 'source_website_id' => 12, 'language' => 'fr',
            'recipient' => $recipient, 'sender_email' => $sender, 'sender_name' => $profile->sender_name, 'reply_to' => $sender,
            'subject' => $subject, 'mime_path' => $stem.'.eml', 'mime_sha256' => hash('sha256', $mime),
            'text_sha256' => hash('sha256', $text), 'html_sha256' => $html === null ? null : hash('sha256', $html),
            'attachments' => $attachments, 'logo_url' => $html === null ? null : $profile->logo_url];
    }
    diagnosticWrite($matrixPath, ['format' => 'wphub-confirmed-deliverability-diagnostic-v1', 'created_at' => gmdate(DATE_ATOM),
        'id' => $batch, 'profile_sha256' => hash_file('sha256', $root.'/brand-profiles.json'), 'recipients' => [$recipient], 'records' => $records,
        'limits' => 'Two controls differ in multiple content factors. Results test content sensitivity, not a single causal variable. Provider acceptance is not folder-placement proof.',
        'emails_sent' => 0]);
    diagnosticWrite($ledgerPath, array_fill_keys(array_column($records, 'id'), ['status' => 'prepared', 'attempts' => 0]));
    echo json_encode(['phase' => 'prepare', 'fixture' => $root, 'records' => 2, 'emails_sent' => 0, 'matrix_sha256' => hash_file('sha256', $matrixPath)])."\n";
    exit;
}
$matrix = diagnosticRead($matrixPath);
if ($phase === 'evidence') {
    echo json_encode(['fixture' => $root, 'matrix_sha256' => hash_file('sha256', $matrixPath), 'ledger' => diagnosticRead($ledgerPath), 'production_actions' => 0], JSON_PRETTY_PRINT)."\n";
    exit;
}
$review = diagnosticRead($root.'/confirmed-diagnostic-review.json');
$approval = diagnosticRead($root.'/confirmed-diagnostic-approval.json');
if (is_file($root.'/confirmed-diagnostic-blocked.json') || ($matrix['format'] ?? '') !== 'wphub-confirmed-deliverability-diagnostic-v1'
    || count($matrix['records'] ?? []) !== 2 || $matrix['recipients'] !== [$recipient]
    || array_column($matrix['records'], 'kind') !== ['plain-control', 'branded-document']
    || ($review['status'] ?? '') !== 'passed' || ($review['matrix_sha256'] ?? '') !== hash_file('sha256', $matrixPath)
    || ($approval['matrix_sha256'] ?? '') !== $review['matrix_sha256'] || ($approval['manifest_reviewed'] ?? false) !== true
    || ($approval['foreign_logo_fetch_passed'] ?? false) !== true
    || ! hash_equals($matrix['profile_sha256'], hash_file('sha256', $root.'/brand-profiles.json'))) {
    throw new RuntimeException('Two immutable controls, independent audit and separate operator review required.');
}
$id = (string) ($options['record'] ?? '');
$record = collect($matrix['records'])->where('id', $id)->sole();
if (! preg_match('/^[a-f0-9-]{36}$/D', $id) || $record['mime_path'] !== 'confirmed-diagnostic-mail/'.$id.'.eml'
    || $record['sender_email'] !== $sender || $record['recipient'] !== $recipient || $record['reply_to'] !== $sender) {
    throw new RuntimeException('Exact reviewed control ID and allowed addresses required.');
}
$mime = file_get_contents($root.'/'.$record['mime_path']);
if (! hash_equals($record['mime_sha256'], hash('sha256', $mime))) {
    throw new RuntimeException('Reviewed MIME changed.');
}
app(\App\Services\DocumentValidationSafety::class)->assertRecipients($mime);
$lock = fopen($root.'/confirmed-diagnostic-send.lock', 'c');
if (! flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Another diagnostic action is running.');
}
$ledger = diagnosticRead($ledgerPath);
if (($ledger[$id]['status'] ?? '') !== 'prepared' || ($ledger[$id]['attempts'] ?? -1) !== 0
    || in_array('uncertain', array_column($ledger, 'status'), true)) {
    throw new RuntimeException('A control already started or is uncertain; inspect, never replay.');
}
$ledger[$id] = ['status' => 'validating', 'attempts' => 1, 'started_at' => gmdate(DATE_ATOM)];
diagnosticWrite($ledgerPath, $ledger);
config(['document_delivery.validation.mail_transport' => 'gmail']);
$started = false;
try {
    $gmail = app(\App\Services\GmailClient::class);
    $connection = \App\Models\GmailConnection::sole();
    $deadline = hrtime(true) / 1_000_000_000 + 20;
    $aliases = $gmail->aliases($connection, $deadline);
    if (! in_array($sender, array_column($aliases, 'email'), true)) {
        throw new \App\Exceptions\GmailDeliveryException('Frozen sender is no longer verified.');
    }
    $ledger[$id]['status'] = 'sending';
    diagnosticWrite($ledgerPath, $ledger);
    $started = true;
    $provider = $gmail->send($connection, $mime, null, $deadline);
    $ledger[$id] += ['provider_message_id' => $provider, 'sent_at' => gmdate(DATE_ATOM)];
    $ledger[$id]['status'] = 'sent';
} catch (\App\Exceptions\GmailDeliveryException $exception) {
    $ledger[$id]['status'] = $exception->uncertain ? 'uncertain' : 'failed_no_submission_or_definite_rejection';
} catch (Throwable) {
    $ledger[$id]['status'] = $started ? 'uncertain' : 'failed_before_submission';
}
diagnosticWrite($ledgerPath, $ledger);
flock($lock, LOCK_UN);
fclose($lock);
echo json_encode(['record' => $id, 'recipient' => $recipient, 'result' => $ledger[$id], 'note' => 'Acceptance is not receipt; no retry supported.'], JSON_PRETTY_PRINT)."\n";
