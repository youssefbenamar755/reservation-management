<?php

namespace App\Support;

class WebhookFailureSummary
{
    /** Only fixed public messages may leave the server; exceptions can contain customer data and credentials. */
    public static function summarize(?string $error): string
    {
        $text = strtolower($error ?? '');

        return match (true) {
            (str_contains($text, 'missing') || str_contains($text, 'required') || str_contains($text, 'invalid'))
                && preg_match('/\b(form_id|entry_id|order id|order_id|identifier|identifiers)\b/', $text) === 1 => 'Required order or submission identifiers are missing or invalid. Check the website integration before retrying.',
            str_contains($text, 'timed out'), str_contains($text, 'timeout'), str_contains($text, 'curl error 28') => 'Processing timed out. Check the website connection and retry.',
            str_contains($text, 'sqlstate'), str_contains($text, 'deadlock'), str_contains($text, 'database'), str_contains($text, 'duplicate entry') => 'A database operation failed. Retry after the database issue is resolved.',
            str_contains($text, 'connection'), str_contains($text, 'network'), str_contains($text, 'could not resolve'), str_contains($text, 'curl error') => 'A service connection failed. Check connectivity and retry.',
            str_contains($text, 'payload'), str_contains($text, 'json'), str_contains($text, 'schema'), str_contains($text, 'validation'), str_contains($text, 'invalid format') => 'The webhook data could not be processed. Check the website integration and data format before retrying.',
            default => 'Processing failed. Check the website integration before retrying, or ask an administrator to investigate.',
        };
    }

    public static function attempt(string $status, ?string $message): string
    {
        if (in_array($message, [
            'Retry queued.', 'Retry is processing.', 'Webhook processed successfully.',
            'Retry failed. Review the delivery details before trying again.',
            'Submission already exists; existing data was kept unchanged.',
            'Retry was skipped because the delivery is no longer eligible.',
            'Retry was skipped because the website is not active.',
        ], true)) {
            return $message;
        }

        return match ($status) {
            'pending', 'queued' => 'The retry is waiting to start.',
            'running', 'processing' => 'The retry is processing.',
            'succeeded', 'processed' => 'The webhook was processed successfully.',
            'failed' => self::summarize($message),
            default => 'The retry could not be completed. Refresh to check its status.',
        };
    }
}
