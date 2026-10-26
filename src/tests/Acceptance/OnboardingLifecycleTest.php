<?php

namespace Tests\Acceptance;

use App\Jobs\DeliverNotification;
use App\Mail\OnboardingLifecycle;
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

class OnboardingLifecycleTest extends AcceptanceCase
{
    private function request(bool $notify = true): array
    {
        $user = User::factory()->create();
        $org = app(Workspaces::class)->create($user->id, 'PRIVATE WORKSPACE');
        $this->actingAs($user)->withSession(['active_org_id' => $org]);
        $lead = $this->postJson('/pipeline', ['brand_name' => 'PRIVATE BRAND', 'notes' => 'PRIVATE CRM', 'currency' => 'PHP', 'value_minor' => '123'])->assertCreated()->json('id');
        $invitation = $this->postJson('/onboarding-requests', [
            'pipeline_entry_id' => $lead, 'label' => 'PRIVATE LABEL', 'notify_submission' => $notify,
            'submission_notify_user_id' => 999999, // Caller cannot nominate another account.
        ])->assertCreated()->json();
        $this->postJson('/onboarding/session', ['token' => explode('#token=', $invitation['url'])[1]])->assertOk();

        return [$user, $org, $invitation['id']];
    }

    private function submission(string $id, int $version = 1, ?string $key = null): array
    {
        $data = ['version' => $version, 'idempotency_key' => $key ?? (string) Str::uuid(), 'consent' => true,
            'business_name' => 'PRIVATE BUSINESS', 'contact_name' => 'PRIVATE CONTACT', 'business_email' => 'untrusted@example.test', 'brief' => 'PRIVATE BRIEF'];
        $this->postJson('/onboarding/form/'.$id.'/submit', $data)->assertOk();

        return $data;
    }

    private function notice(string $id, string $kind): ?object
    {
        return $this->control->table('notification_outbox')->where('onboarding_request_id', $id)->where('kind', 'onboarding.'.$kind)->orderByDesc('onboarding_version')->first();
    }

    private function outcome(string $id, string $action = 'correct', int $version = 2): object
    {
        $this->withHeaders(['Host' => 'untrusted.example.test'])->postJson('/onboarding-requests/'.$id.'/'.$action, [
            'version' => $version, 'note' => 'PRIVATE CORRECTION', 'send_email' => true, 'recipient_email' => 'explicit@example.test',
        ])->assertOk()->assertJsonPath('message', ($action === 'correct' ? 'Correction requested' : 'Onboarding accepted').'. Outcome email queued for explicit@example.test');

        return $this->notice($id, $action === 'correct' ? 'corrected' : 'accepted');
    }

    private function deliver(object $notice): object
    {
        app(NotificationOutbox::class)->deliver($notice->actor_id, $notice->org_id, $notice->id);

        return $this->control->table('notification_outbox')->where('id', $notice->id)->first();
    }

    private function assertDeniedMutation(callable $mutation): void
    {
        try {
            $this->assertSame(0, DB::transaction($mutation));
        } catch (QueryException $e) {
            // Fresh migrations deny the table privilege; a blanket bootstrap
            // grant still cannot bypass the table's missing mutation policies.
            $this->assertSame('42501', $e->errorInfo[0]);
        }
    }

