<?php

/**
 * Read-only release-preview exporter. No Laravel bootstrap, database, credentials,
 * queue, HTTP client, Gmail client or store adapter is loaded or called.
 *
 * php tools/validation/export-document-previews.php registry.json output-directory
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Models\WebsiteDocumentProfile;
use App\Services\BrandedDocumentRenderer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

$registryPath = $argv[1] ?? '';
$output = rtrim($argv[2] ?? '', '/\\');
if (! is_file($registryPath) || $output === '') {
    fwrite(STDERR, "Usage: php export-document-previews.php registry.json output-directory\n");
    exit(1);
}
$registry = json_decode(file_get_contents($registryPath), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($registry['websites'] ?? null)) {
    throw new RuntimeException('The input must contain the observed website registry.');
}
if (! is_dir($output)) {
    mkdir($output, 0700, true);
}
$renderer = new BrandedDocumentRenderer;
$manifest = [
    'generated_at' => gmdate('c'),
    'registry_source' => $registry['source'],
    'registry_observed_at' => $registry['observed_at'],
    'status' => 'DRAFT / UNAPPROVED DIAGNOSTIC EXPORTS — NOT SENT',
    'isolation' => 'Composer autoload only; no Laravel bootstrap/database/HTTP/Gmail/store/queue. Dompdf remote fetching disabled. Fictional recipient and documents only.',
    'renderer' => BrandedDocumentRenderer::class,
    'software' => ['php' => PHP_VERSION, 'dompdf' => Composer\InstalledVersions::getPrettyVersion('dompdf/dompdf'),
        'symfony_mime' => Composer\InstalledVersions::getPrettyVersion('symfony/mime')],
    'limitations' => [
        'Production document-profile tables do not exist. No website is activated or configured for this new workflow.',
        'Legacy logo and accent references are copied only from each same-site registry record. Remote logo availability and ownership were not fetched or reverified.',
        'Neutral primary/background/text colours and conspicuous service/footer placeholders are diagnostic values, not saved brand configuration.',
        'French/English copy is the shared application default. These exports do not prove a website has approved either language.',
        'Acknowledgements are renderer-only previews. Actual WooCommerce acknowledgements preserve its order table and transport; a real store test remains required.',
        'MIME exports are local files, not sent or received messages. Gmail and Outlook rendering remains pending.',
        'Image-blocked HTML replaces image elements to demonstrate readable brand text; it is not an email-client screenshot.',
    ],
    'websites' => [],
];
$indexRows = [];

foreach ($registry['websites'] as $site) {
    $id = (int) $site['id'];
    $siteDirectory = $output.'/website-'.$id;
    if (! is_dir($siteDirectory)) {
        mkdir($siteDirectory, 0700, true);
    }
    $sender = $site['sender'] ?: 'fixture@example.invalid';
    $missing = ['document_profile', 'primary_color', 'background_color', 'text_color', 'service_label', 'footer',
        'approved_templates', 'required_services', 'explicit_gmail_assignment', 'companion_pairing_and_current_capability'];
    if (! $site['sender']) {
        $missing[] = 'sender_email_and_verification';
    }
    $siteManifest = [
        'website_id' => $id, 'registry_name' => $site['name'], 'website_url' => $site['url'], 'status' => 'draft_unapproved',
        'same_site_registry_identity' => array_intersect_key($site, array_flip(['brand_name', 'logo_url', 'accent_color', 'reply_to', 'sender', 'cached_alias_verified'])),
        'diagnostic_values_not_configuration' => ['primary_color' => '#334155', 'background_color' => '#f3f4f6', 'text_color' => '#18212f',
            'service_label' => '[SERVICE LABEL REQUIRED]', 'footer' => '[FOOTER REQUIRED — DIAGNOSTIC SAMPLE ONLY]',
            'sender_when_missing' => $site['sender'] ? null : 'fixture@example.invalid'],
        'missing_configuration' => $missing, 'samples' => [],
    ];
    foreach (['fr', 'en'] as $language) {
        $profile = new WebsiteDocumentProfile([
            'website_id' => $id, 'brand_name' => $site['brand_name'], 'logo_url' => $site['logo_url'],
            'primary_color' => '#334155', 'accent_color' => $site['accent_color'], 'background_color' => '#f3f4f6', 'text_color' => '#18212f',
            'support_email' => $site['reply_to'], 'website_url' => $site['url'], 'sender_email' => $sender,
            'sender_name' => $site['brand_name'], 'reply_to' => $site['reply_to'], 'default_language' => $language,
            'service_label' => $language === 'fr' ? '[TYPE DE SERVICE À CONFIGURER]' : '[SERVICE LABEL REQUIRED]',
            'footer' => $language === 'fr' ? '[PIED DE PAGE À CONFIGURER — EXEMPLE DE DIAGNOSTIC UNIQUEMENT]' : '[FOOTER REQUIRED — DIAGNOSTIC SAMPLE ONLY]',
            'templates' => BrandedDocumentRenderer::defaults(), 'workflow_enabled' => false, 'config_version' => 0,
        ]);
        $order = ['customer_first_name' => 'Alexandria Éléonore Morgan-Van-Der-Linden de la Fontaine',
            'order_number' => 'FICTIONAL-'.$id.'-1042', 'language' => $language];
        $documents = [];
        for ($number = 1; $number <= 3; $number++) {
            $filename = 'Fictional-document-'.$number.'-Alexandria-Morgan-Van-Der-Linden-'.$id.'-'.$language.'-approved-sample.pdf';
            $options = new Options;
            $options->setIsRemoteEnabled(false);
            $options->setIsPhpEnabled(false);
            $options->setIsJavascriptEnabled(false);
            $options->setDefaultFont('Helvetica');
            $pdf = new Dompdf($options);
            $pdf->loadHtml('<!doctype html><html><head><meta charset="UTF-8"><style>body{font-family:Helvetica,sans-serif;color:#152235;margin:40px}h1{color:#9a3412;font-size:24px}.notice{border:3px solid #9a3412;padding:18px;font-size:18px}</style></head><body><h1>FICTIONAL VALIDATION DOCUMENT '.$number.'</h1><p class="notice">NOT VALID FOR TRAVEL / DOCUMENT DE TEST SANS VALEUR</p><p>Local rendering and attachment-integrity test only. No booking, payment, passenger record or real customer data.</p><p>Registry website: '.htmlspecialchars($site['brand_name']).'</p><p>Fictional order: '.htmlspecialchars($order['order_number']).'</p><p>Fictional name: '.htmlspecialchars($order['customer_first_name']).'</p><p>Language variant: '.$language.'</p><p>Document number: '.$number.' of 3</p><p>This PDF is deliberately harmless. It must never be presented as a real reservation or customer document.</p></body></html>', 'UTF-8');
            $pdf->setPaper('A4');
            $pdf->render();
            $bytes = $pdf->output();
            if (! str_starts_with($bytes, '%PDF-') || ! str_contains($bytes, '%%EOF')) {
                throw new RuntimeException('Dompdf did not return a complete sample PDF.');
            }
            file_put_contents($siteDirectory.'/'.$filename, $bytes);
            $documents[] = ['name' => $filename, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'bytes' => $bytes];
        }
        foreach ([['acknowledgement', 0], ['delivery', 1], ['delivery', 3]] as [$kind, $count]) {
            $selected = array_slice($documents, 0, $count);
            $attachments = array_map(fn ($doc) => array_diff_key($doc, ['bytes' => true]), $selected);
            $rendered = $renderer->render($profile, $order, $attachments, $kind, $language);
            $diagnostic = $language === 'fr'
                ? 'BROUILLON NON APPROUVÉ — EXEMPLE LOCAL FICTIF. Aucun profil de documents enregistré. Les couleurs principale/fond/texte sont neutres pour le diagnostic ; type de service et pied de page restent à configurer. Identité existante du même site uniquement. Modèle commun à faire valider. Aucun e-mail envoyé.'
                : 'UNAPPROVED DRAFT — LOCAL FICTIONAL SAMPLE. No document profile is saved. Primary/background/text colours are neutral diagnostic values; service label and footer still require configuration. Existing same-site identity only. Shared default copy requires approval. No email was sent.';
            if (! $site['sender']) {
                $diagnostic .= $language === 'fr' ? ' EXPÉDITEUR MANQUANT : fixture@example.invalid est uniquement un en-tête fictif.' : ' SENDER MISSING: fixture@example.invalid is a diagnostic header only.';
            }
            $banner = '<div role="note" style="background:#fff7ed;border-bottom:2px solid #9a3412;color:#7c2d12;padding:16px;font-family:Arial,sans-serif;font-size:13px;line-height:1.5">'.htmlspecialchars($diagnostic).'</div>';
            $html = preg_replace('/(<body\b[^>]*>)/i', '$1'.$banner, $rendered['html'], 1);
            $text = $diagnostic."\n\n".$rendered['text'];
            $subject = '[DRAFT / NOT SENT] '.$rendered['subject'];
            $stem = $kind.'-'.$language.($kind === 'delivery' ? '-'.$count.'-pdf' : '');
            $email = (new Email)->from(new Address($sender, $site['brand_name']))->to('fixture@example.invalid')
                ->replyTo($site['reply_to'])->subject($subject)->text($text)->html($html);
            $email->getHeaders()->addTextHeader('X-WPHub-Preview-Status', 'unapproved-local-fixture-not-sent');
            $email->getHeaders()->addTextHeader('X-WPHub-Website-Id', (string) $id);
            foreach ($selected as $document) {
                $email->attach($document['bytes'], $document['name'], 'application/pdf');
            }
            $mime = $email->toString();
            $blockedHtml = preg_replace('/<img\b[^>]*>/i', '<p style="font-size:12px;color:#475569">[Images blocked — logo hidden]</p>', $html);
            file_put_contents($siteDirectory.'/'.$stem.'.html', $html);
            file_put_contents($siteDirectory.'/'.$stem.'-images-blocked.html', $blockedHtml);
            file_put_contents($siteDirectory.'/'.$stem.'.txt', $text);
            file_put_contents($siteDirectory.'/'.$stem.'.eml', $mime);
            $relative = 'website-'.$id.'/'.$stem;
            $siteManifest['samples'][] = ['language' => $language, 'kind' => $kind, 'attachments' => $attachments,
                'html' => $relative.'.html', 'images_blocked_html' => $relative.'-images-blocked.html', 'text' => $relative.'.txt',
                'mime' => $relative.'.eml', 'mime_sha256' => hash('sha256', $mime), 'subject' => $subject,
                'from' => $sender, 'reply_to' => $site['reply_to'], 'recipient' => 'fixture@example.invalid'];
            $indexRows[] = '<tr><td>'.htmlspecialchars($site['name']).'</td><td>'.$kind.'</td><td>'.$language.'</td><td>'.$count.'</td><td><a href="'.$relative.'.html">HTML</a> · <a href="'.$relative.'-images-blocked.html">Images blocked</a> · <a href="'.$relative.'.txt">Text</a> · <a href="'.$relative.'.eml">MIME</a></td></tr>';
        }
    }
    $manifest['websites'][] = $siteManifest;
}
file_put_contents($output.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
file_put_contents($output.'/index.html', '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Unapproved WP Hub email release previews</title><style>body{font-family:Arial,sans-serif;margin:32px;color:#18212f}table{border-collapse:collapse}th,td{padding:10px;border:1px solid #d5dae0;text-align:left}aside{padding:16px;background:#fff7ed;border:2px solid #9a3412;margin-bottom:24px;max-width:900px;line-height:1.6}</style></head><body><h1>Unapproved local email drafts</h1><aside>All six sites lack the new document profile. These are diagnostic exports, not configured or approved emails. Neutral primary/background/text colours and explicit missing-service/footer placeholders are used solely to inspect layouts. Logos, accent colours and sender references come from each same-site registry record. No email was sent; no logos were fetched. The Canada site has no sender and uses an obvious fictional MIME header. Acknowledgements are renderer previews, not actual WooCommerce order emails. See manifest.json for provenance and blockers.</aside><table><thead><tr><th>Website</th><th>Template</th><th>Language</th><th>PDF count</th><th>Preview files</th></tr></thead><tbody>'.implode('', $indexRows).'</tbody></table></body></html>');
echo json_encode(['websites' => count($manifest['websites']), 'samples' => count($indexRows), 'sample_pdfs' => count($manifest['websites']) * 6, 'output' => $output, 'network_calls' => 0, 'emails_sent' => 0], JSON_UNESCAPED_SLASHES)."\n";
