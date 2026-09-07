<?php

namespace App\Http\Controllers;

use App\Models\OrderEmailDelivery;
use App\Models\WcOrder;
use App\Services\OrderEmailComposer;
use App\Services\OrderEmailDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderEmailController extends Controller
{
    public function options(Request $request, WcOrder $order, OrderEmailComposer $composer)
    {
        $this->authorize('view', $order);

        return response()->json($composer->options($order, $request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, WcOrder $order, OrderEmailComposer $composer)
    {
        $this->authorize('update', $order);
        $values = $request->validate([
            'recipient' => ['required', 'string', 'email:rfc', 'max:254', 'not_regex:/[\r\n\x00]/'],
            'subject' => ['required', 'string', 'max:255', 'not_regex:/[\r\n\x00]/'],
            'body' => ['required', 'string', 'max:20000'],
            'files' => ['required', 'array', 'min:1', 'max:5'],
            'files.*' => ['required', 'file', 'mimetypes:application/pdf', 'max:5120'],
            'sender' => ['prohibited'], 'sender_email' => ['prohibited'], 'from' => ['prohibited'],
        ]);
        $delivery = $composer->prepare($order, $request->user(), $values, $request->file('files'));

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
        $this->scope($request, $order, $delivery);
        if ($request->input('confirmed') !== true) {
            throw ValidationException::withMessages(['confirmed' => 'Confirm this exact email preview before sending.']);
        }
        $delivery = $service->send($order, $delivery, $request->user());

        return response()->json(['delivery' => $delivery->outcome()])->header('Cache-Control', 'private, no-store');
    }

    private function scope(Request $request, WcOrder $order, OrderEmailDelivery $delivery): void
    {
        abort_unless($delivery->wc_order_id === $order->id && $delivery->website_id === $order->website_id && $delivery->user_id === $request->user()->id, 404);
    }
}
