<?php

namespace App\Http\Controllers;

use App\Exceptions\DocumentStoreException;
use App\Exceptions\GmailDeliveryException;
use App\Models\OrderDocument;
use App\Models\OrderEmailDelivery;
use App\Models\WcOrder;
use App\Services\BrandedDocumentDelivery;
use App\Services\DocumentCompletionSync;
use App\Services\DocumentDeliveryReview;
use App\Services\OrderDocumentStorage;
use App\Services\OrderEmailComposer;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DocumentDeliveryController extends Controller
{
    public function documents(Request $request, WcOrder $order, OrderDocumentStorage $storage)
    {
        $this->authorize('update', $order);
        $values = $request->validate(['files' => 'required|array|min:1|max:5', 'files.*' => 'required|file|mimetypes:application/pdf|max:5120',
            'approved' => 'required|accepted', 'intended_customer_email' => 'required|email:rfc|max:254']);

        return response()->json(['documents' => $storage->upload($order, $request->user(), $request->file('files'), $values['intended_customer_email'], true)]);
    }

    public function download(Request $request, WcOrder $order, OrderDocument $document, OrderDocumentStorage $storage)
    {
        $this->authorize('view', $order);
        abort_unless($document->website_id === $order->website_id && $document->wc_order_id === $order->id, 404);

        return response($storage->bytes($document))->header('Content-Type', 'application/pdf')->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff')->header('Content-Disposition', 'attachment; filename="document.pdf"; filename*=UTF-8\'\''.rawurlencode($document->name));
    }

    public function prepare(Request $request, WcOrder $order, BrandedDocumentDelivery $service)
    {
        $this->authorize('update', $order);
        $values = $request->validate([
            'document_ids' => 'required|array|min:1|max:5', 'document_ids.*' => 'required|uuid|distinct',
            'mode' => 'required|in:complete,partial,resend,correction', 'recipient' => ['required', 'email:rfc', 'max:254', 'not_regex:/[\r\n\x00]/'],
            'recipient_override_reason' => 'nullable|string|min:5|max:500', 'language' => 'nullable|in:fr,en',
            'fulfilled_services' => 'sometimes|array|max:20', 'fulfilled_services.*' => 'string|max:120',
            'fulfillment_confirmed' => 'sometimes|boolean', 'previous_delivery_id' => 'nullable|uuid', 'resend_confirmed' => 'sometimes|boolean',
            'sender' => 'prohibited', 'sender_email' => 'prohibited', 'from' => 'prohibited', 'cc' => 'prohibited', 'bcc' => 'prohibited',
        ]);
        try {
            $delivery = $service->prepare($order, $request->user(), $values);
        } catch (GmailDeliveryException|DocumentStoreException $exception) {
            throw ValidationException::withMessages(['delivery' => $exception->getMessage()]);
        }

        return response()->json(['preview' => $delivery->preview(), 'delivery' => $delivery->outcome()])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, WcOrder $order, OrderEmailDelivery $delivery, OrderEmailComposer $composer)
    {
        $this->authorize('view', $order);
        $this->scope($order, $delivery);
        $delivery = $composer->refreshStatus($delivery);

        return response()->json(['preview' => $delivery->preview(), 'delivery' => $delivery->outcome()])->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $request, WcOrder $order, OrderEmailDelivery $delivery, BrandedDocumentDelivery $service)
    {
        $this->authorize('update', $order);
        $this->scope($order, $delivery);
        $request->validate(['confirmed' => 'required|accepted']);
        $delivery = $service->send($order, $delivery, $request->user());

        return response()->json(['delivery' => $delivery->outcome()])->header('Cache-Control', 'private, no-store');
    }

    public function sync(Request $request, WcOrder $order, OrderEmailDelivery $delivery, DocumentCompletionSync $service)
    {
        $this->authorize('update', $order);
        $this->scope($order, $delivery);

        return response()->json(['delivery' => $service->sync($delivery->id)->outcome()])->header('Cache-Control', 'private, no-store');
    }

    private function scope(WcOrder $order, OrderEmailDelivery $delivery): void
    {
        abort_unless($delivery->workflow && $delivery->wc_order_id === $order->id && $delivery->website_id === $order->website_id, 404);
    }

    public function review(Request $request, WcOrder $order, OrderEmailDelivery $delivery, DocumentDeliveryReview $service)
    {
        $this->authorize('update', $order);
        $this->scope($order, $delivery);
        $values = $request->validate(['confirmed' => 'required|accepted', 'decision' => 'required|in:sent,not_sent',
            'reason' => 'required|string|min:10|max:1000', 'provider_message_id' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/']]);

        return response()->json(['delivery' => $service->resolve($order, $delivery, $request->user(), $values)->outcome()])->header('Cache-Control', 'private, no-store');
    }
}
