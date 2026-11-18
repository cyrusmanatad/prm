<?php

// CLI-only migration-role fixture for the Brand lead visibility journey (D38): one approved Brand workspace,
// thirteen connected Partner workspaces (the first owned by the given Partner identity) and a few seeded
// registrations. Synthetic data for a disposable database; never part of HTTP routes or the web role.
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BrandFixture;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $brandEmail, $partnerEmail, $brandName] = $argv + [null, '', '', ''];
$synthetic = '/^m1-browser-[a-z0-9-]+@example\.test$/';
if (PHP_SAPI !== 'cli' || ! $app->environment(['local', 'testing']) || ! preg_match($synthetic, $brandEmail) || ! preg_match($synthetic, $partnerEmail)
    || trim($brandName) === '' || strlen($brandName) > 120 || DB::selectOne('SELECT current_user AS name')->name !== 'weavdash_migrator') {
    throw new RuntimeException('Only disposable migration-role browser fixtures are allowed.');
}
$brandOwner = User::where('email', $brandEmail)->firstOrFail();
$partner = User::where('email', $partnerEmail)->firstOrFail();
$brand = BrandFixture::create($brandOwner->id, $brandName, DB::connection());
$result = ['brand' => $brand, 'partners' => []];
DB::transaction(function () use ($brand, $brandName, $partner, &$result): void {
    for ($i = 1; $i <= 13; $i++) {
        $owner = $i === 1 ? $partner : User::factory()->create();
        $org = (string) Str::uuid();
        $name = 'Roster Partner '.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        DB::table('organizations')->insert(['id' => $org, 'name' => $name, 'kind' => 'creator']);
        DB::table('memberships')->insert(['org_id' => $org, 'user_id' => $owner->id, 'role' => 'owner']);
        $invitation = (string) Str::uuid();
        $relationship = (string) Str::uuid();
        DB::table('partner_invitations')->insert(['id' => $invitation, 'brand_org_id' => $brand, 'recipient_email' => Str::random(12).'@example.test',
            'token_hash' => hash('sha256', $invitation), 'status' => 'accepted', 'expires_at' => now()->addDay()]);
        DB::table('partnerships')->insert(['id' => $relationship, 'brand_org_id' => $brand, 'creator_org_id' => $org, 'brand_name' => $brandName,
            'creator_name' => $name, 'invitation_id' => $invitation, 'accepted_at' => now()->subDays(40)->addMinutes($i)]);
        $result['partners'][] = ['org' => $org, 'relationship' => $relationship, 'name' => $name];
        if ($i === 1) {
            // The journey registers this private lead through the Partner screens.
            $result['lead'] = (string) Str::uuid();
            DB::table('pipeline_entries')->insert(['id' => $result['lead'], 'org_id' => $org, 'brand_name' => 'Kanto Cafe linens', 'contact_name' => 'Ana Reyes',
                'contact_email' => 'ana.reyes@example.test', 'notes' => 'Private journey note 4242', 'value_minor' => 4000000, 'currency' => 'PHP', 'created_at' => now()->subDays(45)]);
        }
        if ($i === 2) {
            // Two recent registrations and one older than the 30-day window, for the roster count and its drill-down.
            foreach ([['Liwanag Realty gifting', 3, 'negotiating'], ['Studio Lumen props', 12, 'repeat-client'], ['Older registration', 35, 'lost']] as [$label, $days, $stage]) {
                $entry = (string) Str::uuid();
                DB::table('pipeline_entries')->insert(['id' => $entry, 'org_id' => $org, 'brand_name' => $label.' (private)', 'notes' => 'Seeded private note', 'stage' => $stage, 'currency' => 'PHP', 'created_at' => now()->subDays(50)]);
                DB::table('lead_registrations')->insert(['id' => (string) Str::uuid(), 'partnership_id' => $relationship, 'brand_org_id' => $brand, 'creator_org_id' => $org,
                    'pipeline_entry_id' => $entry, 'display_name' => $label, 'summary' => 'Seeded summary', 'stage' => $stage, 'registered_by' => $owner->id,
                    'registered_at' => now()->subDays($days), 'shared_updated_at' => now()->subDays($days)]);
            }
        }
    }
});
echo json_encode($result, JSON_THROW_ON_ERROR);
