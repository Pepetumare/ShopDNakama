<?php

namespace Tests\Feature;

use App\Models\CustomPaymentIntent;
use App\Models\Order;
use App\Models\PaymentReconciliationEvent;
use App\Models\User;
use App\Services\CustomPaymentRecovery;
use App\Services\CustomQuoteCheckout;
use App\Services\MercadoPagoService;
use App\Services\PaymentReconciliation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\Net\MPResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\StoreTestCase;

class CustomPaymentFindingsTest extends StoreTestCase
{
    private function order(bool $ready = false): Order
    {
        config(['services.mercadopago.collector_id' => '42', 'services.mercadopago.shipping_cost' => 3990]);
        $user = User::factory()->create();
        $quote = $user->customRequests()->create(['customer_name' => $user->name, 'customer_email' => $user->email,
            'product_type' => 'mug', 'quantity' => 1, 'status' => 'quoted', 'quoted_price' => 10000]);
        $order = app(CustomQuoteCheckout::class)->orderFor($quote, $user, [
            'shipping_address_line1' => 'Findings 123', 'shipping_city' => 'Santiago', 'shipping_region' => 'RM',
        ]);
        $order->customPaymentIntent()->update(['state' => $ready ? CustomPaymentIntent::READY : CustomPaymentIntent::UNCERTAIN,
            'preference_id' => $ready ? 'finding-pref' : null, 'checkout_url' => $ready ? 'https://www.mercadopago.cl/finding' : null,
            'sending_at' => now()->subMinutes(5)]);
        if ($ready) {
            $order->forceFill(['mercadopago_preference_id' => 'finding-pref', 'mercadopago_checkout_url' => 'https://www.mercadopago.cl/finding'])->save();
        }

        return $order;
    }

    private function observation(Order $order, string $status = 'approved', string $id = '881', ?string $preference = null): void
    {
        $order->paymentTransactions()->create(['provider' => 'mercadopago', 'provider_payment_id' => $id,
            'custom_payment_intent_id' => $order->customPaymentIntent->id, 'amount' => $order->total, 'currency' => 'CLP',
            'provider_status' => $status, 'preference_id' => $preference, 'last_verified_at' => now(), 'correlation_id' => (string) Str::uuid()]);
    }

    private function historical(Order $order): void
    {
        $snapshot = $order->customPaymentIntent->snapshot;
        foreach ($snapshot['items'] as &$item) {
            $item['id'] = 'custom-'.$order->custom_request_id;
        }
        $order->customPaymentIntent()->update(['legacy' => true, 'snapshot' => $snapshot]);
        $order->unsetRelation('customPaymentIntent');
    }

    private function detail(Order $order, string $id): array
    {
        return ['id' => $id, 'init_point' => 'https://www.mercadopago.cl/'.$id, 'external_reference' => $order->number,
            'metadata' => ['order_id' => $order->id, 'custom_payment_intent' => $order->customPaymentIntent->token],
            'collector_id' => 42, 'items' => $order->customPaymentIntent->snapshot['items'], 'shipments' => ['cost' => 3990]];
    }

    private function payment(Order $order, string $status = 'approved', string $id = '881', string $preference = 'finding-pref'): array
    {
        return ['id' => $id, 'status' => $status, 'external_reference' => $order->number, 'amount' => $order->total,
            'currency' => 'CLP', 'preference_id' => $preference, 'updated_at' => now()->toIso8601String()];
    }

