<?php

namespace App\Services;

use App\Models\CatalogPaymentPage;
use App\Models\CatalogPaymentWork;
use App\Models\CheckoutAttempt;
use App\Models\CustomPaymentIntent;
use App\Models\CustomRequest;
use App\Models\Order;
use App\Models\PaymentReconciliationEvent;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentReconciliation
{
    public function __construct(
        private MercadoPagoService $provider,
        private StockManager $stock,
        private StockReservations $reservations,
    ) {}

    public function verify(string $id, string $source, string $correlation, ?Order $expected = null, ?int $actor = null, bool $recoverCustom = true, ?\Closure $authorize = null): array
    {
        abort_unless(preg_match('/\A\d{1,64}\z/', $id), 422);
        try {
            $payment = $this->provider->getPayment($id);
        } catch (\Throwable $exception) {
            DB::transaction(function () use ($authorize, $id, $expected, $source, $correlation, $actor) {
                if ($authorize && ! $authorize()) {
                    return;
                }
                $known = PaymentTransaction::where('provider', 'mercadopago')->where('provider_payment_id', $id)
                    ->when($expected, fn ($query) => $query->where('order_id', $expected->id))->first();
                $retryOrder = $expected ?? $known?->order;
                if ($retryOrder?->custom_request_id) {
                    app(CustomPaymentRecovery::class)->locked($retryOrder, function (Order $locked, CustomPaymentIntent $intent) {
                        app(CustomPaymentRecovery::class)->requireFinancialReview($intent, 'provider_error');
                        $intent->update(['financial_review_token' => (string) Str::uuid()]);
                    });
                }
                $this->record($source, $correlation, 'provider_error', $expected, $known, $id, $actor);
            }, 3);
            throw $exception;
        }

        $result = DB::transaction(function () use ($id, $payment, $source, $correlation, $expected, $actor, $authorize) {
            if ($authorize && ! $authorize()) {
                return ['http' => 409, 'result' => 'review_superseded'];
            }
            $candidate = is_string($payment['external_reference'] ?? null)
                ? Order::where('number', $payment['external_reference'])->first() : null;
            $custom = $candidate?->custom_request_id
                ? CustomRequest::whereKey($candidate->custom_request_id)->lockForUpdate()->firstOrFail() : null;
            $order = $candidate ? Order::whereKey($candidate->id)->lockForUpdate()->first() : null;
            $status = $payment['status'] ?? null;
            $preference = $payment['preference_id'] ?? null;
            $currency = $payment['currency'] ?? null;
            $amount = $payment['amount'] ?? null;
            $details = ['payment_id' => $payment['id'] ?? null, 'payment_status' => $status,
                'external_reference' => $payment['external_reference'] ?? null, 'preference_id' => $preference];
            $before = ['order_id' => $order?->id, 'stored_preference_id' => $order?->mercadopago_preference_id,
                'payment_status_before' => $order?->payment_status, 'order_status_before' => $order?->status];

            $valid = $order && $order->payment_method === 'mercadopago'
                && $order->number === $payment['external_reference']
                && (! $expected || $expected->id === $order->id)
                && is_scalar($payment['id'] ?? null) && (string) $payment['id'] === $id
                && is_string($status) && preg_match('/\A[a-z_]{1,32}\z/', $status)
                && is_string($currency) && preg_match('/\A[A-Z]{3}\z/', $currency)
                && is_numeric($amount) && (float) $amount >= 0 && (float) $amount < 999999999999
                && ($preference === null || (is_string($preference) && strlen($preference) <= 255));
            if (! $valid || Order::where('mercadopago_payment_id', $id)->whereKeyNot($order->id)->exists()) {
                // Unattributed, API-verified evidence remains investigable without forcing an order FK.
                $evidence = ['amount' => is_numeric($amount) && (float) $amount >= 0 && (float) $amount < 999999999999 ? $amount : null,
                    'currency' => is_string($currency) && preg_match('/\A[A-Z]{3}\z/', $currency) ? $currency : null,
                    'external_reference' => is_string($payment['external_reference'] ?? null)
                        && preg_match('/\A[a-zA-Z0-9_.:-]{1,255}\z/', $payment['external_reference']) ? $payment['external_reference'] : null,
                    'preference_id' => is_string($preference) && preg_match('/\A[a-zA-Z0-9_-]{1,255}\z/', $preference) ? $preference : null];
                $this->record($source, $correlation, 'identity_mismatch', id: $id, actor: $actor,
                    status: is_string($status) && preg_match('/\A[a-z_]{1,32}\z/', $status) ? $status : null,
                    note: json_encode($evidence, JSON_THROW_ON_ERROR));

                return ['http' => 422, 'result' => 'identity_mismatch', 'payment' => $details] + $before;
            }
            $key = ['provider' => 'mercadopago', 'provider_payment_id' => $id];
            $preferenceMismatch = $order->mercadopago_preference_id && filled($preference) && $preference !== $order->mercadopago_preference_id;

            $attempt = CheckoutAttempt::where('order_id', $order->id)->first();
            $customIntent = $custom ? app(CustomPaymentRecovery::class)->initialize($order) : null;
            $awaiting = ! $order->mercadopago_preference_id && in_array($attempt?->state, [CheckoutAttempt::SENDING, CheckoutAttempt::UNCERTAIN], true);
            $awaiting = $awaiting || ($customIntent && ! $order->mercadopago_preference_id);
            // Historical orders may lack a preference ID. Only their already-associated payment can be read.
            $historical = ! $order->mercadopago_preference_id && ! $awaiting && $order->mercadopago_payment_id === $id;
            if (! $order->mercadopago_preference_id && ! $awaiting && ! $historical) {
                $this->record($source, $correlation, 'preference_unverified', $expected, id: $id, actor: $actor);

                return ['http' => 422, 'result' => 'preference_unverified', 'payment' => $details] + $before;
            }

            $updated = $this->date($payment['updated_at'] ?? null);
            $approved = $this->date($payment['approved_at'] ?? null);
            $transaction = PaymentTransaction::where($key)->lockForUpdate()->first();
            if (! $transaction) {
                DB::table('payment_transactions')->upsert([$key + [
                    'order_id' => $order->id, 'checkout_attempt_id' => $attempt?->id, 'custom_payment_intent_id' => $customIntent?->id, 'preference_id' => $preference,
                    'amount' => $amount, 'currency' => $currency, 'provider_status' => $status,
                    'approved_at' => $approved, 'provider_updated_at' => $updated,
                    'last_verified_at' => now(), 'correlation_id' => $correlation, 'created_at' => now(), 'updated_at' => now(),
                ]], ['provider', 'provider_payment_id'], ['provider_payment_id']);
                $transaction = PaymentTransaction::where($key)->lockForUpdate()->firstOrFail();
            }
            if ($transaction->order_id !== $order->id) {
                $this->record($source, $correlation, 'payment_already_linked', id: $id, actor: $actor, status: $status,
                    note: json_encode(['amount' => $amount, 'currency' => $currency, 'preference_id' => $preference,
                        'external_reference' => $payment['external_reference'], 'existing_order_id' => $transaction->order_id], JSON_THROW_ON_ERROR));

                return ['http' => 422, 'result' => 'payment_already_linked', 'payment' => $details] + $before;
            }

            $outcome = 'verified';
            $http = $awaiting ? 503 : 200;
            $stale = $this->stale($transaction, $updated, $status);
            $approvalRegression = ! in_array($order->payment_status, ['paid', 'pagado', 'refunded', 'reembolsado'], true)
                && ($transaction->provider_status === 'approved' || $transaction->approved_at)
                && ! in_array($status, ['approved', 'refunded', 'charged_back'], true);
            if (! $stale) {
                $transaction->fill(['preference_id' => filled($preference) ? $preference : $transaction->preference_id,
                    'amount' => $amount, 'currency' => $currency, 'provider_status' => $status,
                    'provider_updated_at' => $updated ?? $transaction->provider_updated_at,
                    'approved_at' => $approved ?? $transaction->approved_at]);
            }
            if ($preferenceMismatch) {
                $this->flag($transaction, 'preference_mismatch');
                $outcome = 'preference_mismatch';
                $http = 422;
            } elseif (! $stale && $approvalRegression) {
                $this->flag($transaction, 'approval_regression');
                $outcome = 'approval_regression';
                $http = 422;
            } elseif ($stale) {
                $outcome = 'stale_snapshot';
            } else {
                $shippingMatches = ! array_key_exists('shipping_amount', $payment)
                    || round((float) $payment['shipping_amount'] * 100) === round((float) $order->shipping_cost * 100);
                if ($currency !== 'CLP' || ! $shippingMatches || round((float) $amount * 100) !== round((float) $order->total * 100)) {
                    $outcome = $currency !== 'CLP' ? 'currency_mismatch' : 'amount_mismatch';
                    // A previous resolution cannot authorize newly inconsistent provider evidence.
                    $transaction->resolved_at = null;
                    $this->flag($transaction, $outcome);
                    $http = 422;
                } elseif ($awaiting) {
                    $outcome = 'awaiting_preference';
                    $http = 503;
                } elseif (! $transaction->resolved_at && in_array($transaction->incident, ['amount_mismatch', 'currency_mismatch', 'preference_mismatch'], true)) {
                    $outcome = 'held_for_review';
                } elseif ($historical) {
                    $outcome = 'historical_verified';
                } else {
                    $outcome = $this->apply($order, $custom, $transaction, $status);
                }
            }
            $transaction->fill(['last_verified_at' => now(), 'correlation_id' => $correlation])->save();
            if ($customIntent && ($http !== 200 || $outcome === 'held_for_review' || ($transaction->incident && ! $transaction->resolved_at)
                || ! in_array($transaction->provider_status, ['approved', 'rejected', 'cancelled', 'refunded', 'charged_back'], true))) {
                app(CustomPaymentRecovery::class)->requireFinancialReview($customIntent, $outcome);
            }
            $this->record($source, $correlation, $outcome, $order, $transaction, $id, $actor, $status);

            return ['http' => $http, 'result' => $outcome, 'payment' => $details,
                'payment_status_after' => $order->payment_status, 'order_status_after' => $order->status] + $before;
        }, 3);
        // Preserve the verified financial observation before any recovery HTTP request.
        // Re-read the payment after association, never synthesize paid from the preference.
        if ($recoverCustom && $result['result'] === 'awaiting_preference' && $result['order_id']) {
            $order = Order::findOrFail($result['order_id']);
            if ($order->custom_request_id) {
                try {
                    app(CustomPaymentRecovery::class)->recover($order, $this->provider, $source, $actor, $payment['preference_id'] ?? null);
                    if ($order->fresh()->mercadopago_preference_id) {
                        return $this->verify($id, $source, $correlation, $expected, $actor, false, $authorize);
                    }
                } catch (\Throwable) {
                    // Sending/uncertain intent and financial evidence remain durable for the next review.
                }
            }
        }

        return $result;
    }

    private function apply(Order $order, ?CustomRequest $custom, PaymentTransaction $transaction, string $status): string
    {
        $id = $transaction->provider_payment_id;
        $paid = in_array($order->payment_status, ['paid', 'pagado'], true);
        $refunded = in_array($order->payment_status, ['refunded', 'reembolsado'], true);
        $same = $order->mercadopago_payment_id === $id;
        if (($paid || $refunded) && ! $same) {
            if ($status === 'approved' || (in_array($status, ['refunded', 'charged_back'], true) && $transaction->approved_at)) {
                $this->flag($transaction, $refunded ? 'payment_after_refund' : 'additional_payment');
            }

            return 'secondary_payment';
        }
        if ($refunded && $status === 'approved') {
            $this->flag($transaction, 'payment_after_refund');

            return 'terminal_status_preserved';
        }
        $mapped = match ($status) {
            'approved' => 'paid', 'rejected' => 'rejected', 'refunded', 'charged_back' => 'refunded',
            'cancelled' => 'cancelled', default => 'pending',
        };
        if ($refunded || ($paid && $mapped !== 'refunded')) {
            return 'terminal_status_preserved';
        }
        if ($mapped === 'refunded' && $order->mercadopago_payment_id && ! $same) {
            $this->flag($transaction, 'unmatched_reversal');

            return 'secondary_payment';
        }
        if ($mapped !== 'paid' && $order->mercadopago_payment_id && ! $same) {
            return 'secondary_payment';
        }

        $order->forceFill(['mercadopago_payment_id' => $id, 'payment_status' => $mapped]);
        if ($mapped === 'paid') {
            $issue = $this->approvalIssue($order, $custom);
            if ($issue !== null) {
                $this->flag($transaction, $issue);
                $order->fulfillment_issue = 'Pago confirmado: revisar reserva, stock o cancelación antes de preparar o reembolsar.';
            } else {
                if ($custom?->status === 'quoted') {
                    $custom->update(['status' => 'approved']);
                }
                if (in_array($order->status, ['pending', 'pendiente'], true)) {
                    $order->status = 'confirmed';
                }
                $order->fulfillment_issue = null;
            }
        } elseif ($mapped === 'refunded') {
            $order->fulfillment_issue ??= 'Pago reembolsado o en contracargo: revisar la preparación y entrega antes de continuar.';
        }
        $order->save();

        return 'updated';
    }

    private function approvalIssue(Order $order, ?CustomRequest $custom): ?string
    {
        if ($order->status === 'cancelado'
            || ($custom && ! in_array($custom->status, ['quoted', 'approved', 'in_production', 'ready', 'completed'], true))) {
            return 'cancelled_order';
        }
        if ($this->stock->deductOrder($order)) {
            return null;
        }
        if ($this->reservations->rows($order, lock: true)->contains(fn ($row) => ! $this->reservations->isCurrent($row))) {
            return 'late_payment';
        }

        return 'stock_unavailable';
    }

    private function stale(PaymentTransaction $transaction, ?Carbon $updated, string $status): bool
    {
        if ($updated && $transaction->provider_updated_at && ! $updated->equalTo($transaction->provider_updated_at)) {
            return $updated->lt($transaction->provider_updated_at);
        }
        $rank = fn ($state) => match ($state) {
            'refunded', 'charged_back' => 3, 'approved' => 2, 'rejected', 'cancelled' => 1, default => 0,
        };

        return $rank($status) < $rank($transaction->provider_status);
    }

    private function date(mixed $date): ?Carbon
    {
        if (! is_string($date) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T/', $date)) {
            return null;
        }
        try {
            return Carbon::parse($date)->setTimezone(config('app.timezone'));
        } catch (\Exception) {
            return null;
        }
    }

    private function flag(PaymentTransaction $transaction, string $incident): void
    {
        $transaction->incident ??= $incident;
    }

    private function record(string $source, string $correlation, string $outcome, ?Order $order = null,
        ?PaymentTransaction $transaction = null, ?string $id = null, ?int $actor = null, ?string $status = null, ?string $note = null): void
    {
        PaymentReconciliationEvent::create(['source' => $source, 'correlation_id' => $correlation, 'outcome' => $outcome,
            'order_id' => $order?->id, 'payment_transaction_id' => $transaction?->id, 'provider_payment_id' => $id,
            'actor_id' => $actor, 'provider_status' => $status, 'note' => $note]);
    }

    public function annotate(PaymentTransaction $payment, int $actor, string $note, bool $resolve): void
    {
        DB::transaction(function () use ($payment, $actor, $note, $resolve) {
            $order = Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $locked = PaymentTransaction::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->order_id === $order->id, 409);
            abort_if($resolve && (! $locked->incident || $locked->resolved_at), 409);
            if ($resolve) {
                $locked->update(['resolved_at' => now()]);
            }
            $this->record('admin', (string) Str::uuid(), $resolve ? 'resolved_manually' : 'observation',
                $order, $locked, $locked->provider_payment_id, $actor, note: $note);
        }, 3);
    }

    public function resolveFulfillment(Order $order, User $actor, string $note): void
    {
        abort_unless($actor->isAdmin(), 403);
        abort_if(trim($note) === '' || mb_strlen($note) > 1000, 422);
        DB::transaction(function () use ($order, $actor, $note) {
            $custom = $order->custom_request_id
                ? CustomRequest::whereKey($order->custom_request_id)->lockForUpdate()->firstOrFail() : null;
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! filled($locked->fulfillment_issue)) {
                return;
            }
            if ($custom && ! in_array($custom->status, ['approved', 'in_production', 'ready', 'completed'], true)) {
                throw ValidationException::withMessages(['status' => 'La solicitud personalizada no permite continuar la preparación.']);
            }
            $original = $locked->fulfillment_issue;
            // Clear only in memory until the shared policy accepts the persisted financial and stock state.
            $locked->fulfillment_issue = null;
            $locked->assertFulfillable();
            $quantities = $locked->items()->whereNotNull('product_id')->get()->groupBy('product_id')
                ->map(fn ($lines) => (int) $lines->sum('quantity'));
            $reservations = $this->reservations->rows($locked, lock: true);
            foreach ($reservations as $reservation) {
                if ($reservation->status !== 'consumed'
                    || (int) $reservation->quantity !== $quantities->get($reservation->product_id)) {
                    throw ValidationException::withMessages(['status' => 'Las reservas no acreditan la asignación del inventario.']);
                }
            }
            if ($reservations->isNotEmpty() && $reservations->count() !== $quantities->count()) {
                throw ValidationException::withMessages(['status' => 'La asignación de inventario está incompleta.']);
            }
            $locked->save();
            $this->record('admin', (string) Str::uuid(), 'fulfillment_resolved', $locked,
                actor: $actor->id, note: 'Incidencia original: '.$original."\nMotivo: ".$note);
        }, 3);
    }

    public function reviewOrder(Order $order, string $source, ?int $actor = null, int $offset = 0): array
    {
        abort_unless($order->payment_method === 'mercadopago', 422);

        return app(CatalogPaymentReconciliation::class)->review($order, $source, $actor,
            fn (string $id, string $correlation, \Closure $authorize) => $this->verify($id, $source, $correlation, $order, $actor, authorize: $authorize),
            fn (string $correlation) => $this->recoverPreference($order, $source, $correlation, $actor));
    }

    private function finishCustomReview(Order $order, string $outcome, int $verified, bool $complete, string $token): void
    {
        app(CustomPaymentRecovery::class)->locked($order, function (Order $locked, CustomPaymentIntent $intent) use ($outcome, $verified, $complete, $token) {
            if ($intent->financial_review_token !== $token) {
                return;
            }
            $open = $locked->paymentTransactions()->where(fn ($query) => $query
                ->where(fn ($incident) => $incident->whereNotNull('incident')->whereNull('resolved_at'))
                ->orWhereNotIn('provider_status', ['approved', 'rejected', 'cancelled', 'refunded', 'charged_back']))->exists();
            $unapplied = $locked->paymentTransactions()->unappliedCustomApproval()->exists();
            // Empty searches are not evidence that all financial work has finished.
            $durablePending = $locked->catalogPaymentCursor()->where('scan_open', true)->exists()
                || CatalogPaymentPage::where('order_id', $locked->id)->where('state', 'pending')->exists()
                || CatalogPaymentWork::where('order_id', $locked->id)
                    ->where(fn ($work) => $work->whereNull('last_http')->orWhere('last_http', '!=', 200))->exists();
            $required = ! $complete || $verified === 0 || $open || $unapplied || $durablePending || $intent->state !== CustomPaymentIntent::READY;
            $intent->update(['financial_review_required' => $required, 'financial_review_outcome' => $outcome,
                'financial_next_review_at' => $required ? now()->addHours(2) : null]);
        });
    }

    public function reviewObservedCustomPayments(Order $order): void
    {
        abort_unless($order->custom_request_id, 422);
        $lock = Cache::lock('mercadopago-reconciliation-order:'.$order->id, 180);
        if (! $lock->get()) {
            return;
        }
        try {
            $claimed = app(CustomPaymentRecovery::class)->locked($order, function (Order $locked, CustomPaymentIntent $intent) {
                if ($intent->financial_reviewed_at?->gt(now()->subSeconds(30))) {
                    return null;
                }
                $token = (string) Str::uuid();
                $intent->update(['financial_review_required' => true, 'financial_reviewed_at' => now(),
                    'financial_next_review_at' => now()->addHours(2), 'financial_review_outcome' => 'checkout_review', 'financial_review_token' => $token]);

                return $token;
            });
            if (! $claimed) {
                return;
            }
            $ids = $order->paymentTransactions()->blocksCustomCheckout()->orderBy('last_verified_at')->orderBy('id')
                ->limit(10)->pluck('provider_payment_id');
            $verified = 0;
            $complete = $ids->count() < 10;
            foreach ($ids as $id) {
                try {
                    $result = $this->verify($id, 'checkout', (string) Str::uuid(), $order);
                    $verified += $result['http'] === 200 ? 1 : 0;
                    $complete = $complete && $result['http'] === 200 && $result['result'] !== 'held_for_review';
                } catch (\Throwable) {
                    $complete = false;
                }
            }
            $this->finishCustomReview($order, $complete ? 'observed_reviewed' : 'review_incomplete', $verified, $complete, $claimed);
        } finally {
            $lock->release();
        }
    }

    private function recoverPreference(Order $order, string $source, string $correlation, ?int $actor): void
    {
        if ($order->custom_request_id) {
            app(CustomPaymentRecovery::class)->recover($order, $this->provider, $source, $actor);
            $order->refresh();

            return;
        }
        if ($order->mercadopago_preference_id) {
            return;
        }
        $attempt = DB::transaction(function () use ($order) {
            $this->lockBuyer($order);
            $attempt = CheckoutAttempt::where('order_id', $order->id)->lockForUpdate()->first();
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $attempt || $locked->mercadopago_preference_id || ! in_array($attempt->state, [CheckoutAttempt::SENDING, CheckoutAttempt::UNCERTAIN], true)
                || ($attempt->state === CheckoutAttempt::SENDING && $attempt->updated_at->gt(now()->subMinutes(2)))
                || $attempt->lookup_at?->gt(now()->subSeconds(30))) {
                return null;
            }
            $attempt->update(['state' => CheckoutAttempt::UNCERTAIN, 'lookup_at' => now()]);

            return $attempt;
        }, 3);
        if (! $attempt) {
            return;
        }
        try {
            $preference = $this->provider->findPreference($order);
            $id = $preference['id'] ?? null;
            if (! is_string($id) || blank($id) || strlen($id) > 255) {
                $this->record($source, $correlation, 'preference_unresolved', $order, actor: $actor);

                return;
            }
            $attached = DB::transaction(function () use ($order, $attempt, $id) {
                $this->lockBuyer($order);
                $attempt = CheckoutAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($attempt->state !== CheckoutAttempt::UNCERTAIN || $locked->mercadopago_preference_id) {
                    return false;
                }
                // Reconciliation links the verified ID only. Checkout still controls URL release and cart handling.
                $locked->forceFill(['mercadopago_preference_id' => $id])->save();

                return true;
            }, 3);
            $this->record($source, $correlation, $attached ? 'preference_recovered' : 'preference_already_reviewed', $order, actor: $actor);
        } catch (\Throwable) {
            $this->record($source, $correlation, 'preference_lookup_error', $order, actor: $actor);
        }
    }

    private function lockBuyer(Order $order): void
    {
        if ($order->user_id) {
            User::whereKey($order->user_id)->lockForUpdate()->first();
        }
    }

    public function reviewBatch(): int
    {
        $cutoff = now()->subHours(2);
        $orders = Order::select('orders.*')->selectSub(
            PaymentReconciliationEvent::selectRaw('MAX(created_at)')->whereColumn('order_id', 'orders.id')->where('source', 'scheduler'),
            'last_reconciliation_check'
        )->where('payment_method', 'mercadopago')
            ->where(function ($query) {
                $query->whereExists(fn ($payment) => $payment->selectRaw('1')->from('payment_transactions')
                    ->whereColumn('order_id', 'orders.id')->whereNull('resolved_at')
                    ->where(fn ($status) => $status->whereNotNull('incident')->orWhereIn('provider_status', ['pending', 'in_process', 'authorized', 'in_mediation'])))
                    ->orWhereExists(fn ($reservation) => $reservation->selectRaw('1')->from('stock_reservations')
                        ->whereColumn('order_id', 'orders.id')->whereIn('status', ['active', 'expired']))
                    ->orWhereExists(fn ($intent) => $intent->selectRaw('1')->from('custom_payment_intents')
                        ->whereColumn('order_id', 'orders.id')->whereIn('state', [CustomPaymentIntent::SENDING, CustomPaymentIntent::UNCERTAIN]))
                    ->orWhereHas('customPaymentIntent', fn ($intent) => $intent->where('financial_review_required', true))
                    ->orWhereHas('paymentTransactions', fn ($payment) => $payment->unappliedCustomApproval())
                    ->orWhere(fn ($legacy) => $legacy->whereNotNull('custom_request_id')->whereNull('mercadopago_preference_id')
                        ->whereNotExists(fn ($intent) => $intent->selectRaw('1')->from('custom_payment_intents')->whereColumn('order_id', 'orders.id')))
                    ->orWhere(fn ($recent) => $recent->whereNotNull('mercadopago_preference_id')->where('created_at', '>=', now()->subDays(7)))
                    ->orWhere(fn ($catalog) => $catalog->whereNull('custom_request_id')->where(fn ($work) => $work
                        ->whereNotNull('mercadopago_preference_id')->orWhereNotNull('mercadopago_payment_id')
                        ->orWhereHas('catalogPaymentCursor')->orWhereHas('paymentTransactions')
                        ->orWhereExists(fn ($attempt) => $attempt->selectRaw('1')->from('checkout_attempts')
                            ->whereColumn('order_id', 'orders.id')->whereIn('state', [CheckoutAttempt::SENDING, CheckoutAttempt::UNCERTAIN]))));
            })->whereDoesntHave('customPaymentIntent', fn ($intent) => $intent->where('financial_next_review_at', '>', now()))
            ->where(fn ($due) => $due
                ->where(fn ($custom) => $custom->whereNotNull('custom_request_id')->whereDoesntHave('catalogPaymentCursor')
                    ->whereNotExists(fn ($events) => $events->selectRaw('1')->from('payment_reconciliation_events')
                        ->whereColumn('order_id', 'orders.id')->where('source', 'scheduler')->where('created_at', '>=', $cutoff)))
                ->orWhere(fn ($catalog) => $catalog->where(fn ($type) => $type->whereNull('custom_request_id')->orWhereHas('catalogPaymentCursor'))
                    ->whereDoesntHave('catalogPaymentCursor', fn ($cursor) => $cursor
                        ->where('next_run_at', '>', now())->orWhere('lease_expires_at', '>', now()))))
            ->orderBy('last_reconciliation_check')->orderBy('id')->limit(5)->get();

        foreach ($orders as $order) {
            try {
                $this->reviewOrder($order, 'scheduler');
            } catch (\Throwable) {
                $this->record('scheduler', (string) Str::uuid(), 'review_error', $order);
            }
        }

        return $orders->count();
    }
}
