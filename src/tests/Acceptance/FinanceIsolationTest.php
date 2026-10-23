<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceIsolationTest extends AcceptanceCase
{
    public function test_each_private_payment_table_rejects_foreign_and_all_nonowner_operations(): void
    {
        $owner = User::factory()->create();
        $org = app(Workspaces::class)->create($owner->id, 'Private payment security');
        $this->actingAs($owner)->withSession(['active_org_id' => $org]);
        $invoice = $this->postJson('/finance/invoices', ['recipient_name' => 'Synthetic', 'currency' => 'USD', 'command_id' => (string) Str::uuid(),
            'lines' => [['description' => 'Work', 'quantity' => 1, 'unit_price_minor' => '1000']]])->assertCreated()->json('id');
        $payment = $this->postJson('/finance/payments', ['currency' => 'USD', 'amount_minor' => '800', 'received_on' => '2026-10-23',
            'evidence_reference' => 'Security receipt', 'command_id' => (string) Str::uuid()])->assertCreated()->json('id');
        $allocation = $this->postJson('/finance/payments/'.$payment.'/allocate', ['invoice_id' => $invoice, 'invoice_version' => 1, 'version' => 1,
            'amount_minor' => '200', 'command_id' => (string) Str::uuid()])->assertOk()->json('id');
        $this->postJson('/finance/allocations/'.$allocation.'/reverse', ['reason' => 'Security correction', 'command_id' => (string) Str::uuid()])->assertOk();
        $tables = ['manual_payments', 'payment_allocations', 'payment_allocation_reversals', 'payment_commands'];
        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = (array) $this->control->table($table)->where('org_id', $org)->first();
            if (isset($rows[$table]['id'])) {
                $rows[$table]['id'] = (string) Str::uuid();
            }
            if (isset($rows[$table]['command_id'])) {
                $rows[$table]['command_id'] = (string) Str::uuid();
            }
        }
        foreach (['foreign', 'admin', 'manager', 'member', 'viewer'] as $role) {
            $actor = User::factory()->create();
            $contextOrg = $role === 'foreign' ? app(Workspaces::class)->create($actor->id, 'Foreign security') : $org;
            if ($role !== 'foreign') {
                $this->control->table('memberships')->insert(['org_id' => $org, 'user_id' => $actor->id, 'role' => $role]);
            }
            $this->actingAs($actor)->withSession(['active_org_id' => $contextOrg]);
            $this->getJson('/finance/payments')->assertStatus($role === 'foreign' ? 200 : 403);
            $this->postJson('/finance/payments/'.$payment.'/void', ['version' => 2, 'command_id' => (string) Str::uuid()])->assertStatus($role === 'foreign' ? 404 : 403);
            app(TenantContext::class)->run($actor->id, $contextOrg, function () use ($rows, $org): void {
                foreach ($rows as $table => $row) {
                    $this->assertSame(0, DB::table($table)->where('org_id', $org)->count());
                    $this->assertSame(0, DB::table($table)->where('org_id', $org)->update(['org_id' => $org]));
                    $this->assertSame(0, DB::table($table)->where('org_id', $org)->delete());
                    try {
                        DB::transaction(fn () => DB::table($table)->insert($row));
                        $this->fail('Unauthorized payment insert accepted: '.$table);
                    } catch (QueryException $e) {
                        $this->assertSame('42501', $e->errorInfo[0]);
                    }
                }
            });
        }
    }
}