    #[TestWith(['approved'])]
    #[TestWith(['pending'])]
    #[TestWith(['in_process'])]
    #[TestWith(['refunded'])]
    #[TestWith(['charged_back'])]
    public function test_observed_money_blocks_ready_url_when_provider_cannot_reverify(string $status): void
    {
        $order = $this->order(ready: true);
        $this->observation($order, $status);
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('getPayment')->with('881')->once()->andThrow(new ConnectionException('Controlled read failure'));
        $this->actingAs($order->user)->post(route('account.custom-requests.accept', $order->customRequest))
            ->assertRedirect(route('account.custom-requests.show', $order->customRequest))->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Estamos verificando el inicio de tu pago. No necesitas intentarlo nuevamente por ahora.');
        $this->get(route('account.custom-requests.show', $order->customRequest))->assertOk()
            ->assertSee('Estamos verificando el inicio de tu pago.')->assertDontSee('Aceptar cotización y pagar');
        $this->assertTrue($order->customPaymentIntent->fresh()->financial_review_required);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    #[TestWith(['rejected'])]
    #[TestWith(['cancelled'])]
    public function test_verified_nonapproved_attempt_can_reuse_existing_preference(string $status): void
    {
        $order = $this->order(ready: true);
        $this->observation($order, $status);
        $this->mock(MercadoPagoService::class);
        $response = $this->actingAs($order->user)->post(route('account.custom-requests.accept', $order->customRequest))
            ->assertSessionHasNoErrors()->assertRedirect('https://www.mercadopago.cl/finding');
        $response->assertStatus(303);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_approval_observed_during_initial_publication_also_blocks_redirect(): void
    {
        $order = $this->order();
        $order->customPaymentIntent()->update(['state' => CustomPaymentIntent::DRAFT]);
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('createPreference')->once()->andReturnUsing(function () use ($order) {
            $this->observation($order);

            return ['id' => 'finding-pref', 'url' => 'https://www.mercadopago.cl/finding'];
        });
        $provider->shouldReceive('getPayment')->once()->andThrow(new ConnectionException('Controlled payment failure'));
        $this->actingAs($order->user)->post(route('account.custom-requests.accept', $order->customRequest))
            ->assertSessionHasNoErrors()->assertRedirect(route('account.custom-requests.show', $order->customRequest));
        $this->assertSame('finding-pref', $order->fresh()->mercadopago_preference_id);
        $this->assertSame('ready', $order->customPaymentIntent->fresh()->state);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_newer_rejection_cannot_erase_an_unapplied_approval_and_enable_checkout(): void
    {
        $order = $this->order(ready: true);
        $this->observation($order);
        $order->paymentTransactions()->update(['provider_updated_at' => now()->subMinutes(5)]);
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('getPayment')->andReturn($this->payment($order, 'rejected'));
        $result = app(PaymentReconciliation::class)->verify('881', 'webhook', (string) Str::uuid());
        $this->assertSame('approval_regression', $result['result']);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertTrue($order->hasCustomPaymentHold());
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '881', 'incident' => 'approval_regression']);
    }

    public function test_browser_rechecks_approval_and_current_refund_without_reopening_checkout(): void
    {
        $order = $this->order(ready: true);
        $this->observation($order);
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('getPayment')->once()->andReturn($this->payment($order, 'refunded'));
        $this->actingAs($order->user)->post(route('account.custom-requests.accept', $order->customRequest))
            ->assertSessionHasErrors('quote');
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_empty_old_recovery_stays_scheduled_with_due_time_and_visible_queue(): void
    {
        $order = $this->order(ready: true);
        $order->forceFill(['created_at' => now()->subDays(120)])->save();
        $order->customPaymentIntent()->update(['financial_review_required' => true, 'financial_next_review_at' => now()]);
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('searchPayments')->twice()->andReturn(['ids' => [], 'next' => null]);
        $service = app(PaymentReconciliation::class);
        $this->assertSame(1, $service->reviewBatch());
        $this->assertSame(0, $service->reviewBatch());
        $intent = $order->customPaymentIntent->fresh();
        $this->assertTrue($intent->financial_review_required);
        $this->assertNotNull($intent->financial_reviewed_at);
        $this->assertTrue($intent->financial_next_review_at->gt(now()));
        $this->travel(3)->hours();
        $this->assertSame(1, $service->reviewBatch());
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('housekeeping.payments.index'))
            ->assertOk()->assertSee($order->number)->assertSee('Conciliación financiera pendiente');
    }

    public function test_old_approval_can_finish_once_after_transient_failure(): void
    {
        $order = $this->order(ready: true);
        $order->forceFill(['created_at' => now()->subDays(120)])->save();
        $this->observation($order);
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('searchPayments')->andReturn(['ids' => [], 'next' => null]);
        $provider->shouldReceive('getPayment')->with('881')->andReturnUsing(
            fn () => throw new ConnectionException('Controlled first read failure'),
            fn () => $this->payment($order),
        );
        $service = app(PaymentReconciliation::class);
        $this->assertSame(1, $service->reviewBatch());
        $this->travel(3)->hours();
        $this->assertSame(1, $service->reviewBatch());
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertFalse($order->customPaymentIntent->fresh()->financial_review_required);
        $this->travel(3)->hours();
        $this->assertSame(0, $service->reviewBatch());
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_two_valid_administrative_candidates_remain_uncertain(): void
    {
        $order = $this->order();
        $order->customPaymentIntent()->update(['preference_id' => 'previous']);
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('customPreference')->with('previous')->once()->andReturn($this->detail($order, 'previous'));
        $provider->shouldReceive('customPreference')->with('candidate')->once()->andReturn($this->detail($order, 'candidate'));
        $admin = User::factory()->create(['role' => 'admin']);
        $outcome = app(CustomPaymentRecovery::class)->recover($order, $provider, 'admin', $admin->id, 'candidate', 'Two candidates investigated');
        $this->assertSame('candidate_conflict', $outcome);
        $this->assertNull($order->fresh()->mercadopago_preference_id);
        $this->assertSame('previous', $order->customPaymentIntent->fresh()->preference_id);
        $this->assertTrue($order->customPaymentIntent->events()->where('outcome', 'candidate_conflict')->exists());
    }

    public function test_ready_preference_is_immutable_and_requires_no_new_provider_read(): void
    {
        $order = $this->order(ready: true);
        $provider = $this->mock(MercadoPagoService::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertSame('ready', app(CustomPaymentRecovery::class)->recover($order, $provider, 'admin', $admin->id, 'another', 'Candidate rejected by verified association'));
        $this->assertSame('finding-pref', $order->fresh()->mercadopago_preference_id);
        $this->assertTrue($order->customPaymentIntent->events()->where('outcome', 'verified_preference_immutable')->where('actor_id', $admin->id)->exists());
    }

    public function test_financial_observation_for_another_candidate_prevents_association(): void
    {
        $order = $this->order();
        $this->observation($order, preference: 'other-pref');
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('customPreference')->with('candidate')->once()->andReturn($this->detail($order, 'candidate'));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertSame('payment_candidate_conflict', app(CustomPaymentRecovery::class)->recover($order, $provider, 'admin', $admin->id, 'candidate', 'Conflicting money observed'));
        $this->assertNull($order->fresh()->mercadopago_preference_id);
        $this->assertTrue($order->customPaymentIntent->fresh()->financial_review_required);
    }

    public function test_alternative_for_a_wrong_historical_order_id_preserves_previous_evidence(): void
    {
        $order = $this->order();
        $order->forceFill(['mercadopago_preference_id' => 'wrong-old'])->save();
        $this->historical($order);
        $order->customPaymentIntent()->update(['preference_id' => 'wrong-old', 'legacy' => true]);
        $detail = $this->detail($order, 'candidate');
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('customPreference')->with('wrong-old')->once()->andThrow(new MPApiException('Controlled missing old ID', new MPResponse(404, [])));
        $provider->shouldReceive('customPreference')->with('candidate')->once()->andReturn($detail);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertSame('recovered', app(CustomPaymentRecovery::class)->recover($order, $provider, 'admin', $admin->id, 'candidate', 'Verified replacement of unverified historical ID'));
        $this->assertSame('candidate', $order->fresh()->mercadopago_preference_id);
        $event = $order->customPaymentIntent->events()->where('outcome', 'recovered')->firstOrFail();
        $this->assertSame('wrong-old', $event->evidence['previous_id']);
        $this->assertSame('wrong-old', $event->evidence['replaced_order_preference_id']);
    }

    public function test_historical_alternative_with_network_uncertainty_does_not_override_old_id(): void
    {
        $order = $this->order();
        $this->historical($order);
        $order->customPaymentIntent()->update(['preference_id' => 'old', 'legacy' => true]);
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('customPreference')->with('old')->andThrow(new ConnectionException('Old ID may still be valid'));
        $provider->shouldReceive('customPreference')->with('candidate')->andReturn($this->detail($order, 'candidate'));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertSame('candidate_conflict', app(CustomPaymentRecovery::class)->recover($order, $provider, 'admin', $admin->id, 'candidate', 'Ambiguity must remain open'));
        $this->assertNull($order->fresh()->mercadopago_preference_id);
    }

    public function test_discrepant_payment_is_unique_visible_and_blocks_aud003(): void
    {
        $order = $this->order(ready: true);
        $order->forceFill(['payment_status' => 'paid', 'status' => 'confirmed', 'mercadopago_payment_id' => '880'])->save();
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('getPayment')->with('881')->twice()->andReturn($this->payment($order, preference: 'different-pref'));
        $service = app(PaymentReconciliation::class);
        foreach ([1, 2] as $iteration) {
            $this->assertSame(422, $service->verify('881', 'webhook', (string) Str::uuid())['http']);
        }
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame('880', $order->fresh()->mercadopago_payment_id);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('housekeeping.payments.index'))->assertOk()->assertSee('preference_mismatch');
        $this->patch(route('housekeeping.orders.status', $order), ['status' => 'preparando'])->assertSessionHasErrors('status');
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_unknown_reference_preserves_financial_evidence_without_forcing_order_attribution(): void
    {
        $order = $this->order();
        $payment = $this->payment($order);
        $payment['external_reference'] = 'unknown-commercial-reference';
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('getPayment')->andReturn($payment);
        $this->assertSame(422, app(PaymentReconciliation::class)->verify('881', 'webhook', (string) Str::uuid())['http']);
        $this->assertDatabaseCount('payment_transactions', 0);
        $event = PaymentReconciliationEvent::where('provider_payment_id', '881')->firstOrFail();
        $this->assertNull($event->order_id);
        $this->assertEquals($order->total, json_decode($event->note, true)['amount']);
        $this->assertSame('CLP', json_decode($event->note, true)['currency']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('housekeeping.payments.index'))
            ->assertOk()->assertSee('unknown-commercial-reference');
    }

    public function test_known_payment_of_another_order_is_not_reassigned(): void
    {
        $first = $this->order(ready: true);
        $second = $this->order();
        $second->forceFill(['mercadopago_preference_id' => 'second-pref'])->save();
        $this->observation($first);
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('getPayment')->andReturn($this->payment($second, preference: 'second-pref'));
        $this->assertSame('payment_already_linked', app(PaymentReconciliation::class)->verify('881', 'webhook', (string) Str::uuid())['result']);
        $this->assertDatabaseHas('payment_transactions', ['provider_payment_id' => '881', 'order_id' => $first->id]);
        $this->assertSame('pending', $second->fresh()->payment_status);
    }

    public function test_reported_multiple_candidates_are_not_bypassed_by_an_administrative_hint(): void
    {
        $order = $this->order();
        app(CustomPaymentRecovery::class)->event($order->customPaymentIntent, 'scheduler', 'multiple_candidates', ['ids' => ['candidate', 'also-valid']]);
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('customPreference')->with('also-valid')->once()->andReturn($this->detail($order, 'also-valid'));
        $provider->shouldReceive('customPreference')->with('candidate')->once()->andReturn($this->detail($order, 'candidate'));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertSame('candidate_conflict', app(CustomPaymentRecovery::class)->recover($order, $provider, 'admin', $admin->id, 'candidate', 'Prior search has another verifiable candidate'));
        $this->assertNull($order->fresh()->mercadopago_preference_id);
    }

    public function test_admin_alternative_with_another_orders_identity_is_rejected(): void
    {
        $order = $this->order();
        $other = $this->order();
        $order->customPaymentIntent()->update(['preference_id' => 'unverifiable']);
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('customPreference')->with('unverifiable')->once()->andThrow(new ConnectionException('Unverified old candidate'));
        $provider->shouldReceive('customPreference')->with('other-order')->once()->andReturn($this->detail($other, 'other-order'));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertSame('candidate_mismatch', app(CustomPaymentRecovery::class)->recover($order, $provider, 'admin', $admin->id, 'other-order', 'Proposed ID still requires commercial validation'));
        $this->assertNull($order->fresh()->mercadopago_preference_id);
    }

    public function test_older_financial_review_cannot_close_a_newer_review_claim(): void
    {
        $order = $this->order(ready: true);
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('searchPayments')->once()->andReturnUsing(function () use ($order) {
            $order->customPaymentIntent()->update(['financial_review_token' => (string) Str::uuid(),
                'financial_review_required' => true, 'financial_review_outcome' => 'newer_review', 'financial_next_review_at' => now()->addHours(4)]);

            return ['ids' => ['881'], 'next' => null];
        });
        $provider->shouldReceive('getPayment')->once()->andReturn($this->payment($order, 'rejected'));
        app(PaymentReconciliation::class)->reviewOrder($order, 'scheduler');
        $intent = $order->customPaymentIntent->fresh();
        $this->assertTrue($intent->financial_review_required);
        $this->assertSame('newer_review', $intent->financial_review_outcome);
        $this->assertTrue($intent->financial_next_review_at->gt(now()->addHours(3)));
    }

    public function test_rejected_payment_with_an_open_financial_incident_does_not_release_url(): void
    {
        $order = $this->order(ready: true);
        $this->observation($order, 'rejected');
        $order->paymentTransactions()->update(['incident' => 'amount_mismatch']);
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('getPayment')->once()->andReturn($this->payment($order, 'rejected'));
        $this->actingAs($order->user)->post(route('account.custom-requests.accept', $order->customRequest))
            ->assertRedirect(route('account.custom-requests.show', $order->customRequest))->assertSessionHasNoErrors();
        $this->assertTrue($order->hasCustomPaymentHold());
    }

    public function test_resolved_incident_is_not_the_application_of_an_approved_observation(): void
    {
        $order = $this->order(ready: true);
        $order->forceFill(['created_at' => now()->subDays(120)])->save();
        $this->observation($order);
        $order->paymentTransactions()->update(['incident' => 'amount_mismatch', 'resolved_at' => now()]);
        $provider = $this->mock(MercadoPagoService::class);
        $provider->shouldReceive('searchPayments')->andReturn(['ids' => [], 'next' => null]);
        $provider->shouldReceive('getPayment')->andReturn($this->payment($order));
        $service = app(PaymentReconciliation::class);
        $this->assertSame(1, $service->reviewBatch());
        $this->assertTrue($order->customPaymentIntent->fresh()->financial_review_required);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->travel(3)->hours();
        $this->assertSame(1, $service->reviewBatch());
        $this->assertTrue($order->hasCustomPaymentHold());
    }
}
