<?php

namespace Tests\Acceptance;

use App\Models\User;
use App\Services\Onboarding;
use App\Services\UploadScanner;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class OnboardingSecurityTest extends AcceptanceCase
{
    public function test_roles_and_revocation_cover_onboarding_and_publication(): void
    {
        $u = User::factory()->create();
        $org = app(Workspaces::class)->create($u->id, 'M3 roles');
        $this->actingAs($u)->withSession(['active_org_id' => $org]);
        $lead = $this->postJson('/pipeline', ['brand_name' => 'Role lead', 'value_minor' => '0', 'currency' => 'PHP'])->json('id');
        $version = 0;
        foreach (['owner', 'admin', 'manager', 'member'] as $role) {
            $this->control->table('memberships')->where('org_id', $org)->where('user_id', $u->id)->update(['role' => $role]);
            $this->putJson('/media-kit', ['title' => 'Safe public kit', 'published' => false, 'version' => $version++])->assertOk();
            $this->postJson('/lead-links')->assertCreated();
            $request = $this->postJson('/onboarding-requests', ['pipeline_entry_id' => $lead, 'label' => 'Role invitation'])->assertCreated()->json('id');
        }
        $this->control->table('memberships')->where('org_id', $org)->where('user_id', $u->id)->update(['role' => 'viewer']);
        $this->getJson('/onboarding-requests')->assertOk()->assertJsonPath('canWrite', false);
        $this->postJson('/onboarding-requests', ['pipeline_entry_id' => $lead, 'label' => 'forged'])->assertForbidden();
        $this->postJson('/onboarding-requests/'.$request.'/revoke', ['version' => 1])->assertForbidden();
        $this->postJson('/lead-links')->assertForbidden();
        $this->putJson('/media-kit', ['title' => 'forged', 'published' => false, 'version' => $version])->assertForbidden();
        app(TenantContext::class)->run($u->id, $org, function (): void {
            $this->assertSame(0, DB::table('media_kits')->update(['published' => true]));
            $this->assertSame(0, DB::table('onboarding_requests')->update(['status' => 'revoked']));
        });
        $this->control->table('memberships')->where('org_id', $org)->where('user_id', $u->id)->update(['revoked_at' => now()]);
        $this->getJson('/onboarding-requests')->assertForbidden();
        $this->getJson('/public-presence')->assertForbidden();
    }

    public function test_all_new_tables_deny_foreign_reads_updates_deletes_and_inserts_and_functions_have_restricted_privileges(): void
    {
        $u = User::factory()->create();
        $org = app(Workspaces::class)->create($u->id, 'Own');
        $v = User::factory()->create();
        $foreign = app(Workspaces::class)->create($v->id, 'Foreign');
        $lead = (string) Str::uuid();
        $request = (string) Str::uuid();
        $link = (string) Str::uuid();
        $this->control->table('pipeline_entries')->insert(['id' => $lead, 'org_id' => $foreign, 'brand_name' => 'Private', 'currency' => 'PHP']);
        $rows = [
            'media_kits' => ['id' => (string) Str::uuid(), 'org_id' => $foreign, 'title' => 'private'],
            'lead_links' => ['id' => $link, 'org_id' => $foreign, 'token_hash' => hash('sha256', Str::random()), 'expires_at' => now()->addDay()],
            'public_lead_receipts' => ['id' => (string) Str::uuid(), 'org_id' => $foreign, 'link_id' => $link, 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'data')],
            'onboarding_requests' => ['id' => $request, 'org_id' => $foreign, 'pipeline_entry_id' => $lead, 'label' => 'private', 'token_hash' => hash('sha256', Str::random()), 'expires_at' => now()->addDay()],
            'onboarding_sessions' => ['id' => (string) Str::uuid(), 'org_id' => $foreign, 'request_id' => $request, 'token_hash' => hash('sha256', Str::random()), 'expires_at' => now()->addDay()],
            'onboarding_documents' => ['id' => (string) Str::uuid(), 'org_id' => $foreign, 'request_id' => $request, 'name' => 'secret.txt', 'object_key' => (string) Str::uuid(), 'checksum' => hash('sha256', 'x'), 'size' => 1, 'mime' => 'text/plain'],
            'onboarding_submissions' => ['id' => (string) Str::uuid(), 'org_id' => $foreign, 'request_id' => $request, 'revision' => 1, 'snapshot' => '{}', 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'x')],
            'onboarding_events' => ['id' => (string) Str::uuid(), 'org_id' => $foreign, 'request_id' => $request, 'kind' => 'submitted'],
        ];
        foreach ($rows as $table => $row) {
            $this->control->table($table)->insert($row);
        }
        app(TenantContext::class)->run($u->id, $org, function () use ($rows, $org): void {
            foreach ($rows as $table => $row) {
                $this->assertSame(0, DB::table($table)->count(), $table);
                $this->assertSame(0, DB::table($table)->where('id', $row['id'])->update(['org_id' => $org]), $table);
                $this->assertSame(0, DB::table($table)->where('id', $row['id'])->delete(), $table);
                try {
                    DB::transaction(fn () => DB::table($table)->insert([...$row, 'id' => (string) Str::uuid()]));
                    $this->fail('foreign insert '.$table);
                } catch (QueryException $e) {
                    $this->assertSame('42501', $e->errorInfo[0], $table);
                }
            }
        });
        foreach (array_keys($rows) as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $functions = ['app_onboarding_access', 'app_onboarding_scope', 'app_exchange_onboarding', 'app_public_kit', 'app_capture_lead'];
        foreach ($functions as $function) {
            $meta = $this->control->selectOne('SELECT prosecdef,proconfig,pg_get_userbyid(proowner) AS owner FROM pg_proc WHERE proname=?', [$function]);
            $this->assertTrue($meta->prosecdef);
            $this->assertSame('weavdash_migrator', $meta->owner);
            $this->assertStringContainsString('search_path=pg_catalog, public', $meta->proconfig);
            $this->assertSame(0, (int) $this->control->selectOne('SELECT count(*) AS n FROM pg_proc p,aclexplode(p.proacl) a WHERE p.proname=? AND a.grantee=0', [$function])->n);
        }
    }

    public function test_expired_invites_and_sessions_fail_and_real_scanner_rejects_test_signature(): void
    {
        $u = User::factory()->create();
        $org = app(Workspaces::class)->create($u->id, 'Expiry');
        $this->actingAs($u)->withSession(['active_org_id' => $org]);
        $lead = $this->postJson('/pipeline', ['brand_name' => 'Expiry', 'value_minor' => '0', 'currency' => 'PHP'])->json('id');
        $invite = $this->postJson('/onboarding-requests', ['pipeline_entry_id' => $lead, 'label' => 'Expired'])->json();
        $token = substr($invite['url'], strpos($invite['url'], '#token=') + 7);
        $this->control->table('onboarding_requests')->where('id', $invite['id'])->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/onboarding/session', ['token' => $token])->assertNotFound();
        $this->control->table('onboarding_requests')->where('id', $invite['id'])->update(['expires_at' => now()->addDay()]);
        $this->postJson('/onboarding/session', ['token' => $token])->assertOk();
        $this->control->table('onboarding_sessions')->where('request_id', $invite['id'])->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/onboarding/form/'.$invite['id'])->assertForbidden();
        $this->control->table('onboarding_sessions')->where('request_id', $invite['id'])->update(['expires_at' => now()->addHour()]);
        $infected = 'X5O!P%@AP[4'.'\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
        $upload = $this->postJson('/onboarding/form/'.$invite['id'].'/documents', ['version' => 1, 'file' => UploadedFile::fake()->createWithContent('scan-test.txt', $infected)])->assertCreated()->json('id');
        $this->getJson('/onboarding/form/'.$invite['id'])->assertJsonPath('form.documents.0.scan_state', 'rejected');
        $this->getJson('/onboarding/form/'.$invite['id'].'/documents/'.$upload)->assertForbidden();
        $scanner = app(UploadScanner::class);
        $this->assertSame('clean', $scanner->scan('A synthetic collaboration reference.'));
        $test = 'X5O!P%@AP[4'.'\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
        $this->assertSame('rejected', $scanner->scan($test));
        config(['uploads.scanner_host' => '127.0.0.1']);
        $this->assertSame('pending', $scanner->scan('A synthetic file.'));
    }

    public function test_revocation_that_locks_first_blocks_and_then_denies_a_waiting_capability(): void
    {
        $u = User::factory()->create();
        $org = app(Workspaces::class)->create($u->id, 'Concurrent revoke');
        $this->actingAs($u)->withSession(['active_org_id' => $org]);
        $lead = $this->postJson('/pipeline', ['brand_name' => 'Concurrent', 'value_minor' => '0', 'currency' => 'PHP'])->json('id');
        $invite = $this->postJson('/onboarding-requests', ['pipeline_entry_id' => $lead, 'label' => 'Concurrent invitation'])->json();
        $token = substr($invite['url'], strpos($invite['url'], '#token=') + 7);
        $secret = app(Onboarding::class)->exchange($token);
        $this->control->beginTransaction();
        $this->control->table('onboarding_requests')->where('id', $invite['id'])->update(['status' => 'revoked']);
        $process = new Process([PHP_BINARY, 'tests/Fixtures/onboarding-capability.php', $secret], base_path(), ['APP_ENV' => 'testing', 'TEST_MIGRATION_DB_PASSWORD' => false]);
        $process->setTimeout(15);
        $process->start();
        $this->eventually(fn () => str_contains($process->getOutput(), "\n"), 'Probe did not start');
        $this->assertBlocked($process);
        $this->control->commit();
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString('denied:403', $process->getOutput());
    }
}
