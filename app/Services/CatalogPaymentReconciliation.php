<?php

namespace App\Services;

use App\Models\CatalogPaymentCursor;
use App\Models\CatalogPaymentPage;
use App\Models\CatalogPaymentWork;
use App\Models\CustomPaymentIntent;
use App\Models\CustomRequest;
use App\Models\Order;
use App\Models\PaymentReconciliationEvent;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CatalogPaymentReconciliation
{
    public function __construct(private MercadoPagoService $provider) {}

    public function review(Order $order, string $source, ?int $actor, Closure $verify, Closure $recover): array
    {
        $correlation = (string) Str::uuid();
        $cache = Cache::lock('mercadopago-reconciliation-order:'.$order->id, 180);
        if (! $cache->get()) {
            return ['outcome' => 'review_in_progress', 'next' => null];
        }
        $token = null;
        try {
            $claim = DB::transaction(function () use ($order, $source, $actor, $correlation) {
                $this->lockOrder($order);
                $cursor = CatalogPaymentCursor::firstOrCreate(['order_id' => $order->id], ['next_run_at' => now()]);
                if ($cursor->lease_token && $cursor->lease_expires_at?->gt(now())) {
                    return null;
                }
                $cursor->update(['lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinutes(5),
                    'last_attempt_at' => now(), 'next_run_at' => now()->addMinutes(5), 'last_outcome' => 'running']);
                if ($order->custom_request_id) {
                    // Existing legacy offsets have no durable inbox. Start safely at zero.
                    app(CustomPaymentRecovery::class)->initialize($order)->update([
                        'financial_review_required' => true, 'financial_reviewed_at' => now(),
                        'financial_next_review_at' => now()->addMinutes(5),
                        'financial_review_outcome' => 'review_started', 'financial_review_token' => $cursor->lease_token,
                    ]);
                }
                $this->event($order, $source, $actor, $correlation, 'catalog_scan_started');

                return $cursor;
            }, 3);
            if (! $claim) {
                return ['outcome' => 'review_in_progress', 'next' => null];
            }
            $token = $claim->lease_token;
            $searchFailed = false;
            $searchIds = [];
            $recover($correlation);
            $order->refresh();
            if (! $this->mutate($order, $token, function (CatalogPaymentCursor $cursor) use ($order) {
                $this->importKnown($order);
                if (! $cursor->scan_open && $cursor->next_discovery_at?->lte(now())) {
                    $cursor->update(['scan_open' => true, 'generation' => $cursor->generation + 1, 'offset' => 0]);
                }
            })) {
                return ['outcome' => 'review_superseded', 'next' => null];
            }
            $cursor = CatalogPaymentCursor::where('order_id', $order->id)->firstOrFail();
            if ($cursor->scan_open) {
                try {
                    $search = $this->provider->searchPayments($order, $cursor->offset);
                    $searchIds = $this->validatePage($search, $cursor->offset);
                    $saved = $this->mutate($order, $token, function (CatalogPaymentCursor $locked) use ($order, $cursor, $search, $searchIds, $source, $actor, $correlation) {
                        if ($locked->generation !== $cursor->generation || $locked->offset !== $cursor->offset) {
                            throw new RuntimeException('Catalog discovery cursor changed');
                        }
                        $targets = [];
                        foreach ($searchIds as $id) {
                            $work = $this->enqueue($order, $id);
                            $targets[$id] = $work->verification_version + 1;
                            if ($work->last_http === 200) {
                                $work->update(['next_attempt_at' => now()]);
                            }
                        }
                        // Preserve the existing custom search order/stride; both types share durable work.
                        $next = $search['next'] === null ? null : ($order->custom_request_id ? $search['next'] : max($cursor->offset + 1, $search['next'] - 2));
                        CatalogPaymentPage::create(['order_id' => $order->id, 'generation' => $cursor->generation,
                            'offset' => $cursor->offset, 'next_offset' => $next, 'targets' => $targets]);
                        $locked->update(['offset' => $next ?? 0, 'scan_open' => $next !== null, 'search_failures' => 0,
                            'next_discovery_at' => $next === null ? now()->addHours(in_array($order->payment_status, ['paid', 'pagado', 'refunded', 'reembolsado'], true) ? 12 : 2) : null]);
                        if ($order->custom_request_id) {
                            // Compatibility mirror only; the leased cursor is authoritative.
                            $order->customPaymentIntent()->update(['payment_offset' => $next ?? 0]);
                        }
                        $this->event($order, $source, $actor, $correlation, $searchIds === [] ? 'search_empty' : 'search_found');
                    });
                    if (! $saved) {
                        $this->preserveSupersededIds($order, $searchIds, $source, $actor, $correlation);

                        return ['outcome' => 'review_superseded', 'next' => null];
                    }
                } catch (Throwable $exception) {
                    $searchFailed = true;
                    if (! $this->mutate($order, $token, function (CatalogPaymentCursor $locked) use ($order, $source, $actor, $correlation, $exception) {
                        $locked->increment('search_failures');
                        $this->event($order, $source, $actor, $correlation, 'search_error', ['exception_class' => $exception::class]);
                    })) {
                        return ['outcome' => 'review_superseded', 'next' => null];
                    }
                }
            }
            $query = CatalogPaymentWork::where('order_id', $order->id)->where('next_attempt_at', '<=', now());
            if ($order->custom_request_id) {
                // Do not let periodic reads of old payments hide newly discovered custom money.
                $query->orderByRaw('CASE WHEN last_verified_at IS NULL THEN 0 ELSE 1 END');
            }
            $query->orderBy('next_attempt_at')->orderBy('id');
            $ids = $query->limit(filled($order->mercadopago_payment_id) ? 9 : 10)->pluck('payment_id')->all();
            // The canonical known payment remains directly verifiable on every manual/scheduled review.
            if (filled($order->mercadopago_payment_id)) {
                array_unshift($ids, $order->mercadopago_payment_id);
            }
            $failed = false;
            $verified = 0;
            foreach (array_unique($ids) as $id) {
                if (! $this->owns($order, $token)) {
                    return ['outcome' => 'review_superseded', 'next' => null];
                }
                $result = null;
                try {
                    $result = $verify((string) $id, $correlation, fn () => $this->authorize($order, $token));
                } catch (Throwable) {
                    // The durable inbox retains this ID even when no financial observation could be stored.
                }
                $ok = ($result['http'] ?? null) === 200;
                $failed = $failed || ! $ok;
                $verified += $ok ? 1 : 0;
                if (! $this->mutate($order, $token, function () use ($order, $id, $result, $ok) {
                    $work = CatalogPaymentWork::where('order_id', $order->id)->where('payment_id', $id)->firstOrFail();
                    $failures = $ok ? 0 : $work->failures + 1;
                    $work->fill(['attempts' => $work->attempts + 1, 'failures' => $failures,
                        'last_attempt_at' => now(), 'last_http' => $result['http'] ?? null,
                        'last_outcome' => $result['result'] ?? 'verification_error',
                        'next_attempt_at' => $ok ? now()->addHours(2) : now()->addMinutes($this->backoff($failures))]);
                    if ($ok) {
                        $work->verification_version++;
                        $work->last_verified_at = now();
                    }
                    $work->save();
                })) {
                    return ['outcome' => 'review_superseded', 'next' => null];
                }
            }
            $outcome = $searchFailed ? 'search_error' : ($failed ? 'review_incomplete' : ($verified > 0 ? 'reviewed' : ($searchIds === [] ? 'search_empty' : 'review_incomplete')));
            $next = null;
            if (! $this->mutate($order, $token, function (CatalogPaymentCursor $locked) use ($order, &$outcome, $searchFailed, $source, $actor, $correlation, &$next, $verified, $token) {
                $this->refreshPages($order);
                if (! $searchFailed && CatalogPaymentPage::where('order_id', $order->id)->where('state', 'pending')->exists()) {
                    $outcome = 'review_incomplete';
                }
                $due = CatalogPaymentWork::where('order_id', $order->id)->min('next_attempt_at');
                $nextRun = $locked->scan_open ? now()->addMinutes($searchFailed ? $this->backoff($locked->search_failures) : 15) : $locked->next_discovery_at;
                if ($due && (! $nextRun || Carbon::parse($due)->lt($nextRun))) {
                    $nextRun = Carbon::parse($due);
                }
                $nextRun = $nextRun && $nextRun->gt(now()->addMinutes(15)) ? $nextRun : now()->addMinutes(15);
                $next = $locked->scan_open ? $locked->offset : null;
                if ($order->custom_request_id) {
                    $intent = app(CustomPaymentRecovery::class)->initialize($order);
                    $open = $order->paymentTransactions()->where(fn ($query) => $query
                        ->where(fn ($incident) => $incident->whereNotNull('incident')->whereNull('resolved_at'))
                        ->orWhereNotIn('provider_status', ['approved', 'rejected', 'cancelled', 'refunded', 'charged_back']))->exists();
                    $required = $searchFailed || $locked->scan_open || $verified === 0 || $open
                        || CatalogPaymentWork::where('order_id', $order->id)->where(fn ($work) => $work->whereNull('last_http')->orWhere('last_http', '!=', 200))->exists()
                        || CatalogPaymentPage::where('order_id', $order->id)->where('state', 'pending')->exists()
                        || $order->paymentTransactions()->unappliedCustomApproval()->exists()
                        || $intent->state !== CustomPaymentIntent::READY
                        || $intent->financial_review_token !== $token;
                    if ($intent->financial_review_token === $token) {
                        $intent->update(['financial_review_required' => $required,
                            'financial_review_outcome' => $outcome, 'financial_next_review_at' => $required ? $nextRun : null]);
                    }
                }
                $locked->update(['next_run_at' => $nextRun, 'last_outcome' => $outcome, 'lease_token' => null, 'lease_expires_at' => null]);
                $this->event($order, $source, $actor, $correlation, 'catalog_scan_checkpoint', ['outcome' => $outcome, 'next' => $next]);
            })) {
                return ['outcome' => 'review_superseded', 'next' => null];
            }

            return ['outcome' => $outcome, 'next' => $next];
        } finally {
            // A crashed/failed review leaves a durable five-minute lease; a later claimant fences it.
            $cache->release();
        }
    }

    private function enqueue(Order $order, string $id): CatalogPaymentWork
    {
        return CatalogPaymentWork::firstOrCreate(['order_id' => $order->id, 'payment_id' => $id], ['next_attempt_at' => now()]);
    }

    private function preserveSupersededIds(Order $order, array $ids, string $source, ?int $actor, string $correlation): void
    {
        // Append authenticated discovery evidence only. Never change the newer owner's cursor, targets or lease.
        DB::transaction(function () use ($order, $ids, $source, $actor, $correlation) {
            $this->lockOrder($order);
            $pending = false;
            foreach ($ids as $id) {
                $pending = $this->enqueue($order, $id)->last_http !== 200 || $pending;
            }
            if ($order->custom_request_id && $pending) {
                app(CustomPaymentRecovery::class)->requireFinancialReview(
                    app(CustomPaymentRecovery::class)->initialize($order), 'superseded_discovery');
            }
            $this->event($order, $source, $actor, $correlation, 'catalog_scan_superseded', ['ids' => $ids]);
        }, 3);
    }

    private function importKnown(Order $order): void
    {
        if (filled($order->mercadopago_payment_id)) {
            $this->enqueue($order, $order->mercadopago_payment_id);
        }
        $known = $order->paymentTransactions()->where('provider', 'mercadopago')->where(function ($query) use ($order) {
            $query->where(fn ($incident) => $incident->whereNotNull('incident')->whereNull('resolved_at'))
                ->orWhereIn('provider_status', ['pending', 'in_process', 'authorized', 'in_mediation']);
            if ($order->custom_request_id) {
                $query->orWhere(fn ($custom) => $custom->whereNotNull('custom_payment_intent_id')->whereNull('resolved_at'));
            }
        })->whereNotIn('provider_payment_id', CatalogPaymentWork::where('order_id', $order->id)->select('payment_id'))
            ->orderBy('last_verified_at')->orderBy('id')->limit(5)->pluck('provider_payment_id');
        foreach ($known as $id) {
            $this->enqueue($order, $id);
        }
    }

    private function validatePage(array $page, int $offset): array
    {
        if (! is_array($page['ids'] ?? null) || count($page['ids']) > 10 || ! array_key_exists('next', $page)
            || ($page['ids'] === [] && $page['next'] !== null)
            || ($page['next'] !== null && (! is_int($page['next']) || $page['next'] <= $offset || $page['next'] > $offset + 10))) {
            throw new RuntimeException('Invalid catalog payment page');
        }
        foreach ($page['ids'] as $id) {
            if (! is_string($id) || ! preg_match('/\A\d{1,64}\z/', $id)) {
                throw new RuntimeException('Invalid catalog payment identifier');
            }
        }

        return array_values(array_unique($page['ids']));
    }

    private function owns(Order $order, string $token): bool
    {
        return CatalogPaymentCursor::where('order_id', $order->id)->where('lease_token', $token)
            ->where('lease_expires_at', '>', now())->exists();
    }

    private function mutate(Order $order, string $token, Closure $write): bool
    {
        return DB::transaction(function () use ($order, $token, $write) {
            $this->lockOrder($order);
            $cursor = CatalogPaymentCursor::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            if ($cursor->lease_token !== $token || ! $cursor->lease_expires_at?->gt(now())) {
                return false;
            }
            $write($cursor);

            return true;
        }, 3);
    }

    private function lockOrder(Order $order): void
    {
        if ($order->custom_request_id) {
            CustomRequest::whereKey($order->custom_request_id)->lockForUpdate()->firstOrFail();
        }
        Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    private function authorize(Order $order, string $token): bool
    {
        // Called inside verify's transaction AFTER HTTP, keeping locks through financial commit.
        $this->lockOrder($order);
        $cursor = CatalogPaymentCursor::where('order_id', $order->id)->lockForUpdate()->firstOrFail();

        return $cursor->lease_token === $token && $cursor->lease_expires_at?->gt(now());
    }

    private function refreshPages(Order $order): void
    {
        $pages = CatalogPaymentPage::where('order_id', $order->id)->where('state', 'pending')
            ->orderBy('last_checked_at')->orderBy('id')->limit(25)->get();
        foreach ($pages as $page) {
            $work = CatalogPaymentWork::where('order_id', $order->id)->whereIn('payment_id', array_keys($page->targets))->get()->keyBy('payment_id');
            $complete = true;
            foreach ($page->targets as $id => $version) {
                $complete = $complete && isset($work[$id]) && $work[$id]->last_http === 200 && $work[$id]->verification_version >= $version;
            }
            $page->update(['last_checked_at' => now(), 'state' => $complete ? 'verified' : 'pending']);
        }
    }

    private function backoff(int $failures): int
    {
        return min(120, 15 * (2 ** min(3, max(0, $failures - 1))));
    }

    private function event(Order $order, string $source, ?int $actor, string $correlation, string $outcome, array $details = []): void
    {
        PaymentReconciliationEvent::create(['order_id' => $order->id, 'source' => $source, 'actor_id' => $actor,
            'correlation_id' => $correlation, 'outcome' => $outcome, 'note' => $details ? json_encode($details, JSON_THROW_ON_ERROR) : null]);
    }
}
