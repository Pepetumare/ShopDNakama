<?php

namespace App\Services;

use App\Models\CustomPaymentIntent;
use App\Models\CustomRequest;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MercadoPago\Exceptions\MPApiException;
use RuntimeException;

class CustomPaymentRecovery
{
    // Called only while holding the request and order locks. Existing orders are never assumed unsent.
    public function initialize(Order $order, bool $new = false): CustomPaymentIntent
    {
        $intent = CustomPaymentIntent::where('order_id', $order->id)->lockForUpdate()->first();
        if ($intent) {
            return $intent;
        }
        $intent = CustomPaymentIntent::create([
            'token' => (string) Str::uuid(), 'order_id' => $order->id, 'custom_request_id' => $order->custom_request_id,
            'external_reference' => $order->number, 'state' => $new ? CustomPaymentIntent::DRAFT : CustomPaymentIntent::UNCERTAIN,
            'legacy' => ! $new, 'snapshot' => $this->snapshot($order, ! $new),
            'sending_at' => $new ? null : $order->created_at,
            'preference_id' => $order->mercadopago_preference_id,
            'financial_review_required' => ! $new, 'financial_next_review_at' => $new ? null : now(),
        ]);
        $this->event($intent, 'checkout', $new ? 'created' : 'historical_import');

        return $intent;
    }

    private function snapshot(Order $order, bool $legacy): array
    {
        $order->loadMissing('items');

        return [
            'order_id' => $order->id, 'reference' => $order->number,
            'collector_id' => config('services.mercadopago.collector_id'),
            'shipping_cents' => $this->cents($order->shipping_cost), 'total_cents' => $this->cents($order->total),
            'items' => $order->items->map(fn ($line) => [
                'id' => $legacy && $line->product_id === null ? 'custom-'.$order->custom_request_id : ($legacy ? (string) $line->product_id : 'custom-line-'.$line->id),
                'title' => $line->product_name, 'quantity' => (int) $line->quantity,
                'unit_price' => (float) $line->unit_price, 'currency_id' => 'CLP',
            ])->all(),
        ];
    }

    public function unchanged(Order $order, CustomPaymentIntent $intent): bool
    {
        $current = $this->snapshot($order, $intent->legacy);
        unset($current['collector_id']);
        $original = $intent->snapshot;
        unset($original['collector_id']);

        return $current == $original;
    }

    public function event(CustomPaymentIntent $intent, string $source, string $outcome, array $evidence = [], ?int $actor = null, ?string $note = null): void
    {
        $intent->events()->create(['source' => $source, 'outcome' => $outcome, 'evidence' => $evidence,
            'actor_id' => $actor, 'note' => $note, 'created_at' => now()]);
    }

    // Caller holds request -> order -> intent. This flag is independent of preference publication.
    public function requireFinancialReview(CustomPaymentIntent $intent, string $outcome): void
    {
        $next = $intent->financial_next_review_at;
        if (! $next) {
            $afterLastReview = $intent->financial_reviewed_at?->copy()->addHours(2);
            $next = $afterLastReview?->gt(now()) ? $afterLastReview : now();
        }
        $intent->forceFill(['financial_review_required' => true,
            'financial_next_review_at' => $next,
            'financial_review_outcome' => $outcome])->save();
    }

    public function locked(Order $order, callable $work): mixed
    {
        return DB::transaction(function () use ($order, $work) {
            CustomRequest::whereKey($order->custom_request_id)->lockForUpdate()->firstOrFail();
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $intent = $this->initialize($locked);

            return $work($locked, $intent);
        }, 3);
    }

