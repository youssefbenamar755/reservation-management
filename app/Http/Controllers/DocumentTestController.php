<?php

namespace App\Http\Controllers;

use App\Exceptions\GmailDeliveryException;
use App\Models\DocumentTestSend;
use App\Models\GmailConnection;
use App\Models\Website;
use App\Models\WebsiteDocumentProfile;
use App\Services\BrandedDocumentRenderer;
use App\Services\GmailClient;
use App\Services\OrderEmailComposer;
use App\Services\WebsiteDocumentProfiles;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

class DocumentTestController extends Controller
{
    public function send(Request $request, Website $website, GmailClient $gmail, WebsiteDocumentProfiles $profiles, BrandedDocumentRenderer $renderer)
    {
        $this->authorize('update', $website);
        $values = $request->validate(['recipient' => ['required', 'email:rfc', 'max:254', 'not_regex:/[\r\n\x00]/'],
            'language' => 'required|in:fr,en', 'template' => 'required|in:acknowledgement,delivery,correction',
            'sample_document_count' => 'required|integer|min:1|max:2', 'idempotency_key' => 'required|uuid']);
        $profile = WebsiteDocumentProfile::where('website_id', $website->id)->firstOrFail();
        $readiness = $profiles->readiness($profile);
        if (! $readiness['ready']) {
            throw ValidationException::withMessages(['profile' => 'Configuration required: '.implode(', ', $readiness['missing'])]);
        }
        $connection = GmailConnection::findOrFail($profile->gmail_connection_id);
        if (! $profiles->allowedConnections($website, $request->user(), $profile)->contains('id', $connection->id)) {
            abort(403);
        }
        [$attempt, $claimed] = DB::transaction(function () use ($values, $profile, $request, $website) {
            $current = WebsiteDocumentProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();
            if ($current->config_version !== $profile->config_version || $current->gmail_connection_id !== $profile->gmail_connection_id) {
                throw ValidationException::withMessages(['profile' => 'The website settings changed. Reload before sending a test.']);
            }
            $existing = DocumentTestSend::find($values['idempotency_key']);
            if ($existing) {
                abort_unless($existing->website_id === $website->id && $existing->user_id === $request->user()->id, 404);

                return [$existing, false];
            }

            return [DocumentTestSend::create(['id' => $values['idempotency_key'], 'website_id' => $website->id, 'user_id' => $request->user()->id,
                'snapshot' => $values + ['config_version' => $profile->config_version], 'status' => 'sending']), true];
        });
        if (! $claimed) {
            return response()->json(['message' => $attempt->message ?? 'A test with this reference has already started. Do not retry an unknown outcome.', 'result' => $attempt->status])->header('Cache-Control', 'private, no-store');
        }
        $started = false;
        try {
            $sender = app(OrderEmailComposer::class)->sender($gmail->aliases($connection), $profile->sender_email);
            if (! $sender) {
                throw new GmailDeliveryException('The configured sender is not a verified Gmail alias.');
            }
            $attachments = [];
            for ($i = 1; $i <= $values['sample_document_count']; $i++) {
                $attachments[] = ['name' => 'Sample-document-'.$i.'.pdf'];
            }
            $rendered = $renderer->render($profile, ['order_number' => 'SAMPLE-001', 'customer_first_name' => 'Alex', 'language' => $values['language']],
                $values['template'] === 'acknowledgement' ? [] : $attachments, $values['template'], $values['language']);
            $banner = '<p style="padding:16px;background:#fef3c7;color:#111">TEST — fictional sample. No real order or reservation.</p>';
            $testHtml = preg_replace('/(<body[^>]*>)/i', '$1'.$banner, $rendered['html'], 1);
            $email = (new Email)->from(new Address($sender['email'], $profile->sender_name))->to($values['recipient'])->replyTo($profile->reply_to)
                ->subject('[TEST] '.$rendered['subject'])->text("TEST — fictional sample. No real order or reservation.\n\n".$rendered['text'])
                ->html($testHtml);
            if ($values['template'] !== 'acknowledgement') {
                $pdf = new Dompdf;
                $pdf->set_option('isRemoteEnabled', false);
                $pdf->loadHtml('<h1>WP Hub sample document</h1><p>Fictional test only. This is not a reservation or travel document.</p>');
                $pdf->render();
                foreach ($attachments as $attachment) {
                    $email->attach($pdf->output(), $attachment['name'], 'application/pdf');
                }
            }
            $attempt->update(['snapshot' => $attempt->snapshot + ['sender' => ['email' => $sender['email'], 'name' => $profile->sender_name],
                'reply_to' => $profile->reply_to, 'subject' => '[TEST] '.$rendered['subject'], 'html' => $testHtml, 'text' => $rendered['text'],
                'attachments' => $values['template'] === 'acknowledgement' ? [] : $attachments]]);
            $started = true;
            $id = $gmail->send($connection, $email->toString());
            $captured = app(\App\Services\DocumentValidationSafety::class)->capture() && str_starts_with($id, 'capture_');
            $attempt->update(['status' => $captured ? 'captured' : 'sent', 'provider_message_id' => $id,
                'message' => $captured ? 'Captured locally for validation. No Gmail message was sent and no orders were changed.'
                    : 'Test email sent to the explicitly selected test recipient. No orders were changed.']);
        } catch (GmailDeliveryException $exception) {
            $attempt->update(['status' => $exception->uncertain ? 'uncertain' : 'failed', 'message' => $exception->uncertain ? 'Sending outcome unknown — review Gmail Sent before another test.' : $exception->getMessage()]);
        } catch (Throwable) {
            $attempt->update(['status' => $started ? 'uncertain' : 'failed', 'message' => $started ? 'Sending outcome unknown — review Gmail Sent before another test.' : 'Test could not be prepared. Review the website configuration.']);
        }
        $profile->update(['last_test_at' => now(), 'last_test_result' => ['status' => $attempt->status, 'message' => $attempt->message, 'config_version' => $profile->config_version]]);

        return response()->json(['message' => $attempt->message, 'result' => $attempt->status])->header('Cache-Control', 'private, no-store');
    }
}
