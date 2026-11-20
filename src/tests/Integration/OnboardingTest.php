<?php

namespace Tests\Integration;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Onboarding;
use App\Services\OnboardingDocuments;
use App\Services\UploadScanner;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToWriteFile;
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

    public function test_storage_write_failure_keeps_document_metadata_and_request_version_unchanged(): void
    {
        [$user, $org, $lead, $id] = $this->workspace();
        Storage::shouldReceive('disk')->with('onboarding')->once()->andReturnSelf();
        Storage::shouldReceive('put')->once()->andThrow(UnableToWriteFile::atLocation('synthetic'));
        app(TenantContext::class)->run($user->id, $org, function () use ($org, $id): void {
            try {
                DB::transaction(fn () => app(OnboardingDocuments::class)->upload($org, $id, 1, UploadedFile::fake()->createWithContent('test.txt', 'synthetic')));
                $this->fail('Storage failure must propagate');
            } catch (UnableToWriteFile) {
                $this->assertSame(0, DB::table('onboarding_documents')->where('request_id', $id)->count());
                $this->assertSame(1, DB::table('onboarding_requests')->where('id', $id)->value('version'));
            }
        });
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

    public function test_staging_unscanned_upload_is_labelled_and_requires_opt_in_for_download_and_submission(): void
    {
        config(['staging.enabled' => true, 'uploads.staging_unscanned' => true, 'uploads.enabled' => true]);
        [$u, $org, $lead, $id, $token] = $this->workspace();
        Storage::fake('onboarding');
        $this->mock(UploadScanner::class, fn ($mock) => $mock->shouldNotReceive('scan'));
        $this->postJson('/onboarding/session', ['token' => $token])->assertOk();
        $bytes = 'A non-sensitive staging test reference.';
        $doc = $this->postJson('/onboarding/form/'.$id.'/documents', ['version' => 1,
            'file' => UploadedFile::fake()->createWithContent('staging.txt', $bytes)])->assertCreated()->json('id');
        $this->getJson('/onboarding/form/'.$id)->assertJsonPath('form.documents.0.scan_state', 'unscanned')
            ->assertJsonPath('form.documents.0.downloadable', true);
        $this->get('/onboarding/form/'.$id.'/documents/'.$doc)->assertOk()->assertContent($bytes)
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        config(['staging.enabled' => false]);
        $this->getJson('/onboarding/form/'.$id)->assertJsonPath('form.documents.0.downloadable', false);
        $this->getJson('/onboarding/form/'.$id.'/documents/'.$doc)->assertForbidden();
        $this->postJson('/onboarding/form/'.$id.'/submit', [...$this->fields(), 'version' => 2,
            'consent' => true, 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        config(['staging.enabled' => true, 'uploads.staging_unscanned' => false]);
        $this->getJson('/onboarding/form/'.$id.'/documents/'.$doc)->assertForbidden();
        config(['uploads.staging_unscanned' => true]);
        $submission = $this->postJson('/onboarding/form/'.$id.'/submit', [...$this->fields(), 'version' => 2,
            'consent' => true, 'idempotency_key' => (string) Str::uuid()])->assertOk()->json('submission_id');
        app(TenantContext::class)->run($u->id, $org, function () use ($submission): void {
            $snapshot = json_decode(DB::table('onboarding_submissions')->where('id', $submission)->value('snapshot'), true);
            $this->assertSame('unscanned', $snapshot['documents'][0]['scan_state']);
        });
    }

    public function test_disabled_uploads_allow_removing_pending_and_rejected_files_then_submitting_without_files(): void
    {
        Storage::fake('onboarding');
        $this->mock(UploadScanner::class, fn ($mock) => $mock->shouldReceive('scan')->twice()->andReturn('pending', 'rejected'));
        foreach (['pending', 'rejected'] as $state) {
            config(['uploads.enabled' => true, 'staging.enabled' => false, 'uploads.staging_unscanned' => false]);
            [$user, $org, $lead, $id, $token] = $this->workspace();
            auth()->logout();
            $this->postJson('/onboarding/session', ['token' => $token])->assertOk();
            $doc = $this->postJson('/onboarding/form/'.$id.'/documents', ['version' => 1,
                'file' => UploadedFile::fake()->createWithContent('reference.txt', 'Synthetic reference')])->assertCreated()->json('id');
            $key = 'onboarding/'.$org.'/'.$id.'/'.$doc;
            Storage::disk('onboarding')->assertExists($key);
            config(['uploads.enabled' => false]);
            $this->getJson('/onboarding/form/'.$id)->assertJsonPath('form.documents.0.scan_state', $state);
            $this->postJson('/onboarding/form/'.$id.'/documents', ['version' => 2,
                'file' => UploadedFile::fake()->createWithContent('new.txt', 'Blocked')])
                ->assertUnprocessable()->assertJsonValidationErrors('file');
            $this->postJson('/onboarding/form/'.$id.'/submit', [...$this->fields(), 'version' => 2,
                'consent' => true, 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
            $this->deleteJson('/onboarding/form/'.$id.'/documents/'.$doc, ['version' => 1])->assertConflict();
            $this->deleteJson('/onboarding/form/'.$id.'/documents/'.Str::uuid(), ['version' => 2])->assertNotFound();
            Storage::disk('onboarding')->assertExists($key);
            $this->deleteJson('/onboarding/form/'.$id.'/documents/'.$doc, ['version' => 2])->assertOk();
            Storage::disk('onboarding')->assertMissing($key);
            $this->getJson('/onboarding/form/'.$id)->assertJsonCount(0, 'form.documents')->assertJsonPath('form.version', 3);
            $this->postJson('/onboarding/form/'.$id.'/submit', [...$this->fields(), 'version' => 3,
                'consent' => true, 'idempotency_key' => (string) Str::uuid()])->assertOk();
        }
    }

    public function test_disabled_uploads_preserve_submitted_file_and_revoked_capability_removal_guards(): void
    {
        config(['uploads.enabled' => true, 'staging.enabled' => false, 'uploads.staging_unscanned' => false]);
        Storage::fake('onboarding');
        $this->mock(UploadScanner::class, fn ($mock) => $mock->shouldReceive('scan')->once()->andReturn('clean'));
        [$user, $org, $lead, $id, $token] = $this->workspace();
        auth()->logout();
        $this->postJson('/onboarding/session', ['token' => $token])->assertOk();
        $doc = $this->postJson('/onboarding/form/'.$id.'/documents', ['version' => 1,
            'file' => UploadedFile::fake()->createWithContent('reference.txt', 'Submitted reference')])->assertCreated()->json('id');
        $this->postJson('/onboarding/form/'.$id.'/submit', [...$this->fields(), 'version' => 2,
            'consent' => true, 'idempotency_key' => (string) Str::uuid()])->assertOk();
        config(['uploads.enabled' => false]);
        $this->deleteJson('/onboarding/form/'.$id.'/documents/'.$doc, ['version' => 3])->assertConflict();
        $this->actingAs($user)->postJson('/onboarding-requests/'.$id.'/correct', ['version' => 3, 'note' => 'Clarify brief'])->assertOk();
        auth()->logout();
        $this->deleteJson('/onboarding/form/'.$id.'/documents/'.$doc, ['version' => 4])->assertConflict();
        Storage::disk('onboarding')->assertExists('onboarding/'.$org.'/'.$id.'/'.$doc);
        $this->actingAs($user)->postJson('/onboarding-requests/'.$id.'/revoke', ['version' => 4])->assertOk();
        auth()->logout();
        $this->deleteJson('/onboarding/form/'.$id.'/documents/'.$doc, ['version' => 5])->assertForbidden();
        Storage::disk('onboarding')->assertExists('onboarding/'.$org.'/'.$id.'/'.$doc);
    }

    public function test_disabled_uploads_do_not_allow_removing_another_workspaces_file(): void
    {
        config(['uploads.enabled' => true, 'staging.enabled' => true, 'uploads.staging_unscanned' => true]);
        Storage::fake('onboarding');
        [$user, $org, $lead, $id, $token] = $this->workspace();
        $this->postJson('/onboarding/session', ['token' => $token])->assertOk();
        $doc = $this->postJson('/onboarding/form/'.$id.'/documents', ['version' => 1,
            'file' => UploadedFile::fake()->createWithContent('private.txt', 'Foreign workspace reference')])->assertCreated()->json('id');

        [$otherUser, $otherOrg, $otherLead, $otherId, $otherToken] = $this->workspace();
        auth()->logout();
        $this->flushSession();
        $this->postJson('/onboarding/session', ['token' => $otherToken])->assertOk();
        config(['uploads.enabled' => false]);
        $this->deleteJson('/onboarding/form/'.$id.'/documents/'.$doc, ['version' => 2])->assertForbidden();
        $this->deleteJson('/onboarding/form/'.$otherId.'/documents/'.$doc, ['version' => 1])->assertNotFound();
        Storage::disk('onboarding')->assertExists('onboarding/'.$org.'/'.$id.'/'.$doc);
        app(TenantContext::class)->run($user->id, $org, function () use ($doc): void {
            $this->assertTrue(DB::table('onboarding_documents')->where('id', $doc)->exists());
        });
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

    public function test_request_list_filters_searches_pages_and_details_with_owner_only_leads(): void
    {
        [$u, $o, $lead, $first, $token] = $this->workspace();
        $other = $this->postJson('/pipeline', ['brand_name' => 'Glow Labs', 'value_minor' => '0', 'currency' => 'PHP'])->json('id');
        $ids = [$first];
        for ($i = 0; $i < 21; $i++) {
            $ids[] = $this->postJson('/onboarding-requests', ['pipeline_entry_id' => $other, 'label' => 'Batch '.$i])->json('id');
        }
        auth()->logout();
        $this->postJson('/onboarding/session', ['token' => $token])->assertOk();
        $this->postJson('/onboarding/form/'.$first.'/submit', [...$this->fields(), 'version' => 1, 'consent' => true, 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $this->actingAs($u)->withSession(['active_org_id' => $o]);
        app(TenantContext::class)->run($u->id, $o, fn () => DB::table('onboarding_requests')->where('id', $ids[1])->update(['status' => 'revoked']));

        // Requests awaiting review open first.
        $this->getJson('/onboarding-requests')->assertJsonPath('filters.status', 'submitted')
            ->assertJsonPath('requests.counts', ['submitted' => 1, 'draft' => 20, 'correction' => 0, 'accepted' => 0, 'revoked' => 1, 'all' => 22])
            ->assertJsonCount(1, 'requests.items')->assertJsonPath('requests.items.0.id', $first)
            ->assertJsonPath('requests.items.0.brand_name', 'Private brand')->assertJsonMissingPath('requests.items.0.notes')
            ->assertJsonPath('leads.0.brand_name', 'Glow Labs')->assertJsonCount(2, 'leads');
        $submittedAt = $this->getJson('/onboarding-requests')->json('requests.items.0.submitted_at');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $submittedAt);
        // A direct link to one request lists every status, with the request's details.
        $this->getJson('/onboarding-requests?request='.$first)->assertJsonPath('filters.status', 'all')
            ->assertJsonPath('requests.total', 22)->assertJsonCount(20, 'requests.items')->assertJsonPath('requests.perPage', 20)
            ->assertJsonPath('selected.brand_name', 'Private brand')->assertJsonPath('selected.pipeline_entry_id', $lead)
            ->assertJsonPath('selected.submitted_at', $submittedAt)->assertJsonPath('submissions.0.revision', 1);
        $this->getJson('/onboarding-requests?status=all&page=2')->assertJsonCount(2, 'requests.items')->assertJsonPath('requests.page', 2);
        $this->getJson('/onboarding-requests?status=draft&page=9')->assertJsonPath('requests.page', 1)->assertJsonPath('requests.total', 20);
        $this->getJson('/onboarding-requests?status=all&q=private')->assertJsonPath('requests.total', 1)->assertJsonPath('requests.counts.submitted', 1);
        $this->getJson('/onboarding-requests?status=all&q=batch%201')->assertJsonPath('requests.counts.all', 11);
        $this->getJson('/onboarding-requests?status=forged')->assertUnprocessable();
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

    public function test_media_kit_introduction_is_sanitized_formatted_text_and_legacy_text_becomes_paragraphs(): void
    {
        [$u,$o] = $this->workspace();
        $kit = ['title' => 'Formatted kit', 'website' => '', 'published' => true, 'consent' => true, 'version' => 0];
        $dirty = '<h3 onclick="x()">About</h3><p style="color:red">I <strong>review</strong> <em>gear</em> <script>alert(1)</script>'
            .'<a href="javascript:alert(1)">bad</a> <a href="https://example.test/work" target="_blank">work</a></p>'
            .'<ul><li>Video</li></ul><img src="https://example.test/x.png"><iframe src="https://example.test"></iframe>';
        $this->putJson('/media-kit', [...$kit, 'bio' => $dirty])->assertOk();
        $html = $this->getJson('/public-presence')->json('kit.bio');
        $this->assertStringContainsString('<h3>About</h3>', $html);
        $this->assertStringContainsString('<strong>review</strong>', $html);
        $this->assertStringContainsString('<a href="https://example.test/work" rel="noopener noreferrer nofollow">work</a>', $html);
        $this->assertStringContainsString('<ul><li>Video</li></ul>', $html);
        foreach (['script', 'onclick', 'style=', 'javascript:', '<img', '<iframe', 'target='] as $removed) {
            $this->assertStringNotContainsString($removed, $html);
        }
        $kitId = $this->getJson('/public-presence')->json('kit.id');
        $page = $this->get('/kits/'.$kitId)->assertOk()->assertSee('<strong>review</strong>', false)->assertDontSee('<script>alert', false)
            ->assertSee('<meta name="description" content="About I review gear bad work Video">', false);

        // Stored values written before formatting, and anything that bypassed the save path, are cleaned again on display.
        app(TenantContext::class)->run($u->id, $o, fn () => DB::table('media_kits')->where('id', $kitId)->update(['bio' => "First line\nsecond <b>line</b>\n\nNext paragraph"]));
        $this->getJson('/public-presence')->assertJsonPath('kit.bio', '<p>First line<br />second &lt;b&gt;line&lt;/b&gt;</p><p>Next paragraph</p>');
        app(TenantContext::class)->run($u->id, $o, fn () => DB::table('media_kits')->where('id', $kitId)->update(['bio' => '<p>Hi<img src=x onerror=alert(1)></p>']));
        $this->get('/kits/'.$kitId)->assertOk()->assertDontSee('onerror', false)->assertSee('<p>Hi</p>', false);

        $this->putJson('/media-kit', [...$kit, 'bio' => '<p></p><p> </p>', 'version' => 1])->assertOk();
        $this->getJson('/public-presence')->assertJsonPath('kit.bio', '');
        $this->putJson('/media-kit', [...$kit, 'bio' => '<p><strong>'.str_repeat('a', 5000).'</strong></p>', 'version' => 2])->assertOk();
        $this->putJson('/media-kit', [...$kit, 'bio' => '<p>'.str_repeat('a', 5001).'</p>', 'version' => 3])->assertUnprocessable()->assertJsonValidationErrors('bio');
    }

    public function test_lead_links_have_labels_statuses_lead_counts_and_pages(): void
    {
        [$u,$o] = $this->workspace();
        $first = $this->postJson('/lead-links', ['label' => '  Instagram bio  '])->assertCreated()->json();
        $this->postJson('/lead-links', ['label' => str_repeat('x', 81)])->assertUnprocessable();
        $token = substr($first['url'], strpos($first['url'], '#token=') + 7);
        foreach (['One', 'Two'] as $brand) {
            $this->postJson('/lead', ['token' => $token, 'brand_name' => $brand, 'contact_name' => 'C', 'email' => 'c@example.test', 'consent' => true, 'idempotency_key' => (string) Str::uuid()])->assertOk();
        }
        $expired = $this->postJson('/lead-links')->json('id');
        $revoked = $this->postJson('/lead-links')->json('id');
        app(TenantContext::class)->run($u->id, $o, fn () => DB::table('lead_links')->where('id', $expired)->update(['expires_at' => now()->subDay()]));
        $this->delete('/lead-links/'.$revoked, ['status' => 'all', 'page' => 1])->assertRedirect('/partner/media-kit?status=all');
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/lead-links', ['label' => 'Batch '.$i]);
        }
        $this->getJson('/public-presence')
            ->assertJsonPath('links.counts', ['active' => 11, 'expired' => 1, 'revoked' => 1, 'all' => 13])
            ->assertJsonPath('links.total', 11)->assertJsonCount(10, 'links.items')->assertJsonPath('links.items.0.label', 'Batch 9')
            ->assertJsonPath('summary', ['views' => 0, 'activeLinks' => 11, 'leads' => 2])->assertJsonMissingPath('links.items.0.token_hash');
        $this->getJson('/public-presence?page=2')->assertJsonCount(1, 'links.items')->assertJsonPath('links.items.0.label', 'Instagram bio')
            ->assertJsonPath('links.items.0.leads', 2)->assertJsonPath('links.items.0.status', 'active');
        $this->getJson('/public-presence?status=expired')->assertJsonPath('links.items.0.id', $expired)->assertJsonPath('links.items.0.status', 'expired');
        $this->getJson('/public-presence?status=revoked&page=9')->assertJsonPath('links.page', 1)->assertJsonPath('links.items.0.status', 'revoked');
        $this->getJson('/public-presence?status=forged')->assertUnprocessable();
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
