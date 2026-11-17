<?php

namespace Tests\Feature;

use App\Models\CustomPaymentIntent;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomPaymentPending;
use App\Services\CustomPaymentRecovery;
use App\Services\CustomQuoteCheckout;
use App\Services\MercadoPagoService;
use App\Services\PaymentReconciliation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use MercadoPago\Exceptions\MPApiException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\StoreTestCase;

class CustomPaymentRecoveryTest extends StoreTestCase
{
    private function fake(callable|array $callback): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://nakama.example', 'services.mercadopago.access_token' => 'TEST-fake',
            'services.mercadopago.collector_id' => '42', 'services.mercadopago.webhook_secret' => 'aud001-secret',
            'services.mercadopago.shipping_cost' => 3990]);
    }

    private function order(bool $legacy = false): Order
    {
        $user = User::factory()->create();
        $quote = $user->customRequests()->create(['customer_name' => $user->name, 'customer_email' => $user->email,
            'product_type' => 'mug', 'quantity' => 3, 'status' => 'quoted', 'quoted_price' => 10500,
            'design_assistance' => true, 'design_assistance_fee' => 500, 'base_quoted_price' => 10000]);
        if (! $legacy) {
            return app(CustomQuoteCheckout::class)->orderFor($quote, $user, [
                'shipping_address_line1' => 'Test 123', 'shipping_city' => 'Santiago', 'shipping_region' => 'RM',
            ]);
        }
        $order = $quote->order()->create(['user_id' => $user->id, 'customer_name' => $user->name, 'customer_email' => $user->email,
            'status' => 'pending', 'payment_status' => 'pending', 'payment_method' => 'mercadopago',
            'subtotal' => 10500, 'shipping_cost' => 3990, 'total' => 14490]);
        foreach ([['Lot', 10000], ['Design', 500]] as [$name, $price]) {
            $order->items()->create(['product_name' => $name, 'quantity' => 1, 'unit_price' => $price, 'line_total' => $price]);
        }

        return $order;
    }

    private function detail(Order $order, string $id = 'pref-one'): array
    {
        $intent = $order->customPaymentIntent()->firstOrFail();

        return ['id' => $id, 'init_point' => 'https://www.mercadopago.cl/checkout/'.$id,
            'external_reference' => $order->number, 'collector_id' => 42,
            'metadata' => ['order_id' => $order->id] + ($intent->legacy ? [] : ['custom_payment_intent' => $intent->token]),
            'items' => $intent->snapshot['items'], 'shipments' => ['cost' => 3990]];
    }

    private function uncertain(Order $order, ?string $id = null): void
    {
        app(CustomPaymentRecovery::class)->locked($order, function ($locked, $intent) use ($id) {
            if ($intent->state === CustomPaymentIntent::DRAFT) {
                $intent->transition(CustomPaymentIntent::SENDING);
            }
            $intent->transition(CustomPaymentIntent::UNCERTAIN);
            $intent->fill(['sending_at' => now()->subMinutes(3), 'preference_id' => $id])->save();
        });
    }

    private function checkout(Order $order): ?string
    {
        try {
            return app(CustomQuoteCheckout::class)->checkoutUrl($order, $order->user, app(MercadoPagoService::class));
        } catch (CustomPaymentPending|ConnectionException|MPApiException $exception) {
            return null;
        }
    }

    private function payment(Order $order, string $id = '777', string $status = 'approved', ?string $preference = 'pref-one'): array
    {
        return ['id' => (int) $id, 'status' => $status, 'external_reference' => $order->number,
            // Match Checkout Pro's actual response: merchandise and shipping are separate.
            'transaction_amount' => (float) $order->total - (float) $order->shipping_cost,
            'shipping_amount' => (float) $order->shipping_cost, 'currency_id' => 'CLP', 'preference_id' => $preference,
            'date_last_updated' => now()->toIso8601String(), 'date_approved' => $status === 'approved' ? now()->toIso8601String() : null];
    }

    public function test_publication_commits_claim_before_one_post_and_browser_repetition_reuses_it(): void
    {
        $order = $this->order();
        $level = DB::transactionLevel();
        $this->fake(function ($request) use ($order, $level) {
            $this->assertSame($level, DB::transactionLevel());
            $this->assertSame('sending', $order->customPaymentIntent()->value('state'));
            $this->assertSame('POST', $request->method());
            $this->assertSame($order->number, $request['external_reference']);
            $this->assertCount(2, array_unique(array_column($request['items'], 'id')));
            $this->assertEquals(14490, array_sum(array_map(fn ($line) => $line['unit_price'] * $line['quantity'], $request['items'])) + $request['shipments']['cost']);

            return Http::response(['id' => 'pref-one', 'init_point' => 'https://www.mercadopago.cl/checkout/pref-one'], 201);
        });
        $this->assertSame('https://www.mercadopago.cl/checkout/pref-one', $this->checkout($order));
        $this->assertSame($this->checkout($order), $this->checkout($order));
        Http::assertSentCount(1);
        $this->assertSame('ready', $order->customPaymentIntent()->value('state'));
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertDatabaseCount('custom_payment_intents', 1);
    }

    #[TestWith(['timeout'])]
    #[TestWith(['http_500'])]
    #[TestWith(['no_id'])]
    #[TestWith(['no_url'])]
    public function test_ambiguous_publication_is_not_reposted_and_can_be_recovered(string $failure): void
    {
        $order = $this->order();
        $publications = 0;
        $this->fake(function () use ($failure, &$publications) {
            $publications++;
            if ($failure === 'timeout') {
                throw new ConnectionException('Accepted remotely; response lost');
            }

            return match ($failure) {
                'http_500' => Http::response([], 500),
                'no_id' => Http::response(['init_point' => 'https://www.mercadopago.cl/pay'], 201),
                default => Http::response(['id' => 'pref-one'], 201),
            };
        });
        $this->assertNull($this->checkout($order));
        $this->assertSame(1, $publications);
        $this->assertSame('reconciliation_required', $order->customPaymentIntent()->value('state'));
        $detail = $this->detail($order);
        $posts = 0;
        $this->fake(function ($request) use ($detail, &$posts) {
            if ($request->method() === 'POST') {
                $posts++;
            }

            return Http::response(str_contains($request->url(), '/search')
                ? ['elements' => [['id' => 'pref-one']], 'total' => 1, 'next_offset' => 1] : $detail);
        });
        $this->assertSame($detail['init_point'], $this->checkout($order));
        $this->assertSame(0, $posts);
        $this->assertSame('pref-one', $order->fresh()->mercadopago_preference_id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_confirmed_local_preflight_failure_can_retry_without_an_earlier_post(): void
    {
        $order = $this->order();
        config(['app.url' => 'http://invalid.example']);
        $this->actingAs($order->user)->post(route('account.custom-requests.accept', $order->customRequest))
            ->assertSessionHasErrors('payment');
        Http::assertNothingSent();
        $this->assertSame('failed', $order->customPaymentIntent()->value('state'));
        config(['app.url' => 'https://nakama.example']);
        $this->fake(['*' => Http::response(['id' => 'pref-one', 'init_point' => 'https://www.mercadopago.cl/pay'], 201)]);
        $this->assertSame('https://www.mercadopago.cl/pay', $this->checkout($order));
        Http::assertSentCount(1);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_process_interruption_after_claim_never_authorizes_another_post(bool $createdRemotely): void
    {
        $order = $this->order();
        $order->customPaymentIntent()->update(['state' => 'sending', 'sending_at' => now()]);
        $this->assertNull($this->checkout($order));
        Http::assertNothingSent();
        $this->travel(3)->minutes();
        $detail = $this->detail($order);
        $this->fake(fn ($request) => Http::response(str_contains($request->url(), '/search')
            ? ['elements' => $createdRemotely ? [['id' => 'pref-one']] : [], 'total' => $createdRemotely ? 1 : 0] : $detail));
        $url = $this->checkout($order);
        $this->assertSame($createdRemotely ? $detail['init_point'] : null, $url);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertSame($createdRemotely ? 'ready' : 'reconciliation_required', $order->customPaymentIntent()->value('state'));
    }

    public function test_local_persistence_failure_keeps_the_committed_intent_and_recovers_by_reads(): void
    {
        $order = $this->order();
        $armed = true;
        Event::listen('eloquent.saving: '.Order::class, function (Order $saving) use (&$armed) {
            if ($armed && $saving->mercadopago_preference_id) {
                $armed = false;
                throw new \RuntimeException('Simulated local commit failure');
            }
        });
        $this->fake(['*' => Http::response(['id' => 'pref-one', 'init_point' => 'https://www.mercadopago.cl/pay'], 201)]);
        try {
            $this->checkout($order);
            $this->fail('Persistence must fail');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated local commit failure', $exception->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertSame('reconciliation_required', $order->customPaymentIntent()->value('state'));
        $detail = $this->detail($order);
        $this->fake(fn ($request) => Http::response(str_contains($request->url(), '/search') ? ['elements' => [['id' => 'pref-one']], 'total' => 1] : $detail));
        $this->assertSame($detail['init_point'], $this->checkout($order));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    #[TestWith(['empty', 0, 'search_empty'])]
    #[TestWith(['multiple', 2, 'multiple_candidates'])]
    #[TestWith(['incomplete', 1, 'lookup_error'])]
    #[TestWith(['http_error', 0, 'lookup_error'])]
    public function test_search_outcomes_preserve_uncertainty_and_evidence(string $case, int $total, string $outcome): void
    {
        $order = $this->order();
        $this->uncertain($order);
        $this->fake(function ($request) use ($case, $total, $order) {
            if (str_contains($request->url(), '/search')) {
                $ids = match ($case) {
                    'multiple' => ['pref-one', 'pref-two'], default => []
                };

                return Http::response(['elements' => array_map(fn ($id) => ['id' => $id], $ids), 'total' => $total], $case === 'http_error' ? 503 : 200);
            }

            return Http::response($this->detail($order, basename(parse_url($request->url(), PHP_URL_PATH))));
        });
        $this->assertNull($this->checkout($order));
        $intent = $order->customPaymentIntent()->firstOrFail();
        $this->assertSame('reconciliation_required', $intent->state);
        $this->assertSame($outcome, $intent->outcome);
        $this->assertTrue($intent->events()->where('outcome', $outcome)->exists());
        $this->assertNull($order->fresh()->mercadopago_preference_id);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_search_visits_every_page_and_does_not_select_the_first_match(): void
    {
        $order = $this->order();
        $this->uncertain($order);
        $offsets = [];
        $this->fake(function ($request) use ($order, &$offsets) {
            if (str_contains($request->url(), '/search')) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $offsets[] = (int) $query['offset'];
                $this->assertSame($order->number, $query['external_reference']);
                $ids = (int) $query['offset'] === 0 ? range(1, 20) : [21];

                return Http::response(['elements' => array_map(fn ($id) => ['id' => 'pref-'.$id], $ids), 'total' => 21, 'next_offset' => (int) $query['offset'] + count($ids)]);
            }

            return Http::response($this->detail($order, basename(parse_url($request->url(), PHP_URL_PATH))));
        });
        $this->assertNull($this->checkout($order));
        $this->assertSame([0, 20], $offsets);
        $this->assertSame('multiple_candidates', $order->customPaymentIntent()->value('outcome'));
        $event = $order->customPaymentIntent->events()->where('outcome', 'multiple_candidates')->firstOrFail();
        $this->assertCount(2, $event->evidence['pages']);
        $this->assertCount(21, $event->evidence['ids']);
    }

    #[TestWith(['external_reference', 'other-order'])]
    #[TestWith(['collector_id', 99])]
    #[TestWith(['metadata.order_id', 999])]
    #[TestWith(['metadata.custom_payment_intent', 'wrong-intent'])]
    #[TestWith(['items.0.currency_id', 'USD'])]
    #[TestWith(['items.0.quantity', 2])]
    #[TestWith(['items.1.unit_price', 0])]
    #[TestWith(['items.1.id', 'wrong-design-line'])]
    #[TestWith(['shipments.cost', 0])]
    #[TestWith(['total_amount', 1])]
    public function test_candidate_identity_and_every_commercial_component_must_match(string $path, mixed $value): void
    {
        $order = $this->order();
        $this->uncertain($order, 'pref-one');
        $detail = $this->detail($order);
        data_set($detail, $path, $value);
        $this->fake(['*' => Http::response($detail)]);
        $this->assertNull($this->checkout($order));
        $this->assertSame('candidate_mismatch', $order->customPaymentIntent()->value('outcome'));
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame('quoted', $order->customRequest->status);
    }

    public function test_historical_duplicate_line_ids_require_full_multiset_and_configured_merchant(): void
    {
        $order = $this->order(legacy: true);
        app(CustomPaymentRecovery::class)->locked($order, fn () => null);
        $detail = $this->detail($order);
        $this->assertCount(1, array_unique(array_column($detail['items'], 'id')));
        $this->fake(fn ($request) => Http::response(str_contains($request->url(), '/search') ? ['elements' => [['id' => 'pref-one']], 'total' => 1] : $detail));
        $this->assertSame($detail['init_point'], $this->checkout($order));
        $this->assertTrue($order->customPaymentIntent->legacy);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_outside_search_window_requires_direct_id_and_never_claims_absence(): void
    {
        $order = $this->order();
        $this->uncertain($order);
        $order->customPaymentIntent()->update(['sending_at' => now()->subDays(91)]);
        $this->assertNull($this->checkout($order));
        Http::assertNothingSent();
        $this->assertSame('outside_search_window', $order->customPaymentIntent()->value('outcome'));
        $this->travel(3)->minutes();
        $detail = $this->detail($order);
        $this->fake(['*' => Http::response($detail)]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertSame('recovered', app(CustomPaymentRecovery::class)->recover($order, app(MercadoPagoService::class), 'admin', $admin->id, 'pref-one', 'Verified historical preference evidence'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/checkout/preferences/pref-one'));
    }

    private function notify(): TestResponse
    {
        $ts = (string) time();
        $signature = hash_hmac('sha256', "id:777;request-id:aud001;ts:$ts;", 'aud001-secret');

        return $this->postJson('/webhooks/mercadopago?data.id=777&type=payment', [], ['x-request-id' => 'aud001', 'x-signature' => "ts=$ts,v1=$signature"]);
    }

    public function test_early_webhook_is_preserved_then_recovered_and_applied_once(): void
    {
        $order = $this->order();
        $order->customPaymentIntent()->update(['state' => 'sending', 'sending_at' => now()]);
        $detail = $this->detail($order);
        $this->fake(function ($request) use ($order, $detail) {
            if (str_contains($request->url(), '/checkout/preferences/')) {
                $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '777', 'provider_status' => 'approved']);
                $this->assertSame('pending', $order->fresh()->payment_status);

                return Http::response($detail);
            }

            return Http::response($this->payment($order));
        });
        $this->notify()->assertOk();
        $this->notify()->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('approved', $order->customRequest->fresh()->status);
        $this->assertSame('ready', $order->customPaymentIntent()->value('state'));
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertDatabaseCount('stock_movements', 0);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_early_payment_without_preference_evidence_cannot_start_production(): void
    {
        $order = $this->order();
        $order->customPaymentIntent()->update(['state' => 'sending', 'sending_at' => now()]);
        $this->fake(['*' => Http::response($this->payment($order, preference: null))]);
        $this->notify()->assertStatus(503);
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '777', 'provider_status' => 'approved']);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertFalse($order->customRequest->fresh()->ready_for_production);
        $this->assertSame('quoted', $order->customRequest->fresh()->status);
    }

    #[TestWith(['pending', 'pending'])]
    #[TestWith(['approved', 'paid'])]
    #[TestWith(['rejected', 'rejected'])]
    #[TestWith(['refunded', 'refunded'])]
    #[TestWith(['charged_back', 'refunded'])]
    public function test_recovered_preference_still_requires_a_verified_payment_state(string $remote, string $local): void
    {
        $order = $this->order();
        $this->uncertain($order, 'pref-one');
        $detail = $this->detail($order);
        $this->fake(fn ($request) => Http::response(str_contains($request->url(), '/checkout/preferences/') ? $detail : $this->payment($order, status: $remote)));
        $this->notify()->assertOk();
        $this->notify()->assertOk();
        $this->assertSame($local, $order->fresh()->payment_status);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_additional_payment_and_refund_precedence_survive_repeated_reconciliation(): void
    {
        $order = $this->order();
        $this->uncertain($order, 'pref-one');
        $detail = $this->detail($order);
        $statuses = ['777' => 'approved', '778' => 'approved'];
        $this->fake(function ($request) use ($order, $detail, &$statuses) {
            if (str_contains($request->url(), '/checkout/preferences/')) {
                return Http::response($detail);
            }
            if (str_contains($request->url(), '/payments/search')) {
                return Http::response(['results' => [['id' => 777], ['id' => 778]], 'paging' => ['total' => 2]]);
            }
            $id = basename(parse_url($request->url(), PHP_URL_PATH));

            return Http::response($this->payment($order, $id, $statuses[$id]));
        });
        $this->notify()->assertOk();
        $service = app(PaymentReconciliation::class);
        $service->reviewOrder($order->fresh(), 'scheduler');
        $service->reviewOrder($order->fresh(), 'scheduler');
        $this->assertDatabaseCount('payment_transactions', 2);
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '778', 'incident' => 'additional_payment']);
        $statuses['777'] = 'refunded';
        $this->travel(1)->minutes();
        $this->notify()->assertOk();
        $statuses['777'] = 'approved';
        $this->travel(1)->minutes();
        $this->notify()->assertOk();
        $this->assertSame('refunded', $order->fresh()->payment_status);
    }

    public function test_cancelled_order_can_recover_late_payment_without_production_or_url_release(): void
    {
        $order = $this->order();
        $this->uncertain($order, 'pref-one');
        app(CustomQuoteCheckout::class)->decline($order->customRequest, $order->user);
        $detail = $this->detail($order);
        $this->fake(fn ($request) => Http::response(str_contains($request->url(), '/checkout/preferences/') ? $detail : $this->payment($order)));
        $this->notify()->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('cancelado', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->fulfillment_issue);
        $this->assertFalse($order->customRequest->fresh()->ready_for_production);
        $this->actingAs($order->user)->post(route('account.custom-requests.accept', $order->customRequest))->assertSessionHasErrors('quote');
    }

    public function test_admin_can_record_evidence_and_verify_an_id_but_cannot_reset_an_ambiguous_intent(): void
    {
        $order = $this->order();
        $this->uncertain($order);
        $route = route('housekeeping.payments.custom-intent', $order);
        $this->actingAs($order->user)->post($route, ['note' => 'forbidden'])->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post($route, ['preference_id' => 'pref-one'])->assertSessionHasErrors('note');
        $detail = $this->detail($order);
        $this->fake(['*' => Http::response($detail)]);
        $this->actingAs($admin)->post($route, ['note' => 'Merchant support case AUD001-123', 'verified' => 1, 'preference_id' => 'pref-one'])->assertSessionHasNoErrors();
        $this->assertSame('ready', $order->customPaymentIntent()->value('state'));
        $this->assertDatabaseHas('custom_payment_intent_events', ['actor_id' => $admin->id, 'note' => 'Merchant support case AUD001-123', 'outcome' => 'observation']);
        $this->get(route('housekeeping.payments.show', $order))->assertOk()->assertSee('Merchant support case AUD001-123');
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        try {
            $order->customPaymentIntent->transition('sending');
            $this->fail('Ready cannot be reset');
        } catch (\LogicException) {
            $this->assertSame('ready', $order->customPaymentIntent()->value('state'));
        }
    }

    public function test_scheduler_and_admin_list_include_intents_without_stock_or_payment_rows(): void
    {
        $order = $this->order();
        $this->uncertain($order);
        $this->fake(fn ($request) => Http::response(str_contains($request->url(), '/preferences/search')
            ? ['elements' => [], 'total' => 0] : ['results' => [], 'paging' => ['total' => 0]]));
        $this->assertSame(1, app(PaymentReconciliation::class)->reviewBatch());
        $this->assertSame('search_empty', $order->customPaymentIntent()->value('outcome'));
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('housekeeping.payments.index'))
            ->assertOk()->assertSee($order->number)->assertSee('reconciliation_required');
        $this->actingAs($order->user)->get(route('account.custom-requests.show', $order->customRequest))
            ->assertOk()->assertSee('Estamos verificando el inicio de tu pago.')->assertDontSee('Aceptar cotización y pagar');
    }

    public function test_empty_eventually_consistent_search_does_not_reopen_post_and_later_recovers(): void
    {
        $order = $this->order();
        $this->uncertain($order);
        $exists = false;
        $detail = $this->detail($order);
        $this->fake(function ($request) use (&$exists, $detail) {
            return Http::response(str_contains($request->url(), '/search')
                ? ['elements' => $exists ? [['id' => 'pref-one']] : [], 'total' => $exists ? 1 : 0] : $detail);
        });
        $this->assertNull($this->checkout($order));
        $exists = true;
        $this->assertNull($this->checkout($order));
        Http::assertSentCount(1);
        $this->travel(3)->minutes();
        $this->assertSame($detail['init_point'], $this->checkout($order));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_commercial_changes_during_http_prevent_url_release_and_preserve_preference_id(): void
    {
        $order = $this->order();
        $this->fake(function () use ($order) {
            $order->update(['total' => 1]);

            return Http::response(['id' => 'pref-one', 'init_point' => 'https://www.mercadopago.cl/pay'], 201);
        });
        $this->assertNull($this->checkout($order));
        $this->assertDatabaseHas('custom_payment_intents', ['order_id' => $order->id,
            'state' => 'reconciliation_required', 'outcome' => 'order_snapshot_changed', 'preference_id' => 'pref-one']);
        $this->assertNull($order->fresh()->mercadopago_preference_id);
    }

    public function test_historical_id_beyond_ninety_days_is_read_directly_and_requires_merchant_identity(): void
    {
        config(['services.mercadopago.collector_id' => null]);
        $order = $this->order(legacy: true);
        $order->forceFill(['mercadopago_preference_id' => 'pref-one', 'created_at' => now()->subDays(100)])->save();
        app(CustomPaymentRecovery::class)->locked($order, fn () => null);
        $detail = $this->detail($order);
        $this->fake(['*' => Http::response($detail)]);
        $this->assertNull($this->checkout($order));
        Http::assertNothingSent();
        config(['services.mercadopago.collector_id' => '42']);
        $this->travel(3)->minutes();
        $this->assertSame($detail['init_point'], $this->checkout($order));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/checkout/preferences/pref-one'));
    }

    public function test_stale_recovery_worker_cannot_overwrite_a_newer_lookup_claim(): void
    {
        $order = $this->order();
        $this->uncertain($order, 'pref-one');
        $detail = $this->detail($order);
        $this->fake(function () use ($order, $detail) {
            $order->customPaymentIntent()->update(['lookup_token' => (string) Str::uuid()]);

            return Http::response($detail);
        });
        $this->assertSame('already_reviewed', app(CustomPaymentRecovery::class)->recover($order, app(MercadoPagoService::class), 'scheduler'));
        $this->assertSame('reconciliation_required', $order->customPaymentIntent()->value('state'));
        $this->assertNull($order->fresh()->mercadopago_preference_id);
        $this->assertTrue($order->customPaymentIntent->events()->where('outcome', 'lookup_superseded')->exists());
    }

    public function test_scheduler_continues_payment_pages_and_rechecks_early_approved_observations(): void
    {
        $order = $this->order();
        $this->uncertain($order, 'pref-one');
        $detail = $this->detail($order);
        $offsets = [];
        $this->fake(function ($request) use ($order, $detail, &$offsets) {
            if (str_contains($request->url(), '/preferences/')) {
                return Http::response($detail);
            }
            if (str_contains($request->url(), '/payments/search')) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $offsets[] = (int) $query['offset'];
                $ids = (int) $query['offset'] === 0 ? range(700, 709) : [710];

                return Http::response(['results' => array_map(fn ($id) => ['id' => $id], $ids), 'paging' => ['total' => 11]]);
            }
            $id = basename(parse_url($request->url(), PHP_URL_PATH));

            return Http::response($this->payment($order, $id, $id === '710' ? 'approved' : 'pending'));
        });
        $service = app(PaymentReconciliation::class);
        $this->assertSame(1, $service->reviewBatch());
        $this->assertSame(10, $order->customPaymentIntent()->value('payment_offset'));
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->travel(121)->minutes();
        $this->assertSame(1, $service->reviewBatch());
        $this->assertSame([0, 10], $offsets);
        $this->assertSame(0, $order->customPaymentIntent()->value('payment_offset'));
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertDatabaseCount('payment_transactions', 11);
    }

    public function test_a_later_page_error_retains_prior_page_evidence_and_cannot_associate(): void
    {
        $order = $this->order();
        $this->uncertain($order);
        $this->fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return (int) $query['offset'] === 0
                ? Http::response(['elements' => array_map(fn ($id) => ['id' => 'pref-'.$id], range(1, 20)), 'total' => 21, 'next_offset' => 20])
                : Http::response([], 503);
        });
        $this->assertNull($this->checkout($order));
        $event = $order->customPaymentIntent->events()->where('outcome', 'lookup_error')->firstOrFail();
        $this->assertCount(1, $event->evidence['pages']);
        $this->assertCount(20, $event->evidence['pages'][0]['ids']);
        $this->assertSame(503, $event->evidence['http_status']);
        $this->assertNull($order->fresh()->mercadopago_preference_id);
    }

    public function test_equal_total_with_different_design_and_lot_prices_is_insufficient(): void
    {
        $order = $this->order();
        $this->uncertain($order, 'pref-one');
        $detail = $this->detail($order);
        $detail['items'][0]['unit_price'] = 9500;
        $detail['items'][1]['unit_price'] = 1000;
        $this->fake(['*' => Http::response($detail)]);
        $this->assertNull($this->checkout($order));
        $this->assertSame('candidate_mismatch', $order->customPaymentIntent()->value('outcome'));
        $this->assertNull($order->fresh()->mercadopago_preference_id);
    }
}
