<?php

namespace App\Http\Controllers;

use App\Exceptions\MarketingProviderException;
use App\Models\MarketingCampaign;
use App\Models\MarketingConnection;
use App\Models\MarketingContact;
use App\Models\MarketingTemplate;
use App\Models\Website;
use App\Services\BrevoClient;
use App\Services\MarketingAudience;
use App\Services\MarketingContent;
use App\Services\MarketingDelivery;
use App\Services\MarketingLock;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class MarketingController extends Controller
{
    public function __construct(private MarketingAudience $audiences, private MarketingContent $content) {}

    private function page(Request $request, string $component, array $data)
    {
        return Inertia::render($component, $data)->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    private function site(Request $request, int $id): Website
    {
        $site = Website::findOrFail($id);
        $this->authorize('update', $site);

        return $site;
    }

    private function ownCampaign(Request $request, MarketingCampaign $campaign): void
    {
        abort_unless($campaign->user_id === $request->user()->id, 404);
        $this->site($request, $campaign->website_id);
    }

    private function common(Request $request): array
    {
        $connection = MarketingConnection::where('user_id', $request->user()->id)->first();

        return ['websites' => $this->audiences->websites($request->user()), 'connected' => $connection?->ready() ?? false,
            'senders' => $connection?->senders ?? [], 'testEmail' => $connection?->account_email];
    }

    private function filters(Request $request): array
    {
        return $request->validate(['website_id' => ['nullable', 'integer', 'min:1'], 'locale' => ['nullable', Rule::in(['fr', 'en'])],
            'segment' => ['nullable', Rule::in(['all', 'first', 'repeat', 'inactive'])], 'status' => ['nullable', Rule::in(['unknown', 'subscribed', 'unsubscribed'])],
            'search' => ['nullable', 'string', 'max:200'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
    }

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $common = $this->common($request);
        if (! empty($filters['website_id'])) {
            $this->site($request, (int) $filters['website_id']);
        }
        $campaigns = MarketingCampaign::where('user_id', $request->user()->id)->whereIn('website_id', $common['websites']->pluck('id'))
            ->when(! empty($filters['website_id']), fn ($q) => $q->where('website_id', $filters['website_id']));
        $summary = (clone $campaigns)->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as drafts, SUM(CASE WHEN status IN ('scheduled','preparing','sending') THEN 1 ELSE 0 END) as scheduled, SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted")->first()->getAttributes();

        return $this->page($request, 'Marketing/Index', $common + ['filters' => $filters, 'summary' => array_map('intval', $summary),
            'campaigns' => $campaigns->latest('id')->paginate(20, ['id', 'website_id', 'name', 'status', 'scheduled_at', 'submitted_at', 'recipient_count', 'result_message', 'created_at'])->withQueryString()]);
    }

    public function audience(Request $request)
    {
        $filters = $this->filters($request);
        $common = $this->common($request);
        $siteId = (int) ($filters['website_id'] ?? $common['websites']->first()?->id ?? 0);
        if ($siteId) {
            $this->site($request, $siteId);
        }
        $filters['website_id'] = $siteId;
        $summary = MarketingContact::where('website_id', $siteId)->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'subscribed' THEN 1 ELSE 0 END) as subscribed, SUM(CASE WHEN status = 'unknown' THEN 1 ELSE 0 END) as unknown, SUM(CASE WHEN status = 'unsubscribed' THEN 1 ELSE 0 END) as unsubscribed")->first()->getAttributes();
        $connection = MarketingConnection::where('user_id', $request->user()->id)->first();
        $contacts = $this->audiences->query($siteId, $filters)->orderByDesc('marketing_contacts.id')->paginate(25)->withQueryString();
        $suppressed = DB::table('marketing_suppressions')->where('marketing_connection_id', $connection?->id ?? 0)->whereIn('email', $contacts->pluck('email'))->pluck('reason', 'email');
        $contacts->through(fn ($c) => $c->toArray() + ['suppression' => $suppressed[$c->email] ?? null]);

        return $this->page($request, 'Marketing/Audience', $common + ['filters' => $filters, 'summary' => array_map('intval', $summary), 'contacts' => $contacts]);
    }

    public function discover(Request $request, Website $website)
    {
        $this->site($request, $website->id);
        $count = $this->audiences->discover($website->id);

        return back()->with('success', $count.' customer addresses added with no marketing permission. Existing preferences were preserved.');
    }

    private function contactData(array $input): array
    {
        $input['email'] = is_string($input['email'] ?? null) ? strtolower(trim($input['email'])) : $input['email'] ?? null;
        foreach (['locale', 'name', 'consent_source', 'consented_at'] as $field) {
            if (($input[$field] ?? null) === '') {
                $input[$field] = null;
            }
        }

        return Validator::make($input, ['email' => ['required', 'email:rfc', 'max:254'], 'name' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', Rule::in(['fr', 'en'])], 'status' => ['required', Rule::in(['unknown', 'subscribed', 'unsubscribed'])],
            'consent_source' => ['required_if:status,subscribed', 'nullable', 'string', 'min:5', 'max:500'],
            'consented_at' => ['required_if:status,subscribed', 'nullable', 'date', 'before_or_equal:now'],
            'confirm_consent' => ['required_if:status,subscribed', 'accepted_if:status,subscribed'],
        ])->validate();
    }

    private function saveContact(Request $request, int $siteId, array $data, bool $import = false): void
    {
        $existing = MarketingContact::where('website_id', $siteId)->where('email', $data['email'])->lockForUpdate()->first();
        if ($import && $existing && $data['status'] === 'unknown') {
            return; // A generic customer import must not overwrite an existing preference.
        }
        if ($existing?->status === 'unsubscribed' && $data['status'] !== 'unsubscribed') {
            if ($import || $data['status'] !== 'subscribed' || Carbon::parse($data['consented_at'])->lte($existing->unsubscribed_at)) {
                throw ValidationException::withMessages(['consent_source' => 'This address unsubscribed. Record new permission dated after the unsubscribe individually; imports cannot resubscribe it.']);
            }
        }
        $contact = $existing ?? new MarketingContact(['website_id' => $siteId, 'email' => $data['email']]);
        $contact->fill(['name' => $data['name'] ?? null, 'locale' => $data['locale'] ?? null, 'status' => $data['status']]);
        if ($data['status'] === 'subscribed') {
            $contact->fill(['consent_source' => $data['consent_source'], 'consented_at' => Carbon::parse($data['consented_at'])->utc(), 'unsubscribed_at' => null]);
        } elseif ($data['status'] === 'unsubscribed') {
            $contact->unsubscribed_at = $existing?->unsubscribed_at ?? now();
        }
        $contact->save();
        DB::table('marketing_consent_events')->insert(['marketing_contact_id' => $contact->id, 'actor_id' => $request->user()->id,
            'status' => $data['status'], 'source' => $data['status'] === 'subscribed' ? $data['consent_source'] : 'Recorded in WP Hub',
            'consented_at' => $data['status'] === 'subscribed' ? $contact->consented_at : null, 'created_at' => now()]);
    }

    public function contact(Request $request, Website $website, MarketingLock $lock)
    {
        $this->site($request, $website->id);
        $data = $this->contactData($request->all());
        $lock->run($request->user()->id, fn () => DB::transaction(fn () => $this->saveContact($request, $website->id, $data)));

        return back()->with('success', 'Contact preferences saved. Brevo suppression, if present, still takes priority.');
    }

    public function import(Request $request, Website $website, MarketingLock $lock)
    {
        $this->site($request, $website->id);
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt'], 'confirm_consent' => ['required', 'accepted']]);
        $handle = fopen($request->file('file')->getRealPath(), 'rb');
        $rows = [];
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (! $header) {
                throw ValidationException::withMessages(['file' => 'The CSV is empty.']);
            }
            $header = array_map(fn ($v) => trim(ltrim($v, "\xEF\xBB\xBF")), $header);
            if (! in_array('email', $header, true) || count($header) !== count(array_unique($header))) {
                throw ValidationException::withMessages(['file' => 'Use a CSV with unique headers including email.']);
            }
            $line = 1;
            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($header) || count($rows) >= 5000) {
                    throw ValidationException::withMessages(['file' => 'Check CSV row '.$line.'. Maximum 5,000 rows per import.']);
                }
                $input = array_combine($header, $values);
                try {
                    $rows[] = $this->contactData($input + ['status' => 'unknown', 'confirm_consent' => true]);
                } catch (ValidationException) {
                    throw ValidationException::withMessages(['file' => 'Check row '.$line.': valid email, language (fr/en), status, and permission source/date are required for subscribers.']);
                }
            }
        } finally {
            fclose($handle);
        }
        $lock->run($request->user()->id, function () use ($request, $website, $rows) {
            DB::transaction(function () use ($request, $website, $rows) {
                foreach ($rows as $data) {
                    $this->saveContact($request, $website->id, $data, true);
                }
            });
        });

        return back()->with('success', count($rows).' contact records imported.');
    }

    public function templates(Request $request)
    {
        $filters = $this->filters($request);
        $common = $this->common($request);
        $siteId = (int) ($filters['website_id'] ?? $common['websites']->first()?->id ?? 0);
        if ($siteId) {
            $this->site($request, $siteId);
        }

        return $this->page($request, 'Marketing/Templates', $common + ['websiteId' => $siteId,
            'templates' => MarketingTemplate::where('user_id', $request->user()->id)->where('website_id', $siteId)->latest('id')->paginate(20)->withQueryString()]);
    }

    public function template(Request $request, ?MarketingTemplate $template = null)
    {
        $data = $request->validate(['website_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:120']] + $this->content->rules());
        $this->site($request, (int) $data['website_id']);
        if ($template?->exists) {
            abort_unless($template->user_id === $request->user()->id && $template->website_id === (int) $data['website_id'], 404);
        }
        $content = array_intersect_key($data, $this->content->rules());
        $template ??= new MarketingTemplate;
        $template->fill(['user_id' => $request->user()->id, 'website_id' => $data['website_id'], 'name' => $data['name'], 'content' => $content])->save();

        return back()->with('success', 'Template saved. Existing campaigns keep their reviewed content.');
    }

    public function preview(Request $request)
    {
        $data = $request->validate($this->content->rules());

        return response()->json(['html' => $this->content->html($data, true)])->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request)
    {
        $data = $request->validate(['template_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:120'],
            'locale' => ['nullable', Rule::in(['fr', 'en'])], 'segment' => ['required', Rule::in(['all', 'first', 'repeat', 'inactive'])]]);
        $template = MarketingTemplate::where('user_id', $request->user()->id)->findOrFail($data['template_id']);
        $this->site($request, $template->website_id);
        $connection = MarketingConnection::where('user_id', $request->user()->id)->first();
        $campaign = MarketingCampaign::create(['user_id' => $request->user()->id, 'website_id' => $template->website_id,
            'marketing_connection_id' => $connection?->id, 'connection_key' => $connection?->connection_key,
            'name' => $data['name'], 'content' => $template->content, 'audience' => ['locale' => $data['locale'] ?? null, 'segment' => $data['segment']]]);

        return redirect('/marketing/campaigns/'.$campaign->id);
    }

    public function show(Request $request, MarketingCampaign $campaign, MarketingDelivery $delivery)
    {
        $this->ownCampaign($request, $campaign);
        // Resolve setup for preview in memory. GET requests do not change campaign records.
        $delivery->attachConnection($campaign);
        $review = $this->audiences->review($campaign);
        unset($review['rows']);

        return $this->page($request, 'Marketing/Campaign', $this->common($request) + ['campaign' => $campaign,
            'review' => $review, 'previewHtml' => $this->content->html($campaign->content, true, $campaign->id), 'stats' => $delivery->stats($campaign)]);
    }

    public function schedule(Request $request, MarketingCampaign $campaign, MarketingDelivery $delivery, MarketingLock $lock)
    {
        $this->ownCampaign($request, $campaign);
        $data = $request->validate(['review_token' => ['required', 'string', 'size:64'], 'confirmed' => ['required', 'accepted'],
            'scheduled_at' => ['nullable', 'date', 'after:now', 'before:'.now()->addYear()->toIso8601String()]]);
        try {
            $lock->run($request->user()->id, function () use ($campaign, $data, $delivery) {
                DB::transaction(function () use ($campaign, $data, $delivery) {
                    $campaign = MarketingCampaign::lockForUpdate()->findOrFail($campaign->id);
                    if ($campaign->status !== 'draft') {
                        throw ValidationException::withMessages(['campaign' => 'This campaign is no longer a draft. Refresh to see its current status.']);
                    }
                    $delivery->attachConnection($campaign);
                    $connection = $delivery->connection($campaign);
                    if (! in_array(strtolower($campaign->content['sender_email']), array_column($connection->senders ?? [], 'email'), true)) {
                        throw ValidationException::withMessages(['campaign' => 'Choose a verified Brevo sender and refresh Marketing settings.']);
                    }
                    $review = $this->audiences->review($campaign);
                    if (! hash_equals($review['token'], $data['review_token'])) {
                        throw ValidationException::withMessages(['campaign' => 'The audience changed. Refresh and review the recipient selection again.']);
                    }
                    if ($review['count'] < 1 || $review['count'] > MarketingAudience::MAX_RECIPIENTS) {
                        throw ValidationException::withMessages(['campaign' => 'Choose between 1 and 5,000 eligible subscribers per campaign.']);
                    }
                    $rows = $review['rows']->map(fn ($c) => ['marketing_campaign_id' => $campaign->id, 'marketing_contact_id' => $c->id, 'email' => $c->email, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                    foreach ($rows->chunk(250) as $batch) {
                        DB::table('marketing_recipients')->insert($batch->all());
                    }
                    $campaign->update(['status' => 'scheduled', 'recipient_count' => $review['count'], 'scheduled_at' => empty($data['scheduled_at']) ? now() : Carbon::parse($data['scheduled_at'])->utc(), 'result_message' => null]);
                });
            });
        } catch (MarketingProviderException $e) {
            return back()->withErrors(['campaign' => $e->getMessage()]);
        }

        return back()->with('success', 'Campaign scheduled. Only the reviewed recipients can be included; withdrawals are checked before sending.');
    }

    public function cancel(Request $request, MarketingCampaign $campaign, MarketingLock $lock)
    {
        $this->ownCampaign($request, $campaign);
        $lock->run($request->user()->id, function () use ($campaign) {
            $changed = MarketingCampaign::whereKey($campaign->id)->whereIn('status', ['draft', 'scheduled', 'preparing'])->update(['status' => 'cancelled', 'result_message' => 'Cancelled in WP Hub.']);
            if (! $changed) {
                throw ValidationException::withMessages(['campaign' => 'This campaign has already reached the sending step and cannot be cancelled here.']);
            }
        });

        return back()->with('success', 'Campaign cancelled.');
    }

    public function test(Request $request, MarketingCampaign $campaign, MarketingDelivery $delivery, BrevoClient $client, MarketingLock $lock)
    {
        $this->ownCampaign($request, $campaign);
        $request->validate(['confirmed' => ['required', 'accepted']]);
        try {
            $lock->run($request->user()->id, function () use ($campaign, $delivery, $client) {
                $campaign->refresh();
                abort_unless($campaign->status === 'draft', 409);
                $delivery->attachConnection($campaign);
                $campaign->save();
                $connection = $delivery->connection($campaign);
                $client->assertSender($connection, $campaign->content['sender_email']);
                $id = $delivery->providerDraft($campaign, $connection);
                // Always explicit: Brevo treats an empty emailTo array as the entire test list.
                $client->request($connection, 'POST', '/emailCampaigns/'.$id.'/sendTest', ['emailTo' => [$connection->account_email]]);
                $campaign->update(['test_sent_at' => now()]);
            });
        } catch (MarketingProviderException $e) {
            return back()->withErrors(['campaign' => $e->getMessage()]);
        }

        return back()->with('success', 'Brevo accepted the test request for your Brevo account email.');
    }
}
