<?php

namespace Tests\Acceptance;

use App\Jobs\DeliverAccountNotification;
use App\Mail\BrandRequestOutcome;
use App\Models\User;
use App\Services\AccountNotifications;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\BrandFixture;

/** Brand workspace approval (D37) against the real runtime role, RLS and PostgreSQL functions. */
class BrandApprovalTest extends AcceptanceCase
{
    private const PASSWORD = 'SyntheticPassword123!';

    private const FIELDS = ['brand_name' => 'Northwind Synthetic', 'website' => 'https://northwind.example.test/about', 'applicant_role' => 'Marketing lead', 'intended_use' => 'PRIVATE-USE-MARKER partner programme'];

    protected function setUp(): void
    {
        parent::setUp();
        // Each rule is asserted on its own; per-route throttles are covered by the existing throttle tests.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function operator(string $permissions = '{brands.review}'): User
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $this->control->table('platform_grants')->insert(['user_id' => $user->id, 'permissions' => $permissions]);
        $this->control->table('users')->where('id', $user->id)->update(['admin_totp_confirmed_at' => now()]);

        return $user->refresh();
    }

    private function reviewer(User $user, int $version = 1): static
    {
        return $this->actingAs($user)->withSession(['platform_mfa' => ['user' => $user->id, 'version' => $version, 'until' => time() + 900]]);
    }

    /** A verified applicant with one submitted request. */
    private function applicant(array $fields = []): array
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $id = $this->actingAs($user)->postJson('/brand/requests', [...self::FIELDS, ...$fields])->assertCreated()->json('id');

