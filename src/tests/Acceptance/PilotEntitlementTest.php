<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\InvoiceDrafts;
use App\Services\PublicPresence;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class PilotEntitlementTest extends AcceptanceCase
{
    private function assign(string $org, array $quotas, bool $expired = false): string
    {
        $id = (string) Str::uuid();
        $this->control->table('pilot_plan_versions')->insert(['id' => $id, 'name' => $id, 'version' => 1, 'quotas' => json_encode($quotas)]);
        $this->control->table('pilot_assignments')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'plan_id' => $id,
            'version' => (int) $this->control->table('pilot_assignments')->where('org_id', $org)->max('version') + 1,
            'starts_at' => now()->subDays(2), 'ends_at' => $expired ? now()->subDay() : now()->addDay()]);

        return $id;
    }

    private function draft(string $key): array
    {
        return ['recipient_name' => 'Concurrent', 'currency' => 'USD', 'due_date' => null, 'command_id' => $key,
            'lines' => [['description' => 'Work', 'quantity' => 1, 'unit_price_minor' => '100']]];
    }

    public function test_downgrade_expiry_missing_features_and_no_runtime_assignment_mutations(): void
    {
        $actor = User::factory()->create();
        $org = app(Workspaces::class)->create($actor->id, 'Pilot');
        $this->actingAs($actor)->withSession(['active_org_id' => $org]);
        $this->getJson('/workspace/entitlements')->assertJsonPath('entitlements.state', 'unassigned-development');
        $plan = $this->assign($org, ['invoice_drafts' => 2]);
        $id = $this->postJson('/finance/invoices', $this->draft((string) Str::uuid()))->assertCreated()->json('id');
        $this->postJson('/finance/payments', ['currency' => 'USD', 'amount_minor' => '1', 'received_on' => '2026-10-23', 'evidence_reference' => 'Blocked', 'command_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('quota');
        $this->assign($org, ['invoice_drafts' => 0]);
        $this->postJson('/finance/invoices', $this->draft((string) Str::uuid()))->assertUnprocessable();
        $this->getJson('/finance/invoices/'.$id)->assertOk();
        $this->patchJson('/finance/invoices/'.$id, [...$this->draft((string) Str::uuid()), 'version' => 1])->assertOk();
        $this->assign($org, ['invoice_drafts' => 50], true);
        $this->postJson('/finance/invoices', $this->draft((string) Str::uuid()))->assertUnprocessable();
        $this->getJson('/workspace/entitlements')->assertJsonPath('entitlements.state', 'expired');
        $this->postJson('/finance/invoices/'.$id.'/void', ['version' => 2, 'command_id' => (string) Str::uuid()])->assertOk();
        app(TenantContext::class)->run($actor->id, $org, function () use ($org, $plan): void {
            $this->assertSame(0, DB::table('pilot_assignments')->where('org_id', $org)->delete());
            $this->assertSame(0, DB::table('pilot_plan_versions')->where('id', $plan)->update(['quotas' => '{}']));
        });
        $other = User::factory()->create();
        $otherOrg = app(Workspaces::class)->create($other->id, 'Other pilot');
        app(TenantContext::class)->run($other->id, $otherOrg, function (): void {
            $this->assertSame(0, DB::table('pilot_assignments')->count());
            $this->assertSame(0, DB::table('pilot_plan_versions')->count());
        });
    }

    public function test_concurrent_creates_cannot_exceed_one_record_quota(): void
    {
        $actor = User::factory()->create();
        $org = app(Workspaces::class)->create($actor->id, 'Concurrent quota');
        $this->assign($org, ['invoice_drafts' => 1]);
        $probe = null;
        try {
            app(TenantContext::class)->run($actor->id, $org, function () use ($actor, $org, &$probe): void {
                app(InvoiceDrafts::class)->command($actor->id, $org, (string) Str::uuid(), 'create', array_diff_key($this->draft('ignored'), ['command_id' => true]), null);
                $probe = new Process([PHP_BINARY, 'tests/Fixtures/finance-command.php', (string) $actor->id, $org, (string) Str::uuid()], base_path(), ['TEST_MIGRATION_DB_PASSWORD' => false]);
                $probe->start();
                $this->eventually(fn () => str_contains($probe->getOutput(), "\n"), 'Quota probe must connect');
                $this->assertBlocked($probe);
            });
            $this->assertSame(['status' => 422, 'sqlstate' => 'P0004'], $this->probeResult($probe));
            $this->assertSame(1, $this->control->table('invoice_drafts')->where('org_id', $org)->count());
            $this->assertSame(1, $this->control->table('financial_commands')->where('org_id', $org)->count());
        } finally {
            $probe?->stop(0);
        }
    }

    public function test_local_pilot_commands_require_migration_credentials_and_audit_each_change(): void
    {
        $actor = User::factory()->create();
        $org = app(Workspaces::class)->create($actor->id, 'Administered pilot');
        $name = 'Synthetic-'.Str::uuid();
        $args = ['name' => $name, 'version' => 1, 'quotas' => '{"invoice_drafts":2}', '--operator' => 'local-test', '--reason' => 'Synthetic pilot verification'];
        try {
            Artisan::call('pilot:plan', $args);
            $this->fail('Runtime credentials administered pilot');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('migration credentials', $e->getMessage());
        }
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('acceptance_control');
        try {
            $this->assertSame(0, Artisan::call('pilot:plan', $args));
            $plan = trim(Artisan::output());
            $this->assertTrue(Str::isUuid($plan));
            $this->assertSame(0, Artisan::call('pilot:assign', ['workspace' => $org, 'plan' => $plan,
                'starts' => now()->subMinute()->format('Y-m-d\TH:i:sP'), 'ends' => now()->addDay()->format('Y-m-d\TH:i:sP'), '--operator' => 'local-test', '--reason' => 'Synthetic pilot verification']));
            $this->assertSame(2, DB::table('pilot_admin_events')->where('operator', 'local-test')->whereIn('subject_id', [$plan, DB::table('pilot_assignments')->where('org_id', $org)->value('id')])->count());
        } finally {
            DB::setDefaultConnection($original);
        }
        $this->actingAs($actor)->withSession(['active_org_id' => $org]);
        $this->getJson('/workspace/entitlements')->assertOk()->assertJsonPath('entitlements.state', 'active')->assertJsonPath('entitlements.plan.name', $name);
    }

    public function test_all_supported_resources_are_enforced_below_service_layer(): void
    {
        $actor = User::factory()->create();
        $org = app(Workspaces::class)->create($actor->id, 'Resource caps');
        $this->assign($org, ['invoice_drafts' => 0]);
        app(TenantContext::class)->run($actor->id, $org, function () use ($org): void {
            foreach (['pipeline_entries', 'brand_contacts', 'reminders', 'onboarding_requests', 'outreach_templates', 'invoice_drafts', 'manual_payments'] as $table) {
                try {
                    DB::transaction(fn () => DB::table($table)->insert(['id' => (string) Str::uuid(), 'org_id' => $org]));
                    $this->fail('Quota bypassed for '.$table);
                } catch (QueryException $e) {
                    $this->assertSame('P0004', $e->errorInfo[0]);
                }
            }
            foreach (['pilot_plan_versions' => ['id' => (string) Str::uuid(), 'name' => 'Forged', 'version' => 1, 'quotas' => '{}'],
                'pilot_assignments' => ['id' => (string) Str::uuid(), 'org_id' => $org, 'plan_id' => (string) Str::uuid(), 'version' => 2, 'starts_at' => now(), 'ends_at' => now()->addDay()],
                'pilot_admin_events' => ['id' => (string) Str::uuid(), 'operator' => 'forged', 'reason' => 'forged', 'event' => 'forged', 'subject_id' => $org, 'org_id' => $org]] as $table => $row) {
                try {
                    DB::transaction(fn () => DB::table($table)->insert($row));
                    $this->fail('Pilot administration bypassed');
                } catch (QueryException $e) {
                    $this->assertSame('42501', $e->errorInfo[0]);
                }
            }
        });
    }

    public function test_public_capture_obeys_quota_and_retry_uses_no_extra_slot(): void
    {
        $actor = User::factory()->create();
        $org = app(Workspaces::class)->create($actor->id, 'Public capture pilot');
        $link = app(TenantContext::class)->run($actor->id, $org, fn () => app(PublicPresence::class)->leadLink($actor->id, $org));
        $token = explode('#token=', $link['url'])[1];
        $this->assign($org, ['pipeline_entries' => 1]);
        $data = ['token' => $token, 'idempotency_key' => (string) Str::uuid(), 'brand_name' => 'Synthetic public brand', 'contact_name' => 'Synthetic contact', 'email' => 'synthetic@example.test', 'consent' => true];
        $this->postJson('/lead', $data)->assertOk();
        $this->postJson('/lead', $data)->assertOk();
        $this->postJson('/lead', [...$data, 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('quota');
        $this->assertSame(1, $this->control->table('pipeline_entries')->where('org_id', $org)->count());
    }
}
