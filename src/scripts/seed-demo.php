<?php

// Explicit local demo fixture in Philippine pesos (PHP). Requires an empty migrated database and runtime grants.
use App\Enums\PipelineStage;
use App\Models\User;
use App\Services\Crm;
use App\Services\InvoiceDrafts;
use App\Services\ManualPayments;
use App\Services\NotificationOutbox;
use App\Services\Onboarding;
use App\Services\OnboardingDocuments;
use App\Services\OutreachTemplates;
use App\Services\Pipeline;
use App\Services\PublicPresence;
use App\Services\Workspaces;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || ! $app->environment(['local', 'testing'])) {
    throw new RuntimeException('Demo fixtures require local/testing CLI.');
}
if (($argv[1] ?? '') === '--pilot') {
    Artisan::call('pilot:plan', ['name' => 'Demo exploration', 'version' => 1, 'quotas' => json_encode(array_fill_keys(['pipeline_entries', 'brand_contacts', 'reminders', 'onboarding_requests', 'outreach_templates', 'invoice_drafts', 'manual_payments'], 1000)), '--operator' => 'demo-seeder', '--reason' => 'User-requested synthetic demo']);
    $plan = trim(Artisan::output());
    foreach (DB::table('organizations')->pluck('id') as $org) {
        Artisan::call('pilot:assign', ['workspace' => $org, 'plan' => $plan, 'starts' => now()->subMinute()->format('c'), 'ends' => now()->addDays(90)->format('c'), '--operator' => 'demo-seeder', '--reason' => 'User-requested synthetic demo']);
    }
    echo "Pilot entitlements assigned.\n";
    exit;
}
if (DB::table('users')->exists()) {
    throw new RuntimeException('Seed only an empty freshly migrated database.');
}
config(['mail.default' => 'array']);
$context = app(TenantContext::class);
$credentials = ['WEAVDASH LOCAL DEMO', 'Created: '.now()->toIso8601String(), 'Login: http://localhost:8000/login', 'All accounts are verified workspace owners. All data is synthetic.', 'Invitation emails are captured locally, never sent externally.', 'Pilot access lasts 90 days. Finance records are private drafts and simulated receipts.', ''];
$links = [];
$actors = [];
function identity(string $name, string $email, string $kind): array
{
    global $credentials, $actors;
    $password = 'Demo!'.bin2hex(random_bytes(9));
    $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password)]);
    $user->forceFill(['email_verified_at' => now()])->save();
    $org = app(Workspaces::class)->create($user->id, $name, $kind);
    $credentials[] = "$name | $email | $password";
    $actors[] = [$user->id, $org];

    return [$user->id, $org];
}
function populate(int $actor, string $org, string $name, int $count): void
{
    global $context, $links;
    $context->run($actor, $org, function () use ($actor, $org, $name, $count): void {
        $pipeline = app(Pipeline::class);
        $crm = app(Crm::class);
        for ($i = 1; $i <= $count; $i++) {
            $email = "contact-$i-".substr($org, 0, 8).'@example.test';
            $entry = $pipeline->create($actor, $org, ['brand_name' => ['Cedar Studio', 'Aurora Goods', 'Summit Outdoors', 'Harbor Coffee', 'Bloom Skincare', 'Atlas Travel', 'Willow Home', 'Orbit Tech', 'Solstice Fitness', 'Meadow Foods'][$i - 1], 'contact_name' => "Campaign Manager $i", 'contact_email' => $email, 'currency' => 'PHP', 'value_minor' => 50000 + $i * 15000, 'notes' => 'Synthetic campaign opportunity: short-form video and product photography.', 'next_action_due' => now()->addDays($i - 4)]);
            $pipeline->change($actor, $org, $entry, 1, 'stage', ['stage' => PipelineStage::cases()[($i - 1) % 7]->value]);
            $pipeline->addActivity($actor, $org, $entry, 2, 'note', 'Discussed campaign scope, timeline and creative direction.');
            $contact = $crm->save($actor, $org, 'contacts', ['name' => "Campaign Manager $i", 'email' => $email, 'company' => "Demo prospect $i", 'notes' => 'Prefers an initial creative brief before a call.']);
            $crm->link($actor, $org, $entry, $contact, 3, true);
            $reminder = $crm->save($actor, $org, 'reminders', ['pipeline_entry_id' => $entry, 'title' => "Follow up on campaign $i", 'due_at' => now()->addDays($i - 4)]);
            if ($i % 3 === 0) {
                $crm->command($actor, $org, $reminder, 1, 'complete', null);
            }
            if ($i <= 4) {
                $onboarding = app(Onboarding::class);
                $request = $onboarding->create($actor, $org, $entry, "Campaign $i onboarding", $email);
                $links[] = "$name onboarding $i: ".$request['url'];
                if ($i === 1) {
                    file_put_contents('/tmp/demo-brief.txt', 'Synthetic campaign brief: three videos and six product photographs.');
                    app(OnboardingDocuments::class)->upload($org, $request['id'], 1, new UploadedFile('/tmp/demo-brief.txt', 'demo-brief.txt', 'text/plain', null, true));
                }
                if ($i > 1) {
                    $onboarding->submit($org, $request['id'], 1, (string) Str::uuid(), ['business_name' => "Demo prospect $i", 'contact_name' => "Campaign Manager $i", 'business_email' => $email, 'website' => 'https://example.test', 'brief' => 'Create three short videos and six product photos for a seasonal launch.']);
                    if ($i > 2) {
                        $onboarding->review($actor, $org, $request['id'], 2, $i === 3 ? 'correct' : 'accept', $i === 3 ? 'Please add the preferred launch date.' : 'Demo brief approved.');
                    }
                }
            }
        }
        foreach (['Introduction', 'Campaign follow-up', 'Repeat collaboration'] as $title) {
            app(OutreachTemplates::class)->save($actor, $org, ['name' => $title, 'subject' => "$title: creative partnership", 'body' => 'Hello! I would love to discuss your next campaign. Here is a suggested creative direction and a time to connect.']);
        }
        app(PublicPresence::class)->saveKit($actor, $org, ['title' => "$name — media kit", 'bio' => 'Demo creative studio specializing in authentic storytelling, lifestyle photography and short-form video.', 'website' => 'https://example.test', 'published' => true, 'social_links' => json_encode([['label' => 'Portfolio', 'url' => 'https://example.test/portfolio']])], 0);
        $link = app(PublicPresence::class)->leadLink($actor, $org);
        $GLOBALS['links'][] = "$name lead capture: ".$link['url'];
        $GLOBALS['links'][] = "$name media kit: http://localhost:8000/kits/".DB::table('media_kits')->where('org_id', $org)->value('id');
        for ($i = 1; $i <= 3; $i++) {
            $invoice = app(InvoiceDrafts::class)->command($actor, $org, (string) Str::uuid(), 'create', ['recipient_name' => "Demo campaign client $i", 'currency' => 'PHP', 'due_date' => now()->addDays(14)->toDateString(), 'lines' => [['description' => 'Campaign content package', 'quantity' => 2, 'unit_price_minor' => '75000'], ['description' => 'Usage rights', 'quantity' => 1, 'unit_price_minor' => '25000']]], null);
            if ($i === 3) {
                app(InvoiceDrafts::class)->command($actor, $org, (string) Str::uuid(), 'void', ['version' => 1], $invoice);

                continue;
            }
            $payment = app(ManualPayments::class)->command($actor, $org, (string) Str::uuid(), 'record', ['currency' => 'PHP', 'amount_minor' => $i === 1 ? '175000' : '50000', 'received_on' => now()->subDays($i)->toDateString(), 'evidence_reference' => "DEMO-$org-$i", 'correction_of' => null], null);
            app(ManualPayments::class)->command($actor, $org, (string) Str::uuid(), 'allocate', ['version' => 1, 'invoice_id' => $invoice, 'invoice_version' => 1, 'amount_minor' => $i === 1 ? '175000' : '50000'], $payment);
        }
    });
}
foreach (['Northstar Outdoors', 'Lumen Living'] as $b => $brandName) {
    $brandNumber = $b + 1;
    $credentials[] = "\nBRAND $brandNumber — $brandName\nName | Email | Password";
    [$brandActor, $brandOrg] = identity($brandName, "brand$brandNumber@example.test", 'brand');
    populate($brandActor, $brandOrg, $brandName, 7);
    for ($p = 1; $p <= 10; $p++) {
        $email = sprintf('brand%d.partner%02d@example.test', $brandNumber, $p);
        $name = "$brandName Partner ".sprintf('%02d', $p);
        [$actor, $org] = identity($name, $email, 'creator');
        $token = bin2hex(random_bytes(32));
        $invitation = (string) Str::uuid();
        $notification = $context->run($brandActor, $brandOrg, function () use ($brandActor, $brandOrg, $email, $token, $invitation) {
            $ok = DB::selectOne('SELECT app_invite_partner(?::uuid,?,?) AS ok', [$invitation, $email, hash('sha256', $token)])->ok;
            if (! $ok) {
                throw new RuntimeException('Partner invitation failed.');
            }

            return app(NotificationOutbox::class)->invitation($brandActor, $brandOrg, $invitation, url('/partnerships/accept').'#token='.$token);
        });
        app(NotificationOutbox::class)->deliver($brandActor, $brandOrg, $notification);
        $context->run($actor, $org, function () use ($token) {
            if (! DB::selectOne('SELECT app_accept_partner(?,?::uuid) AS ok', [hash('sha256', $token), (string) Str::uuid()])->ok) {
                throw new RuntimeException('Partnership acceptance failed.');
            }
        });
        populate($actor, $org, $name, 5 + (($p - 1) % 6));
    }
}
foreach ($actors as [$actor, $org]) {
    $ids = $context->run($actor, $org, fn () => DB::table('notification_outbox')->where('state', 'pending')->pluck('id')->all());
    foreach ($ids as $id) {
        app(NotificationOutbox::class)->deliver($actor, $org, $id);
    }
}
file_put_contents('/tmp/demo-user.txt', implode("\n", [...$credentials, '', 'DEMO LINKS (invitation links expire in 7 days; capture links in 30 days)', ...$links])."\n");
echo "Created 2 brands, 20 partners, and populated all implemented modules. Credentials: /tmp/demo-user.txt\n";