    public function recover(Order $order, MercadoPagoService $provider, string $source, ?int $actor = null, ?string $hint = null, ?string $note = null): string
    {
        $administrative = $source === 'admin' && $hint !== null;
        if ($administrative) {
            abort_unless($actor && User::find($actor)?->isAdmin(), 403);
            abort_if(trim((string) $note) === '' || mb_strlen((string) $note) > 1000, 422);
        }
        $claim = $this->locked($order, function (Order $locked, CustomPaymentIntent $intent) use ($source, $actor, $hint, $note) {
            if ($intent->state === CustomPaymentIntent::READY) {
                if ($hint && $hint !== $intent->preference_id) {
                    $this->event($intent, $source, 'verified_preference_immutable', ['stored_id' => $intent->preference_id, 'candidate_id' => $hint], $actor, $note);
                }

                return 'ready';
            }
            if (! $this->unchanged($locked, $intent)) {
                $this->event($intent, $source, 'order_snapshot_changed', [], $actor, $note);
                $intent->update(['outcome' => 'order_snapshot_changed']);

                return 'order_snapshot_changed';
            }
            if ($intent->state === CustomPaymentIntent::DRAFT || $intent->state === CustomPaymentIntent::FAILED) {
                return 'not_sent';
            }
            if (($intent->state === CustomPaymentIntent::SENDING && $intent->sending_at?->gt(now()->subMinutes(2)) && ! $hint)
                || $intent->lookup_at?->gt(now()->subMinutes(2))) {
                return 'lookup_deferred';
            }
            $intent->transition(CustomPaymentIntent::UNCERTAIN);
            $intent->lookup_at = now();
            $intent->lookup_token = (string) Str::uuid();
            $intent->save();
            $this->event($intent, $source, 'lookup_started', ['stored_id' => $intent->preference_id, 'candidate_id' => $hint], $actor, $note);

            return $intent;
        });
        if (is_string($claim)) {
            return $claim;
        }
        $evidence = ['ids' => [], 'matches' => [], 'pages' => [],
            'collector_id' => $claim->snapshot['collector_id'] ?: config('services.mercadopago.collector_id'),
            'representation' => $claim->legacy ? 'historical_multiset' : 'intent_line_ids'];
        $match = null;
        $outcome = 'search_empty';
        $conflict = false;
        try {
            $ids = [];
            if ($claim->legacy && blank($evidence['collector_id'])) {
                throw new RuntimeException('Historical recovery requires configured collector identity');
            }
            $direct = $claim->preference_id ?? $order->fresh()->mercadopago_preference_id;
            $alternative = $administrative && $direct && $hint !== $direct;
            if ($alternative) {
                $evidence['previous_id'] = $direct;
                $evidence['administrative_candidate'] = $hint;
                try {
                    $previous = $provider->customPreference($direct);
                    $previousMatches = ($previous['id'] ?? null) === $direct && $this->matches($claim, $previous);
                    $evidence['previous_verification'] = $previousMatches ? 'verified_match' : 'mismatch';
                    $conflict = $previousMatches;
                } catch (\Throwable $exception) {
                    $evidence['previous_verification'] = 'unverifiable';
                    $evidence['previous_exception_class'] = $exception::class;
                    $evidence['previous_http_status'] = $exception instanceof MPApiException ? $exception->getApiResponse()->getStatusCode() : null;
                    // Historical preferences have no intent UUID: a read failure alone cannot rule out two valid candidates.
                    $conflict = $claim->legacy && (! $exception instanceof MPApiException || $exception->getApiResponse()->getStatusCode() !== 404);
                }
            }
            if ($administrative) {
                $reports = $claim->events()->whereIn('outcome', ['multiple_candidates', 'candidate_conflict'])->orderByDesc('id')->limit(21)->get();
                if ($reports->count() > 20) {
                    $conflict = true;
                    $evidence['candidate_history_limit_reached'] = true;
                }
                $reported = $reports->flatMap(fn ($event) => array_merge($event->evidence['ids'] ?? [], array_keys($event->evidence['reported_candidates'] ?? [])))
                    ->unique()
                    ->reject(fn ($id) => $id === $hint || $id === $direct)->values();
                if ($reported->count() > 20) {
                    $conflict = true;
                    $evidence['candidate_limit_reached'] = true;
                } else {
                    foreach ($reported as $reportedId) {
                        try {
                            $other = $provider->customPreference($reportedId);
                            $matches = ($other['id'] ?? null) === $reportedId && $this->matches($claim, $other);
                            $evidence['reported_candidates'][$reportedId] = $matches ? 'verified_match' : 'mismatch';
                            $conflict = $conflict || $matches;
                        } catch (\Throwable) {
                            $evidence['reported_candidates'][$reportedId] = 'unverifiable';
                            $conflict = true;
                        }
                    }
                }
            }
            // A provider-reported or administrator-supplied ID enables reads beyond the search window.
            if ($direct || $hint) {
                $ids = [$alternative ? $hint : ($direct ?: $hint)];
                $evidence['direct'] = true;
            } elseif (($claim->sending_at ?? $order->created_at)->lte(now()->subDays(90))) {
                $outcome = 'outside_search_window';
            } else {
                $offset = 0;
                $total = null;
                do {
                    $page = $provider->customPreferencePage($claim->external_reference, $offset);
                    $evidence['pages'][] = ['offset' => $offset, 'total' => $page['total'], 'ids' => $page['ids']];
                    if ($total !== null && $total !== $page['total']) {
                        throw new RuntimeException('Unstable preference search');
                    }
                    $total = $page['total'];
                    $ids = array_merge($ids, $page['ids']);
                    $offset = $page['next'];
                    if (count($evidence['pages']) >= 100 && $offset !== null) {
                        throw new RuntimeException('Preference search limit reached');
                    }
                } while ($offset !== null);
                if (count(array_unique($ids)) !== count($ids) || count($ids) !== $total) {
                    throw new RuntimeException('Incomplete preference search');
                }
            }
            $evidence['ids'] = $ids;
            foreach (count($ids) === 1 ? $ids : [] as $id) {
                $detail = $provider->customPreference($id);
                if (($detail['id'] ?? null) === $id && $this->matches($claim, $detail)) {
                    $evidence['matches'][] = $id;
                    $match = ['id' => $id, 'url' => $detail['init_point'] ?? null];
                }
            }
            if (count($ids) > 1) {
                // Even a second, malformed preference with the same reference needs operator review.
                $outcome = 'multiple_candidates';
                $match = null;
            } elseif (count($evidence['matches']) === 1) {
                $outcome = $this->validResult($match) ? 'recovered' : 'incomplete_preference';
            } elseif ($ids !== []) {
                $outcome = 'candidate_mismatch';
            }
            if ($conflict) {
                $outcome = 'candidate_conflict';
                $match = null;
            }
        } catch (\Throwable $exception) {
            $outcome = 'lookup_error';
            $evidence['exception_class'] = $exception::class;
            $evidence['http_status'] = $exception instanceof MPApiException ? $exception->getApiResponse()->getStatusCode() : null;
            $match = null;
        }

        return $this->locked($order, function (Order $locked, CustomPaymentIntent $intent) use ($claim, $source, $actor, $evidence, $match, $outcome, $administrative) {
            if ($intent->lookup_token !== $claim->lookup_token || $intent->state === CustomPaymentIntent::READY) {
                $this->event($intent, $source, 'lookup_superseded', $evidence + ['lookup_outcome' => $outcome], $actor);

                return 'already_reviewed';
            }
            if (! $this->unchanged($locked, $intent)) {
                $intent->update(['outcome' => 'order_snapshot_changed']);
                $this->event($intent, $source, 'order_snapshot_changed', $evidence, $actor);

                return 'order_snapshot_changed';
            }
            $replaceUnverified = $administrative && ! $locked->mercadopago_payment_id
                && $locked->mercadopago_preference_id === ($evidence['previous_id'] ?? null)
                && in_array($evidence['previous_verification'] ?? null, ['mismatch', 'unverifiable'], true)
                && (! $intent->legacy || ($evidence['previous_verification'] ?? null) === 'mismatch' || ($evidence['previous_http_status'] ?? null) === 404);
            if ($outcome === 'recovered' && $locked->mercadopago_preference_id && $locked->mercadopago_preference_id !== $match['id'] && ! $replaceUnverified) {
                $outcome = 'association_conflict';
            }
            if ($outcome === 'recovered') {
                $otherPayments = $locked->paymentTransactions()->whereNotNull('preference_id')->where('preference_id', '!=', $match['id'])
                    ->blocksCustomCheckout()->orderBy('id')->lockForUpdate()->get();
                if ($otherPayments->isNotEmpty()) {
                    $outcome = 'payment_candidate_conflict';
                    $evidence['conflicting_payment_ids'] = $otherPayments->pluck('provider_payment_id')->all();
                }
            }
            $intent->outcome = $outcome;
            if ($match && count($evidence['matches']) === 1 && count($evidence['ids']) === 1 && ! $intent->preference_id) {
                $intent->preference_id = $match['id'];
            }
            if ($outcome === 'recovered') {
                $evidence['replaced_order_preference_id'] = $replaceUnverified ? $locked->mercadopago_preference_id : null;
                $intent->transition(CustomPaymentIntent::READY);
                $intent->preference_id = $match['id'];
                $intent->checkout_url = $match['url'];
                $locked->forceFill(['mercadopago_preference_id' => $match['id'], 'mercadopago_checkout_url' => $match['url']])->save();
            }
            $intent->save();
            $this->event($intent, $source, $outcome, $evidence, $actor);
            $this->requireFinancialReview($intent, $outcome);

            return $outcome;
        });
    }

