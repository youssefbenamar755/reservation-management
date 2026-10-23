<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\DocumentStoreException;
use App\Http\Controllers\Controller;
use App\Models\ActionHistoryEvent;
use App\Models\Website;
use App\Models\WebsiteDocumentProfile;
use App\Services\BrandedDocumentRenderer;
use App\Services\DocumentStoreGateway;
use App\Services\GmailClient;
use App\Services\WebsiteDocumentProfiles;
use App\Support\TrustpilotReviewLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DocumentSettingsController extends Controller
{
    /** Review links affect future email content, not store completion configuration. */
    public function reviewLink(Request $request, Website $website)
    {
        $this->authorize('update', $website);
        $data = $request->validate([
            'trustpilot_url' => ['present', 'nullable', 'string', 'max:1000'],
            'expected_trustpilot_url' => ['present', 'nullable', 'string', 'max:1000'],
        ]);

        $url = DB::transaction(function () use ($request, $website, $data) {
            $lockedWebsite = Website::whereKey($website->id)->lockForUpdate()->firstOrFail();
            $this->authorize('update', $lockedWebsite);
            $profile = WebsiteDocumentProfile::where('website_id', $lockedWebsite->id)->lockForUpdate()->first();
            if (! $profile) {
                throw ValidationException::withMessages(['trustpilot_url' => 'Save this website’s document profile before adding a Trustpilot link.']);
            }
            abort_if($profile->trustpilot_url !== $data['expected_trustpilot_url'], 409,
                'The Trustpilot link changed in another session. Reload before saving.');

            $url = TrustpilotReviewLink::normalize($data['trustpilot_url'], $lockedWebsite->base_url);
            if (filled($data['trustpilot_url']) && $url === null) {
                throw ValidationException::withMessages(['trustpilot_url' => 'Use this website’s HTTPS Trustpilot review link without query parameters or fragments.']);
            }
            if ($profile->trustpilot_url !== $url) {
                $profile->update(['trustpilot_url' => $url]);
                ActionHistoryEvent::create(['actor_id' => $request->user()->id, 'website_id' => $lockedWebsite->id,
                    'kind' => 'document_profile', 'entity_key' => (string) $profile->id, 'reference' => $lockedWebsite->name,
                    'outcome' => 'succeeded', 'occurred_at' => now(), 'finished_at' => now(),
                    'details' => ['review_link_updated' => true]]);
            }

            return $url;
        }, 3);

        return response()->json(['website_id' => $website->id, 'trustpilot_url' => $url])
            ->header('Cache-Control', 'private, no-store');
    }

    /** Stop new work even when the saved sender, account assignment or templates are invalid. */
    public function disable(Request $request, Website $website)
    {
        $this->authorize('update', $website);
        $request->validate(['confirmed' => ['required', 'accepted']]);
        [$profile, $previousVersion] = DB::transaction(function () use ($request, $website) {
            $lockedWebsite = Website::whereKey($website->id)->lockForUpdate()->firstOrFail();
            $this->authorize('update', $lockedWebsite);
            $profile = WebsiteDocumentProfile::where('website_id', $lockedWebsite->id)->lockForUpdate()->firstOrFail();
            $previousVersion = (int) $profile->config_version;
            if ($profile->workflow_enabled) {
                $profile->update(['workflow_enabled' => false, 'config_version' => (int) $profile->config_version + 1]);
                ActionHistoryEvent::create(['actor_id' => $request->user()->id, 'website_id' => $lockedWebsite->id,
                    'kind' => 'document_profile', 'entity_key' => (string) $profile->id, 'reference' => $lockedWebsite->name,
                    'outcome' => 'succeeded', 'occurred_at' => now(), 'finished_at' => now(),
                    'details' => ['config_version' => $profile->config_version, 'workflow_enabled' => false, 'emergency_disable' => true]]);
            }

            return [$profile, $previousVersion];
        });

        return response()->json(['website_id' => $website->id, 'workflow_enabled' => false, 'config_version' => $profile->config_version,
            'previous_config_version' => $previousVersion, 'profile' => $profile->attributesToArray(),
            'message' => 'Workflow disabled in WP Hub. Existing history and saved configuration are preserved. Publish the disabled state to the store separately.'])
            ->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, WebsiteDocumentProfiles $profiles, GmailClient $gmail)
    {
        $websites = Website::query()->when(! $request->user()->is_admin, fn ($query) => $query->where('user_id', $request->user()->id))->orderBy('name')->get();

        return Inertia::render('settings/EmailDocuments', [
            'websites' => $websites->map(fn ($website) => $this->present($request, $website, $profiles, $gmail))->all(),
            'placeholders' => array_map(fn ($key) => '{{'.$key.'}}', BrandedDocumentRenderer::PLACEHOLDERS),
            'defaultTemplates' => BrandedDocumentRenderer::defaults(),
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, Website $website, WebsiteDocumentProfiles $profiles, BrandedDocumentRenderer $renderer)
    {
        $this->authorize('update', $website);
        $data = $this->validated($request->all(), $renderer);
        DB::transaction(function () use ($request, $website, $profiles, $data) {
            Website::whereKey($website->id)->lockForUpdate()->firstOrFail();
            $profile = $profiles->draft($website);
            if ((int) ($data['config_version'] ?? 0) !== (int) $profile->config_version) {
                throw ValidationException::withMessages(['config_version' => 'The settings changed in another tab. Reload before saving.']);
            }
            $selected = $data['gmail_connection_id'] ?? null;
            $allowed = $profiles->allowedConnections($website, $request->user(), $profile);
            if ($selected && ! $allowed->contains('id', (int) $selected)) {
                throw ValidationException::withMessages(['gmail_connection_id' => 'Choose an authorized Gmail account for this website.']);
            }
            if ((int) $selected !== (int) $profile->gmail_connection_id) {
                $connection = $allowed->firstWhere('id', (int) $selected);
                // Only the website owner or the administrator currently selecting their own account may establish an assignment.
                if ($connection && (int) $connection->user_id !== (int) $website->user_id && (int) $connection->user_id !== (int) $request->user()->id) {
                    throw ValidationException::withMessages(['gmail_connection_id' => 'Only the account owner may assign this Gmail connection.']);
                }
                $profile->configured_by_user_id = $request->user()->id;
            }
            $profile->fill($data)->unsetRelation('gmailConnection');
            $profile->config_version = (int) $profile->config_version + 1;
            $profile->store_pairing_key ??= (string) Str::uuid();
            if ($profile->workflow_enabled && ! $profiles->readiness($profile)['ready']) {
                throw ValidationException::withMessages(['workflow_enabled' => 'Complete the required brand, sender and template settings before activation.']);
            }
            $profile->save();
            ActionHistoryEvent::create(['actor_id' => $request->user()->id, 'website_id' => $website->id,
                'kind' => 'document_profile', 'entity_key' => (string) $profile->id, 'reference' => $website->name,
                'outcome' => 'succeeded', 'occurred_at' => now(), 'finished_at' => now(),
                'details' => ['config_version' => $profile->config_version, 'workflow_enabled' => $profile->workflow_enabled]]);
        });

        if ($request->expectsJson()) {
            return response()->json(['website' => $this->present($request, $website, $profiles, app(GmailClient::class))])->header('Cache-Control', 'private, no-store');
        }

        return back()->with('success', 'Email & Documents settings saved for '.$website->name.'.');
    }

    /** Fictional preview only: no saved settings, remote calls, files or real customers. */
    public function preview(Request $request, Website $website, WebsiteDocumentProfiles $profiles, BrandedDocumentRenderer $renderer)
    {
        $this->authorize('view', $website);
        $data = $request->validate(['profile' => ['required', 'array'], 'template' => ['required', Rule::in(['acknowledgement', 'delivery', 'correction'])],
            'language' => ['required', Rule::in(['fr', 'en'])], 'sample_document_count' => ['required', 'integer', 'between:0,3']]);
        $profile = $profiles->draft($website);
        $profile->fill($this->validated($data['profile'], $renderer))->unsetRelation('gmailConnection');
        $profile->workflow_enabled = false;
        // Never load a user-supplied account outside the authorized list merely to compute preview readiness.
        if (! $profiles->allowedConnections($website, $request->user(), $profiles->draft($website))->contains('id', (int) $profile->gmail_connection_id)) {
            $profile->gmail_connection_id = null;
        }
        $attachments = [];
        for ($i = 1; $i <= $data['sample_document_count']; $i++) {
            $attachments[] = ['name' => 'sample-document-'.$i.'.pdf', 'size' => 1024];
        }

        return response()->json($renderer->render($profile, ['customer_first_name' => 'Alex', 'order_number' => 'DEMO-1042', 'language' => $data['language']], $attachments, $data['template'], $data['language']) + [
            'sample' => ['recipient' => 'preview@example.invalid', 'attachments' => $attachments, 'order_number' => 'DEMO-1042'],
            'readiness' => $profiles->readiness($profile),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function restore(Request $request, Website $website)
    {
        $this->authorize('update', $website);

        return response()->json(['templates' => BrandedDocumentRenderer::defaults()])->header('Cache-Control', 'private, no-store');
    }

    public function capability(Request $request, Website $website, WebsiteDocumentProfiles $profiles, DocumentStoreGateway $store, GmailClient $gmail)
    {
        $this->authorize('update', $website);
        $profile = WebsiteDocumentProfile::where('website_id', $website->id)->first();
        if (! $profile) {
            throw ValidationException::withMessages(['store' => 'Save the draft profile before checking the companion plugin.']);
        }
        $health = $store->health($website);
        $profile->update(['store_capability' => $health, 'store_checked_at' => now()]);

        return response()->json(['website' => $this->present($request, $website, $profiles, $gmail)])->header('Cache-Control', 'private, no-store');
    }

    public function syncBrand(Request $request, Website $website, WebsiteDocumentProfiles $profiles, DocumentStoreGateway $store, GmailClient $gmail)
    {
        $this->authorize('update', $website);
        $request->validate(['confirmed' => ['required', 'accepted'], 'config_version' => ['required', 'integer', 'min:1']]);
        $profile = WebsiteDocumentProfile::where('website_id', $website->id)->first();
        if (! $profile || (int) $request->input('config_version') !== (int) $profile->config_version) {
            throw ValidationException::withMessages(['store' => 'Save and reload the current settings before publishing store branding.']);
        }
        if ($profile->workflow_enabled && ! $profiles->readiness($profile)['ready']) {
            throw ValidationException::withMessages(['store' => 'Complete the website brand and sender settings before publishing an enabled workflow.']);
        }
        try {
            $store->syncBrand($profile);
            $health = $store->health($website);
        } catch (DocumentStoreException $exception) {
            throw ValidationException::withMessages(['store' => $exception->getMessage()]);
        }
        if (($health['verified'] ?? false) !== true || ($health['supported'] ?? false) !== true
            || (int) ($health['config_version'] ?? -1) !== (int) $profile->config_version
            || (bool) ($health['enabled'] ?? false) !== (bool) $profile->workflow_enabled) {
            $profile->update(['store_capability' => $health, 'store_checked_at' => now(), 'brand_sync_version' => null]);
            throw ValidationException::withMessages(['store' => 'Store branding was not verified. Check the integration and publish the current settings again.']);
        }
        $updated = WebsiteDocumentProfile::whereKey($profile->id)->where('config_version', $profile->config_version)->update([
            'store_capability' => json_encode($health, JSON_THROW_ON_ERROR), 'store_checked_at' => now(), 'brand_sync_version' => $profile->config_version,
        ]);
        if (! $updated) {
            throw ValidationException::withMessages(['store' => 'Settings changed while publishing. Reload and publish the newest version.']);
        }
        ActionHistoryEvent::create(['actor_id' => $request->user()->id, 'website_id' => $website->id,
            'kind' => 'document_brand_sync', 'entity_key' => (string) $profile->id, 'reference' => $website->name,
            'outcome' => 'succeeded', 'occurred_at' => now(), 'finished_at' => now(),
            'details' => ['config_version' => $profile->config_version, 'workflow_enabled' => $profile->workflow_enabled]]);

        return response()->json(['website' => $this->present($request, $website, $profiles, $gmail)])->header('Cache-Control', 'private, no-store');
    }

    private function present(Request $request, Website $website, WebsiteDocumentProfiles $profiles, GmailClient $gmail): array
    {
        $profile = $profiles->draft($website);
        $readiness = $profiles->readiness($profile);

        return ['id' => $website->id, 'name' => $website->name, 'url' => $website->base_url,
            'profile' => $profile->attributesToArray() + ['platform' => $readiness['platform']], 'readiness' => $readiness,
            'connections' => $profiles->allowedConnections($website, $request->user(), $profile)->map(fn ($connection) => [
                'id' => $connection->id, 'email' => $connection->email, 'connected' => $gmail->isConnected($connection),
                'aliases' => $connection->aliases ?? [],
            ])->values()->all()];
    }

    private function validated(array $input, BrandedDocumentRenderer $renderer): array
    {
        $rules = [
            'brand_name' => ['nullable', 'string', 'max:150', 'not_regex:/[\r\n{}]/'], 'logo_url' => ['nullable', 'url:https', 'max:1000'],
            'support_email' => ['nullable', 'email:rfc', 'max:254', 'not_regex:/[\r\n]/'], 'website_url' => ['nullable', 'url:https', 'max:1000'],
            'support_phone' => ['nullable', 'string', 'max:80', 'not_regex:/[\r\n]/'], 'footer' => ['nullable', 'string', 'max:3000'],
            'sender_email' => ['nullable', 'email:rfc', 'max:254', 'not_regex:/[\r\n]/'], 'sender_name' => ['nullable', 'string', 'max:150', 'not_regex:/[\r\n{}]/'],
            'reply_to' => ['nullable', 'email:rfc', 'max:254', 'not_regex:/[\r\n]/'], 'gmail_connection_id' => ['nullable', 'integer'],
            'default_language' => ['required', Rule::in(['fr', 'en'])], 'workflow_enabled' => ['required', 'boolean'],
            'service_label' => ['nullable', 'string', 'max:150', 'not_regex:/[\r\n{}]/'], 'required_services' => ['present', 'array', 'max:12'],
            'required_services.*' => ['required', 'string', 'max:100', 'distinct', 'not_regex:/[\r\n{}]/'],
            'config_version' => ['required', 'integer', 'min:0'], 'templates' => ['required', 'array'],
        ];
        foreach (['primary_color', 'accent_color', 'background_color', 'text_color'] as $color) {
            $rules[$color] = ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/D'];
        }
        $data = Validator::make($input, $rules)->validate();
        foreach (['logo_url', 'website_url'] as $field) {
            if (! empty($data[$field]) && ! BrandedDocumentRenderer::safePublicUrl($data[$field])) {
                throw ValidationException::withMessages([$field => 'Use a public HTTPS URL without embedded credentials.']);
            }
        }
        $renderer->validateTemplates($data['templates']);
        // The signature has the same restricted substitutions as other customer-facing copy.
        $footerTemplates = BrandedDocumentRenderer::defaults();
        $footerTemplates['delivery']['fr']['optional_block'] = $data['footer'] ?? '';
        $renderer->validateTemplates($footerTemplates);

        return $data;
    }
}
