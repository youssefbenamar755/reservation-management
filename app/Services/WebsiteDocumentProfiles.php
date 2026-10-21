<?php

namespace App\Services;

use App\Models\GmailConnection;
use App\Models\MarketingTemplate;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteDocumentProfile;
use App\Models\WebsiteEmailSetting;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class WebsiteDocumentProfiles
{
    public function __construct(private GmailClient $gmail, private BrandedDocumentRenderer $renderer) {}

    /** A read-only draft lets every registered website participate without a seeding job. */
    public function draft(Website $website): WebsiteDocumentProfile
    {
        $existing = WebsiteDocumentProfile::where('website_id', $website->id)->first();
        if ($existing) {
            return $existing->setRelation('website', $website);
        }
        $profile = new WebsiteDocumentProfile([
            'website_id' => $website->id, 'brand_name' => $website->name,
            'website_url' => BrandedDocumentRenderer::safePublicUrl($website->base_url) ? $website->base_url : null,
            'default_language' => 'fr', 'templates' => BrandedDocumentRenderer::defaults(),
            'required_services' => [], 'workflow_enabled' => false, 'config_version' => 0, 'store_capability' => [],
        ]);
        $profile->setRelation('website', $website);
        // Copy only unanimous, same-website identity values from authorized existing templates.
        // Marketing copy, incentives and sender assumptions are deliberately not migrated.
        $trustedUsers = User::where('id', $website->user_id)->orWhere('is_admin', true)->pluck('id');
        $contents = MarketingTemplate::where('website_id', $website->id)->whereIn('user_id', $trustedUsers)->get()->pluck('content');
        foreach (['logo_url' => 'logo_url', 'accent_color' => 'accent_color', 'sender_name' => 'brand_name', 'reply_to' => 'support_email'] as $source => $target) {
            $values = $contents->pluck($source)->filter(fn ($value) => is_string($value) && trim($value) !== '')->unique()->values();
            if ($values->count() === 1) {
                $value = $values->first();
                if (($target === 'logo_url' && BrandedDocumentRenderer::safePublicUrl($value))
                    || ($target === 'accent_color' && preg_match('/^#[0-9a-f]{6}$/i', $value))
                    || ($target === 'brand_name' && ! preg_match('/[\r\n{}]/', $value))
                    || ($target === 'support_email' && filter_var($value, FILTER_VALIDATE_EMAIL))) {
                    $profile->$target = $value;
                }
            }
        }
        // Multiple historical operator settings are ambiguous: leave sender configuration empty.
        $settings = WebsiteEmailSetting::where('website_id', $website->id)->whereIn('user_id', $trustedUsers)->get();
        if ($settings->count() === 1) {
            $setting = $settings->first();
            $connection = GmailConnection::where('user_id', $setting->user_id)->first();
            if ($connection && $this->gmail->isConnected($connection) && $this->hasAlias($connection, (string) $setting->sender_email)) {
                $profile->fill(['gmail_connection_id' => $connection->id, 'configured_by_user_id' => $setting->user_id,
                    'sender_email' => $setting->sender_email, 'sender_name' => $profile->brand_name]);
            }
        }

        return $profile;
    }

    public function platform(Website $website): string
    {
        return $website->wc_consumer_key && $website->wc_consumer_secret ? 'woocommerce' : 'unsupported';
    }

    /** A previously assigned admin account stays usable, but unrelated accounts are never exposed. */
    public function allowedConnections(Website $website, User $actor, ?WebsiteDocumentProfile $profile = null): Collection
    {
        $userIds = [$website->user_id];
        if ($actor->is_admin) {
            $userIds[] = $actor->id;
        }
        $assigned = $profile?->gmail_connection_id && $this->connectionAuthorized($profile)
            ? (int) $profile->gmail_connection_id : null;

        return GmailConnection::where(fn ($query) => $query->whereIn('user_id', $userIds)->when($assigned, fn ($q) => $q->orWhere('id', $assigned)))->get();
    }

    public function connectionAuthorized(WebsiteDocumentProfile $profile): bool
    {
        $connection = $profile->gmailConnection;
        $website = $profile->website;
        if (! $connection || ! $website) {
            return false;
        }
        if ((int) $connection->user_id === (int) $website->user_id) {
            return true;
        }

        return (int) $connection->user_id === (int) $profile->configured_by_user_id
            && User::whereKey($connection->user_id)->where('is_admin', true)->exists();
    }

    public function readiness(WebsiteDocumentProfile $profile): array
    {
        $missing = [];
        foreach (['brand_name', 'logo_url', 'primary_color', 'accent_color', 'background_color', 'text_color', 'support_email', 'website_url', 'sender_email', 'sender_name', 'reply_to', 'service_label', 'footer'] as $field) {
            if (trim((string) $profile->$field) === '') {
                $missing[] = $field;
            }
        }
        foreach (['logo_url', 'website_url'] as $field) {
            if ($profile->$field && ! BrandedDocumentRenderer::safePublicUrl($profile->$field)) {
                $missing[] = $field;
            }
        }
        foreach (['primary_color', 'accent_color', 'background_color', 'text_color'] as $field) {
            if ($profile->$field && ! preg_match('/^#[0-9a-f]{6}$/i', $profile->$field)) {
                $missing[] = $field;
            }
        }
        // Both body/footer text and the 18px brand name are normal-size text.
        // Keep the chosen palette unchanged, but block unreadable configurations.
        foreach ([['primary_color', '#ffffff'], ['text_color', '#ffffff'], ['text_color', $profile->background_color]] as [$field, $background]) {
            if (preg_match('/^#[0-9a-f]{6}$/i', $profile->$field ?? '') && preg_match('/^#[0-9a-f]{6}$/i', $background ?? '')
                && BrandedDocumentRenderer::contrastRatio($profile->$field, $background) < 4.5) {
                $surface = $background === '#ffffff' ? 'white' : 'the selected background';
                $missing[] = str_replace('_', ' ', $field).' contrast against '.$surface.' (minimum 4.5:1)';
            }
        }
        $connection = $profile->gmailConnection;
        $connected = $this->connectionAuthorized($profile) && $this->gmail->isConnected($connection);
        $sender = $connected && $this->hasAlias($connection, (string) $profile->sender_email);
        if (! $connected) {
            $missing[] = 'gmail_connection';
        }
        if (! $sender) {
            $missing[] = 'verified_sender';
        }
        $templatesReady = true;
        try {
            $this->renderer->validateTemplates($profile->templates ?? []);
        } catch (ValidationException) {
            $missing[] = 'templates';
            $templatesReady = false;
        }
        $platform = $this->platform($profile->website);
        $capability = $profile->store_capability ?? [];
        $completion = $platform === 'woocommerce' && ($capability['supported'] ?? false) === true && ($capability['verified'] ?? false) === true
            && is_array($capability['capabilities'] ?? null) && in_array('order_revision', $capability['capabilities'], true)
            && ($capability['enabled'] ?? false) === true && $profile->workflow_enabled
            && (int) ($capability['config_version'] ?? -1) === (int) $profile->config_version
            && $profile->store_checked_at?->isAfter(now()->subDay()) && (int) $profile->brand_sync_version === (int) $profile->config_version;

        return ['ready' => $missing === [], 'missing' => array_values(array_unique($missing)), 'gmail_connected' => $connected,
            'sender_verified' => $sender, 'templates_ready' => $templatesReady, 'completion_supported' => (bool) $completion,
            'platform' => $platform, 'required_services_configured' => count($profile->required_services ?? []) > 0];
    }

    public function hasAlias(GmailConnection $connection, string $email): bool
    {
        return collect($connection->aliases ?? [])->contains(fn ($alias) => isset($alias['email']) && strcasecmp($alias['email'], $email) === 0);
    }
}