        return [$user, $id];
    }

    private function decide(string $request, string $decision, int $version = 1, string $feedback = 'REVIEWER-FEEDBACK-MARKER')
    {
        return $this->postJson('/admin/brand-requests/'.$request.'/decision', ['expected_version' => $version, 'decision' => $decision, 'feedback' => $feedback]);
    }

    private function brandCount(int $user): int
    {
        return $this->control->table('memberships as m')->join('organizations as o', 'o.id', '=', 'm.org_id')->where('m.user_id', $user)->where('o.kind', 'brand')->count();
    }

    private function notices(string $request): array
    {
        return $this->control->table('account_notification_outbox')->where('brand_request_id', $request)->orderBy('created_at')->get()->all();
    }

    public function test_brand_registration_resumes_into_a_request_across_sessions_without_creating_a_workspace(): void
    {
        $data = ['name' => 'Brand Applicant', 'email' => 'brand-applicant-'.Str::lower(Str::random(8)).'@example.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
        $this->post('/brand/register', $data)->assertRedirect('/verify-email');
        $user = User::where('email', $data['email'])->firstOrFail();
        $this->assertSame('brand', $user->registration_workspace_kind);
        // Unverified accounts reach neither the request page nor the portal.
        $this->get('/brand/requests')->assertRedirect('/verify-email');
        $this->post('/logout');

        // Verification is interrupted; the account signs in later from another browser session.
        $this->control->table('users')->where('id', $user->id)->update(['email_verified_at' => now()]);
        $this->flushSession();
        $this->post('/brand/login', ['email' => $data['email'], 'password' => self::PASSWORD])->assertRedirect('/portal/enter');
        $this->get('/portal/enter')->assertRedirect('/brand/requests');
        $this->getJson('/brand/requests')->assertOk()->assertJsonPath('registering', true)->assertJsonPath('open', false)->assertJsonCount(0, 'requests');
        $this->assertSame(0, $this->control->table('memberships')->where('user_id', $user->id)->count());
        // The intent is durable: nothing clears it until a request exists.
        $this->post('/logout');
        $this->flushSession();
        $this->post('/login', ['email' => $data['email'], 'password' => self::PASSWORD])->assertRedirect('/portal/enter');
        $this->get('/portal/enter')->assertRedirect('/brand/requests');

        $id = $this->postJson('/brand/requests', self::FIELDS)->assertCreated()->json('id');
        $this->assertNull($user->refresh()->registration_workspace_id);
        $this->assertNull($user->registration_workspace_kind);
        $this->actingAs($user);
        $this->getJson('/brand/requests')->assertJsonPath('registering', false)->assertJsonPath('open', true)->assertJsonPath('requests.0.id', $id)->assertJsonPath('requests.0.status', 'pending');
        $this->assertSame(0, $this->control->table('memberships')->where('user_id', $user->id)->count());
        // Account security, sign-out and other workspaces stay available during review.
        $this->getJson('/workspaces')->assertOk();
        $this->postJson('/workspaces', ['name' => 'Creator while waiting'])->assertCreated();
        $this->getJson('/account/authenticator')->assertOk();
        // The Brand entrance now leads to the request page; other entrances are unaffected.
        $this->withSession(['portal' => 'brand'])->get('/portal/enter')->assertRedirect('/brand/requests');
    }

    public function test_partner_and_professional_registration_still_provision_their_workspaces(): void
    {
        foreach (['partner' => 'partner', 'professional' => 'professional'] as $portal => $experience) {
            $data = ['name' => ucfirst($portal).' Registrant', 'email' => $portal.'-'.Str::lower(Str::random(8)).'@example.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
            $this->post('/'.$portal.'/register', $data)->assertRedirect('/verify-email');
            $user = User::where('email', $data['email'])->firstOrFail();
            $this->control->table('users')->where('id', $user->id)->update(['email_verified_at' => now()]);
            $this->actingAs($user->refresh())->get('/portal/enter')->assertRedirect($portal === 'professional' ? '/professional' : '/partner/leads');
            $org = $this->control->table('memberships as m')->join('organizations as o', 'o.id', '=', 'm.org_id')->where('m.user_id', $user->id)->first(['o.kind', 'o.experience', 'm.role']);
            $this->assertSame(['creator', $experience, 'owner'], [$org->kind, $org->experience, $org->role]);
            $this->post('/logout');
            $this->flushSession();
        }
    }

    public function test_http_and_runtime_sql_cannot_create_a_brand_workspace_without_approval(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/workspaces', ['name' => 'Bypass', 'kind' => 'brand'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $before = $this->control->table('organizations')->count();
        $context = app(TenantContext::class);
        $attempts = [
            fn (string $org) => DB::select('SELECT app_create_experience_workspace(?::uuid,?,?,?)', [$org, 'Bypass', 'brand', 'partner']),
            fn (string $org) => DB::select('SELECT app_create_typed_workspace(?::uuid,?,?)', [$org, 'Bypass', 'brand']),
            fn (string $org) => DB::table('organizations')->insert(['id' => $org, 'name' => 'Bypass', 'kind' => 'brand']),
            fn (string $org) => DB::table('brand_requests')->insert(['id' => (string) Str::uuid(), 'source' => 'rollout', 'status' => 'approved', 'org_id' => $org, 'decided_at' => now()]),
            fn (string $org) => DB::table('brand_requests')->insert(['id' => (string) Str::uuid(), 'applicant_id' => $user->id, ...self::FIELDS]),
        ];
        foreach ($attempts as $attempt) {
            $org = (string) Str::uuid();
            try {
                $context->run($user->id, $org, fn () => $attempt($org), requireMembership: false);
                $this->fail('The runtime role bypassed Brand review.');
            } catch (QueryException $error) {
                $this->assertContains($error->errorInfo[0], ['42501', '23503']);
            }
        }
        // The service path used by registration and fixtures is refused as well; creator workspaces still work.
        try {
            app(Workspaces::class)->create($user->id, 'Bypass', 'brand');
            $this->fail('The workspace service created a Brand workspace.');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->errorInfo[0]);
        }
        $this->assertSame($before, $this->control->table('organizations')->count());
        $creator = app(Workspaces::class)->create($user->id, 'Creator still allowed');
        // A creator workspace can never become a Brand workspace.
        try {
            $this->control->table('organizations')->where('id', $creator)->update(['kind' => 'brand']);
            $this->fail('Workspace kind changed.');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->errorInfo[0]);
        }
        // Even the schema owner cannot commit a Brand workspace without approval provenance.
        try {
            $this->control->transaction(fn () => $this->control->table('organizations')->insert(['id' => (string) Str::uuid(), 'name' => 'Owner bypass', 'kind' => 'brand']));
            $this->fail('A Brand workspace was committed without provenance.');
        } catch (\PDOException $error) {
            // Checked at commit, so the driver reports it directly.
            $this->assertSame('42501', $error->errorInfo[0]);
        }
        $this->assertSame(0, $this->control->table('organizations')->where('name', 'Owner bypass')->count());
        $this->assertSame(0, $this->control->table('brand_requests')->where('applicant_id', $user->id)->count());
    }

    public function test_applicant_validation_single_open_request_revision_history_and_decline_then_new_request(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->postJson('/brand/requests', [])->assertUnprocessable()->assertJsonValidationErrors(['brand_name', 'website', 'applicant_role', 'intended_use']);
        foreach (['ftp://files.example.test', 'javascript:alert(1)', 'northwind.example.test', 'https://north wind.example.test'] as $address) {
            $this->postJson('/brand/requests', [...self::FIELDS, 'website' => $address])->assertUnprocessable()->assertJsonValidationErrors('website');
        }
        $id = $this->postJson('/brand/requests', self::FIELDS)->assertCreated()->json('id');
        $this->postJson('/brand/requests', [...self::FIELDS, 'brand_name' => 'Second'])->assertConflict()->assertJsonValidationErrors('brand_name');
        // Submitted information cannot change while it awaits review.
        $this->patchJson('/brand/requests/'.$id, [...self::FIELDS, 'brand_name' => 'Changed', 'expected_version' => 1])->assertConflict();
        $this->getJson('/brand/requests/'.$id)->assertOk()->assertJsonPath('request.brand_name', self::FIELDS['brand_name'])->assertJsonPath('request.status', 'pending')->assertJsonCount(1, 'request.history');

        $operator = $this->operator();
        $this->reviewer($operator);
        $this->decide($id, 'request_information', 1, '')->assertUnprocessable()->assertJsonValidationErrors('feedback');
        $this->decide($id, 'request_information', 1, 'Please add your company registration page.')->assertOk()->assertJsonPath('status', 'needs_information')->assertJsonPath('version', 2);
        // Only pending requests can receive a decision.
        $this->decide($id, 'approve', 2)->assertConflict();

        $this->actingAs($user);
        $this->getJson('/brand/requests/'.$id)->assertJsonPath('request.status', 'needs_information')->assertJsonPath('request.history.0.feedback', 'Please add your company registration page.')
            ->assertJsonMissingPath('request.history.0.actor')->assertDontSee($operator->name);
        $this->patchJson('/brand/requests/'.$id, [...self::FIELDS, 'website' => 'https://northwind.example.test/registration', 'expected_version' => 1])->assertConflict();
        $this->patchJson('/brand/requests/'.$id, [...self::FIELDS, 'website' => 'https://northwind.example.test/registration', 'expected_version' => 2])->assertOk()->assertJsonPath('version', 3);
        $detail = $this->getJson('/brand/requests/'.$id)->assertJsonPath('request.status', 'pending')->assertJsonPath('request.website', 'https://northwind.example.test/registration')->json('request.history');
        // Earlier submissions and the reviewer's feedback are all kept.
        $this->assertSame(['resubmitted', 'information_requested', 'submitted'], array_column($detail, 'event'));
        $this->assertSame('https://northwind.example.test/about', $detail[2]['submission']['website']);
        $this->assertSame('https://northwind.example.test/registration', $detail[0]['submission']['website']);

        $this->reviewer($operator)->decide($id, 'decline', 3, 'This does not look like an operating business.')->assertOk()->assertJsonPath('status', 'declined');
        $this->decide($id, 'approve', 4)->assertConflict();
        $this->assertSame(0, $this->brandCount($user->id));
        // Decline is terminal for that request; history cannot be rewritten even by the schema owner.
        foreach ([fn () => $this->control->table('brand_requests')->where('id', $id)->update(['status' => 'pending', 'decided_at' => null]),
            fn () => $this->control->table('brand_requests')->where('id', $id)->delete(),
            fn () => $this->control->table('brand_request_events')->where('request_id', $id)->update(['reason' => 'Rewritten']),
            fn () => $this->control->table('brand_request_events')->where('request_id', $id)->delete()] as $rewrite) {
            try {
                $rewrite();
                $this->fail('Brand request history was rewritten.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
            }
        }
        $this->actingAs($user)->patchJson('/brand/requests/'.$id, [...self::FIELDS, 'expected_version' => 4])->assertConflict();
        $second = $this->postJson('/brand/requests', [...self::FIELDS, 'brand_name' => 'Northwind Second'])->assertCreated()->json('id');
        $this->getJson('/brand/requests')->assertJsonCount(2, 'requests')->assertJsonPath('requests.0.id', $second)->assertJsonPath('requests.1.status', 'declined');
        $this->assertSame(4, $this->control->table('brand_request_events')->where('request_id', $id)->count());
    }

    public function test_real_rls_hides_other_applicants_requests_and_refuses_unauthorized_review(): void
    {
        [$owner, $id] = $this->applicant();
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->getJson('/brand/requests')->assertOk()->assertJsonCount(0, 'requests')->assertDontSee('PRIVATE-USE-MARKER');
        $this->getJson('/brand/requests/'.$id)->assertNotFound();
        $this->patchJson('/brand/requests/'.$id, [...self::FIELDS, 'expected_version' => 1])->assertNotFound();
        $this->getJson('/brand/requests/'.Str::uuid())->assertNotFound();
        $context = app(TenantContext::class);
        $this->assertSame(0, $context->run($other->id, null, fn () => DB::table('brand_requests')->count()));
        $this->assertSame(1, $context->run($owner->id, null, fn () => DB::table('brand_requests')->count()));
        foreach (['brand_request_events', 'account_notification_outbox', 'account_notification_resolutions'] as $table) {
            $this->assertSame(0, $context->run($owner->id, null, fn () => DB::table($table)->count()), $table);
        }
        $this->assertSame(0, $context->run($other->id, null, fn () => DB::table('brand_requests')->where('id', $id)->update(['status' => 'approved'])));
        // No grant, a grant without the capability, an unproven session and a revoked grant are all refused.
        foreach (['/admin/brand-requests', '/admin/brand-requests/'.$id] as $path) {
            $this->getJson($path)->assertForbidden();
        }
        $this->decide($id, 'approve')->assertForbidden();
        $directory = $this->operator('{directory.read,access.manage}');
        $this->reviewer($directory)->getJson('/admin/brand-requests')->assertForbidden();
        $this->decide($id, 'approve')->assertForbidden();
        try {
            $context->run($directory->id, null, function () use ($id) {
                DB::select("SELECT set_config('app.admin_version','1',true)");
                DB::select('SELECT app_platform_brand_decide(?::uuid,1,?,?,?::uuid,?::uuid)', [$id, 'approve', 'Forged', (string) Str::uuid(), (string) Str::uuid()]);
            });
            $this->fail('A grant without brands.review decided a request.');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->errorInfo[0]);
        }
        $reviewer = $this->operator();
        $this->actingAs($reviewer)->withSession(['platform_mfa' => ['user' => $reviewer->id, 'version' => 1, 'until' => time() - 1]])->decide($id, 'approve')->assertForbidden();
        $this->reviewer($reviewer, 7)->decide($id, 'approve')->assertForbidden();
        $this->control->table('platform_grants')->where('user_id', $reviewer->id)->update(['revoked_at' => now(), 'version' => 2]);
        $this->reviewer($reviewer, 2)->decide($id, 'approve')->assertForbidden();
        $this->assertSame('pending', $this->control->table('brand_requests')->where('id', $id)->value('status'));
        $this->assertSame(0, $this->brandCount($owner->id));
        // The capability exposes request metadata only: no workspace membership and no tenant-private access.
        $scoped = $this->operator();
        $this->reviewer($scoped)->getJson('/admin/brand-requests?q='.urlencode($owner->email))->assertOk()->assertJsonPath('requests.total', 1)->assertJsonPath('requests.rows.0.applicant_email', $owner->email);
        $this->getJson('/admin/accounts')->assertForbidden();
        $this->getJson('/admin/workspaces')->assertForbidden();
    }

    public function test_approval_creates_one_owned_workspace_once_and_the_reviewer_gains_no_membership(): void
    {
        [$applicant, $id] = $this->applicant();
        $creator = app(Workspaces::class)->create($applicant->id, 'Existing creator workspace');
        $reviewer = $this->operator('{brands.review,directory.read,audit.read}');
        $this->reviewer($reviewer);
        $this->getJson('/admin/brand-requests')->assertOk()->assertJsonPath('requests.rows.0.status', 'pending');
        $this->getJson('/admin/brand-requests/'.$id)->assertOk()->assertJsonPath('request.intended_use', self::FIELDS['intended_use'])->assertJsonPath('applicant.verified', true)
            ->assertJsonPath('applicant.suspended', false)->assertJsonPath('workspace', null)->assertJsonCount(1, 'history');
        $this->decide($id, 'approve', 9)->assertConflict();
        $result = $this->decide($id, 'approve')->assertOk()->assertJsonPath('status', 'approved')->json();
        // Repeated approvals, with the old or the new version, change nothing.
        $this->decide($id, 'approve')->assertConflict();
        $this->decide($id, 'approve', 2)->assertConflict();
        $this->decide($id, 'decline', 2)->assertConflict();

        $org = $this->control->table('organizations')->where('id', $result['workspace'])->first();
        $this->assertSame([self::FIELDS['brand_name'], 'brand', 'partner'], [$org->name, $org->kind, $org->experience]);
        $members = $this->control->table('memberships')->where('org_id', $org->id)->get();
        $this->assertCount(1, $members);
        $this->assertSame([$applicant->id, 'owner'], [$members[0]->user_id, $members[0]->role]);
        $this->assertSame(0, $this->control->table('memberships')->where('user_id', $reviewer->id)->count());
        $this->assertSame(1, $this->brandCount($applicant->id));
        $this->assertSame(1, $this->control->table('brand_request_events')->where(['request_id' => $id, 'event' => 'approved', 'actor_id' => $reviewer->id])->count());
        $this->assertSame(1, $this->control->table('platform_audit')->where(['event' => 'brand_request.approved', 'target' => $id, 'actor_id' => $reviewer->id])->count());
        $this->assertSame(1, $this->control->table('audit_logs')->where(['org_id' => $org->id, 'event' => 'workspace.approved'])->count());
        $notices = $this->notices($id);
        $this->assertCount(1, $notices);
        $this->assertSame(['brand_request.approved', 'pending', $applicant->id], [$notices[0]->kind, $notices[0]->state, $notices[0]->user_id]);

        $this->getJson('/admin/brand-requests/'.$id)->assertJsonPath('request.status', 'approved')->assertJsonPath('workspace.id', $org->id);
        $this->getJson('/admin/workspaces/'.$org->id)->assertOk()->assertJsonPath('approval.status', 'approved')->assertJsonPath('approval.source', 'request')
            ->assertJsonPath('approval.reviewer', $reviewer->name)->assertJsonPath('approval.reason', 'REVIEWER-FEEDBACK-MARKER')->assertJsonPath('approval.request', $id);
        $this->getJson('/admin/workspaces/'.$creator)->assertOk()->assertJsonPath('approval', null);
        // The reviewer cannot open the workspace; the applicant can, alongside their other workspace.
        $this->withSession(['active_org_id' => $org->id, 'platform_mfa' => ['user' => $reviewer->id, 'version' => 1, 'until' => time() + 900]])->getJson('/brand')->assertForbidden();
        $this->actingAs($applicant)->getJson('/brand/requests/'.$id)->assertJsonPath('request.status', 'approved')->assertJsonPath('request.workspace.id', $org->id)->assertJsonPath('request.workspace.member', true);
        $this->getJson('/workspaces')->assertJsonCount(2, 'workspaces');
        $this->postJson('/workspace/select', ['org_id' => $org->id])->assertOk();
        $this->withSession(['active_org_id' => $org->id])->getJson('/brand')->assertOk()->assertJsonPath('workspace.kind', 'brand');
        $this->withSession(['active_org_id' => $creator])->getJson('/partner/leads')->assertOk();
        // New ownership brings the account under the existing workspace-owner authenticator rule.
        $this->assertTrue((bool) DB::selectOne('SELECT app_account_requires_mfa(?) AS required', [$applicant->id])->required);
        config(['security.owner_mfa' => true]);
        $this->actingAs($applicant)->withSession(['active_org_id' => $org->id])->getJson('/brand')->assertForbidden()->assertJsonPath('message', 'Verify your authenticator to continue.');
    }

    public function test_unverified_suspended_and_self_applicants_cannot_be_approved_and_failures_leave_nothing_partial(): void
    {
        $reviewer = $this->operator();
        [$unverified, $first] = $this->applicant();
        $this->control->table('users')->where('id', $unverified->id)->update(['email_verified_at' => null]);
        $this->reviewer($reviewer)->decide($first, 'approve')->assertUnprocessable()->assertJsonValidationErrors('feedback');
        [$suspended, $second] = $this->applicant();
        $this->control->table('platform_suspensions')->insert(['subject' => 'account', 'user_id' => $suspended->id, 'reason' => 'Synthetic suspension', 'suspended_by' => $reviewer->id]);
        $this->reviewer($reviewer)->getJson('/admin/brand-requests/'.$second)->assertJsonPath('applicant.suspended', true);
        $this->decide($second, 'approve')->assertUnprocessable();
        // A reviewer cannot decide their own request.
        $own = $this->actingAs($reviewer)->postJson('/brand/requests', self::FIELDS)->assertCreated()->json('id');
        $this->reviewer($reviewer)->decide($own, 'approve')->assertUnprocessable();
        foreach ([$first, $second, $own] as $id) {
            $this->assertSame('pending', $this->control->table('brand_requests')->where('id', $id)->value('status'));
            $this->assertSame(1, $this->control->table('brand_request_events')->where('request_id', $id)->count());
            $this->assertCount(0, $this->notices($id));
        }
        foreach ([$unverified, $suspended, $reviewer] as $account) {
            $this->assertSame(0, $this->brandCount($account->id));
        }

        // A failure after the workspace is written rolls everything back: no workspace, event, audit or notice.
        $rollback = 'Rollback Synthetic '.Str::random(8);
        [$applicant, $id] = $this->applicant(['brand_name' => $rollback]);
        $audits = $this->control->table('platform_audit')->where('target', $id)->count();
        try {
            app(TenantContext::class)->run($reviewer->id, null, function () use ($id) {
                DB::select("SELECT set_config('app.admin_version','1',true)");
                DB::select('SELECT app_platform_brand_decide(?::uuid,1,?,?,?::uuid,?::uuid)', [$id, 'approve', 'Approved then failed', (string) Str::uuid(), (string) Str::uuid()]);
                throw new RuntimeException('Failure after approval');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('Failure after approval', $error->getMessage());
        }
        $this->assertSame('pending', $this->control->table('brand_requests')->where('id', $id)->value('status'));
        $this->assertSame(0, $this->control->table('organizations')->where('name', $rollback)->count());
        $this->assertSame(0, $this->brandCount($applicant->id));
        $this->assertSame($audits, $this->control->table('platform_audit')->where('target', $id)->count());
        $this->assertCount(0, $this->notices($id));
        // The request is still decidable afterwards.
        $this->reviewer($reviewer)->decide($id, 'approve')->assertOk();
        $this->assertSame(1, $this->brandCount($applicant->id));
    }

    public function test_concurrent_approvals_serialize_and_produce_one_workspace_event_and_notice(): void
    {
        $name = 'Concurrent Synthetic '.Str::random(8);
        [$applicant, $id] = $this->applicant(['brand_name' => $name]);
        $first = $this->operator();
        $second = $this->operator();
        config(['database.connections.second_runtime' => config('database.connections.pgsql')]);
        $other = DB::connection('second_runtime');
        $approve = fn ($connection, User $operator) => $connection->select('SELECT app_platform_brand_decide(?::uuid,1,?,?,?::uuid,?::uuid)', [$id, 'approve', 'Concurrent approval', (string) Str::uuid(), (string) Str::uuid()]);
        $begin = function ($connection, User $operator): void {
            $connection->beginTransaction();
            $connection->select("SELECT set_config('app.actor_id',?,true),set_config('app.org_id','',true),set_config('app.admin_version','1',true)", [(string) $operator->id]);
        };
        $begin(DB::connection(), $first);
        $approve(DB::connection(), $first);
        // The second reviewer waits on the first decision instead of creating a second workspace.
        $begin($other, $second);
        $other->statement("SET LOCAL lock_timeout = '400ms'");
        try {
            $approve($other, $second);
            $this->fail('A concurrent approval did not wait for the first.');
        } catch (QueryException $error) {
            $this->assertSame('55P03', $error->errorInfo[0]);
        }
        $other->rollBack();
        DB::connection()->commit();
        $begin($other, $second);
        try {
            $approve($other, $second);
            $this->fail('A second approval succeeded.');
        } catch (QueryException $error) {
            $this->assertSame('40001', $error->errorInfo[0]);
        }
        $other->rollBack();
        DB::purge('second_runtime');
        $this->assertSame(1, $this->brandCount($applicant->id));
        $this->assertSame(1, $this->control->table('organizations')->where('name', $name)->count());
        $this->assertSame(1, $this->control->table('brand_request_events')->where(['request_id' => $id, 'event' => 'approved'])->count());
        $this->assertCount(1, $this->notices($id));
        $this->assertSame(0, $this->control->table('memberships')->whereIn('user_id', [$first->id, $second->id])->count());
    }

    public function test_outcome_email_is_reserved_once_links_to_the_request_and_survives_queue_failure_and_replay(): void
    {
        [$applicant, $id] = $this->applicant();
        $reviewer = $this->operator('{brands.review,notifications.read}');
        $this->reviewer($reviewer)->decide($id, 'approve')->assertOk();
        $notice = $this->notices($id)[0]->id;

        // Queue publication fails: the approval stands and the intent stays durable with a recorded deferral.
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('PRIVATE-QUEUE-MARKER'));
        $this->assertSame(0, app(AccountNotifications::class)->dispatch());
        Queue::clearResolvedInstances();
        $this->app->forgetInstance('queue');
        $row = $this->notices($id)[0];
        $this->assertSame(['pending', 'queue_unavailable', 1], [$row->state, $row->failure_code, $row->defer_count]);
        $this->assertSame('approved', $this->control->table('brand_requests')->where('id', $id)->value('status'));
        $this->actingAs($applicant)->getJson('/brand/requests/'.$id)->assertJsonPath('request.status', 'approved');

        $this->control->table('account_notification_outbox')->where('id', $notice)->update(['available_at' => now()->subMinute(), 'dispatch_after' => now()->subMinute()]);
        Queue::fake();
        $this->assertSame(1, app(AccountNotifications::class)->dispatch());
        Queue::assertPushed(DeliverAccountNotification::class, fn ($job) => $job->notificationId === $notice);
        // The claim leases the notice: an immediate second scheduler pass does not queue it again.
        $this->assertSame(0, app(AccountNotifications::class)->dispatch());

        Mail::fake();
        app(AccountNotifications::class)->deliver($notice);
        Mail::assertSent(BrandRequestOutcome::class, fn (BrandRequestOutcome $mail) => $mail->hasTo($applicant->email) && $mail->kind === 'brand_request.approved'
            && $mail->requestUrl === rtrim(config('app.url'), '/').'/brand/requests/'.$id);
        $this->assertSame('captured', $this->notices($id)[0]->state);
        // A retried or replayed job after the reservation sends nothing more.
        app(AccountNotifications::class)->deliver($notice);
        app(AccountNotifications::class)->deliver($notice);
        Mail::assertSentCount(1);
        $this->assertSame(0, app(AccountNotifications::class)->dispatch());

        // The message names the outcome and the authenticated page; it carries no submission or feedback.
        foreach (['brand_request.approved', 'brand_request.needs_information', 'brand_request.declined'] as $kind) {
            $text = (new BrandRequestOutcome($kind, 'https://app.example.test/brand/requests/'.$id, $notice))->render();
            $this->assertStringContainsString('/brand/requests/'.$id, $text);
            foreach ([self::FIELDS['brand_name'], 'PRIVATE-USE-MARKER', 'REVIEWER-FEEDBACK-MARKER', self::FIELDS['website'], $applicant->email] as $private) {
                $this->assertStringNotContainsString($private, $text);
            }
        }
        // Diagnostics list the notice by account, without the address or any content.
        $this->reviewer($reviewer)->getJson('/admin/notifications?q='.$notice)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('notices.0.kind', 'brand_request.approved')
            ->assertJsonPath('notices.0.account', $applicant->name)->assertJsonPath('notices.0.org_id', null)->assertDontSee($applicant->email)->assertDontSee('PRIVATE-USE-MARKER');
        $this->getJson('/admin/notifications/'.$notice)->assertOk()->assertJsonPath('notice.account.id', $applicant->id)->assertJsonPath('notice.state', 'captured')->assertDontSee($applicant->email);
    }

    public function test_superseded_suspended_and_unverified_notices_are_cancelled_before_send(): void
    {
        Mail::fake();
        $reviewer = $this->operator();
        // The applicant resubmits before the information request was sent: that notice is now obsolete.
        [$applicant, $id] = $this->applicant();
        $this->reviewer($reviewer)->decide($id, 'request_information')->assertOk();
        $asked = $this->notices($id)[0]->id;
        $this->actingAs($applicant)->patchJson('/brand/requests/'.$id, [...self::FIELDS, 'expected_version' => 2])->assertOk();
        $this->assertSame('cancelled', $this->notices($id)[0]->state);
        app(AccountNotifications::class)->deliver($asked);
        // The next decision has its own notification identity.
        $this->reviewer($reviewer)->decide($id, 'decline', 3)->assertOk();
        $rows = $this->notices($id);
        $this->assertSame(['cancelled', 'pending'], [$rows[0]->state, $rows[1]->state]);
        $this->assertSame(2, count(array_unique(array_column($rows, 'event_id'))));
        try {
            $this->control->table('account_notification_outbox')->insert(['id' => (string) Str::uuid(), 'user_id' => $applicant->id, 'kind' => 'brand_request.declined', 'brand_request_id' => $id, 'event_id' => $rows[1]->event_id, 'request_version' => 4]);
            $this->fail('One decision produced two notification identities.');
        } catch (QueryException $error) {
            $this->assertSame('23505', $error->errorInfo[0]);
        }
        // A race the cancellation did not cover is still caught at reservation.
        [$late, $lateId] = $this->applicant();
        $this->reviewer($reviewer)->decide($lateId, 'request_information')->assertOk();
        $stale = $this->notices($lateId)[0]->id;
        $this->control->table('account_notification_outbox')->where('id', $stale)->update(['request_version' => 1]);
        app(AccountNotifications::class)->deliver($stale);
        $this->assertSame('cancelled', $this->notices($lateId)[0]->state);

        [$suspended, $suspendedId] = $this->applicant();
        $this->reviewer($reviewer)->decide($suspendedId, 'decline')->assertOk();
        $this->control->table('platform_suspensions')->insert(['subject' => 'account', 'user_id' => $suspended->id, 'reason' => 'Synthetic suspension', 'suspended_by' => $reviewer->id]);
        app(AccountNotifications::class)->deliver($this->notices($suspendedId)[0]->id);
        $this->assertSame('cancelled', $this->notices($suspendedId)[0]->state);

        [$unverified, $unverifiedId] = $this->applicant();
        $this->reviewer($reviewer)->decide($unverifiedId, 'decline')->assertOk();
        $this->control->table('users')->where('id', $unverified->id)->update(['email_verified_at' => null]);
        Queue::fake();
        app(AccountNotifications::class)->dispatch();
        $this->assertSame('cancelled', $this->notices($unverifiedId)[0]->state);
        Mail::assertNothingSent();
        // The decisions themselves are untouched.
        $this->assertSame('declined', $this->control->table('brand_requests')->where('id', $suspendedId)->value('status'));
    }

    public function test_budgets_transport_and_uncertain_outcomes_follow_the_existing_delivery_rules(): void
    {
        $reviewer = $this->operator('{brands.review,notifications.read,notifications.reconcile}');
        [$applicant, $id] = $this->applicant();
        $this->reviewer($reviewer)->decide($id, 'decline')->assertOk();
        $notice = $this->notices($id)[0]->id;
        $ready = fn () => $this->control->table('account_notification_outbox')->where('id', $notice)->update(['available_at' => now()->subMinute()]);
        $this->control->table('notification_delivery_windows')->delete();
        Mail::fake();

        // A log transport is never used; delivery waits for a real transport.
        config(['mail.default' => 'log']);
        app(AccountNotifications::class)->deliver($notice);
        $this->assertSame(['pending', 'transport_unavailable'], [$this->notices($id)[0]->state, $this->notices($id)[0]->failure_code]);
        config(['mail.default' => 'array']);
        // Invalid limits defer instead of silently disabling the safeguard.
        $ready();
        config(['notifications.global_per_minute' => 'unlimited']);
        app(AccountNotifications::class)->deliver($notice);
        $this->assertSame(['pending', 'global_budget'], [$this->notices($id)[0]->state, $this->notices($id)[0]->failure_code]);
        // The global window is the one workspace notifications already consumed.
        $ready();
        config(['notifications.global_per_minute' => 1, 'notifications.account_per_minute' => 5]);
        $this->control->table('notification_delivery_windows')->insert(['scope' => 'global', 'window_start' => DB::raw("date_trunc('minute',clock_timestamp())"), 'used' => 1]);
        app(AccountNotifications::class)->deliver($notice);
        $this->assertSame(['pending', 'global_budget'], [$this->notices($id)[0]->state, $this->notices($id)[0]->failure_code]);
        $this->control->table('notification_delivery_windows')->delete();
        $ready();
        config(['notifications.global_per_minute' => 0, 'notifications.account_per_minute' => 1]);
        $this->control->table('notification_delivery_windows')->insert(['scope' => 'account:'.$applicant->id, 'window_start' => DB::raw("date_trunc('minute',clock_timestamp())"), 'used' => 1]);
        app(AccountNotifications::class)->deliver($notice);
        $this->assertSame(['pending', 'account_budget'], [$this->notices($id)[0]->state, $this->notices($id)[0]->failure_code]);
        Mail::assertNothingSent();

        // An ambiguous transport outcome is recorded as uncertain and never retried.
        $this->control->table('notification_delivery_windows')->delete();
        $ready();
        config(['notifications.global_per_minute' => 2, 'notifications.account_per_minute' => 2]);
        Mail::shouldReceive('mailer')->once()->andThrow(new RuntimeException('PRIVATE-SMTP-MARKER '.$applicant->email));
        app(AccountNotifications::class)->deliver($notice);
        $this->assertSame('uncertain', $this->notices($id)[0]->state);
        $this->assertSame(1, (int) $this->control->table('notification_delivery_windows')->where('scope', 'global')->value('used'));
        $this->assertSame(1, (int) $this->control->table('notification_delivery_windows')->where('scope', 'account:'.$applicant->id)->value('used'));
        Mail::clearResolvedInstances();
        $this->app->forgetInstance('mail.manager');
        Mail::fake();
        app(AccountNotifications::class)->deliver($notice);
        Queue::fake();
        $this->assertSame(0, app(AccountNotifications::class)->dispatch());
        Mail::assertNothingSent();
        $this->assertSame('uncertain', $this->notices($id)[0]->state);

        // A send that started and never reported back is also uncertain, not pending.
        [, $crashedId] = $this->applicant();
        $this->reviewer($reviewer)->decide($crashedId, 'decline')->assertOk();
        $this->control->table('account_notification_outbox')->where('brand_request_id', $crashedId)->update(['state' => 'sending', 'started_at' => now()->subMinutes(6)]);
        $this->assertSame(0, app(AccountNotifications::class)->dispatch());
        $this->assertSame('uncertain', $this->notices($crashedId)[0]->state);

        // Operator resolution uses the existing diagnostics route and capability, and never resends.
        $this->reviewer($reviewer)->getJson('/admin/notifications?state=uncertain&q='.$notice)->assertOk()->assertJsonPath('total', 1)->assertDontSee('PRIVATE-SMTP-MARKER')->assertDontSee($applicant->email);
        $this->getJson('/admin/notifications?state=uncertain&q='.$this->notices($crashedId)[0]->id)->assertOk()->assertJsonPath('total', 1);
        $data = ['version' => 0, 'resolution' => 'not_sent', 'reason' => 'Synthetic provider evidence'];
        $this->postJson('/admin/notifications/'.$notice.'/resolve', $data)->assertOk();
        $this->postJson('/admin/notifications/'.$notice.'/resolve', $data)->assertConflict();
        $this->assertSame(1, $this->control->table('account_notification_resolutions')->where('notification_id', $notice)->count());
        $this->assertSame(1, $this->control->table('platform_audit')->where(['target' => $notice, 'event' => 'notification.resolved'])->count());
        $this->getJson('/admin/notifications/'.$notice)->assertJsonPath('notice.resolution', 'not_sent')->assertJsonPath('notice.state', 'uncertain');
        $scoped = $this->operator('{brands.review}');
        $this->reviewer($scoped)->getJson('/admin/notifications')->assertForbidden();
        $this->assertSame('declined', $this->control->table('brand_requests')->where('id', $id)->value('status'));
    }

    public function test_rollout_records_attributed_approvals_once_and_preserves_existing_workspaces(): void
    {
        // Brand workspaces that predate review: written without provenance, as they exist before the rollout.
        $owner = User::factory()->create();
        $legacy = [];
        $this->control->statement('ALTER TABLE organizations DISABLE TRIGGER organization_brand_provenance');
        try {
            foreach (['Legacy Brand One', 'Legacy Brand Two'] as $name) {
                $org = (string) Str::uuid();
                $this->control->table('organizations')->insert(['id' => $org, 'name' => $name.' '.Str::random(6), 'kind' => 'brand', 'created_at' => '2026-10-22 08:00:00+00']);
                $this->control->table('memberships')->insert(['org_id' => $org, 'user_id' => $owner->id, 'role' => 'owner', 'created_at' => '2026-10-22 08:00:00+00']);
                $legacy[] = $org;
            }
        } finally {
            $this->control->statement('ALTER TABLE organizations ENABLE TRIGGER organization_brand_provenance');
        }
        $recorded = BrandFixture::create($owner->id, 'Already recorded', $this->control);
        $snapshot = fn () => json_encode([$this->control->table('organizations')->whereIn('id', $legacy)->orderBy('id')->get()->all(), $this->control->table('memberships')->whereIn('org_id', $legacy)->orderBy('org_id')->get()->all()]);
        $before = $snapshot();
        $approver = $this->operator('{platform.owner}');
        $inspector = $this->operator('{directory.read,brands.review}');
        $this->reviewer($inspector)->getJson('/admin/workspaces/'.$legacy[0])->assertOk()->assertJsonPath('approval.status', 'unrecorded');
        $this->assertGreaterThanOrEqual(2, $this->control->table('organizations as o')->where('kind', 'brand')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('brand_requests as r')->whereColumn('r.org_id', 'o.id'))->count());
        $requests = fn () => $this->control->table('brand_requests')->where('source', 'rollout')->whereIn('org_id', $legacy)->count();
        $notices = $this->control->table('account_notification_outbox')->count();

        // Refusals write nothing: runtime role, missing reason, an account that is not a usable platform owner.
        $this->assertSame(1, $this->rollout(['--approver='.$approver->id, '--reason=Synthetic rollout', '--operator=acceptance'], migrator: false)->getExitCode());
        $this->assertSame(1, $this->rollout(['--approver='.$approver->id, '--operator=acceptance'])->getExitCode());
        $this->assertSame(1, $this->rollout(['--approver='.$inspector->id, '--reason=Synthetic rollout', '--operator=acceptance'])->getExitCode());
        $this->assertSame(1, $this->rollout(['--approver-email=nobody@example.test', '--reason=Synthetic rollout', '--operator=acceptance'])->getExitCode());
        $this->control->table('platform_suspensions')->insert(['subject' => 'account', 'user_id' => $approver->id, 'reason' => 'Synthetic suspension', 'suspended_by' => $inspector->id]);
        $refused = $this->rollout(['--approver-email='.$approver->email, '--reason=Synthetic rollout', '--operator=acceptance']);
        $this->assertSame(1, $refused->getExitCode());
        $this->assertStringContainsString('Nothing was written', $refused->getOutput());
        $this->control->table('platform_suspensions')->where('user_id', $approver->id)->update(['lifted_at' => now(), 'lifted_by' => $inspector->id]);
        $this->assertSame(0, $requests());

        $dry = $this->rollout(['--approver-email='.strtoupper($approver->email), '--reason=Synthetic rollout', '--operator=acceptance', '--dry-run']);
        $this->assertSame(0, $dry->getExitCode(), $dry->getOutput().$dry->getErrorOutput());
        $report = $this->report($dry);
        $this->assertTrue($report['dry_run']);
        $this->assertEmpty(array_diff($legacy, $report['workspace_ids']));
        $this->assertNotContains($recorded, $report['workspace_ids']);
        $this->assertStringNotContainsString($approver->email, $dry->getOutput());
        $this->assertSame(0, $requests());

        $started = now()->subSecond();
        $run = $this->rollout(['--approver-email='.$approver->email, '--reason=Synthetic rollout', '--operator=acceptance']);
        $this->assertSame(0, $run->getExitCode(), $run->getOutput().$run->getErrorOutput());
        $report = $this->report($run);
        $this->assertSame([false, $approver->id, count($report['workspace_ids'])], [$report['dry_run'], $report['approver_id'], $report['recorded']]);
        $this->assertSame(2, $requests());
        foreach ($legacy as $org) {
            $request = $this->control->table('brand_requests')->where('org_id', $org)->first();
            $this->assertSame(['rollout', 'approved', null, null], [$request->source, $request->status, $request->applicant_id, $request->brand_name]);
            $event = $this->control->table('brand_request_events')->where('request_id', $request->id)->first();
            $this->assertSame(['rollout_approved', $approver->id, 'acceptance', 'Synthetic rollout'], [$event->event, $event->actor_id, $event->operator, $event->reason]);
            // The actual execution time, not the workspace's original creation date.
            $this->assertTrue(now()->parse($event->occurred_at)->greaterThan($started));
            $this->assertSame(1, $this->control->table('platform_audit')->where(['event' => 'brand.rollout_approved', 'target' => $org, 'operator' => 'acceptance'])->count());
        }
        $this->assertSame($before, $snapshot());
        $this->assertSame($notices, $this->control->table('account_notification_outbox')->count());

        // Repeating the command records nothing more.
        $again = $this->report($this->rollout(['--approver='.$approver->id, '--reason=Synthetic rollout', '--operator=acceptance']));
        $this->assertSame([0, []], [$again['recorded'], $again['workspace_ids']]);
        $this->assertSame(2, $requests());
        $this->assertSame(2, $this->control->table('platform_audit')->where('event', 'brand.rollout_approved')->whereIn('target', $legacy)->count());

        // Access is unchanged, and administration shows who approved, when, why and how.
        $this->actingAs($owner)->withSession(['active_org_id' => $legacy[0]])->getJson('/brand')->assertOk();
        $this->reviewer($inspector)->getJson('/admin/workspaces/'.$legacy[0])->assertOk()->assertJsonPath('approval.status', 'approved')->assertJsonPath('approval.source', 'rollout')
            ->assertJsonPath('approval.reviewer', $approver->name)->assertJsonPath('approval.operator', 'acceptance')->assertJsonPath('approval.reason', 'Synthetic rollout');
        $this->getJson('/admin/brand-requests?source=rollout&status=approved&q='.urlencode($this->control->table('organizations')->where('id', $legacy[0])->value('name')))->assertOk()->assertJsonPath('requests.total', 1);
        // A rollout record is provenance, not a reviewable request, and its owner sees no request of their own.
        $rolloutId = $this->control->table('brand_requests')->where('org_id', $legacy[0])->value('id');
        $this->decide($rolloutId, 'decline')->assertNotFound();
        $this->actingAs($owner)->getJson('/brand/requests')->assertJsonCount(0, 'requests');
        $this->getJson('/brand/requests/'.$rolloutId)->assertNotFound();
    }

    private function rollout(array $arguments, bool $migrator = true): Process
    {
        $process = new Process([PHP_BINARY, 'artisan', 'brand:rollout-approvals', ...$arguments], base_path(), [
            'APP_ENV' => 'testing', 'TEST_MIGRATION_DB_PASSWORD' => false,
            ...($migrator ? ['DB_USERNAME' => 'weavdash_migrator', 'DB_PASSWORD' => getenv('TEST_MIGRATION_DB_PASSWORD')] : []),
        ]);
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    private function report(Process $process): array
    {
        preg_match('/^\{.*"workspace_ids".*\}$/m', $process->getOutput(), $match);
        $this->assertNotEmpty($match, $process->getOutput().$process->getErrorOutput());

        return json_decode($match[0], true, flags: JSON_THROW_ON_ERROR);
    }
}