    public function validResult(?array $result): bool
    {
        return is_string($result['id'] ?? null) && preg_match('/\A[a-zA-Z0-9_-]{1,255}\z/', $result['id'])
            && is_string($result['url'] ?? null) && strlen($result['url']) <= 2048
            && filter_var($result['url'], FILTER_VALIDATE_URL) && parse_url($result['url'], PHP_URL_SCHEME) === 'https'
            && ! parse_url($result['url'], PHP_URL_USER) && ! parse_url($result['url'], PHP_URL_PASS);
    }

    public function matches(CustomPaymentIntent $intent, array $preference): bool
    {
        $snapshot = $intent->snapshot;
        $collector = $snapshot['collector_id'] ?: config('services.mercadopago.collector_id');
        if (($preference['external_reference'] ?? null) !== $intent->external_reference
            || (string) data_get($preference, 'metadata.order_id') !== (string) $intent->order_id
            || (! $intent->legacy && data_get($preference, 'metadata.custom_payment_intent') !== $intent->token)
            || ($intent->legacy && blank($collector))
            || (filled($collector) && (string) ($preference['collector_id'] ?? '') !== (string) $collector)
            || ! is_array($preference['items'] ?? null)
            || ! is_numeric(data_get($preference, 'shipments.cost'))
            || $this->cents(data_get($preference, 'shipments.cost')) !== $snapshot['shipping_cents']) {
            return false;
        }
        // A multiset preserves repeated historical IDs (base lot and design fee); total alone is insufficient.
        $normalize = function (array $items): array {
            $rows = [];
            foreach ($items as $item) {
                if (! is_array($item) || ! is_numeric($item['quantity'] ?? null) || (float) $item['quantity'] <= 0
                    || (float) $item['quantity'] !== (float) (int) $item['quantity']
                    || ! is_numeric($item['unit_price'] ?? null) || (float) $item['unit_price'] < 0
                    || ($item['currency_id'] ?? null) !== 'CLP') {
                    return [];
                }
                $rows[] = json_encode([(string) ($item['id'] ?? ''), $item['title'] ?? null, (int) $item['quantity'], $this->cents($item['unit_price']), 'CLP']);
            }
            sort($rows);

            return $rows;
        };
        $actual = $normalize($preference['items']);
        $expected = $normalize($snapshot['items']);
        $total = array_sum(array_map(fn ($line) => $this->cents($line['unit_price']) * $line['quantity'], $snapshot['items'])) + $snapshot['shipping_cents'];

        return $expected !== [] && $actual === $expected && $total === $snapshot['total_cents']
            && (! isset($preference['total_amount']) || $this->cents($preference['total_amount']) === $total);
    }

    public function annotate(Order $order, User $actor, string $note): void
    {
        abort_unless($actor->isAdmin(), 403);
        abort_if(trim($note) === '' || mb_strlen($note) > 1000, 422);
        $this->locked($order, function (Order $locked, CustomPaymentIntent $intent) use ($actor, $note) {
            $this->event($intent, 'admin', 'observation', [], $actor->id, $note);
        });
    }

    private function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
