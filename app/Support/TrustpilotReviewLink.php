<?php

namespace App\Support;

use App\Models\WebsiteDocumentProfile;

class TrustpilotReviewLink
{
    public static function normalize(?string $url, ?string $websiteBaseUrl): ?string
    {
        $url = trim($url ?? '');
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || ! in_array(strtolower($parts['host'] ?? ''), ['trustpilot.com', 'www.trustpilot.com', 'fr.trustpilot.com'], true)
            || array_intersect(['user', 'pass', 'port', 'query', 'fragment'], array_keys($parts))
            || ! preg_match('~^/(?:review|evaluate)/([^/]+)/?$~D', $parts['path'] ?? '', $match)) {
            return null;
        }

        $websiteHost = self::domain(parse_url($websiteBaseUrl ?? '', PHP_URL_HOST) ?: '');
        $reviewHost = self::domain($match[1]);
        if ($websiteHost === null || $reviewHost !== $websiteHost) {
            return null;
        }

        return 'https://www.trustpilot.com/review/'.$websiteHost;
    }

    public static function actionUrl(WebsiteDocumentProfile $profile): ?string
    {
        // The website identity comes from its saved record, never editable brand copy.
        $url = self::normalize($profile->trustpilot_url, $profile->website()->value('base_url'));

        return $url === null ? null : str_replace('/review/', '/evaluate/', $url);
    }

    private static function domain(string $host): ?string
    {
        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return str_contains($host, '.') && ! filter_var($host, FILTER_VALIDATE_IP)
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            ? $host : null;
    }
}
