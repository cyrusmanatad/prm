<?php

// M6-01 isolated restore fixture. No product route, mail transport or existing-data cleanup.
use App\Models\User;
use App\Services\OnboardingDocuments;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('READINESS_FIXTURE') !== 'synthetic-disposable') {
    throw new RuntimeException('Isolated testing fixture required.');
}
config(['filesystems.disks.onboarding' => ['driver' => 'local', 'root' => '/objects', 'throw' => true]]);
$mode = $argv[1] ?? '';
$require = function (bool $ok): void {
    if (! $ok) {
        throw new RuntimeException('Restore verification failed.');
    }
};
if (in_array($mode, ['seed', 'fingerprint'], true)) {
    $require(DB::selectOne('SELECT current_user AS name')->name === 'weavdash_migrator');
}
if ($mode === 'seed') {
    $require(DB::table('users')->count() === 0);
    DB::transaction(function (): void {
        foreach (['a', 'b'] as $tenant) {
            $user = User::factory()->create(['email' => 'restore-'.$tenant.'@example.test']);
            $user->admin_totp_secret = Crypt::encryptString('synthetic-restore-key-check');
            $user->saveQuietly();
            $org = (string) Str::uuid();
            $entry = (string) Str::uuid();
            $request = (string) Str::uuid();
            $document = (string) Str::uuid();
            $bytes = 'Synthetic M6 restore document for tenant '.$tenant;
            $key = 'onboarding/'.$org.'/'.$request.'/'.$document;
            DB::table('organizations')->insert(['id' => $org, 'kind' => 'creator', 'name' => 'Restore '.$tenant]);
            DB::table('memberships')->insert(['org_id' => $org, 'user_id' => $user->id, 'role' => 'owner']);
            DB::table('pipeline_entries')->insert(['id' => $entry, 'org_id' => $org, 'brand_name' => 'Synthetic '.$tenant, 'currency' => 'USD']);
            DB::table('onboarding_requests')->insert(['id' => $request, 'org_id' => $org, 'pipeline_entry_id' => $entry,
                'label' => 'Synthetic restore '.$tenant, 'token_hash' => hash('sha256', $request), 'expires_at' => now()->addDay()]);
            Storage::disk('onboarding')->put($key, $bytes);
            DB::table('onboarding_documents')->insert(['id' => $document, 'org_id' => $org, 'request_id' => $request,
                'object_key' => $key, 'name' => 'synthetic.txt', 'checksum' => hash('sha256', $bytes), 'size' => strlen($bytes),
                'mime' => 'text/plain', 'scan_state' => 'clean']);
        }
        DB::table('mail_budget_policy')->where('singleton', true)->update(['enabled' => true]);
        foreach (['business', 'account'] as $category) {
            DB::selectOne('SELECT app_mail_budget_reserve(?,1) AS data', [$category]);
        }
    });
    echo json_encode(['seeded_tenants' => 2, 'documents' => 2])."\n";
} elseif ($mode === 'fingerprint') {
    $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename");
    $fingerprints = [];
    foreach ($tables as $table) {
        $name = '"'.str_replace('"', '""', $table->tablename).'"';
        $row = DB::selectOne('SELECT count(*) AS count, md5(coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text)::text,\'[]\')) AS hash FROM '.$name.' t');
        $fingerprints[$table->tablename] = [$row->count, $row->hash];
    }
    $objects = [];
    foreach (Storage::disk('onboarding')->allFiles() as $key) {
        $objects[$key] = hash('sha256', Storage::disk('onboarding')->get($key));
    }
    ksort($objects);
    echo json_encode(['tables' => $fingerprints, 'objects' => $objects], JSON_THROW_ON_ERROR)."\n";
} elseif ($mode === 'verify') {
    $require(DB::selectOne('SELECT current_user AS name')->name === 'weavdash_app');
    $budget = json_decode(DB::selectOne('SELECT app_mail_budget_snapshot() AS data')->data, true, flags: JSON_THROW_ON_ERROR);
    $require($budget['enabled'] && $budget['rolling_total'] === 2 && $budget['rolling_business'] === 1 && $budget['rolling_account'] === 1);
    $users = User::whereIn('email', ['restore-a@example.test', 'restore-b@example.test'])->orderBy('email')->get();
    $require($users->count() === 2);
    foreach ($users as $user) {
        $require(Crypt::decryptString($user->admin_totp_secret) === 'synthetic-restore-key-check');
        try {
            (new Encrypter(random_bytes(32), 'AES-256-CBC'))->decryptString($user->admin_totp_secret);
            throw new RuntimeException('Wrong key unexpectedly decrypted fixture.');
        } catch (DecryptException) {
            // The backup's matching application key is required.
        }
    }
    $documents = [];
    foreach ($users as $user) {
        app(TenantContext::class)->run($user->id, null, function () use ($user, &$documents): void {
            $org = DB::table('memberships')->where('user_id', $user->id)->value('org_id');
            $documents[] = [$user->id, $org];
        }, requireMembership: false);
    }
    foreach ($documents as [$actor, $org]) {
        app(TenantContext::class)->run($actor, $org, function () use ($org, $documents, $require): void {
            $require(DB::table('pipeline_entries')->count() === 1 && DB::table('onboarding_documents')->count() === 1);
            $doc = DB::table('onboarding_documents')->first();
            [$bytes] = app(OnboardingDocuments::class)->download($org, $doc->request_id, $doc->id);
            $require(hash('sha256', $bytes) === $doc->checksum);
            $foreign = array_values(array_filter($documents, fn ($pair) => $pair[1] !== $org))[0][1];
            $require(DB::table('pipeline_entries')->where('org_id', $foreign)->count() === 0);
            Storage::disk('onboarding')->put($doc->object_key, 'tampered');
            try {
                app(OnboardingDocuments::class)->download($org, $doc->request_id, $doc->id);
                throw new RuntimeException('Tampered document unexpectedly downloaded.');
            } catch (HttpException $error) {
                $require($error->getStatusCode() === 403);
            } finally {
                Storage::disk('onboarding')->put($doc->object_key, $bytes);
            }
        });
    }
    echo json_encode(['restricted_runtime' => true, 'tenants_verified' => 2, 'documents_verified' => 2,
        'matching_key' => true, 'wrong_key_denied' => true, 'foreign_rows_denied' => true, 'tampered_download_denied' => true,
        'active_budget_consumption_preserved' => true])."\n";
} else {
    throw new RuntimeException('Unknown fixture mode.');
}
