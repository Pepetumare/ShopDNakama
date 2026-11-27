<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalNotification;
use App\Mail\TransactionalMail;
use App\Models\User;
use App\Services\TransactionalNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TransactionalNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_notice_is_durable_deduplicated_and_payload_is_encrypted(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $first = app(TransactionalNotifications::class)->queue('verify_email', $user->email,
            ['user_id' => $user->id], key: 'verify-email:'.$user->id.':test');
        $again = app(TransactionalNotifications::class)->queue('verify_email', $user->email,
            ['user_id' => $user->id], key: 'verify-email:'.$user->id.':test');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(['user_id' => $user->id], $first->fresh()->payload);
        $this->assertNotSame(json_encode(['user_id' => $user->id]), $first->getRawOriginal('payload'));
        $this->assertDatabaseHas('transactional_notification_events', ['notification_id' => $first->id, 'event' => 'queued']);
    }

    public function test_worker_sends_custom_mail_and_persists_signed_verification_event(): void
    {
        Queue::fake();
        Mail::fake();
        $user = User::factory()->create();
        $notice = app(TransactionalNotifications::class)->queue('verify_email', $user->email,
            ['user_id' => $user->id], key: 'verify-email:'.$user->id.':worker-test');

        (new SendTransactionalNotification($notice->id))->handle();

        $this->assertSame('sent', $notice->fresh()->status);
        $this->assertDatabaseHas('transactional_notification_events', ['notification_id' => $notice->id, 'event' => 'sent']);
        Mail::assertSent(TransactionalMail::class, fn (TransactionalMail $mail) => $mail->kind === 'verify_email'
            && str_contains($mail->data['url'], 'signature='));
    }

    public function test_transport_failure_is_recorded_and_admin_retry_creates_only_a_mail_attempt(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $notice = app(TransactionalNotifications::class)->queue('order_pending', $user->email,
            ['number' => 'SDN-TEST-1'], key: 'pending:failure-test');
        Mail::shouldReceive('to')->times(3)->with($user->email)->andThrow(new \RuntimeException('SMTP unavailable'));

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                (new SendTransactionalNotification($notice->id))->handle();
                $this->fail('La excepción del transporte debe propagarse al worker para reintento.');
            } catch (\RuntimeException $error) {
                $this->assertSame('SMTP unavailable', $error->getMessage());
            }
        }

        $this->assertSame('failed', $notice->fresh()->status);
        $this->assertSame(3, $notice->fresh()->attempts);
        $this->assertDatabaseHas('transactional_notification_events', ['notification_id' => $notice->id, 'event' => 'failed']);

        $retry = app(TransactionalNotifications::class)->retry($notice->fresh());
        $this->assertSame($notice->id, $retry->retry_of_id);
        $this->assertSame('queued', $retry->status);
        $this->assertDatabaseHas('transactional_notification_events', ['notification_id' => $retry->id, 'event' => 'retry_queued']);
        $this->assertSame('failed', $notice->fresh()->status);
    }

    public function test_dispatch_relay_only_requeues_stale_notifications(): void
    {
        Queue::fake();
        $notice = app(TransactionalNotifications::class)->queue('order_pending', 'relay@example.test',
            ['number' => 'SDN-RELAY'], key: 'pending:relay-test');

        $this->assertSame(0, app(TransactionalNotifications::class)->dispatchPending());
        $this->travel(16)->minutes();
        $this->assertSame(1, app(TransactionalNotifications::class)->dispatchPending());
        $this->assertDatabaseHas('transactional_notification_events', ['notification_id' => $notice->id, 'event' => 'redispatched']);
    }
}
