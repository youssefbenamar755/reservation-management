<?php

namespace App\Services;

use App\Models\FfForm;
use App\Models\FfSubmission;
use App\Models\MarketingContact;
use App\Models\WcOrder;
use Illuminate\Support\Facades\DB;

class MarketingContactDiscovery
{
    public function email(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $email = strtolower(trim($value));

        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function contact(int $websiteId, string $email, ?string $name): MarketingContact
    {
        // Identity is not consent. Never overwrite preferences, language or suppression.
        $contact = MarketingContact::firstOrCreate(['website_id' => $websiteId, 'email' => $email], [
            'name' => $name, 'status' => 'unknown',
        ]);
        if ($name && ! $contact->name) {
            MarketingContact::whereKey($contact->id)->where(fn ($q) => $q->whereNull('name')->orWhere('name', ''))
                ->update(['name' => $name]);
        }

        return $contact;
    }

    private function name(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = implode(' ', array_filter([
                is_string($value['first_name'] ?? null) ? $value['first_name'] : '',
                is_string($value['middle_name'] ?? null) ? $value['middle_name'] : '',
                is_string($value['last_name'] ?? null) ? $value['last_name'] : '',
            ]));
        }

        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 255) : null;
    }

    public function order(WcOrder $order): bool
    {
        $email = $this->email($order->customer_email);

        return $email ? $this->contact($order->website_id, $email, $this->name($order->customer_name))->wasRecentlyCreated : false;
    }

    public function submission(FfSubmission $submission, ?array $schema = null): bool
    {
        $identity = $this->submissionIdentity($submission, $schema);
        if (! $identity) {
            DB::table('marketing_contact_submissions')->where('ff_submission_id', $submission->id)->delete();

            return false;
        }
        $contact = $this->contact($submission->website_id, $identity['email'], $identity['name']);
        DB::table('marketing_contact_submissions')->upsert([
            ['ff_submission_id' => $submission->id, 'marketing_contact_id' => $contact->id],
        ], ['ff_submission_id'], ['marketing_contact_id']);

        return $contact->wasRecentlyCreated;
    }

    /** Read contact fields, not arbitrary passenger addresses or free-text answers. */
    private function submissionIdentity(FfSubmission $submission, ?array $schema): ?array
    {
        $payload = $submission->payload;
        $response = $payload['response'] ?? [];
        if (is_string($response)) {
            $response = json_decode($response, true);
        }
        $fields = is_array($response) ? $response + $payload : $payload;
        foreach (is_array($payload['inputs'] ?? null) ? $payload['inputs'] : [] as $input) {
            if (is_array($input) && is_string($input['name'] ?? null)) {
                $fields[$input['name']] ??= $input['value'] ?? null;
            }
        }
        $fields = array_change_key_case($fields, CASE_LOWER);
        $email = $this->email($submission->email);
        foreach (['email', 'email_address', 'emailaddress', 'user_email', 'contact_email', 'input_email'] as $key) {
            $email ??= $this->email($fields[$key] ?? null);
        }
        // Fluent Forms adds numeric suffixes to repeated field types.
        $candidates = [];
        if (! $email) {
            $schema ??= FfForm::where('website_id', $submission->website_id)->where('form_id', $submission->form_id)->value('fields') ?? [];
            foreach ($fields as $key => $value) {
                $type = $schema[$key]['type'] ?? null;
                if (preg_match('/^(?:email|input_email)_\d+$/', $key) || in_array($type, ['email', 'input_email'], true)) {
                    if ($candidate = $this->email($value)) {
                        $candidates[$candidate] = true;
                    }
                }
            }
            // Multiple different email fields are ambiguous; do not guess the recipient.
            $email = count($candidates) === 1 ? array_key_first($candidates) : null;
        }
        if (! $email) {
            return null;
        }
        $name = null;
        foreach (['contact_name', 'customer_name', 'names', 'name', 'full_name'] as $key) {
            $name ??= $this->name($fields[$key] ?? null);
        }
        $name ??= $this->name(['first_name' => $fields['first_name'] ?? null, 'last_name' => $fields['last_name'] ?? null]);

        return compact('email', 'name');
    }

    public function discover(int $websiteId): int
    {
        $added = 0;
        WcOrder::where('website_id', $websiteId)->whereNotNull('customer_email')
            ->select('id', 'website_id', 'customer_email', 'customer_name')->chunkById(250, function ($orders) use ($websiteId, &$added) {
                $identities = [];
                foreach ($orders as $order) {
                    if ($email = $this->email($order->customer_email)) {
                        $identities[] = ['email' => $email, 'name' => $this->name($order->customer_name)];
                    }
                }
                $this->storeBatch($websiteId, $identities, $added);
            });
        $schemas = FfForm::where('website_id', $websiteId)->get(['form_id', 'fields'])->keyBy('form_id');
        FfSubmission::where('website_id', $websiteId)->select('id', 'website_id', 'form_id', 'email', 'payload')
            ->chunkById(250, function ($submissions) use ($websiteId, &$added, $schemas) {
                $identities = [];
                $invalid = [];
                foreach ($submissions as $submission) {
                    if ($identity = $this->submissionIdentity($submission, $schemas->get($submission->form_id)?->fields ?? [])) {
                        $identities[$submission->id] = $identity;
                    } else {
                        $invalid[] = $submission->id;
                    }
                }
                $contacts = $this->storeBatch($websiteId, $identities, $added);
                $links = [];
                foreach ($identities as $id => $identity) {
                    $links[] = ['ff_submission_id' => $id, 'marketing_contact_id' => $contacts[$identity['email']]->id];
                }
                if ($links) {
                    DB::table('marketing_contact_submissions')->upsert($links, ['ff_submission_id'], ['marketing_contact_id']);
                }
                if ($invalid) {
                    DB::table('marketing_contact_submissions')->whereIn('ff_submission_id', $invalid)->delete();
                }
            });

        return $added;
    }

    /** Bounded local batches keep historical discovery out of the page's read path. */
    private function storeBatch(int $websiteId, array $identities, int &$added)
    {
        $rows = [];
        foreach ($identities as $identity) {
            $email = $identity['email'];
            $rows[$email] ??= ['website_id' => $websiteId, 'email' => $email, 'name' => null, 'status' => 'unknown', 'created_at' => now(), 'updated_at' => now()];
            $rows[$email]['name'] ??= $identity['name'];
        }
        if (! $rows) {
            return collect();
        }
        $added += DB::table('marketing_contacts')->insertOrIgnore(array_values($rows));
        $contacts = MarketingContact::where('website_id', $websiteId)->whereIn('email', array_keys($rows))->get(['id', 'email', 'name'])->keyBy('email');
        foreach ($contacts as $contact) {
            if (! $contact->name && $rows[$contact->email]['name']) {
                MarketingContact::whereKey($contact->id)->where(fn ($q) => $q->whereNull('name')->orWhere('name', ''))
                    ->update(['name' => $rows[$contact->email]['name']]);
            }
        }

        return $contacts;
    }
}
