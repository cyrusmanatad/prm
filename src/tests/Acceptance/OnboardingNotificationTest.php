<?php

namespace Tests\Acceptance;

use App\Jobs\DeliverNotification;
use App\Mail\OnboardingInvitation;
use App\Models\User;
use App\Services\NotificationOutbox;
use App\Services\Onboarding;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;

class OnboardingNotificationTest extends AcceptanceCase
{
    private function invitation(bool $send = true): array
    {
        $user = User::factory()->create();
        $org = app(Workspaces::class)->create($user->id, 'Private creator');
        $this->actingAs($user)->withSession(['active_org_id' => $org]);
        $lead = $this->postJson('/pipeline', ['brand_name' => 'Private lead', 'notes' => 'PRIVATE NOTE', 'currency' => 'PHP', 'value_minor' => '123'])->assertCreated()->json('id');
        $recipient = 'onboarding-'.Str::uuid().'@example.test';
        $result = $this->withHeaders(['Host' => 'untrusted.example.test'])->postJson('/onboarding-requests', [
            'pipeline_entry_id' => $lead, 'label' => 'PRIVATE LABEL', 'send_email' => $send, 'recipient_email' => $recipient,
        ])->assertCreated()->json();
        $row = $this->control->table('notification_outbox')->where('onboarding_request_id', $result['id'])->first();

        return [$user, $org, $lead, $recipient, $result, $row];
    }

    private function state(string $id): object
    {
        return $this->control->table('notification_outbox')->where('id', $id)->first();
    }

    public function test_opt_in_validation_encryption_canonical_link_and_minimal_message(): void
    {
        Mail::fake();
        [, , $lead, , , $none] = $this->invitation(false);
        $this->assertNull($none);
        foreach (['', 'not an email', "victim@example.test\r\nBcc: other@example.test"] as $recipient) {
            $this->postJson('/onboarding-requests', ['pipeline_entry_id' => $lead, 'label' => 'Validation', 'send_email' => true, 'recipient_email' => $recipient])
                ->assertUnprocessable()->assertJsonValidationErrors('recipient_email');
        }
        $this->assertSame(1, $this->control->table('onboarding_requests')->where('pipeline_entry_id', $lead)->count());
        [$user, $org, , $recipient, $result, $row] = $this->invitation();
        $this->assertSame(['url' => $result['url'], 'recipient' => $recipient], decrypt($row->encrypted_payload));
        $this->assertStringNotContainsString($recipient, $row->encrypted_payload);
        $this->assertStringNotContainsString(explode('#', $result['url'])[1], $row->encrypted_payload);
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/onboarding#token=', $result['url']);
        $serialized = serialize(new DeliverNotification($user->id, $org, $row->id));
        $this->assertStringNotContainsString($recipient, $serialized);
        $this->assertStringNotContainsString(explode('#', $result['url'])[1], $serialized);
        app(NotificationOutbox::class)->deliver($user->id, $org, $row->id);
        app(NotificationOutbox::class)->deliver($user->id, $org, $row->id);
        Mail::assertSent(OnboardingInvitation::class, function ($mail) use ($recipient, $result) {
            $this->assertTrue($mail->hasTo($recipient));
            $body = $mail->render();
            foreach ([$result['url'], '24 hours', 'Anyone with the link', 'Open invitation'] as $text) {
                $this->assertStringContainsString($text, $body);
            }
            foreach (['PRIVATE LABEL', 'PRIVATE NOTE', 'Private creator', 'Private lead'] as $text) {
                $this->assertStringNotContainsString($text, $body);
            }

            return true;
        });
        Mail::assertSentCount(1);
        $this->assertSame('captured', $this->state($row->id)->state);
        $this->assertNull($this->state($row->id)->encrypted_payload);
        $this->getJson('/onboarding-requests?request='.$result['id'])->assertJsonPath('emails.0.state', 'captured')
            ->assertDontSee($recipient)->assertDontSee('encrypted_payload')->assertDontSee('invitation_hash');
        $token = explode('#token=', $result['url'])[1];
        $this->get('/onboarding')->assertOk(); // GET never consumes the capability.
        $this->postJson('/onboarding/session', ['token' => $token])->assertOk();
        $this->getJson('/onboarding/form/'.$result['id'])->assertOk()->assertJsonMissingPath('emails')->assertDontSee($recipient);
        $this->postJson('/onboarding/session', ['token' => $token])->assertNotFound();
    }

