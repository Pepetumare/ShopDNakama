<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcceptCustomQuote;
use App\Models\CustomPaymentIntent;
use App\Models\CustomRequest;
use App\Services\CustomPaymentPending;
use App\Services\CustomQuoteCheckout;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustomQuoteController extends Controller
{
    public function accept(AcceptCustomQuote $request, CustomRequest $customRequest, CustomQuoteCheckout $checkout, MercadoPagoService $mercadoPago)
    {
        $order = $checkout->orderFor($customRequest, $request->user(), $request->validated());
        try {
            $url = $checkout->checkoutUrl($order, $request->user(), $mercadoPago);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (CustomPaymentPending) {
            return to_route('account.custom-requests.show', $customRequest)
                ->with('status', 'Estamos verificando el inicio de tu pago. No necesitas intentarlo nuevamente por ahora.');
        } catch (Throwable $exception) {
            // Do not log API response bodies, credentials, customer data or SDK exception messages.
            Log::error('No se pudo iniciar el pago personalizado.', ['order_id' => $order->id, 'exception_class' => get_class($exception)]);

            $failed = $order->customPaymentIntent()->value('state') === CustomPaymentIntent::FAILED;

            if (! $failed) {
                return to_route('account.custom-requests.show', $customRequest)
                    ->with('status', 'Estamos verificando el inicio de tu pago. No necesitas intentarlo nuevamente por ahora.');
            }

            return to_route('account.custom-requests.show', $customRequest)->withErrors(['payment' => 'No se inició el pago. Puedes volver a intentarlo o contactar a la tienda.']);
        }

        return redirect()->away($url, 303, ['Cache-Control' => 'no-store']);
    }

    public function decline(Request $request, CustomRequest $customRequest, CustomQuoteCheckout $checkout)
    {
        Gate::authorize('decline', $customRequest);
        $checkout->decline($customRequest, $request->user());

        return to_route('account.custom-requests.show', $customRequest)->with('status', 'Cotización rechazada. La solicitud quedó cancelada.');
    }
}
