<?php

namespace App\Http\Controllers;

use App\Models\OrderDocument;
use App\Models\OrderEmailDelivery;
use App\Models\WcOrder;
use App\Services\GeneratedItineraryAttachment;
use App\Services\OrderEmailComposer;
use App\Services\OrderEmailDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderEmailController extends Controller
{
    public function context(Request $request, WcOrder $order, OrderEmailComposer $composer)
    {
        $this->authorize('view', $order);
        $values = $request->validate(['submission_id' => 'nullable|integer|min:1']);

        return response()->json($composer->options($order, $request->user()) + [
            'workflow' => app(\App\Services\BrandedDocumentDelivery::class)->context($order, $request->user()),
            'itinerary_attachment' => app(GeneratedItineraryAttachment::class)->context($order, $request->user(), isset($values['submission_id']) ? (int) $values['submission_id'] : null),
            'documents' => OrderDocument::where('website_id', $order->website_id)->where('wc_order_id', $order->id)
                ->where('customer_email', strtolower($order->customer_email ?? ''))->whereNotNull('approved_at')->whereNotNull('source_version')
                ->orderByDesc('version')->limit(100)->get()->map->summary()->all(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function attachItinerary(Request $request, WcOrder $order, GeneratedItineraryAttachment $attachments)
    {
        $this->authorize('update', $order);
        $values = $request->validate(['submission_id' => 'required|integer|min:1', 'source_version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            'approved' => 'required|accepted', 'intended_customer_email' => ['required', 'email:rfc', 'max:254'],
            'path' => 'prohibited', 'url' => 'prohibited', 'document' => 'prohibited']);
        $document = $attachments->attach($order, $request->user(), $values);

        return response()->json(['document' => $document->summary(),
            'itinerary_attachment' => $attachments->context($order->fresh(), $request->user(), (int) $values['submission_id'])])
            ->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, WcOrder $order, OrderEmailComposer $composer)
    {
        $this->authorize('update', $order);
        $this->requireLegacyWorkflow($order);
        $values = $request->validate([
            'recipient' => ['required', 'string', 'email:rfc', 'max:254', 'not_regex:/[\r\n\x00]/'],
            'subject' => ['required', 'string', 'max:255', 'not_regex:/[\r\n\x00]/'],
            'body' => ['required', 'string', 'max:20000'],
            'track_opens' => ['sometimes', 'boolean'],
            'files' => ['sometimes', 'array', 'max:5'],
            'files.*' => ['required', 'file', 'mimetypes:application/pdf', 'max:5120'],
            'document_ids' => ['sometimes', 'array', 'max:5'], 'document_ids.*' => ['required', 'uuid', 'distinct'],
            'sender' => ['prohibited'], 'sender_email' => ['prohibited'], 'from' => ['prohibited'],
        ]);
        $delivery = $composer->prepare($order, $request->user(), $values, $request->file('files', []));

        return response()->json(['preview' => $delivery->preview()])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, WcOrder $order, OrderEmailDelivery $delivery, OrderEmailComposer $composer)
    {
        $this->authorize('view', $order);
        $this->scope($request, $order, $delivery);
        $delivery = $composer->refreshStatus($delivery);

        return response()->json(['preview' => $delivery->preview(), 'delivery' => $delivery->outcome()])->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $request, WcOrder $order, OrderEmailDelivery $delivery, OrderEmailDeliveryService $service)
    {
        $this->authorize('update', $order);
        $this->requireLegacyWorkflow($order);
        $this->scope($request, $order, $delivery);
        if ($request->input('confirmed') !== true) {
            throw ValidationException::withMessages(['confirmed' => 'Confirm this exact email preview before sending.']);
        }
        $delivery = $service->send($order, $delivery, $request->user());

        return response()->json(['delivery' => $delivery->outcome()])->header('Cache-Control', 'private, no-store');
    }

    private function scope(Request $request, WcOrder $order, OrderEmailDelivery $delivery): void
    {
        abort_unless(! $delivery->workflow && $delivery->wc_order_id === $order->id && $delivery->website_id === $order->website_id && $delivery->user_id === $request->user()->id, 404);
    }

    private function requireLegacyWorkflow(WcOrder $order): void
    {
        if (\App\Models\WebsiteDocumentProfile::where('website_id', $order->website_id)->where('workflow_enabled', true)->exists()) {
            throw ValidationException::withMessages(['delivery' => 'This website uses the branded Email & Documents workflow. Reopen Email documents to approve its preview.']);
        }
    }
}