    public function test_submission_opt_in_current_recipient_minimal_content_and_idempotency(): void
    {
        Mail::fake();
        [, , $none] = $this->request(false);
        $this->submission($none);
        $this->assertNull($this->notice($none, 'submitted'));
        [$user, $org, $id] = $this->request();
        $data = $this->submission($id);
        $this->postJson('/onboarding/form/'.$id.'/submit', $data)->assertOk();
        $this->assertSame(1, $this->control->table('notification_outbox')->where('onboarding_request_id', $id)->count());
        $notice = $this->notice($id, 'submitted');
        $this->assertSame($user->id, $notice->actor_id);
        $this->assertNull($notice->encrypted_payload);
        $this->assertSame('captured', $this->deliver($notice)->state);
        $this->deliver($notice);
        Mail::assertSent(OnboardingLifecycle::class, function ($mail) use ($user, $id) {
            $this->assertTrue($mail->hasTo($user->email));
            $this->assertSame(rtrim(config('app.url'), '/').'/partner/onboarding?request='.$id, $mail->returnUrl);
            $body = $mail->render();
            foreach (['PRIVATE', 'untrusted@example.test', 'token=', 'untrusted.example.test'] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
            $this->assertStringContainsString('select the workspace', $body);

            return true;
        });
        Mail::assertSentCount(1);
    }

    public function test_outcome_requires_explicit_address_and_preserves_state_on_validation_failure(): void
    {
        [$user, $org, $id] = $this->request(false);
        $this->submission($id);
        foreach (['', 'bad', "victim@example.test\r\nBcc: other@example.test"] as $recipient) {
            $this->postJson('/onboarding-requests/'.$id.'/correct', ['version' => 2, 'note' => 'Correct this', 'send_email' => true, 'recipient_email' => $recipient])
                ->assertUnprocessable()->assertJsonValidationErrors('recipient_email');
        }
        $this->assertSame('submitted', $this->control->table('onboarding_requests')->where('id', $id)->value('status'));
        $this->postJson('/onboarding-requests/'.$id.'/accept', ['version' => 2])->assertOk();
        $this->assertNull($this->notice($id, 'accepted'));
        [, , $id] = $this->request(false);
        $this->submission($id);
        $this->control->table('onboarding_requests')->where('id', $id)->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/onboarding-requests/'.$id.'/accept', ['version' => 2, 'send_email' => true, 'recipient_email' => 'explicit@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('recipient_email');
        $this->assertSame(2, $this->control->table('onboarding_requests')->where('id', $id)->value('version'));
    }

    public function test_fresh_return_link_survives_old_session_expiry_is_single_use_and_scoped(): void
    {
        Mail::fake();
        [$user, $org, $id] = $this->request();
        $this->submission($id);
        $notice = $this->outcome($id);
        $payload = decrypt($notice->encrypted_payload);
        $this->assertSame('explicit@example.test', $payload['recipient']);
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/onboarding#token=', $payload['url']);
        $this->assertStringNotContainsString('explicit@example.test', $notice->encrypted_payload);
        $token = explode('#token=', $payload['url'])[1];
        $this->assertSame(hash('sha256', $token), $this->control->table('onboarding_return_links')->where('notification_id', $notice->id)->value('token_hash'));
        $serialized = serialize(new DeliverNotification($user->id, $org, $notice->id));
        $this->assertStringNotContainsString($token, $serialized);
        $this->assertSame('captured', $this->deliver($notice)->state);
        Mail::assertSent(OnboardingLifecycle::class, function ($mail) {
            $this->assertTrue($mail->hasTo('explicit@example.test'));
            $this->assertStringContainsString('24 hours', $mail->render());
            $this->assertStringNotContainsString('PRIVATE', $mail->render());

            return true;
        });
        $this->control->table('onboarding_sessions')->where('request_id', $id)->update(['expires_at' => now()->subSecond()]);
        $this->getJson('/onboarding/form/'.$id)->assertForbidden();
        auth()->logout();
        $this->get('/onboarding')->assertOk();
        $this->postJson('/onboarding/session', ['token' => $token])->assertOk()->assertJsonPath('id', $id);
        $this->postJson('/onboarding/session', ['token' => $token])->assertNotFound();
        $this->getJson('/onboarding/form/'.$id)->assertOk()->assertJsonPath('form.correction_note', 'PRIVATE CORRECTION')
            ->assertJsonMissingPath('form.submission_notify_user_id')->assertJsonMissingPath('emails');
        $this->submission($id, 3);
        $this->actingAs($user)->withSession(['active_org_id' => $org]);
        $accepted = $this->outcome($id, 'accept', 4);
        $acceptToken = explode('#token=', decrypt($accepted->encrypted_payload)['url'])[1];
        $this->deliver($accepted);
        $this->postJson('/onboarding/session', ['token' => $acceptToken])->assertOk();
        $this->getJson('/onboarding/form/'.$id)->assertJsonPath('form.status', 'accepted');
        $this->patchJson('/onboarding/form/'.$id, ['version' => 5, 'brief' => 'forged'])->assertConflict();
        $this->postJson('/onboarding-requests/'.$id.'/revoke', ['version' => 5])->assertOk();
        $this->getJson('/onboarding/form/'.$id)->assertForbidden();
    }

    public function test_obsolete_notifications_and_links_are_cancelled_for_current_state_and_authorization(): void
    {
        Mail::fake();
        foreach (['expired', 'revoked', 'replaced', 'edited', 'membership', 'role', 'verification', 'link_expired', 'link_consumed'] as $reason) {
            [$user, $org, $id] = $this->request();
            $this->submission($id);
            $notice = $this->outcome($id);
            $token = explode('#token=', decrypt($notice->encrypted_payload)['url'])[1];
            if ($reason === 'revoked') {
                $this->postJson('/onboarding-requests/'.$id.'/revoke', ['version' => 3])->assertOk();
            } elseif ($reason === 'replaced') {
                $this->postJson('/onboarding-requests/'.$id.'/renew', ['version' => 3])->assertOk();
            } elseif ($reason === 'expired') {
                $this->control->table('onboarding_requests')->where('id', $id)->update(['expires_at' => now()->subSecond()]);
            } elseif ($reason === 'edited') {
                $this->patchJson('/onboarding/form/'.$id, ['version' => 3, 'brief' => 'Edited'])->assertOk();
            } elseif ($reason === 'verification') {
                $this->control->table('users')->where('id', $user->id)->update(['email_verified_at' => null]);
            } elseif (str_starts_with($reason, 'link_')) {
                $this->control->table('onboarding_return_links')->where('notification_id', $notice->id)
                    ->update($reason === 'link_expired' ? ['expires_at' => now()->subSecond()] : ['consumed_at' => now()]);
            } else {
                $this->control->table('memberships')->where('org_id', $org)->update($reason === 'role' ? ['role' => 'viewer'] : ['revoked_at' => now()]);
            }
            $this->deliver($notice);
            DB::select('SELECT app_reconcile_notifications()');
            $this->assertSame('cancelled', $this->control->table('notification_outbox')->where('id', $notice->id)->value('state'), $reason);
            $this->postJson('/onboarding/session', ['token' => $token])->assertNotFound();
        }
        Mail::assertNothingSent();
    }

    public function test_reconciliation_alone_cancels_stale_outcomes_and_submitted_notices(): void
    {
        [$user, $org, $id] = $this->request();
        $this->submission($id);
        $submitted = $this->notice($id, 'submitted');
        $outcome = $this->outcome($id);
        DB::select('SELECT app_reconcile_notifications()');
        $this->assertSame('cancelled', $this->control->table('notification_outbox')->where('id', $submitted->id)->value('state'));
        $this->postJson('/onboarding-requests/'.$id.'/renew', ['version' => 3])->assertOk();
        DB::select('SELECT app_reconcile_notifications()');
        $this->assertSame('cancelled', $this->control->table('notification_outbox')->where('id', $outcome->id)->value('state'));
        $this->assertNull($this->control->table('onboarding_requests')->where('id', $id)->value('submission_notify_user_id'));
    }

    public function test_capability_and_foreign_workspace_cannot_read_or_forge_lifecycle_intent(): void
    {
        [$owner, $org, $id] = $this->request();
        $this->submission($id);
        $outcome = $this->outcome($id);
        $secret = app(Onboarding::class)->exchange(explode('#token=', decrypt($outcome->encrypted_payload)['url'])[1]);
        app(Onboarding::class)->publicRun($secret, function () {
            $this->assertSame(0, DB::table('notification_outbox')->count());
            $this->assertSame(0, DB::table('onboarding_return_links')->count());
        });
        [$foreign, $other, $otherId] = $this->request(false);
        app(TenantContext::class)->run($foreign->id, $other, function () use ($outcome, $id) {
            $this->assertSame(0, DB::table('notification_outbox')->where('id', $outcome->id)->count());
            $this->assertSame(0, DB::table('onboarding_return_links')->where('request_id', $id)->count());
            $this->assertDeniedMutation(fn () => DB::table('onboarding_return_links')->where('request_id', $id)->update(['consumed_at' => now()]));
            DB::select('SELECT app_queue_onboarding_submitted(?::uuid,?::uuid)', [$id, (string) Str::uuid()]);
            try {
                DB::transaction(fn () => DB::table('onboarding_return_links')->insert([
                    'id' => (string) Str::uuid(), 'org_id' => $outcome->org_id, 'request_id' => $id, 'notification_id' => $outcome->id,
                    'token_hash' => hash('sha256', 'forged'), 'expires_at' => now()->addHour(),
                ]));
                $this->fail('Foreign insert accepted');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0]);
            }
        });
        $this->postJson('/onboarding-requests/'.$id.'/accept', ['version' => 3, 'send_email' => true, 'recipient_email' => 'foreign@example.test'])->assertNotFound();
        $this->assertSame(2, $this->control->table('notification_outbox')->where('onboarding_request_id', $id)->count());
    }

    public function test_review_and_submission_intent_roll_back_with_their_events(): void
    {
        [$user, $org, $id] = $this->request();
        $data = $this->submission($id);
        $events = $this->control->table('onboarding_events')->where('request_id', $id)->count();
        try {
            app(TenantContext::class)->run($user->id, $org, function () use ($user, $org, $id) {
                app(Onboarding::class)->review($user->id, $org, $id, 2, 'correct', 'Rollback', 'rollback@example.test');
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }
        $this->assertSame('submitted', $this->control->table('onboarding_requests')->where('id', $id)->value('status'));
        $this->assertSame($events, $this->control->table('onboarding_events')->where('request_id', $id)->count());
        $this->assertNull($this->notice($id, 'corrected'));
        $this->assertSame(0, $this->control->table('onboarding_return_links')->where('request_id', $id)->count());
        $this->outcome($id);
        $token = explode('#token=', decrypt($this->notice($id, 'corrected')->encrypted_payload)['url'])[1];
        $secret = app(Onboarding::class)->exchange($token);
        try {
            app(Onboarding::class)->publicRun($secret, function () use ($org, $id, $data) {
                app(Onboarding::class)->submit($org, $id, 3, (string) Str::uuid(), array_intersect_key($data, array_flip(['business_name', 'contact_name', 'business_email', 'brief'])));
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }
        $this->assertSame('correction', $this->control->table('onboarding_requests')->where('id', $id)->value('status'));
        $this->assertSame(1, $this->control->table('onboarding_submissions')->where('request_id', $id)->count());
        $this->assertSame(1, $this->control->table('notification_outbox')->where('onboarding_request_id', $id)->where('kind', 'onboarding.submitted')->count());
    }

    public function test_schema_enforces_rls_function_privileges_and_composite_notification_target(): void
    {
        [$user, $org, $id] = $this->request(false);
        $this->submission($id);
        $outcome = $this->outcome($id);
        $catalog = DB::selectOne("SELECT relrowsecurity,relforcerowsecurity FROM pg_class WHERE oid='onboarding_return_links'::regclass");
        $this->assertTrue($catalog->relrowsecurity);
        $this->assertTrue($catalog->relforcerowsecurity);
        $public = DB::selectOne("SELECT count(*) AS count FROM pg_proc p,aclexplode(p.proacl) a WHERE p.proname IN ('app_queue_onboarding_submitted','app_exchange_onboarding_return') AND a.grantee=0");
        $this->assertSame(0, $public->count);
        [, , $foreignId] = $this->request(false);
        app(TenantContext::class)->run($user->id, $org, function () use ($user, $id, $org, $outcome, $foreignId) {
            $this->assertDeniedMutation(fn () => DB::table('onboarding_return_links')->where('request_id', $id)->update(['consumed_at' => now()]));
            $this->assertDeniedMutation(fn () => DB::table('onboarding_return_links')->where('request_id', $id)->delete());
            $notice = (string) Str::uuid();
            DB::table('notification_outbox')->insert([
                'id' => $notice, 'org_id' => $org, 'actor_id' => $user->id, 'kind' => 'onboarding.accepted',
                'onboarding_request_id' => $id, 'onboarding_version' => 99, 'invitation_hash' => $outcome->invitation_hash, 'state' => 'cancelled',
            ]);
            try {
                DB::transaction(fn () => DB::table('onboarding_return_links')->insert([
                    'id' => (string) Str::uuid(), 'org_id' => $org, 'request_id' => $foreignId, 'notification_id' => $notice,
                    'token_hash' => hash('sha256', 'composite'), 'expires_at' => now()->addHour(),
                ]));
                $this->fail('Mismatched notification target accepted');
            } catch (QueryException $e) {
                $this->assertSame('23503', $e->errorInfo[0]);
            }
        });
    }

    public function test_writer_roles_and_current_revocation_protect_submitted_notifications(): void
    {
        Mail::fake();
        foreach (['owner', 'admin', 'manager', 'member', 'viewer', 'revoked', 'unverified'] as $role) {
            [$user, $org, $id] = $this->request();
            $this->control->table('memberships')->where('org_id', $org)->update($role === 'revoked' ? ['revoked_at' => now()] : ['role' => $role === 'unverified' ? 'owner' : $role]);
            if ($role === 'unverified') {
                $this->control->table('users')->where('id', $user->id)->update(['email_verified_at' => null]);
            }
            $this->submission($id);
            $notice = $this->notice($id, 'submitted');
            if (in_array($role, ['viewer', 'revoked', 'unverified'], true)) {
                $this->assertNull($notice, $role);
            } else {
                $this->assertSame('captured', $this->deliver($notice)->state);
            }
        }
        Mail::assertSentCount(4);
    }

    public function test_redis_loss_replay_and_uncertain_delivery_reuse_durable_semantics(): void
    {
        [$user, $org, $id] = $this->request(false);
        $this->submission($id);
        $notice = $this->outcome($id, 'accept');
        $queue = Queue::connection('redis');
        $name = 'lifecycle-test-'.$notice->id;
        try {
            $queue->push(new DeliverNotification($user->id, $org, $notice->id), '', $name);
            $queue->clear($name);
            $this->assertNotNull(collect(DB::select('SELECT * FROM app_pending_notifications()'))->firstWhere('id', $notice->id));
            for ($i = 0; $i < 2; $i++) {
                $queue->push(new DeliverNotification($user->id, $org, $notice->id), '', $name);
                $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--queue' => $name]));
            }
            $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
            $this->assertCount(1, $messages);
            $this->assertSame('explicit@example.test', $messages->first()->getOriginalMessage()->getTo()[0]->getAddress());
        } finally {
            $queue->clear($name);
        }
        [, , $id] = $this->request(false);
        $this->submission($id);
        $uncertain = $this->outcome($id);
        Mail::shouldReceive('mailer->to->send')->once()->andThrow(new RuntimeException('Sensitive transport detail'));
        $this->assertSame('uncertain', $this->deliver($uncertain)->state);
        $this->assertNull($this->deliver($uncertain)->encrypted_payload);
    }
}
