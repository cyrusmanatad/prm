<?php

namespace Tests\Acceptance;

use App\Jobs\DeliverNotification;
use App\Jobs\MaterializeSchedule;
use App\Models\User;
use App\Services\BusinessSchedules;
use App\Services\NotificationOutbox;
use App\Services\PlatformAdministration;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BrandFixture;

class PlatformGovernanceTest extends AcceptanceCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private const PASSWORD = 'SyntheticPassword123!';

    private function operator(string $permissions): User
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $this->control->table('platform_grants')->insert(['user_id' => $user->id, 'permissions' => $permissions]);
        $this->control->table('users')->where('id', $user->id)->update(['admin_totp_secret' => encrypt(self::SECRET), 'admin_totp_confirmed_at' => now()]);

        return $user->refresh();
    }

    private function verified(User $user): static
    {
        return $this->actingAs($user)->withSession(['platform_mfa' => ['user' => $user->id, 'version' => 1, 'until' => time() + 900]]);
    }

    /** Reason plus fresh proof. Replay protection is covered in PlatformConsoleTest, so consumed steps are cleared here. */
    private function proof(array $extra = []): array
    {
        $this->control->table('users')->whereNotNull('admin_totp_counter')->update(['admin_totp_counter' => null]);

        return [...['reason' => 'Synthetic governance check', 'password' => self::PASSWORD, 'code' => app(PlatformAdministration::class)->code(self::SECRET, intdiv(time(), 30))], ...$extra];
    }

    private function workspace(string $kind = 'creator'): array
    {
        $owner = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);

        $name = 'Governance '.Str::uuid();

        return [$owner, $kind === 'brand' ? BrandFixture::create($owner->id, $name, $this->control) : app(Workspaces::class)->create($owner->id, $name)];
    }

    private function member(User $user, string $org): static
    {
        return $this->actingAs($user)->withSession(['active_org_id' => $org]);
    }

    public function test_account_suspension_ends_sessions_refuses_sign_in_and_blocks_every_workspace_until_reactivated(): void
    {
        [$owner, $org] = $this->workspace();
        $peer = User::factory()->create();
        $this->control->table('memberships')->insert(['org_id' => $org, 'user_id' => $peer->id, 'role' => 'admin']);
        $this->control->table('sessions')->insert(['id' => 'governance-'.Str::uuid(), 'user_id' => $owner->id, 'payload' => '', 'last_activity' => time()]);
        $this->member($owner, $org)->getJson('/pipeline')->assertOk();

        $scoped = $this->operator('{directory.read}');
        $this->verified($scoped)->postJson('/admin/accounts/'.$owner->id.'/suspend', $this->proof(['expected_version' => 0]))->assertForbidden();
        $manager = $this->operator('{directory.read,access.manage}');
        $this->verified($manager);
        $this->getJson('/admin/accounts/'.$owner->id)->assertOk()->assertJsonPath('access.suspended', false)->assertJsonPath('access.sessions', 1)->assertJsonPath('access.sole_owner_of.0.id', $org);
        $this->postJson('/admin/accounts/'.$owner->id.'/suspend', ['expected_version' => 0, 'reason' => 'No proof'])->assertUnprocessable();
        $this->postJson('/admin/accounts/'.$owner->id.'/suspend', $this->proof(['expected_version' => 0, 'code' => '000000']))->assertUnprocessable();
        $this->postJson('/admin/accounts/'.$owner->id.'/suspend', $this->proof(['expected_version' => 4]))->assertConflict();
        $this->postJson('/admin/accounts/'.$manager->id.'/suspend', $this->proof(['expected_version' => 0]))->assertUnprocessable();
        $this->assertSame(0, $this->control->table('platform_suspensions')->where('user_id', $owner->id)->count());

        $this->postJson('/admin/accounts/'.$owner->id.'/suspend', $this->proof(['expected_version' => 0]))->assertOk();
        $this->postJson('/admin/accounts/'.$owner->id.'/suspend', $this->proof(['expected_version' => 1]))->assertConflict();
        $this->assertSame(0, $this->control->table('sessions')->where('user_id', $owner->id)->count());
        $this->assertSame(1, $this->control->table('platform_audit')->where(['event' => 'account.suspended', 'target' => (string) $owner->id, 'actor_id' => $manager->id])->count());
        $this->getJson('/admin/accounts?access=suspended&q='.urlencode($owner->email))->assertOk()->assertJsonPath('accounts.total', 1)->assertJsonPath('accounts.rows.0.suspended', true);

        // An existing session is ended on its next request, sign-in is refused, and tenant policies deny the actor.
        $this->member($owner, $org)->getJson('/pipeline')->assertForbidden()->assertJsonPath('message', "This account's access is suspended. Contact support.");
        $this->assertGuest();
        $this->postJson('/login', ['email' => $owner->email, 'password' => self::PASSWORD])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();
        try {
            app(TenantContext::class)->run($owner->id, $org, fn () => DB::table('pipeline_entries')->count());
            $this->fail('A suspended account opened a workspace transaction.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        // The workspace keeps working for its other members.
        $this->member($peer, $org)->getJson('/pipeline')->assertOk();

        $this->control->table('memberships')->where(['org_id' => $org, 'user_id' => $owner->id])->update(['revoked_at' => now()]);
        $this->verified($manager)->postJson('/admin/accounts/'.$owner->id.'/reactivate', $this->proof(['expected_version' => 0]))->assertConflict();
        $this->postJson('/admin/accounts/'.$owner->id.'/reactivate', $this->proof(['expected_version' => 1]))->assertOk();
        auth()->guard('web')->logout();
        $this->postJson('/login', ['email' => $owner->email, 'password' => self::PASSWORD])->assertOk();
        // Reactivation restores sign-in only; the membership revoked meanwhile stays revoked.
        $this->member($owner, $org)->getJson('/pipeline')->assertForbidden();
        $this->assertNotNull($this->control->table('platform_suspensions')->where('user_id', $owner->id)->value('lifted_at'));
    }

    public function test_workspace_suspension_blocks_members_public_links_and_queued_work_without_replay(): void
    {
        [$owner, $org] = $this->workspace('brand');
        $invitation = $this->member($owner, $org)->postJson('/partner-invitations', ['email' => 'governance-'.Str::uuid().'@example.test', 'send_email' => true])->assertCreated()->json('id');
        $notice = $this->control->table('notification_outbox')->where('subject_id', $invitation)->first();
        $this->assertSame('pending', $notice->state);
        [$creator, $creatorOrg] = $this->workspace();
        $this->member($creator, $creatorOrg)->postJson('/lead-links')->assertCreated();
        $link = $this->control->table('lead_links')->where('org_id', $creatorOrg)->value('token_hash');

        $manager = $this->operator('{directory.read,access.manage,support.request}');
        $this->verified($manager);
        $this->getJson('/admin/workspaces/'.$org)->assertOk()->assertJsonPath('access.pending_notifications', 1)->assertJsonPath('access.members', 1);
        $support = $this->postJson('/admin/support-access', ['workspace' => $org, 'purpose' => 'Before suspension', 'hours' => 2, 'resources' => ['reminders']])->assertCreated()->json('id');
        $this->postJson('/admin/workspaces/'.$org.'/suspend', $this->proof(['expected_version' => 0]))->assertOk();
        $this->postJson('/admin/workspaces/'.$creatorOrg.'/suspend', $this->proof(['expected_version' => 0]))->assertOk();

        $this->assertSame('cancelled', $this->control->table('notification_outbox')->where('id', $notice->id)->value('state'));
        $this->assertNull($this->control->table('notification_outbox')->where('id', $notice->id)->value('encrypted_payload'));
        $this->assertSame('revoked', $this->control->table('support_grants')->where('id', $support)->value('state'));
        $this->postJson('/admin/support-access', ['workspace' => $org, 'purpose' => 'While suspended', 'hours' => 2, 'resources' => ['reminders']])->assertUnprocessable();
        $this->assertTrue((bool) DB::selectOne("SELECT app_public_link_suspended('lead',?) AS s", [$link])->s);
        $this->assertFalse((bool) DB::selectOne("SELECT app_public_link_suspended('lead',?) AS s", [hash('sha256', 'unknown')])->s);

        // Members can still sign in, but every route, policy and worker transaction for the workspace is denied.
        $this->member($owner, $org)->getJson('/pipeline')->assertForbidden();
        $this->get('/pipeline')->assertForbidden()->assertSee('access is suspended');
        $this->getJson('/workspaces')->assertOk();
        $this->postJson('/partner-invitations', ['email' => 'later-'.Str::uuid().'@example.test'])->assertForbidden();
        try {
            app(TenantContext::class)->run($owner->id, $org, fn () => DB::table('notification_outbox')->count());
            $this->fail('A worker transaction ran for a suspended workspace.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertFalse((bool) app(TenantContext::class)->run($owner->id, null, fn () => DB::selectOne('SELECT app_has_membership(?::uuid) AS ok', [$org])->ok));

        $this->verified($manager)->postJson('/admin/workspaces/'.$org.'/reactivate', $this->proof(['expected_version' => 1]))->assertOk();
        $this->member($owner, $org)->getJson('/pipeline')->assertOk();
        // Cancelled work and ended support access stay ended after reactivation.
        $this->assertSame('cancelled', $this->control->table('notification_outbox')->where('id', $notice->id)->value('state'));
        $this->assertSame('revoked', $this->control->table('support_grants')->where('id', $support)->value('state'));
        $this->assertSame(['workspace.suspended', 'workspace.reactivated'], $this->control->table('platform_audit')->where('target', $org)->whereIn('event', ['workspace.suspended', 'workspace.reactivated'])->orderBy('id')->pluck('event')->all());
    }

    public function test_operators_can_be_suspended_but_never_the_last_usable_owner_and_sessions_can_be_signed_out_alone(): void
    {
        $manager = $this->operator('{directory.read,access.manage}');
        $target = $this->operator('{directory.read}');
        $this->control->table('sessions')->insert([['id' => 'signout-'.Str::uuid(), 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()], ['id' => 'signout-'.Str::uuid(), 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]]);
        $this->verified($manager)->postJson('/admin/accounts/'.$target->id.'/sign-out', $this->proof())->assertOk()->assertJsonPath('ended', 2);
        $this->postJson('/admin/accounts/'.$manager->id.'/sign-out', $this->proof())->assertUnprocessable();
        $this->assertSame(1, $this->control->table('platform_audit')->where(['event' => 'account.signed_out', 'target' => (string) $target->id])->count());
        $this->assertSame(0, $this->control->table('platform_suspensions')->where('user_id', $target->id)->count());

        $this->verified($target)->getJson('/admin/accounts')->assertOk();
        $this->verified($manager)->postJson('/admin/accounts/'.$target->id.'/suspend', $this->proof(['expected_version' => 0]))->assertOk();
        // The grant is kept, but a suspended operator cannot use it even with an unexpired proof.
        $this->assertNull($this->control->table('platform_grants')->where('user_id', $target->id)->value('revoked_at'));
        $this->assertFalse((bool) app(TenantContext::class)->run($target->id, null, function () {
            DB::select("SELECT set_config('app.admin_version','1',true)");

            return DB::selectOne("SELECT app_platform_allowed('directory.read') AS ok")->ok;
        }));

        // With every other owner suspended or revoked, the remaining owner is protected.
        $this->control->transaction(function () use ($manager) {
            $owner = $this->operator('{platform.owner}');
            $others = $this->control->table('platform_grants')->where('user_id', '<>', $owner->id)->whereNull('revoked_at')->whereRaw("'platform.owner'=ANY(permissions)")->pluck('user_id');
            $this->control->table('platform_grants')->whereIn('user_id', $others)->update(['revoked_at' => now()]);
            $impact = $this->control->selectOne("SELECT set_config('app.actor_id',?,true),set_config('app.admin_version','1',true)", [(string) $manager->id]);
            try {
                $this->control->select("SELECT app_platform_suspend('account',?,0,'Last owner attempt')", [(string) $owner->id]);
                $this->fail('The last usable platform owner was suspended.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('last usable platform owner', $error->getMessage());
            }
            $this->control->rollBack();
            $this->control->beginTransaction();
        });
    }

    public function test_support_access_needs_owner_approval_stays_in_scope_expires_and_records_every_view(): void
    {
        [$owner, $org] = $this->workspace();
        $admin = User::factory()->create();
        $this->control->table('memberships')->insert(['org_id' => $org, 'user_id' => $admin->id, 'role' => 'admin']);
        [$stranger, $otherOrg] = $this->workspace();
        $this->member($owner, $org)->postJson('/pipeline', ['brand_name' => 'Support Visible Brand', 'contact_name' => 'Visible Contact', 'contact_email' => 'visible@example.test', 'notes' => 'PRIVATE-SUPPORT-NOTE', 'value_minor' => '1200', 'currency' => 'USD'])->assertCreated();

        $operator = $this->operator('{support.request}');
        $colleague = $this->operator('{support.request}');
        $this->verified($this->operator('{directory.read}'))->postJson('/admin/support-access', ['workspace' => $org, 'purpose' => 'No capability', 'hours' => 1, 'resources' => ['reminders']])->assertForbidden();
        $this->verified($operator);
        foreach ([['hours' => 25], ['hours' => 0], ['resources' => ['everything']], ['resources' => []], ['purpose' => '  ']] as $invalid) {
            $this->postJson('/admin/support-access', [...['workspace' => $org, 'purpose' => 'Investigate a stuck lead', 'hours' => 4, 'resources' => ['pipeline_entries']], ...$invalid])->assertUnprocessable();
        }
        $grant = $this->postJson('/admin/support-access', ['workspace' => $org, 'purpose' => 'Investigate a stuck lead', 'hours' => 4, 'resources' => ['pipeline_entries']])->assertCreated()->json('id');
        $this->postJson('/admin/support-access', ['workspace' => $org, 'purpose' => 'Duplicate', 'hours' => 1, 'resources' => ['reminders']])->assertConflict();

        // Requested is not access: nothing is readable until an owner approves.
        $this->getJson('/admin/support-access/'.$grant.'?area=pipeline_entries')->assertOk()->assertJsonPath('grant.state', 'requested')->assertJsonPath('records', null);
        $denied = function (User $actor, string $area) use ($grant): void {
            try {
                app(TenantContext::class)->run($actor->id, null, function () use ($grant, $area) {
                    DB::select("SELECT set_config('app.admin_version','1',true)");

                    return DB::select('SELECT app_support_records(?::uuid,?,1)', [$grant, $area]);
                });
                $this->fail('Support records were readable for '.$area);
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
            }
        };
        $denied($operator, 'pipeline_entries');

        // Only an owner of that workspace decides. Other roles and other workspaces cannot see or act on the request.
        $this->member($admin, $org)->getJson('/workspace/support-access')->assertForbidden();
        $this->postJson('/workspace/support-access/'.$grant, ['expected_version' => 1, 'decision' => 'approve'])->assertForbidden();
        $this->member($stranger, $otherOrg)->getJson('/workspace/support-access')->assertOk()->assertJsonCount(0, 'requests');
        $this->postJson('/workspace/support-access/'.$grant, ['expected_version' => 1, 'decision' => 'approve'])->assertNotFound();
        $this->member($owner, $org)->getJson('/workspace/support-access')->assertOk()->assertJsonPath('requests.0.purpose', 'Investigate a stuck lead')->assertJsonMissingPath('requests.0.operator_id');
        $this->postJson('/workspace/support-access/'.$grant, ['expected_version' => 9, 'decision' => 'approve'])->assertConflict();
        $this->postJson('/workspace/support-access/'.$grant, ['expected_version' => 1, 'decision' => 'approve'])->assertOk();
        $this->postJson('/workspace/support-access/'.$grant, ['expected_version' => 2, 'decision' => 'approve'])->assertConflict();
        $approved = $this->control->table('support_grants')->where('id', $grant)->first();
        $this->assertSame($owner->id, $approved->decided_by);
        $this->assertEqualsWithDelta(4 * 3600, strtotime($approved->expires_at) - strtotime($approved->decided_at), 2);

        // In scope: a fixed read-only projection without free text. Out of scope and other operators: denied.
        $view = $this->verified($operator)->getJson('/admin/support-access/'.$grant.'?area=pipeline_entries')->assertOk()->assertJsonPath('records.total', 1)
            ->assertJsonPath('records.rows.0.brand_name', 'Support Visible Brand')->assertJsonMissingPath('records.rows.0.notes')->assertJsonMissingPath('records.rows.0.org_id');
        $view->assertDontSee('PRIVATE-SUPPORT-NOTE');
        $this->getJson('/admin/support-access/'.$grant.'?area=reminders')->assertForbidden();
        $this->getJson('/admin/support-access/'.$grant.'?area=invoice_drafts')->assertForbidden();
        $this->getJson('/admin/support-access/'.$grant.'?area=users')->assertUnprocessable();
        $this->verified($colleague)->getJson('/admin/support-access/'.$grant.'?area=pipeline_entries')->assertOk()->assertJsonPath('grant.mine', false)->assertJsonPath('records', null);
        $denied($colleague, 'pipeline_entries');
        $this->postJson('/admin/support-access/'.$grant.'/end', ['expected_version' => 2, 'reason' => 'Not my grant'])->assertForbidden();
        $this->assertSame(1, $this->control->table('platform_audit')->where(['event' => 'support.viewed', 'target' => $grant, 'actor_id' => $operator->id])->count());
        $this->assertSame(1, $this->control->table('audit_logs')->where(['org_id' => $org, 'event' => 'support.viewed.pipeline_entries', 'subject_id' => $grant])->count());
        $this->assertSame(1, $this->control->table('audit_logs')->where(['org_id' => $org, 'event' => 'support.approved', 'actor_id' => $owner->id])->count());
        // The grant confers no membership and no general tenant access.
        $this->assertFalse((bool) app(TenantContext::class)->run($operator->id, null, fn () => DB::selectOne('SELECT app_has_membership(?::uuid) AS ok', [$org])->ok));
        $this->member($operator, $org)->getJson('/pipeline')->assertForbidden();

        // Access ends at expiry without any job running, and again when the owner ends it early.
        $this->control->table('support_grants')->where('id', $grant)->update(['decided_at' => now()->subHours(5), 'expires_at' => now()->subHour()]);
        $this->verified($operator)->getJson('/admin/support-access/'.$grant.'?area=pipeline_entries')->assertOk()->assertJsonPath('grant.state', 'expired')->assertJsonPath('records', null);
        $denied($operator, 'pipeline_entries');
        $second = $this->postJson('/admin/support-access', ['workspace' => $org, 'purpose' => 'Follow-up check', 'hours' => 24, 'resources' => ['finance', 'reminders']])->assertCreated()->json('id');
        $this->member($owner, $org)->postJson('/workspace/support-access/'.$second, ['expected_version' => 1, 'decision' => 'approve'])->assertOk();
        $this->verified($operator)->getJson('/admin/support-access/'.$second.'?area=manual_payments')->assertOk()->assertJsonPath('records.total', 0);
        $this->member($owner, $org)->postJson('/workspace/support-access/'.$second, ['expected_version' => 2, 'decision' => 'end'])->assertOk();
        $this->verified($operator)->getJson('/admin/support-access/'.$second.'?area=manual_payments')->assertOk()->assertJsonPath('grant.state', 'revoked')->assertJsonPath('records', null);
        $third = $this->postJson('/admin/support-access', ['workspace' => $org, 'purpose' => 'Declined check', 'hours' => 1, 'resources' => ['reminders']])->assertCreated()->json('id');
        $this->member($owner, $org)->postJson('/workspace/support-access/'.$third, ['expected_version' => 1, 'decision' => 'decline'])->assertOk();
        $this->assertSame('declined', $this->control->table('support_grants')->where('id', $third)->value('state'));
        $fourth = $this->verified($operator)->postJson('/admin/support-access', ['workspace' => $org, 'purpose' => 'Withdrawn check', 'hours' => 1, 'resources' => ['reminders']])->assertCreated()->json('id');
        $this->postJson('/admin/support-access/'.$fourth.'/end', ['expected_version' => 1, 'reason' => 'No longer needed'])->assertOk();
        $this->assertSame('cancelled', $this->control->table('support_grants')->where('id', $fourth)->value('state'));
    }

    public function test_cleanup_produces_an_exact_dry_run_with_blocks_and_protection_and_deletes_nothing(): void
    {
        [$financeOwner, $financeOrg] = $this->workspace();
        $this->control->table('invoice_drafts')->insert(['id' => (string) Str::uuid(), 'org_id' => $financeOrg, 'recipient_name' => 'Synthetic recipient', 'currency' => 'USD', 'total_minor' => 100]);
        [$sharedOwner, $sharedOrg] = $this->workspace();
        $outsider = User::factory()->create();
        $this->control->table('memberships')->insert(['org_id' => $sharedOrg, 'user_id' => $outsider->id, 'role' => 'member']);
        [$cleanOwner, $cleanOrg] = $this->workspace();
        $this->member($cleanOwner, $cleanOrg)->postJson('/pipeline', ['brand_name' => 'Synthetic lead', 'contact_name' => 'Synthetic', 'contact_email' => 'synthetic@example.test', 'notes' => '', 'value_minor' => '0', 'currency' => 'USD'])->assertCreated();
        $reviewer = $this->operator('{cleanup.review}');
        $unprivileged = $this->operator('{directory.read}');
        $counts = fn () => [$this->control->table('users')->count(), $this->control->table('organizations')->count(), $this->control->table('memberships')->count(), $this->control->table('pipeline_entries')->count(), $this->control->table('invoice_drafts')->count()];
        $before = $counts();

        $this->verified($unprivileged)->getJson('/admin/cleanup')->assertForbidden();
        $this->postJson('/admin/cleanup', ['name' => 'Denied', 'reason' => 'No capability'])->assertForbidden();
        $this->verified($reviewer);
        $name = 'Acceptance batch '.Str::uuid();
        $batch = $this->postJson('/admin/cleanup', ['name' => $name, 'reason' => 'Synthetic acceptance data'])->assertCreated()->json('id');
        $this->postJson('/admin/cleanup', ['name' => $name, 'reason' => 'Duplicate'])->assertConflict();
        $this->postJson('/admin/cleanup/'.$batch.'/runs', ['expected_version' => 1])->assertUnprocessable();
        $version = 1;
        $mark = function (string $type, string $reference, array $extra = []) use ($batch, &$version) {
            return $this->postJson('/admin/cleanup/'.$batch.'/marks', [...['expected_version' => $version, 'type' => $type, 'reference' => $reference, 'evidence' => 'Created by this acceptance test', 'remove' => false], ...$extra]);
        };
        $mark('workspace', (string) Str::uuid())->assertUnprocessable();
        $mark('account', '999999999')->assertUnprocessable();
        $mark('workspace', $cleanOrg, ['evidence' => '  '])->assertUnprocessable();
        $mark('workspace', $cleanOrg, ['expected_version' => 7])->assertConflict();
        foreach ([['workspace', $financeOrg], ['account', (string) $financeOwner->id], ['workspace', $sharedOrg], ['account', (string) $sharedOwner->id], ['workspace', $cleanOrg], ['account', (string) $cleanOwner->id], ['account', (string) $reviewer->id]] as [$type, $reference]) {
            $mark($type, $reference)->assertOk();
            $version++;
        }
        $mark('workspace', $cleanOrg)->assertConflict();
        // The list reports each batch's latest dry run; the shared database can hold other batches, so find this one.
        $listed = fn () => collect($this->getJson('/admin/cleanup')->assertOk()->json('batches'))->firstWhere('id', $batch);
        $this->assertSame([7, 0, null], [$listed()['marks'], $listed()['runs'], $listed()['latest_run']]);

        $run = $this->postJson('/admin/cleanup/'.$batch.'/runs', ['expected_version' => $version])->assertCreated()->json('id');
        $latest = $listed()['latest_run'];
        $this->assertSame([$run, 2, 4, 1, false], [$latest['id'], $latest['eligible'], $latest['blocked'], $latest['protected'], $latest['stale']]);
        $manifest = collect($this->getJson('/admin/cleanup/runs/'.$run)->assertOk()->assertJsonPath('run.stale', false)->assertJsonPath('run.eligible', 2)->assertJsonPath('run.blocked', 4)->assertJsonPath('run.protected', 1)->json('run.items'))->keyBy('reference');
        $this->assertSame(['blocked', ['Holds finance records, which cleanup never removes']], [$manifest[$financeOrg]['disposition'], $manifest[$financeOrg]['reasons']]);
        $this->assertSame(1, $manifest[$financeOrg]['records']['invoice_drafts']);
        $this->assertSame(['blocked', ['Has a member account that is not marked in this batch']], [$manifest[$sharedOrg]['disposition'], $manifest[$sharedOrg]['reasons']]);
        $this->assertSame('eligible', $manifest[$cleanOrg]['disposition']);
        $this->assertSame(1, $manifest[$cleanOrg]['records']['pipeline_entries']);
        $this->assertSame('eligible', $manifest[(string) $cleanOwner->id]['disposition']);
        $this->assertSame('protected', $manifest[(string) $reviewer->id]['disposition']);
        $this->assertSame('blocked', $manifest[(string) $sharedOwner->id]['disposition']);
        $this->assertArrayNotHasKey('audit_logs', $manifest[$cleanOrg]['records']);
        $this->getJson('/admin/cleanup/runs/'.$run)->assertDontSee('Synthetic lead')->assertDontSee('synthetic@example.test')->assertDontSee('Synthetic recipient');

        // Changing the marks makes the recorded run out of date; the run itself never changes.
        $mark('account', (string) $reviewer->id, ['remove' => true])->assertOk();
        $this->getJson('/admin/cleanup/runs/'.$run)->assertOk()->assertJsonPath('run.stale', true)->assertJsonPath('run.protected', 1);
        $this->getJson('/admin/cleanup/'.$batch)->assertOk()->assertJsonCount(6, 'marks')->assertJsonPath('runs.0.stale', true);
        $this->assertSame([$run, true], [$listed()['latest_run']['id'], $listed()['latest_run']['stale']]);

        // Nothing was deleted, no execution function exists, and the runtime role cannot touch the tables directly.
        $this->assertSame($before, $counts());
        $functions = $this->control->table('pg_proc')->where('proname', 'like', 'app_cleanup_%')->orderBy('proname')->pluck('proname')->all();
        $this->assertSame(['app_cleanup_batch', 'app_cleanup_batch_create', 'app_cleanup_batches', 'app_cleanup_dry_run', 'app_cleanup_mark', 'app_cleanup_run'], $functions);
        foreach (['cleanup_runs', 'fixture_marks', 'fixture_batches', 'support_grants', 'platform_suspensions'] as $table) {
            $this->assertSame(0, app(TenantContext::class)->run($reviewer->id, null, fn () => DB::table($table)->count()), $table);
            $this->assertSame(0, app(TenantContext::class)->run($reviewer->id, null, fn () => DB::table($table)->delete()), $table);
        }
        $this->assertSame(1, $this->control->table('cleanup_runs')->where('id', $run)->count());
        $this->assertSame(1, $this->control->table('platform_audit')->where(['event' => 'cleanup.dry_run', 'target' => $run])->count());
    }

    /** Suspends or lifts directly through the migration connection; the operator flow is covered above. */
    private function suspend(string $subject, int|string $reference, bool $lift = false): void
    {
        $column = $subject === 'account' ? 'user_id' : 'org_id';
        $by = $this->control->table('platform_grants')->value('user_id');
        if ($lift) {
            $this->control->table('platform_suspensions')->where($column, $reference)->whereNull('lifted_at')->update(['lifted_at' => now(), 'lifted_by' => $by, 'lift_reason' => 'Synthetic lift']);
        } else {
            $this->control->table('platform_suspensions')->insert(['subject' => $subject, $column => $reference, 'reason' => 'Synthetic suspension', 'suspended_by' => $by]);
        }
    }

    public function test_the_other_party_sees_a_suspended_workspaces_partnership_as_paused_and_nothing_more(): void
    {
        $this->operator('{access.manage}');
        [$brandOwner, $brand] = $this->workspace('brand');
        [$creator, $creatorOrg] = $this->workspace();
        [$outsider, $outsiderOrg] = $this->workspace();
        $invitation = (string) Str::uuid();
        $partnership = (string) Str::uuid();
        $this->control->table('partner_invitations')->insert(['id' => $invitation, 'brand_org_id' => $brand, 'recipient_email' => $creator->email, 'token_hash' => hash('sha256', $invitation), 'status' => 'accepted', 'expires_at' => now()->addDay()]);
        $this->control->table('partnerships')->insert(['id' => $partnership, 'brand_org_id' => $brand, 'creator_org_id' => $creatorOrg, 'brand_name' => 'Synthetic brand', 'creator_name' => 'Synthetic creator', 'invitation_id' => $invitation]);
        $paused = fn (User $user, string $org) => (bool) app(TenantContext::class)->run($user->id, $org, fn () => DB::selectOne('SELECT app_partnership_paused(?::uuid) AS p', [$partnership])->p);

        $this->member($brandOwner, $brand)->getJson('/brand')->assertOk()->assertJsonPath('relationships.0.id', $partnership)->assertJsonPath('relationships.0.paused', false);
        $this->member($creator, $creatorOrg)->getJson('/partner')->assertOk()->assertJsonPath('relationships.0.paused', false);

        $this->suspend('workspace', $creatorOrg);
        // The brand keeps the relationship and learns only that it is paused: no reason, time or operator.
        $row = $this->member($brandOwner, $brand)->getJson('/brand')->assertOk()->assertJsonCount(1, 'relationships')->assertJsonPath('relationships.0.paused', true)->json('relationships.0');
        $this->assertSame(['accepted_at', 'brand_name', 'creator_name', 'id', 'paused', 'status'], collect($row)->keys()->sort()->values()->all());
        $this->member($creator, $creatorOrg)->getJson('/partner')->assertForbidden();
        // A workspace outside the partnership learns nothing, and the brand cannot read the suspension record.
        $this->assertFalse($paused($outsider, $outsiderOrg));
        $this->assertSame(0, app(TenantContext::class)->run($brandOwner->id, $brand, fn () => DB::table('platform_suspensions')->count()));
        $this->assertSame('active', $this->control->table('partnerships')->where('id', $partnership)->value('status'));

        $this->suspend('workspace', $creatorOrg, true);
        $this->member($brandOwner, $brand)->getJson('/brand')->assertOk()->assertJsonPath('relationships.0.paused', false);
        // The label works in both directions.
        $this->suspend('workspace', $brand);
        $this->member($creator, $creatorOrg)->getJson('/partner')->assertOk()->assertJsonPath('relationships.0.paused', true);
        $this->assertSame(0, app(TenantContext::class)->run($creator->id, $creatorOrg, fn () => DB::table('platform_suspensions')->count()));
    }

    public function test_work_due_during_suspension_is_recorded_as_skipped_and_never_replayed(): void
    {
        Queue::fake();
        $this->operator('{access.manage}');
        $schedules = app(BusinessSchedules::class);
        [$actor, $org] = $this->workspace('brand');
        $this->member($actor, $org)->putJson('/automation/preferences', ['version' => 0, 'timezone' => 'Asia/Manila', 'reminders' => true, 'weekly_digest' => true, 'follow_up_drafts' => true])->assertOk();
        $schedule = $this->postJson('/automation/schedules', ['name' => 'Weekly review', 'kind' => 'weekly_digest', 'weekday' => 1, 'local_time' => '09:00', 'consent' => true])->assertCreated()->json('id');
        $due = now()->subMinutes(5)->startOfMinute();
        $this->control->table('business_schedules')->where('id', $schedule)->update(['next_due_at' => $due]);
        $invitation = $this->postJson('/partner-invitations', ['email' => 'skipped-'.Str::uuid().'@example.test', 'send_email' => true])->assertCreated()->json('id');
        $notice = $this->control->table('notification_outbox')->where('subject_id', $invitation)->value('id');
        $runs = fn () => $this->control->table('schedule_runs')->where('schedule_id', $schedule)->orderBy('due_at')->get();

        // The runtime role cannot use the skip function on work that is not suspended.
        $this->assertFalse((bool) DB::selectOne('SELECT app_skip_due_schedule(?::uuid,?::timestamptz) AS ok', [$schedule, now()->addWeek()])->ok);
        $this->assertCount(0, $runs());

        // Due while the actor is suspended: recorded as skipped, moved to the next occurrence, not queued.
        $this->suspend('account', $actor->id);
        $schedules->dispatch();
        $schedules->dispatch();
        $this->assertCount(1, $runs());
        $skipped = $runs()->first();
        $this->assertSame('skipped', $skipped->state);
        $this->assertNotNull($skipped->completed_at);
        $this->assertTrue(CarbonImmutable::parse($skipped->due_at)->equalTo($due));
        $this->assertTrue(CarbonImmutable::parse($this->control->table('business_schedules')->where('id', $schedule)->value('next_due_at'))->isFuture());
        $this->assertSame(1, $this->control->table('audit_logs')->where(['org_id' => $org, 'event' => 'automation.run.skipped', 'subject_id' => $skipped->id])->count());
        Queue::assertNotPushed(MaterializeSchedule::class, fn ($job) => $job->scheduleId === $schedule);

        // Email waiting for the suspended actor is cancelled, not held.
        app(NotificationOutbox::class)->dispatch();
        $this->assertSame('cancelled', $this->control->table('notification_outbox')->where('id', $notice)->value('state'));
        $this->assertNull($this->control->table('notification_outbox')->where('id', $notice)->value('encrypted_payload'));
        Queue::assertNotPushed(DeliverNotification::class, fn ($job) => $job->notificationId === $notice);

        // A run that was already ready when the actor was suspended is skipped when it would have executed.
        $this->suspend('account', $actor->id, true);
        $this->control->table('business_schedules')->where('id', $schedule)->update(['next_due_at' => now()->subMinute()]);
        $schedules->materialize($actor->id, $org, $schedule);
        $ready = $runs()->firstWhere('state', 'ready');
        $this->assertNotNull($ready);
        $this->suspend('account', $actor->id);
        $schedules->dispatch();
        $this->assertSame('skipped', $this->control->table('schedule_runs')->where('id', $ready->id)->value('state'));
        $this->assertSame(1, $this->control->table('audit_logs')->where(['event' => 'automation.run.skipped', 'subject_id' => $ready->id])->count());

        // Reactivation restores access from now on: nothing skipped or cancelled comes back.
        $this->suspend('account', $actor->id, true);
        $schedules->dispatch();
        app(NotificationOutbox::class)->dispatch();
        $schedules->execute($actor->id, $org, $schedule, $ready->id, fn () => $this->fail('A skipped run executed after reactivation.'));
        $this->assertSame(['skipped', 'skipped'], $runs()->pluck('state')->all());
        $this->assertSame('cancelled', $this->control->table('notification_outbox')->where('id', $notice)->value('state'));
        $this->assertTrue((bool) $this->control->table('business_schedules')->where('id', $schedule)->value('enabled'));
        // The runtime role still cannot write the state directly for another actor's run.
        [$other, $otherOrg] = $this->workspace();
        $this->assertSame(0, app(TenantContext::class)->run($other->id, $otherOrg, fn () => DB::table('schedule_runs')->where('id', $ready->id)->update(['state' => 'ready'])));
    }

    public function test_directories_filter_by_created_date_and_the_overview_reports_expiring_support_and_blocked_cleanup(): void
    {
        $reader = $this->operator('{directory.read}');
        $account = User::factory()->create();
        [, $org] = $this->workspace();
        // Acceptance data is not rolled back, so each run uses its own day and compares against what was there before.
        $day = CarbonImmutable::parse('1990-01-01')->addDays(random_int(0, 3000));
        [$on, $before, $after] = [$day->format('Y-m-d'), $day->subDay()->format('Y-m-d'), $day->addDay()->format('Y-m-d')];
        $this->verified($reader);
        $existing = ['accounts' => $this->getJson("/admin/accounts?from=$on&to=$on")->assertOk()->json('accounts.total'), 'workspaces' => $this->getJson("/admin/workspaces?from=$on&to=$on")->assertOk()->json('workspaces.total')];
        $this->control->table('users')->where('id', $account->id)->update(['created_at' => $on.' 23:30:00']);
        $this->control->table('organizations')->where('id', $org)->update(['created_at' => $on.' 00:10:00']);
        foreach (['accounts' => $account->id, 'workspaces' => $org] as $list => $id) {
            // The end date is inclusive and both dates are whole UTC days.
            $found = $this->getJson("/admin/$list?from=$on&to=$on")->assertOk()->assertJsonPath("$list.total", $existing[$list] + 1)->assertJsonPath('filters.from', $on)->assertJsonPath('filters.to', $on)->json("$list.rows");
            $this->assertContains($id, array_column($found, 'id'));
            $this->assertNotContains($id, array_column($this->getJson("/admin/$list?to=$before")->assertOk()->json("$list.rows"), 'id'));
            $this->assertNotContains($id, array_column($this->getJson("/admin/$list?from=$after")->assertOk()->json("$list.rows"), 'id'));
            $this->assertNotContains($id, array_column($this->getJson("/admin/$list?from=$before&to=$before")->assertOk()->json("$list.rows"), 'id'));
            $this->getJson("/admin/$list?from=$after&to=$on")->assertUnprocessable();
            $this->getJson("/admin/$list?from=03/02/2001")->assertUnprocessable();
        }

        $overview = fn (User $user) => app(TenantContext::class)->run($user->id, null, function () {
            DB::select("SELECT set_config('app.admin_version','1',true)");

            return json_decode(DB::selectOne('SELECT app_platform_overview() AS data')->data, true, flags: JSON_THROW_ON_ERROR);
        });
        // Figures outside the caller's capabilities are null, never zero.
        $this->assertNull($overview($reader)['support_expiring']);
        $this->assertNull($overview($reader)['cleanup_blocked']);
        $operator = $this->operator('{support.request,cleanup.review}');
        $baseline = $overview($operator);
        $grant = fn (string $expires) => $this->control->table('support_grants')->insert(['id' => (string) Str::uuid(), 'org_id' => $this->workspace()[1], 'operator_id' => $operator->id, 'purpose' => 'Synthetic', 'resources' => '{reminders}',
            'hours' => 24, 'state' => 'approved', 'decided_at' => now(), 'expires_at' => $expires]);
        $grant(now()->addHour());
        $grant(now()->addHours(5));
        $batch = (string) Str::uuid();
        $this->control->table('fixture_batches')->insert(['id' => $batch, 'name' => 'Overview '.$batch, 'reason' => 'Synthetic', 'created_by' => $operator->id]);
        $this->control->table('cleanup_runs')->insert(['id' => (string) Str::uuid(), 'batch_id' => $batch, 'batch_version' => 1, 'manifest' => '[]', 'eligible' => 0, 'blocked' => 2, 'protected' => 0, 'created_by' => $operator->id]);
        $after = $overview($operator);
        $this->assertSame($baseline['support_expiring'] + 1, $after['support_expiring']);
        $this->assertSame($baseline['cleanup_blocked'] + 1, $after['cleanup_blocked']);
        // A dry run that no longer matches its batch is not counted.
        $this->control->table('fixture_batches')->where('id', $batch)->update(['version' => 2]);
        $this->assertSame($baseline['cleanup_blocked'], $overview($operator)['cleanup_blocked']);
    }
}
