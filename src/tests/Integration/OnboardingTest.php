<?php

namespace Tests\Integration;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Onboarding;
use App\Services\UploadScanner;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    private function workspace(): array
    {
        $u = User::factory()->create();
        $o = app(Workspaces::class)->create($u->id, 'Onboarding workspace');
        $this->actingAs($u)->withSession(['active_org_id' => $o]);
        $lead = $this->postJson('/pipeline', ['brand_name' => 'Private brand', 'value_minor' => '123', 'currency' => 'PHP', 'notes' => 'PRIVATE NEVER EXPOSE'])->json('id');
        $invite = $this->postJson('/onboarding-requests', ['pipeline_entry_id' => $lead, 'label' => 'Brand onboarding'])->assertCreated()->json();

        $this->deleteJson('/pipeline/'.$lead, ['version' => 1])->assertConflict()->assertJsonValidationErrors('entry');

        return [$u, $o, $lead, $invite['id'], substr($invite['url'], strpos($invite['url'], '#token=') + 7)];
    }

    private function fields(): array
    {
        return ['business_name' => 'Studio', 'contact_name' => 'Sam', 'business_email' => 'sam@example.test', 'website' => 'https://example.test', 'brief' => 'A small collaboration.'];
    }

    public function test_one_use_invitation_save_resume_safe_projection_idempotent_submission_and_immutable_correction_history(): void
    {
        [$u,$o,$lead,$id,$token] = $this->workspace();
        auth()->logout();
        $this->postJson('/onboarding/session', ['token' => $token])->assertOk()->assertJsonPath('id', $id);
        $this->postJson('/onboarding/session', ['token' => $token])->assertNotFound();
        $this->patchJson('/onboarding/form/'.$id, [...$this->fields(), 'version' => 1, 'org_id' => (string) Str::uuid(), 'status' => 'accepted', 'notes' => 'forged'])->assertOk();
        $this->getJson('/onboarding/form/'.$id)->assertJsonPath('form.business_name', 'Studio')->assertJsonPath('form.status', 'draft')->assertJsonPath('form.version', 2)
            ->assertJsonMissingPath('form.pipeline_entry_id')->assertJsonMissingPath('form.token_hash')->assertDontSee('PRIVATE NEVER EXPOSE');
        $this->patchJson('/onboarding/form/'.$id, [...$this->fields(), 'version' => 1])->assertConflict();
        $key = (string) Str::uuid();
        $data = [...$this->fields(), 'version' => 2, 'consent' => true, 'idempotency_key' => $key];
        $first = $this->postJson('/onboarding/form/'.$id.'/submit', $data)->assertOk()->json('submission_id');
        $this->postJson('/onboarding/form/'.$id.'/submit', $data)->assertOk()->assertJsonPath('submission_id', $first);
        $this->postJson('/onboarding/form/'.$id.'/submit', [...$data, 'brief' => 'Changed'])->assertConflict();
        $this->actingAs($u)->postJson('/onboarding-requests/'.$id.'/correct', ['version' => 3, 'note' => 'Please clarify the brief.'])->assertOk();
        $this->getJson('/onboarding/form/'.$id)->assertJsonPath('form.correction_note', 'Please clarify the brief.');
        $this->postJson('/onboarding/form/'.$id.'/submit', [...$data, 'version' => 4, 'brief' => 'Corrected brief', 'idempotency_key' => (string) Str::uuid()])->assertOk();
        app(TenantContext::class)->run($u->id, $o, function () use ($id, $lead): void {
            $this->assertSame(2, DB::table('onboarding_submissions')->where('request_id', $id)->count());
            $this->assertSame(0, DB::table('onboarding_submissions')->where('request_id', $id)->update(['revision' => 99]));
            $this->assertSame(0, DB::table('onboarding_submissions')->where('request_id', $id)->delete());
            $this->assertSame('lead', DB::table('pipeline_entries')->where('id', $lead)->value('stage'));
        });
        $this->postJson('/onboarding-requests/'.$id.'/revoke', ['version' => 5])->assertOk();
        $this->getJson('/onboarding/form/'.$id)->assertForbidden();
    }

    public function test_upload_is_quarantined_on_scanner_failure_cannot_download_or_submit_and_can_remove_unused_file(): void
    {
        [$u,$o,$lead,$id,$token] = $this->workspace();
        Storage::fake('onboarding');
        $this->mock(UploadScanner::class, fn ($m) => $m->shouldReceive('scan')->once()->andReturn('pending'));
        $this->postJson('/onboarding/session', ['token' => $token])->assertOk();
        $doc = $this->postJson('/onboarding/form/'.$id.'/documents', ['version' => 1, 'file' => UploadedFile::fake()->createWithContent('brief.txt', 'A synthetic non-sensitive collaboration brief.')])->assertCreated()->json('id');
        $this->getJson('/onboarding/form/'.$id)->assertJsonPath('form.documents.0.scan_state', 'pending');
        $this->getJson('/onboarding/form/'.$id.'/documents/'.$doc)->assertForbidden();
        $this->postJson('/onboarding/form/'.$id.'/submit', [...$this->fields(), 'version' => 2, 'consent' => true, 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->deleteJson('/onboarding/form/'.$id.'/documents/'.$doc, ['version' => 2])->assertOk();
        $this->getJson('/onboarding/form/'.$id)->assertJsonCount(0, 'form.documents');
        $this->postJson('/onboarding/form/'.$id.'/documents', ['version' => 3, 'file' => UploadedFile::fake()->createWithContent('script.html', '<html><script>alert(1)</script></html>')])->assertUnprocessable();
        $this->postJson('/onboarding/form/'.$id.'/documents', ['version' => 3, 'file' => UploadedFile::fake()->create('large.pdf', 2049, 'application/pdf')])->assertUnprocessable();
    }

    public function test_valid_capability_cannot_read_crm_other_requests_or_foreign_files_and_partial_props_are_allowlisted(): void
    {
        [$u,$o,$lead,$id,$token] = $this->workspace();
        $service = app(Onboarding::class);
        $other = (string) Str::uuid();
        $foreignUser = User::factory()->create();
        $foreignOrg = app(Workspaces::class)->create($foreignUser->id, 'Hidden');
        $secret = $service->exchange($token);
        $service->publicRun($secret, function ($org, $request) use ($o, $id, $foreignOrg): void {
            $this->assertSame($o, $org);
            $this->assertSame($id, $request);
            $this->assertSame(0, DB::table('pipeline_entries')->count());
            $this->assertSame(0, DB::table('brand_contacts')->count());
            $this->assertSame(0, DB::table('onboarding_requests')->where('org_id', $foreignOrg)->count());
            try {
                DB::transaction(fn () => DB::table('onboarding_requests')->where('id', $id)->update(['org_id' => $foreignOrg]));
                $this->fail('Capability changed owner');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0]);
            }
        });
        $this->withSession(['onboarding' => [$id => Crypt::encryptString($secret)]]);
        $this->getJson('/onboarding/form/'.$other)->assertForbidden();
        $this->getJson('/onboarding/form/'.$id.'/documents/'.$other)->assertNotFound();
        $this->withoutVite();
        $this->get('/onboarding/form/'.$id, ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '',
            'X-Inertia-Partial-Component' => 'OnboardingForm', 'X-Inertia-Partial-Data' => 'form,notes,pipeline_entry_id,org_id,token_hash'])
            ->assertOk()->assertJsonMissingPath('props.notes')->assertJsonMissingPath('props.org_id')->assertDontSee('PRIVATE NEVER EXPOSE');
        app(TenantContext::class)->run($u->id, $o, fn () => DB::table('onboarding_requests')->where('id', $id)->update(['expires_at' => now()->subMinute()]));
        $this->getJson('/onboarding/form/'.$id)->assertForbidden();
        $this->assertNull(DB::selectOne("SELECT nullif(current_setting('app.capability',true),'') AS cap")->cap);
    }

    public function test_media_kit_has_explicit_publication_consent_and_revocable_idempotent_lead_links(): void
    {
        [$u,$o] = $this->workspace();
        $kit = ['title' => 'Creator kit', 'bio' => 'Public introduction', 'website' => 'https://example.test', 'published' => true, 'version' => 0];
        $this->putJson('/media-kit', $kit)->assertUnprocessable();
        $this->putJson('/media-kit', [...$kit, 'consent' => true, 'social_links' => array_fill(0, 9, ['label' => 'Many', 'url' => 'https://example.test'])])->assertUnprocessable();
        $this->putJson('/media-kit', [...$kit, 'consent' => true, 'social_links' => [['label' => 'Bad', 'url' => 'javascript:alert(1)']]])->assertUnprocessable();
        $this->putJson('/media-kit', [...$kit, 'consent' => true, 'social_links' => [['label' => 'Bad', 'url' => 'https://example.test', 'private_note' => 'never expose']]])->assertUnprocessable();
        $this->putJson('/media-kit', [...$kit, 'consent' => true, 'social_links' => [['label' => 'Creator channel', 'url' => 'https://example.test/channel']]])->assertOk();
        $kitId = $this->getJson('/public-presence')->assertJsonPath('kit.social_links.0.label', 'Creator channel')->json('kit.id');
        app(TenantContext::class)->run($u->id, $o, fn () => DB::table('media_kits')->where('id', $kitId)->update(['social_links' => json_encode([['label' => 'Creator channel', 'url' => 'https://example.test/channel', 'private_note' => 'SECRET PROFILE METADATA']])]));
        $this->get('/kits/'.$kitId)->assertOk()->assertDontSee('SECRET PROFILE METADATA')->assertSee('Public introduction')->assertSee('Creator channel')->assertSee('https://example.test/channel')->assertDontSee('PRIVATE NEVER EXPOSE');
        $this->putJson('/media-kit', [...$kit, 'published' => false, 'version' => 1])->assertOk();
        $this->get('/kits/'.$kitId)->assertNotFound();
        $link = $this->postJson('/lead-links')->assertCreated()->json();
        $token = substr($link['url'], strpos($link['url'], '#token=') + 7);
        $payload = ['token' => $token, 'brand_name' => 'Inbound brand', 'contact_name' => 'Inbound contact', 'email' => 'inbound@example.test', 'consent' => true, 'idempotency_key' => (string) Str::uuid()];
        $this->postJson('/lead', $payload)->assertOk();
        $this->postJson('/lead', $payload)->assertOk();
        $this->postJson('/lead', [...$payload, 'brand_name' => 'Changed'])->assertConflict();
        $this->getJson('/pipeline?q=Inbound')->assertJsonCount(1, 'entries');
        $this->deleteJson('/lead-links/'.$link['id'])->assertOk();
        $this->postJson('/lead', $payload)->assertNotFound();
    }

    public function test_replacement_invitation_ends_old_sessions_and_requires_a_new_one_use_exchange(): void
    {
        [$u,$org,$lead,$id,$token] = $this->workspace();
        $this->postJson('/onboarding/session', ['token' => $token])->assertOk();
        $renew = $this->postJson('/onboarding-requests/'.$id.'/renew', ['version' => 1])->assertOk()->json('url');
        $this->getJson('/onboarding/form/'.$id)->assertForbidden();
        $newToken = substr($renew, strpos($renew, '#token=') + 7);
        $this->postJson('/onboarding/session', ['token' => $newToken])->assertOk();
        $this->getJson('/onboarding/form/'.$id)->assertOk()->assertJsonPath('form.version', 2);
    }
}
