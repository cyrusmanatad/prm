<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\ManualPayments;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class ManualPaymentTest extends AcceptanceCase
{
    private function command(string $path, array $data = [], int $status = 200): array
    {
        return $this->postJson($path, [...$data, 'command_id' => (string) Str::uuid()])->assertStatus($status)->json();
    }

    public function test_partial_allocations_duplicates_currency_bounds_and_history_preserving_corrections(): void
    {
        $user = User::factory()->create();
        $org = app(Workspaces::class)->create($user->id, 'Manual finance');
        $this->actingAs($user)->withSession(['active_org_id' => $org]);
        $invoice = $this->command('/finance/invoices', ['recipient_name' => 'Synthetic', 'currency' => 'USD',
            'lines' => [['description' => 'Work', 'quantity' => 1, 'unit_price_minor' => '1000']]], 201)['id'];
        $receipt = ['currency' => 'USD', 'amount_minor' => '800', 'received_on' => '2026-10-23', 'evidence_reference' => 'Receipt 1'];
        $key = (string) Str::uuid();
        $payment = $this->postJson('/finance/payments', [...$receipt, 'command_id' => $key])->assertCreated()->json('id');
        $this->postJson('/finance/payments', [...$receipt, 'command_id' => $key])->assertCreated()->assertJsonPath('id', $payment);
        $this->postJson('/finance/payments', [...$receipt, 'amount_minor' => '900', 'command_id' => $key])->assertConflict();
        $this->command('/finance/payments', [...$receipt, 'evidence_reference' => ' receipt 1 '], 409);
        $this->command('/finance/payments', [...$receipt, 'evidence_reference' => 'Zero', 'amount_minor' => '0'], 422);
        $allocation = ['invoice_id' => $invoice, 'invoice_version' => 1, 'version' => 1, 'amount_minor' => '500'];
        $id = $this->command('/finance/payments/'.$payment.'/allocate', $allocation)['id'];
        $this->getJson('/finance/payments')->assertJsonPath('payments.0.allocated_minor', '500');
        $this->command('/finance/payments/'.$payment.'/allocate', [...$allocation, 'version' => 2, 'amount_minor' => '301'], 409);
        $this->command('/finance/payments/'.$payment.'/void', ['version' => 2], 409);
        $this->command('/finance/invoices/'.$invoice.'/void', ['version' => 1], 409);
        $euro = $this->command('/finance/payments', [...$receipt, 'currency' => 'EUR', 'evidence_reference' => 'EUR'], 201)['id'];
        $this->command('/finance/payments/'.$euro.'/allocate', $allocation, 409);
        app(TenantContext::class)->run($user->id, $org, function () use ($org, $payment, $euro, $invoice, $id): void {
            $probes = [
                fn () => DB::table('payment_allocations')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'payment_id' => $payment, 'invoice_id' => $invoice, 'amount_minor' => 400]),
                fn () => DB::table('payment_allocations')->insert(['id' => (string) Str::uuid(), 'org_id' => $org, 'payment_id' => $euro, 'invoice_id' => $invoice, 'amount_minor' => 1]),
                fn () => DB::table('manual_payments')->where('id', $payment)->update(['amount_minor' => 900, 'version' => 3]),
                fn () => DB::table('invoice_drafts')->where('id', $invoice)->update(['total_minor' => 2000]),
            ];
            foreach ($probes as $probe) {
                try {
                    DB::transaction($probe);
                    $this->fail('Financial integrity guard bypassed');
                } catch (QueryException $e) {
                    $this->assertSame('23514', $e->errorInfo[0]);
                }
            }
            $this->assertSame(0, DB::table('payment_allocations')->where('id', $id)->delete());
        });

        $key = (string) Str::uuid();
        $this->postJson('/finance/allocations/'.$id.'/reverse', ['reason' => 'Wrong allocation', 'command_id' => $key])->assertOk();
        $this->postJson('/finance/allocations/'.$id.'/reverse', ['reason' => 'Wrong allocation', 'command_id' => $key])->assertOk();
        $this->command('/finance/allocations/'.$id.'/reverse', ['reason' => 'Again'], 409);
        $this->command('/finance/payments/'.$payment.'/void', ['version' => 2]);
        $this->command('/finance/payments', $receipt, 409);
        $this->command('/finance/payments', [...$receipt, 'evidence_reference' => 'Receipt 1 correction', 'correction_of' => $payment], 201);
        $this->assertSame(1, $this->control->table('payment_allocations')->where('id', $id)->count());
        $this->assertSame(1, $this->control->table('payment_allocation_reversals')->where('allocation_id', $id)->count());
        $other = User::factory()->create();
        $foreign = app(Workspaces::class)->create($other->id, 'Other');
        $this->actingAs($other)->withSession(['active_org_id' => $foreign]);
        $this->command('/finance/payments/'.$payment.'/void', ['version' => 3], 404);
        $this->getJson('/finance/payments')->assertJsonCount(0, 'payments');
        $this->command('/finance/allocations/'.$id.'/reverse', ['reason' => 'Foreign'], 404);
    }

    public function test_allocation_retry_and_competing_payments_wait_and_preserve_invoice_bounds(): void
    {
        foreach (['retry', 'compete'] as $mode) {
            $actor = User::factory()->create();
            $org = app(Workspaces::class)->create($actor->id, 'Allocation concurrency');
            $this->actingAs($actor)->withSession(['active_org_id' => $org]);
            $invoice = $this->command('/finance/invoices', ['recipient_name' => 'Concurrent', 'currency' => 'USD', 'lines' => [['description' => 'Work', 'quantity' => 1, 'unit_price_minor' => '1000']]], 201)['id'];
            $payment = $this->command('/finance/payments', ['currency' => 'USD', 'amount_minor' => '800', 'received_on' => '2026-10-23', 'evidence_reference' => 'First'], 201)['id'];
            $other = $mode === 'retry' ? $payment : $this->command('/finance/payments', ['currency' => 'USD', 'amount_minor' => '800', 'received_on' => '2026-10-23', 'evidence_reference' => 'Second'], 201)['id'];
            $key = (string) Str::uuid();
            $probe = null;
            try {
                $id = app(TenantContext::class)->run($actor->id, $org, function () use ($actor, $org, $key, $payment, $other, $invoice, &$probe) {
                    $id = app(ManualPayments::class)->command($actor->id, $org, $key, 'allocate', ['version' => 1, 'invoice_id' => $invoice, 'invoice_version' => 1, 'amount_minor' => '600'], $payment);
                    $probe = new Process([PHP_BINARY, 'tests/Fixtures/payment-command.php', (string) $actor->id, $org, $key, $other, $invoice], base_path(), ['TEST_MIGRATION_DB_PASSWORD' => false]);
                    $probe->start();
                    $this->eventually(fn () => str_contains($probe->getOutput(), "\n"), 'Payment probe must connect');
                    $this->assertBlocked($probe);

                    return $id;
                });
                $this->assertSame($mode === 'retry' ? ['id' => $id] : ['status' => 409], $this->probeResult($probe));
                $this->assertSame('600', (string) $this->control->table('payment_allocations')->where('org_id', $org)->sum('amount_minor'));
                $this->assertSame(1, $this->control->table('audit_logs')->where('org_id', $org)->where('event', 'manual_payment.allocate')->count());
            } finally {
                $probe?->stop(0);
            }
        }
    }
}
