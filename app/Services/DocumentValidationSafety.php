<?php

namespace App\Services;

use App\Exceptions\GmailDeliveryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Symfony\Component\Mime\Address;

/** Fail-closed transport guard for an explicitly isolated fixture environment. */
class DocumentValidationSafety
{
    public function enabled(): bool
    {
        return (bool) config('document_delivery.validation.enabled', false);
    }

    public function capture(): bool
    {
        return $this->enabled() && config('document_delivery.validation.mail_transport') === 'capture';
    }

    public function assertEnvironment(): void
    {
        if (! in_array(app()->environment(), ['local', 'testing', 'staging'], true)) {
            throw new RuntimeException('Document validation mode is forbidden in this application environment.');
        }
        if (! in_array(config('document_delivery.validation.mail_transport'), ['capture', 'gmail'], true)) {
            throw new RuntimeException('Document validation requires capture or explicitly approved Gmail transport.');
        }
    }

    /** Returns a local capture reference, or null only when approved Gmail sending is allowed. */
    public function prepareMail(string $mime): ?string
    {
        if (! $this->enabled()) {
            return null;
        }
        $this->assertEnvironment();
        $this->assertRecipients($mime);
        if (! $this->capture()) {
            return null;
        }
        $root = (string) config('document_delivery.validation.capture_path');
        if (PrivateStoragePath::isPublic($root)) {
            throw new GmailDeliveryException('Validation capture storage must be private and outside the public directory.');
        }
        $id = 'capture_'.Str::uuid();
        $disk = Storage::build(['driver' => 'local', 'root' => $root, 'visibility' => 'private', 'throw' => true]);
        $disk->put($id.'.eml', $mime, ['visibility' => 'private']);

        return $id;
    }

    /** Strictly accepts the one-recipient MIME produced by WP Hub, with no hidden recipients. */
    public function assertRecipients(string $mime): void
    {
        $head = preg_split('/\r?\n\r?\n/', $mime, 2)[0] ?? '';
        $head = preg_replace('/\r?\n[ \t]+/', ' ', $head);
        $recipients = [];
        foreach (preg_split('/\r?\n/', $head) as $line) {
            if (! str_contains($line, ':')) {
                throw new GmailDeliveryException('Validation blocked malformed email headers.');
            }
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            if (in_array($name, ['cc', 'bcc', 'resent-to', 'resent-cc', 'resent-bcc'], true)) {
                throw new GmailDeliveryException('Validation allows one explicitly approved test recipient and no CC/BCC.');
            }
            if ($name === 'to') {
                try {
                    $value = trim($value);
                    if (str_contains($value, ',') || str_contains($value, ';')) {
                        throw new RuntimeException;
                    }
                    $recipients[] = strtolower(Address::create($value)->getAddress());
                } catch (\Throwable) {
                    throw new GmailDeliveryException('Validation requires one valid approved test recipient.');
                }
            }
        }
        $allowed = array_map(static fn ($email) => strtolower(trim((string) $email)), (array) config('document_delivery.validation.recipients', []));
        if (count($recipients) !== 1 || ! in_array($recipients[0], $allowed, true)) {
            throw new GmailDeliveryException('Validation blocked this recipient. An explicitly approved test address must be on the environment allowlist.');
        }
    }

    /** Installed for every Laravel HTTP client, including unrelated payment/marketing jobs. */
    public function guardRequest(RequestInterface $request): RequestInterface
    {
        $this->assertEnvironment();
        $uri = $request->getUri();
        $origin = strtolower($uri->getScheme().'://'.$uri->getHost()).($uri->getPort() ? ':'.$uri->getPort() : '');
        if ($uri->getUserInfo() !== '') {
            throw new RuntimeException('Validation blocked credentials in an outbound URL.');
        }
        $stores = array_map(static fn ($url) => strtolower(rtrim((string) $url, '/')), (array) config('document_delivery.validation.store_origins', []));
        if (in_array($origin, $stores, true) && preg_match('#^/wp-json/wc/v3/(?:orders(?:/|$)|wphub-documents/)#', $uri->getPath())) {
            return $request;
        }
        if (config('document_delivery.validation.mail_transport') === 'gmail') {
            if ($origin === 'https://oauth2.googleapis.com' && $uri->getPath() === '/token') {
                return $request;
            }
            if ($origin === 'https://gmail.googleapis.com' && $uri->getPath() === '/gmail/v1/users/me/settings/sendAs' && $request->getMethod() === 'GET') {
                return $request;
            }
            if ($origin === 'https://gmail.googleapis.com' && $uri->getPath() === '/gmail/v1/users/me/messages/send' && $request->getMethod() === 'POST') {
                $body = json_decode((string) $request->getBody(), true);
                $mime = base64_decode(strtr((string) ($body['raw'] ?? ''), '-_', '+/'), true);
                if ($mime === false) {
                    throw new GmailDeliveryException('Validation blocked malformed Gmail payload.');
                }
                $this->assertRecipients($mime);

                return $request;
            }
        }

        throw new RuntimeException('Validation blocked an outbound request outside the approved test-store and email scope.');
    }
}
