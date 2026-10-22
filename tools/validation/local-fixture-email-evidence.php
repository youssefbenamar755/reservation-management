<?php

// Read-only, narrowly projected evidence for this explicitly approved local fixture.
// Never prints fixture configuration, OAuth credentials/tokens, mail bodies or server logs.
// Usage: php -d disable_functions=mail tools/validation/local-fixture-email-evidence.php

if (PHP_SAPI !== 'cli' || $argc !== 1) {
    fwrite(STDERR, "Run this scoped evidence command from the CLI without arguments.\n");
    exit(2);
}

require __DIR__.'/local-fixture-bootstrap.php';

try {
    $fixtureId = 'wphub-local-gmail-4483c123ccd53998';
    $fixture = dirname(__DIR__, 4).'/outputs/'.$fixtureId;
    [$app] = wphubLocalFixture($fixture);

    // This connection cannot write, including accidental model writes during projection.
    \Illuminate\Support\Facades\DB::connection()->getPdo()->exec('PRAGMA query_only = ON');
    \Illuminate\Support\Facades\Http::globalRequestMiddleware(static function () {
        throw new RuntimeException('Network access is forbidden in this evidence command.');
    });

    $websiteCount = \App\Models\Website::count();
    $orderCount = \App\Models\WcOrder::count();
    $testCount = \App\Models\DocumentTestSend::count();
    if ($websiteCount !== 1 || $orderCount !== 1 || $testCount > 10) {
        throw new RuntimeException('Unexpected fixture scope; do not export other application data.');
    }

    $attempts = \App\Models\DocumentTestSend::query()
        ->select(['id', 'status', 'provider_message_id', 'snapshot', 'created_at'])
        ->orderBy('created_at')->get()->map(function ($attempt) {
            $snapshot = $attempt->snapshot;
            if (! in_array($snapshot['recipient'] ?? null, wphubLocalApprovedRecipients(), true)) {
                throw new RuntimeException('Unexpected recipient; stop this scoped evidence projection.');
            }

            return [
                'id' => $attempt->id,
                'status' => $attempt->status,
                'provider_message_id' => $attempt->provider_message_id,
                'recipient' => $snapshot['recipient'],
                'language' => $snapshot['language'] ?? null,
                'template' => $snapshot['template'] ?? null,
                'sample_document_count' => $snapshot['sample_document_count'] ?? null,
                'sender' => [
                    'email' => $snapshot['sender']['email'] ?? null,
                    'name' => $snapshot['sender']['name'] ?? null,
                ],
                'reply_to' => $snapshot['reply_to'] ?? null,
                'subject' => $snapshot['subject'] ?? null,
                'attachments' => array_map(static fn ($attachment) => [
                    'name' => $attachment['name'] ?? null,
                ], $snapshot['attachments'] ?? []),
                'created_at' => $attempt->created_at?->toIso8601String(),
            ];
        })->all();

    $report = [
        'observed_at' => gmdate(DATE_ATOM),
        'fixture_id' => $fixtureId,
        'evidence' => 'Read-only projection of the fictional fixture after normal UI test sends',
        'transport' => config('document_delivery.validation.mail_transport'),
        'recipient_allowlist' => config('document_delivery.validation.recipients'),
        'store_origins' => config('document_delivery.validation.store_origins'),
        'websites' => $websiteCount,
        'order_count' => $orderCount,
        'orders' => \App\Models\WcOrder::query()->select(['id', 'status'])->orderBy('id')->get()->toArray(),
        'jobs' => \Illuminate\Support\Facades\DB::table('jobs')->count(),
        'document_profiles_enabled' => \App\Models\WebsiteDocumentProfile::where('workflow_enabled', true)->count(),
        'test_send_count' => $testCount,
        'test_sends' => $attempts,
        'database_read_only' => true,
        'network_requests_by_evidence_command' => 0,
        'inbox_receipt_verified' => false,
        'email_client_rendering_verified' => false,
        'received_pdf_integrity_verified' => false,
        'limitation' => 'Sent records with provider IDs establish provider acceptance only, not inbox receipt or rendering.',
    ];

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) {
    // Avoid an uncaught stack trace or exception text that could include private state.
    fwrite(STDERR, "Read-only fixture evidence could not be produced; no private values were printed.\n");
    exit(1);
}
