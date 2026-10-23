<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\InvoiceDrafts;
use App\Services\InvoiceMoney;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class InvoiceDraftTest extends AcceptanceCase
{
    private function details(): array
    {
        return ['recipient_name' => 'Synthetic recipient', 'currency' => 'USD', 'lines' => [
            ['description' => 'Private work', 'quantity' => 1, 'unit_price_minor' => '9007199254740993'],
        ]];
    }

    public function test_lifecycle_exact_money_retries_conflicts_and_rollback(): void
    {
        $user = User::factory()->create();
        $org = app(Workspaces::class)->create($user->id, 'Finance');
        $this->actingAs($user)->withSession(['active_org_id' => $org]);
        $key = (string) Str::uuid();
        $data = [...$this->details(), 'command_id' => $key, 'status' => 'issued', 'total_minor' => '1'];
        $id = $this->postJson('/finance/invoices', $data)->assertCreated()->json('id');
        $this->postJson('/finance/invoices', $data)->assertCreated()->assertJsonPath('id', $id);
        $this->postJson('/finance/invoices', [...$data, 'currency' => 'EUR'])->assertConflict();
        $this->getJson('/finance/invoices/'.$id)->assertOk()->assertJsonPath('invoice.total_minor', '9007199254740993')->assertJsonPath('invoice.status', 'draft');
        $this->patchJson('/finance/invoices/'.$id, [...$this->details(), 'version' => 1, 'command_id' => (string) Str::uuid()])->assertOk();
        $this->patchJson('/finance/invoices/'.$id, [...$this->details(), 'version' => 1, 'command_id' => (string) Str::uuid()])->assertConflict();
        foreach (['9223372036854775808', '01', '1.2'] as $bad) {
            $input = $this->details();
            $input['lines'][0]['unit_price_minor'] = $bad;
            $this->postJson('/finance/invoices', [...$input, 'command_id' => (string) Str::uuid()])->assertUnprocessable();
        }
        $input = $this->details();
        $input['lines'][0]['quantity'] = 1000000;
        $this->postJson('/finance/invoices', [...$input, 'command_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/finance/invoices/'.$id.'/void', ['version' => 2, 'command_id' => (string) Str::uuid()])->assertOk();
        $this->patchJson('/finance/invoices/'.$id, [...$this->details(), 'version' => 3, 'command_id' => (string) Str::uuid()])->assertConflict();
        app(TenantContext::class)->run($user->id, $org, function () use ($id): void {
            $this->assertSame(3, DB::table('financial_commands')->count());
            $this->assertSame(3, DB::table('audit_logs')->where('subject_id', $id)->count());
            $this->assertSame(1, DB::table('invoice_draft_lines')->count());
        });
        $this->assertSame('9223372036854775807', InvoiceMoney::calculate([['quantity' => 1, 'unit_price_minor' => '9223372036854775807']])['total_minor']);
    }

    public function test_roles_foreign_ids_and_direct_rls_on_every_table(): void
    {
        $owner = User::factory()->create();
        $org = app(Workspaces::class)->create($owner->id, 'Private finance');
        $this->actingAs($owner)->withSession(['active_org_id' => $org]);
        $id = $this->postJson('/finance/invoices', [...$this->details(), 'command_id' => (string) Str::uuid()])->assertCreated()->json('id');
        $other = User::factory()->create();
        $foreign = app(Workspaces::class)->create($other->id, 'Foreign');
        $this->actingAs($other)->withSession(['active_org_id' => $foreign]);
        $this->getJson('/finance/invoices/'.$id)->assertNotFound();
        $this->getJson('/finance/invoices?cursor='.$id)->assertNotFound();
        app(TenantContext::class)->run($other->id, $foreign, function () use ($id, $org, $owner, $foreign): void {
            foreach (['invoice_drafts', 'invoice_draft_lines', 'financial_commands'] as $table) {
                $this->assertSame(0, DB::table($table)->count());
                $this->assertSame(0, DB::table($table)->where('org_id', $org)->delete());
                $this->assertSame(0, DB::table($table)->where('org_id', $org)->update(['org_id' => $foreign]));
            }
            $rows = [
                'invoice_drafts' => ['id' => (string) Str::uuid(), 'org_id' => $org, 'recipient_name' => 'Forged', 'currency' => 'USD', 'total_minor' => 1],
                'invoice_draft_lines' => ['org_id' => $org, 'invoice_id' => $id, 'position' => 2, 'description' => 'Forged', 'quantity' => 1, 'unit_price_minor' => 1],
                'financial_commands' => ['org_id' => $org, 'actor_id' => $owner->id, 'operation' => 'forged', 'command_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'result_id' => $id],
            ];
            foreach ($rows as $table => $row) {
                try {
                    DB::transaction(fn () => DB::table($table)->insert($row));
                    $this->fail('RLS insert accepted');
                } catch (QueryException $e) {
                    $this->assertSame('42501', $e->errorInfo[0]);
                }
            }
        });
        // An owner's role is demoted by the fixture control connection in acceptance;
        // here every non-owner workspace role is created through the owner context.
        foreach (['admin', 'manager', 'member', 'viewer'] as $role) {
            $member = User::factory()->create();
            $this->control->table('memberships')->insert([
                'org_id' => $org, 'user_id' => $member->id, 'role' => $role,
            ]);
            $this->actingAs($member)->withSession(['active_org_id' => $org]);
            $this->getJson('/finance/invoices')->assertForbidden();
            $this->getJson('/finance/invoices/'.$id)->assertForbidden();
            $this->postJson('/finance/invoices', [...$this->details(), 'command_id' => (string) Str::uuid()])->assertForbidden();
            app(TenantContext::class)->run($member->id, $org, function (): void {
                foreach (['invoice_drafts', 'invoice_draft_lines', 'financial_commands'] as $table) {
                    $this->assertSame(0, DB::table($table)->count());
                }
            });
        }
    }

    public function test_retry_waits_on_real_postgres_lock_and_revocation_denies_replay(): void
    {
        $actor = User::factory()->create();
        $org = app(Workspaces::class)->create($actor->id, 'Retry test');
        $key = (string) Str::uuid();
        $probe = null;
        try {
            $id = app(TenantContext::class)->run($actor->id, $org, function () use ($actor, $org, $key, &$probe) {
                $id = app(InvoiceDrafts::class)->command($actor->id, $org, $key, 'create', [
                    'recipient_name' => 'Concurrent', 'currency' => 'USD', 'due_date' => null,
                    'lines' => [['description' => 'Work', 'quantity' => 1, 'unit_price_minor' => '100']],
                ], null);
                $probe = new Process([PHP_BINARY, 'tests/Fixtures/finance-command.php', (string) $actor->id, $org, $key], base_path(), ['TEST_MIGRATION_DB_PASSWORD' => false]);
                $probe->start();
                $this->eventually(fn () => str_contains($probe->getOutput(), "\n"), 'Probe must connect');
                $this->assertBlocked($probe);

                return $id;
            });
            $this->assertSame($id, $this->probeResult($probe)['id']);
            $this->assertSame(1, $this->control->table('financial_commands')->where('org_id', $org)->count());
            $this->control->table('memberships')->where('org_id', $org)->update(['revoked_at' => now()]);
            $this->actingAs($actor)->withSession(['active_org_id' => $org]);
            $this->postJson('/finance/invoices', ['command_id' => $key])->assertForbidden();
        } finally {
            $probe?->stop(0);
        }
    }

    public function test_cursor_pages_and_failed_commands_leave_no_financial_rows_or_key(): void
    {
        $actor = User::factory()->create();
        $org = app(Workspaces::class)->create($actor->id, 'Paged drafts');
        $key = (string) Str::uuid();
        try {
            app(TenantContext::class)->run($actor->id, $org, function () use ($actor, $org, $key): void {
                app(InvoiceDrafts::class)->command($actor->id, $org, $key, 'create', [...$this->details(), 'due_date' => null], null);
                throw new \RuntimeException('Synthetic rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic rollback', $e->getMessage());
        }
        foreach (['invoice_drafts', 'invoice_draft_lines', 'financial_commands'] as $table) {
            $this->assertSame(0, $this->control->table($table)->where('org_id', $org)->count());
        }
        $this->assertSame(0, $this->control->table('audit_logs')->where('org_id', $org)->where('event', 'invoice_draft.create')->count());
        app(TenantContext::class)->run($actor->id, $org, function () use ($actor, $org, $key): void {
            for ($i = 0; $i < 26; $i++) {
                app(InvoiceDrafts::class)->command($actor->id, $org, $i === 0 ? $key : (string) Str::uuid(), 'create', [...$this->details(), 'due_date' => null], null);
            }
        });
        $this->actingAs($actor)->withSession(['active_org_id' => $org]);
        $first = $this->getJson('/finance/invoices')->assertOk()->assertJsonCount(25, 'invoices.items')->json('invoices');
        $second = $this->getJson('/finance/invoices?cursor='.$first['nextCursor'])->assertOk()->assertJsonCount(1, 'invoices.items')->assertJsonPath('invoices.nextCursor', null)->json('invoices');
        $this->assertCount(26, array_unique([...array_column($first['items'], 'id'), ...array_column($second['items'], 'id')]));
    }
}
