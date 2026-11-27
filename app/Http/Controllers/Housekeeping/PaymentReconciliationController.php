<?php

namespace App\Http\Controllers\Housekeeping;

use App\Http\Controllers\Controller;
use App\Models\CheckoutAttempt;
use App\Models\CustomPaymentIntent;
use App\Models\Order;
use App\Models\PaymentReconciliationEvent;
use App\Models\PaymentTransaction;
use App\Services\CustomPaymentRecovery;
use App\Services\MercadoPagoService;
use App\Services\PaymentReconciliation;
use App\Services\StockReservations;
use Illuminate\Http\Request;

class PaymentReconciliationController extends Controller
{
    public function index(Request $request)
    {
        $all = $request->boolean('all');
        $payments = PaymentTransaction::with('order')->when(! $all, fn ($query) => $query->whereNull('resolved_at')
            ->where(fn ($pending) => $pending->whereNotNull('incident')->orWhereIn('provider_status', ['pending', 'in_process', 'authorized', 'in_mediation'])
                ->orWhere(fn ($unapplied) => $unapplied->unappliedCustomApproval())))
            ->orderByDesc('id')->paginate(20)->withQueryString();
        $uncertain = CheckoutAttempt::with('order')->whereIn('state', [CheckoutAttempt::SENDING, CheckoutAttempt::UNCERTAIN])
            ->whereExists(fn ($stock) => $stock->selectRaw('1')->from('stock_reservations')
                ->whereColumn('order_id', 'checkout_attempts.order_id')->whereIn('status', ['active', 'expired']))
            ->orderByDesc('id')->paginate(20, pageName: 'attempt_page')->withQueryString();

        $customIntents = CustomPaymentIntent::with('order')->where(fn ($query) => $query
            ->whereIn('state', [CustomPaymentIntent::SENDING, CustomPaymentIntent::UNCERTAIN, CustomPaymentIntent::FAILED])
            ->orWhere('financial_review_required', true))
            ->orderBy('lookup_at')->orderBy('id')->paginate(20, pageName: 'custom_intent_page')->withQueryString();
        $legacyOrders = Order::whereNotNull('custom_request_id')->where('payment_method', 'mercadopago')->whereNull('mercadopago_preference_id')
            ->whereDoesntHave('customPaymentIntent')->orderBy('id')->paginate(20, pageName: 'legacy_page')->withQueryString();

        $quarantined = PaymentReconciliationEvent::whereNull('order_id')->whereIn('outcome', ['identity_mismatch', 'payment_already_linked'])
            ->orderByDesc('id')->paginate(20, pageName: 'quarantine_page')->withQueryString();

        return view('housekeeping.payments.index', compact('payments', 'uncertain', 'all', 'customIntents', 'legacyOrders', 'quarantined'));
    }

    public function show(Order $order, StockReservations $reservations)
    {
        abort_unless($order->payment_method === 'mercadopago', 404);
        $payments = $order->paymentTransactions()->orderByDesc('id')->paginate(20)->withQueryString();
        $events = PaymentReconciliationEvent::where('order_id', $order->id)->orderByDesc('id')
            ->paginate(30, pageName: 'event_page')->withQueryString();

        $stock = $reservations->rows($order);
        $intent = $order->customPaymentIntent;
        $intentEvents = $intent?->events()->orderByDesc('id')->paginate(30, pageName: 'intent_event_page')->withQueryString();

        return view('housekeeping.payments.show', compact('order', 'payments', 'events', 'stock', 'intent', 'intentEvents'));
    }

    public function customIntent(Request $request, Order $order, CustomPaymentRecovery $recovery, MercadoPagoService $provider)
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($order->custom_request_id && $order->payment_method === 'mercadopago', 404);
        $data = $request->validate([
            'note' => ['required', 'string', 'max:1000', 'regex:/\S/'],
            'preference_id' => ['nullable', 'string', 'regex:/\A[a-zA-Z0-9_-]{1,255}\z/'],
        ]);
        if (filled($data['preference_id'] ?? null)) {
            $request->validate(['verified' => 'accepted']);
        }
        $recovery->annotate($order, $request->user(), $data['note']);
        if (filled($data['preference_id'] ?? null)) {
            $recovery->recover($order, $provider, 'admin', $request->user()->id, $data['preference_id'], $data['note']);
        }

        return back()->with('status', 'Evidencia registrada. Revisa el resultado de la verificación; no se emitió una nueva preferencia.');
    }

    public function review(Request $request, Order $order, PaymentReconciliation $reconciliation)
    {
        $data = $request->validate(['offset' => ['nullable', 'integer', 'min:0', 'max:2147483647']]);
        $result = $reconciliation->reviewOrder($order, 'admin', $request->user()->id, (int) ($data['offset'] ?? 0));
        $message = match ($result['outcome']) {
            'search_empty' => 'La búsqueda no devolvió pagos. Esto no confirma el cierre del intento.',
            'search_error' => 'Falló la consulta al proveedor. Reintenta más tarde.',
            'review_in_progress' => 'Ya hay una revisión en curso para este pedido.',
            'review_superseded' => 'Otra revisión tomó el relevo. Consulta el resultado más reciente.',
            'review_incomplete' => 'La revisión quedó incompleta. Consulta los resultados registrados.',
            default => 'Consulta registrada. Revisa los pagos y las incidencias.',
        };

        return to_route('housekeeping.payments.show', $order)->with('status', $message)->with('payment_next', $result['next']);
    }

    public function annotate(Request $request, PaymentTransaction $payment, PaymentReconciliation $reconciliation)
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
            'action' => ['required', 'in:observe,resolve'],
        ]);
        $resolve = $data['action'] === 'resolve';
        if ($resolve) {
            $request->validate(['verified' => 'accepted']);
        }
        $reconciliation->annotate($payment, $request->user()->id, $data['note'], $resolve);

        return to_route('housekeeping.payments.show', $payment->order_id)->with('status', 'Acción registrada.');
    }

    public function resolveFulfillment(Request $request, Order $order, PaymentReconciliation $reconciliation)
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        $reconciliation->resolveFulfillment($order, $request->user(), $data['note']);

        return back()->with('status', 'Incidencia de preparación resuelta y registrada.');
    }
}