    public function test_replacement_generation_stale_requests_and_existing_sessions(): void
    {
        Mail::fake();
        [$user, $org, , , $result, $row] = $this->invitation();
        $token = explode('#token=', $result['url'])[1];
        $this->postJson('/onboarding/session', ['token' => $token])->assertOk();
        $replacement = $this->postJson('/onboarding-requests/'.$result['id'].'/renew', [
            'version' => 1, 'send_email' => true, 'recipient_email' => 'replacement@example.test',
        ])->assertOk()->json();
        $this->postJson('/onboarding-requests/'.$result['id'].'/renew', [
            'version' => 1, 'send_email' => true, 'recipient_email' => 'stale@example.test',
        ])->assertConflict();
        $this->assertSame(2, $this->control->table('notification_outbox')->where('onboarding_request_id', $result['id'])->count());
        $this->getJson('/onboarding/form/'.$result['id'])->assertForbidden();
        $this->postJson('/onboarding/session', ['token' => $token])->assertNotFound();
        app(NotificationOutbox::class)->deliver($user->id, $org, $row->id);
        $this->assertSame('cancelled', $this->state($row->id)->state);
        $fresh = $this->control->table('notification_outbox')->where('onboarding_request_id', $result['id'])->where('id', '!=', $row->id)->first();
        app(NotificationOutbox::class)->deliver($user->id, $org, $fresh->id);
        Mail::assertSent(OnboardingInvitation::class, fn ($mail) => $mail->hasTo('replacement@example.test') && $mail->invitationUrl === $replacement['url']);
        Mail::assertSentCount(1);
        $this->postJson('/onboarding/session', ['token' => explode('#token=', $replacement['url'])[1]])->assertOk();
        $this->postJson('/onboarding-requests/'.$result['id'].'/renew', ['version' => 2])->assertOk();
        $this->assertSame(2, $this->control->table('notification_outbox')->where('onboarding_request_id', $result['id'])->count());
    }

    public function test_obsolete_invites_and_current_authorization_cancel_before_send(): void
    {
        Mail::fake();
        foreach (['expired', 'revoked', 'consumed', 'replaced', 'membership', 'role', 'verification'] as $reason) {
            [$user, $org, , , $result, $row] = $this->invitation();
            if ($reason === 'expired') {
                $this->control->table('onboarding_requests')->where('id', $result['id'])->update(['expires_at' => now()->subSecond()]);
            } elseif ($reason === 'revoked') {
                $this->postJson('/onboarding-requests/'.$result['id'].'/revoke', ['version' => 1])->assertOk();
            } elseif ($reason === 'consumed') {
                $this->postJson('/onboarding/session', ['token' => explode('#token=', $result['url'])[1]])->assertOk();
            } elseif ($reason === 'replaced') {
                $this->postJson('/onboarding-requests/'.$result['id'].'/renew', ['version' => 1])->assertOk();
            } elseif ($reason === 'verification') {
                $this->control->table('users')->where('id', $user->id)->update(['email_verified_at' => null]);
            } else {
                $this->control->table('memberships')->where('org_id', $org)->update($reason === 'role' ? ['role' => 'viewer'] : ['revoked_at' => now()]);
            }
            // Delivery itself rechecks the invitation. Reconciliation also handles lost membership.
            app(NotificationOutbox::class)->deliver($user->id, $org, $row->id);
            DB::select('SELECT app_reconcile_notifications()');
            $this->assertSame('cancelled', $this->state($row->id)->state, $reason);
            $this->assertNull($this->state($row->id)->encrypted_payload);
        }
        Mail::assertNothingSent();
    }

