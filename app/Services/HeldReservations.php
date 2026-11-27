<?php

namespace App\Services;

use App\Models\CatalogPaymentPage;
use App\Models\CatalogPaymentWork;
use App\Models\CheckoutAttempt;
use App\Models\Order;
use App\Models\PaymentReconciliationEvent;
use Illuminate\Support\Collection;

class HeldReservations
{
    // Read-only projection for operators. Release authority stays in StockReservations::recover.
    public function after(int $id): Collection
    {
        $work = CatalogPaymentWork::selectRaw('1')->whereColumn('order_id', 'orders.id')->limit(1);

        return Order::where('id', '>', $id)->whereExists(fn ($rows) => $rows->selectRaw('1')
            ->from('stock_reservations')->whereColumn('order_id', 'orders.id')->where('status', 'expired'))
            ->with('catalogPaymentCursor')->select('orders.*')->withExists([
                'paymentTransactions',
                'paymentTransactions as pending_payment' => fn ($q) => $q->whereIn('provider_status', ['pending', 'in_process', 'authorized', 'in_mediation']),
                'paymentTransactions as open_incident' => fn ($q) => $q->whereNotNull('incident')->whereNull('resolved_at'),
            ])->addSelect([
                'checkout_state' => CheckoutAttempt::select('state')->whereColumn('order_id', 'orders.id')->limit(1),
                'has_work' => clone $work,
                'unverified_work' => $work->where(fn ($q) => $q->whereNull('last_http')->orWhere('last_http', '!=', 200)),
                'observed_id' => PaymentReconciliationEvent::selectRaw('1')->whereColumn('order_id', 'orders.id')
                    ->whereNotNull('provider_payment_id')->limit(1),
                'pending_page' => CatalogPaymentPage::selectRaw('1')->whereColumn('order_id', 'orders.id')->where('state', 'pending')->limit(1),
            ])->orderBy('id')->limit(100)->get()->map(function (Order $order) {
                $cursor = $order->catalogPaymentCursor;
                $uncertain = in_array($order->checkout_state, [CheckoutAttempt::SENDING, CheckoutAttempt::UNCERTAIN], true);
                $published = $order->mercadopago_preference_id || $order->mercadopago_checkout_url || $order->checkout_state === CheckoutAttempt::READY;
                $reasons = array_keys(array_filter([
                    'Pago pendiente de resolución.' => $order->pending_payment,
                    'Incidencia financiera abierta.' => $order->open_incident,
                    'Checkout incierto o en envío.' => $uncertain,
                    'Preferencia posiblemente publicada sin cierre acreditado.' => $published || $uncertain,
                    'Búsqueda remota fallida.' => $cursor?->search_failures > 0,
                    'Pago descubierto sin verificación aceptada.' => $order->unverified_work,
                    'Conciliación en curso o páginas pendientes.' => $cursor?->scan_open || $cursor?->lease_token || $order->pending_page,
                    'Evidencia de pago registrada.' => $order->payment_transactions_exists || $order->has_work || $order->observed_id || $order->mercadopago_payment_id,
                    'Revisar el estado financiero del pedido.' => ! in_array($order->payment_status, ['pending', 'pendiente'], true),
                    'Incidencia de preparación o stock asignado.' => $order->fulfillment_issue || $order->stock_deducted_at,
                    'Flujo ajeno al checkout de catálogo.' => $order->custom_request_id || $order->payment_method !== 'mercadopago',
                    'Falta evidencia de no envío.' => ! $published && ! $uncertain && $order->checkout_state !== CheckoutAttempt::NOT_SENT,
                ]));

                return $order->id.' '.$order->number.' '.($reasons ? implode(' ', $reasons) : 'Elegible por preferencia nunca enviada.');
            });
    }
}
