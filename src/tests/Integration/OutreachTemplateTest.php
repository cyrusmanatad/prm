<?php

namespace Tests\Integration;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\OutreachTemplates;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class OutreachTemplateTest extends TestCase
{
    private function setupWorkspace(): array
    {
        $user = User::factory()->create();
        $org = app(Workspaces::class)->create($user->id, 'Templates');
        $this->actingAs($user)->withSession(['active_org_id' => $org]);

        return [$user, $org];
    }

    private function details(): array
    {
        return ['name' => 'Private pitch', 'subject' => 'Hello {{brand_name}}', 'body' => 'Secret pitch for {{contact_name}}'];
    }

    public function test_crud_validation_conflicts_forgery_and_redacted_audit(): void
    {
        [$user, $org] = $this->setupWorkspace();
        $id = $this->postJson('/outreach-templates', [...$this->details(), 'org_id' => (string) Str::uuid(), 'version' => 90])->assertCreated()->json('id');
        $this->getJson('/outreach-templates')->assertJsonPath('templates.0.version', 1)->assertJsonMissingPath('templates.0.org_id');
        $this->patchJson('/outreach-templates/'.$id, [...$this->details(), 'version' => 1, 'name' => 'Updated'])->assertOk();
        $this->patchJson('/outreach-templates/'.$id, [...$this->details(), 'version' => 1])->assertConflict();
        $this->deleteJson('/outreach-templates/'.$id, ['version' => 1])->assertConflict();
        $this->from('/outreach-templates')->patch('/outreach-templates/'.$id, [...$this->details(), 'version' => 1], ['X-Inertia' => 'true'])
            ->assertRedirect('/outreach-templates')->assertSessionHasErrors('version');
        $this->postJson('/outreach-templates', [...$this->details(), 'body' => ' '])->assertUnprocessable();
        $this->postJson('/outreach-templates', [...$this->details(), 'subject' => str_repeat('a', 241)])->assertUnprocessable();
        $this->getJson('/outreach-templates')->assertJsonPath('templates.0.name', 'Updated')->assertJsonPath('templates.0.version', 2);
        app(TenantContext::class)->run($user->id, $org, function () use ($id): void {
            $audit = DB::table('audit_logs')->where('subject_id', $id)->get();
            $this->assertCount(2, $audit);
            $this->assertStringNotContainsString('Secret pitch', json_encode($audit));
        });
        $this->deleteJson('/outreach-templates/'.$id, ['version' => 2])->assertOk();
        $this->getJson('/outreach-templates')->assertJsonCount(0, 'templates');
    }

    public function test_foreign_routes_partial_props_and_every_direct_rls_operation_are_denied(): void
    {
        [$user, $org] = $this->setupWorkspace();
        $other = User::factory()->create();
        $foreignOrg = app(Workspaces::class)->create($other->id, 'Other templates');
        $foreign = app(TenantContext::class)->run($other->id, $foreignOrg, fn () => app(OutreachTemplates::class)->save($other->id, $foreignOrg, $this->details()));
        $own = $this->postJson('/outreach-templates', $this->details())->json('id');
        $this->patchJson('/outreach-templates/'.$foreign, [...$this->details(), 'version' => 1])->assertNotFound();
        $this->deleteJson('/outreach-templates/'.$foreign, ['version' => 1])->assertNotFound();
        $this->withoutVite();
        $this->get('/partner/outreach-templates', ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '',
            'X-Inertia-Partial-Component' => 'OutreachTemplates', 'X-Inertia-Partial-Data' => 'templates,org_id,actor_id'])
            ->assertOk()->assertJsonCount(1, 'props.templates')->assertJsonPath('props.templates.0.id', $own)
            ->assertJsonMissingPath('props.org_id')->assertJsonMissingPath('props.actor_id');
        $this->assertSame(0, DB::table('outreach_templates')->count());
        app(TenantContext::class)->run($user->id, $org, function () use ($foreignOrg, $foreign, $own): void {
            $this->assertSame(0, DB::table('outreach_templates')->where('id', $foreign)->count());
            $this->assertSame(0, DB::table('outreach_templates')->where('id', $foreign)->update(['name' => 'forged']));
            $this->assertSame(0, DB::table('outreach_templates')->where('id', $foreign)->delete());
            foreach ([fn () => DB::table('outreach_templates')->insert([...$this->details(), 'id' => (string) Str::uuid(), 'org_id' => $foreignOrg]),
                fn () => DB::table('outreach_templates')->where('id', $own)->update(['org_id' => $foreignOrg])] as $probe) {
                try {
                    DB::transaction($probe);
                    $this->fail('Foreign write accepted');
                } catch (QueryException $exception) {
                    $this->assertSame('42501', $exception->errorInfo[0]);
                }
            }
        });
        $this->withSession(['active_org_id' => $foreignOrg])->getJson('/outreach-templates')->assertForbidden();
    }

    public function test_failed_transaction_rolls_back_template_and_audit(): void
    {
        [$user, $org] = $this->setupWorkspace();
        try {
            app(TenantContext::class)->run($user->id, $org, function () use ($user, $org): void {
                app(OutreachTemplates::class)->save($user->id, $org, $this->details());
                throw new LogicException('rollback');
            });
        } catch (LogicException) {
            app(TenantContext::class)->run($user->id, $org, function (): void {
                $this->assertSame(0, DB::table('outreach_templates')->count());
                $this->assertSame(0, DB::table('audit_logs')->where('event', 'outreach_template.created')->count());
            });
        }
    }
}