    public function test_writer_roles_and_foreign_tenant_and_capability_isolation(): void
    {
        Mail::fake();
        [$owner, $org, , , $result, $row] = $this->invitation();
        [$foreign, $otherOrg] = $this->invitation(false);
        app(TenantContext::class)->run($foreign->id, $otherOrg, function () use ($row) {
            $this->assertSame(0, DB::table('notification_outbox')->where('id', $row->id)->count());
            $this->assertSame(0, DB::table('notification_outbox')->where('id', $row->id)->update(['state' => 'sent']));
            try {
                DB::transaction(fn () => DB::table('notification_outbox')->insert([
                    'id' => (string) Str::uuid(), 'org_id' => $row->org_id, 'actor_id' => $row->actor_id,
                    'kind' => $row->kind, 'onboarding_request_id' => $row->onboarding_request_id, 'invitation_hash' => $row->invitation_hash,
                ]));
                $this->fail('Foreign insert accepted');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0]);
            }
        });
        $this->postJson('/onboarding-requests/'.$result['id'].'/renew', ['version' => 1, 'send_email' => true, 'recipient_email' => 'foreign@example.test'])->assertNotFound();
        $secret = app(Onboarding::class)->exchange(explode('#token=', $result['url'])[1]);
        app(Onboarding::class)->publicRun($secret, fn () => $this->assertSame(0, DB::table('notification_outbox')->count()));
        foreach (['admin', 'manager', 'member', 'viewer'] as $role) {
            [$user, $workspace, $lead, , $invitation] = $this->invitation(false);
            $this->control->table('memberships')->where('org_id', $workspace)->update(['role' => $role]);
            $response = $this->postJson('/onboarding-requests/'.$invitation['id'].'/renew', ['version' => 1, 'send_email' => true, 'recipient_email' => 'writer@example.test']);
            if ($role === 'viewer') {
                $response->assertForbidden();
                app(TenantContext::class)->run($user->id, $workspace, fn () => $this->assertSame(0, DB::table('notification_outbox')->count()));
            } else {
                $response->assertOk();
                $intent = $this->control->table('notification_outbox')->where('onboarding_request_id', $invitation['id'])->first();
                app(NotificationOutbox::class)->deliver($user->id, $workspace, $intent->id);
                $this->assertSame('captured', $this->state($intent->id)->state);
            }
        }
    }

    public function test_atomic_rollback_generation_uniqueness_and_composite_target(): void
    {
        [$user, $org, $lead, , $result, $row] = $this->invitation();
        $before = $this->control->table('onboarding_requests')->where('org_id', $org)->count();
        try {
            app(TenantContext::class)->run($user->id, $org, function () use ($user, $org, $lead) {
                app(Onboarding::class)->create($user->id, $org, $lead, 'Rollback', 'rollback@example.test');
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }
        $this->assertSame($before, $this->control->table('onboarding_requests')->where('org_id', $org)->count());
        $this->assertSame(1, $this->control->table('notification_outbox')->where('org_id', $org)->count());
        [, , , , $foreign] = $this->invitation(false);
        app(TenantContext::class)->run($user->id, $org, function () use ($user, $org, $result, $foreign) {
            foreach ([$result['id'] => '23505', $foreign['id'] => '23503'] as $request => $code) {
                try {
                    DB::transaction(fn () => app(NotificationOutbox::class)->onboardingInvitation($user->id, $org, $request, explode('#token=', $result['url'])[1], $result['url'], 'duplicate@example.test'));
                    $this->fail('Invalid intent accepted');
                } catch (QueryException $e) {
                    $this->assertSame($code, $e->errorInfo[0]);
                }
            }
        });
    }

    public function test_redis_loss_recovery_and_queue_replay_capture_once(): void
    {
        [$user, $org, , $recipient, $result, $row] = $this->invitation();
        $name = 'onboarding-test-'.$row->id;
        $queue = Queue::connection('redis');
        try {
            $queue->push(new DeliverNotification($user->id, $org, $row->id), '', $name);
            $queue->clear($name);
            $pending = collect(DB::select('SELECT * FROM app_pending_notifications()'))->firstWhere('id', $row->id);
            $this->assertNotNull($pending);
            for ($i = 0; $i < 2; $i++) {
                $queue->push(new DeliverNotification($pending->actor_id, $pending->org_id, $pending->id), '', $name);
                $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--queue' => $name]));
            }
            $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
            $this->assertCount(1, $messages);
            $mail = $messages->first()->getOriginalMessage();
            $this->assertSame($recipient, $mail->getTo()[0]->getAddress());
            $this->assertStringContainsString($result['url'], $mail->getTextBody());
            $this->assertSame('captured', $this->state($row->id)->state);
        } finally {
            $queue->clear($name);
        }
    }

    public function test_uncertain_delivery_is_not_retried_and_stale_reservation_is_reconciled(): void
    {
        [$user, $org, , , , $row] = $this->invitation();
        Mail::shouldReceive('mailer->to->send')->once()->andThrow(new RuntimeException('Sensitive error'));
        app(NotificationOutbox::class)->deliver($user->id, $org, $row->id);
        app(NotificationOutbox::class)->deliver($user->id, $org, $row->id);
        $this->assertSame('uncertain', $this->state($row->id)->state);
        $this->assertNull($this->state($row->id)->encrypted_payload);
        [$user, $org, , , , $crashed] = $this->invitation();
        $this->control->table('notification_outbox')->where('id', $crashed->id)->update(['state' => 'sending', 'started_at' => now()->subMinutes(6)]);
        DB::select('SELECT app_reconcile_notifications()');
        app(NotificationOutbox::class)->deliver($user->id, $org, $crashed->id);
        $this->assertSame('uncertain', $this->state($crashed->id)->state);
    }
}
