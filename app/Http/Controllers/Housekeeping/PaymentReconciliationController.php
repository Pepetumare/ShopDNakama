<?php

namespace App\Http\Controllers\Housekeeping;

use App\Http\Controllers\Controller;
use App\Models\CheckoutAttempt;
use App\Models\Order;
use App\Models\PaymentReconciliationEvent;
use App\Models\PaymentTransaction;
use App\Services\PaymentReconciliation;
use App\Services\StockReservations;
use Illuminate\Http\Request;

class PaymentReconciliationController extends Controller
{
    public function index(Request $request)
    {
        $all = $request->boolean('all');
        $payments = PaymentTransaction::with('order')->when(! $all, fn ($query) => $query->whereNull('resolved_at')
            ->where(fn ($pending) => $pending->whereNotNull('incident')->orWhereIn('provider_status', ['pending', 'in_process', 'authorized', 'in_mediation'])))
            ->orderByDesc('id')->paginate(20)->withQueryString();
        $uncertain = CheckoutAttempt::with('order')->whereIn('state', [CheckoutAttempt::SENDING, CheckoutAttempt::UNCERTAIN])
            ->whereExists(fn ($stock) => $stock->selectRaw('1')->from('stock_reservations')
                ->whereColumn('order_id', 'checkout_attempts.order_id')->whereIn('status', ['active', 'expired']))
            ->orderByDesc('id')->paginate(20, pageName: 'attempt_page')->withQueryString();

        return view('housekeeping.payments.index', compact('payments', 'uncertain', 'all'));
    }

    public function show(Order $order, StockReservations $reservations)
    {
        abort_unless($order->payment_method === 'mercadopago', 404);
        $payments = $order->paymentTransactions()->orderByDesc('id')->paginate(20)->withQueryString();
        $events = PaymentReconciliationEvent::where('order_id', $order->id)->orderByDesc('id')
            ->paginate(30, pageName: 'event_page')->withQueryString();

        $stock = $reservations->rows($order);

        return view('housekeeping.payments.show', compact('order', 'payments', 'events', 'stock'));
    }

    public function review(Request $request, Order $order, PaymentReconciliation $reconciliation)
    {
        $data = $request->validate(['offset' => ['nullable', 'integer', 'min:0', 'max:1000']]);
        $result = $reconciliation->reviewOrder($order, 'admin', $request->user()->id, (int) ($data['offset'] ?? 0));
        $message = match ($result['outcome']) {
            'search_empty' => 'La búsqueda no devolvió pagos. Esto no confirma el cierre del intento.',
            'search_error' => 'Falló la consulta al proveedor. Reintenta más tarde.',
            'review_in_progress' => 'Ya hay una revisión en curso para este pedido.',
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
