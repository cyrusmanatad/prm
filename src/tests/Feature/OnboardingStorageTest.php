<?php

namespace Tests\Feature;

use App\Services\OnboardingStorageTransfer;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class OnboardingStorageTest extends TestCase
{
    public function test_configured_s3_adapter_can_write_read_and_delete_with_mocked_transport(): void
    {
        $config = config('filesystems.disks.onboarding-r2');
        $this->assertSame('private', $config['visibility']);
        $this->assertTrue($config['throw']);
        $this->assertTrue($config['use_path_style_endpoint']);
        $handler = new MockHandler;
        $handler->append(new Result(['ETag' => 'synthetic']), new Result(['Body' => Utils::streamFor('synthetic')]), new Result);
        $disk = Storage::build(array_replace($config, [
            'key' => 'synthetic', 'secret' => 'synthetic', 'region' => 'auto',
            'bucket' => 'synthetic', 'endpoint' => 'https://example.r2.cloudflarestorage.com', 'handler' => $handler,
        ]));
        $this->assertTrue($disk->put('onboarding/synthetic', 'synthetic'));
        $this->assertSame('synthetic', $disk->get('onboarding/synthetic'));
        $this->assertTrue($disk->delete('onboarding/synthetic'));
        $this->assertSame(0, $handler->count());
    }

    private function document(string $bytes = 'synthetic'): object
    {
        return (object) ['id' => 'document', 'org_id' => 'org', 'request_id' => 'request',
            'object_key' => 'onboarding/org/request/document', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes)];
    }

    public function test_copy_verifies_bytes_retains_source_and_can_resume_and_reverse(): void
    {
        Storage::fake('onboarding-local');
        Storage::fake('onboarding-r2');
        $row = $this->document();
        Storage::disk('onboarding-local')->put($row->object_key, 'synthetic');
        $transfer = app(OnboardingStorageTransfer::class);
        $expected = ['documents' => 1, 'bytes' => 9];
        $this->assertSame($expected, $transfer->run([$row], 'onboarding-local', 'onboarding-r2', true));
        $this->assertSame($expected, $transfer->run([$row], 'onboarding-local', 'onboarding-r2', true));
        $this->assertSame($expected, $transfer->run([$row], 'onboarding-local', 'onboarding-r2', false));
        Storage::disk('onboarding-local')->assertExists($row->object_key);
        $new = clone $row;
        $new->id = 'new';
        $new->object_key = 'onboarding/org/request/new';
        Storage::disk('onboarding-r2')->put($new->object_key, 'synthetic');
        $this->assertSame(['documents' => 2, 'bytes' => 18], $transfer->run([$row, $new], 'onboarding-r2', 'onboarding-local', true));
        Storage::disk('onboarding-r2')->assertExists($new->object_key);
        Storage::disk('onboarding-local')->assertExists($new->object_key);
    }

    public function test_conflicting_destination_is_never_overwritten(): void
    {
        Storage::fake('onboarding-local');
        Storage::fake('onboarding-r2');
        $row = $this->document();
        Storage::disk('onboarding-local')->put($row->object_key, 'synthetic');
        Storage::disk('onboarding-r2')->put($row->object_key, 'different');
        try {
            app(OnboardingStorageTransfer::class)->run([$row], 'onboarding-local', 'onboarding-r2', true);
            $this->fail('Conflicting destination must fail');
        } catch (RuntimeException) {
            $this->assertSame('different', Storage::disk('onboarding-r2')->get($row->object_key));
        }
    }

    public function test_bad_source_checksum_size_or_key_and_missing_destination_fail_closed(): void
    {
        foreach (['checksum', 'size', 'object_key', 'missing'] as $fault) {
            Storage::fake('onboarding-local');
            Storage::fake('onboarding-r2');
            $row = $this->document();
            Storage::disk('onboarding-local')->put($row->object_key, 'synthetic');
            if ($fault !== 'missing') {
                $row->$fault = $fault === 'size' ? 100 : 'incorrect';
            }
            try {
                app(OnboardingStorageTransfer::class)->run([$row], 'onboarding-local', 'onboarding-r2', false);
                $this->fail('Verification must fail for '.$fault);
            } catch (RuntimeException) {
                $this->assertSame([], Storage::disk('onboarding-r2')->allFiles());
            }
        }
    }

    public function test_storage_tools_refuse_normal_deployments(): void
    {
        config(['staging.enabled' => false]);
        $this->artisan('onboarding:storage inventory')->assertFailed();
        $this->artisan('staging:storage-probe')->assertFailed();
    }

    public function test_probe_cleans_up_its_synthetic_object(): void
    {
        config(['staging.enabled' => true]);
        Storage::fake('onboarding-r2');
        $this->artisan('staging:storage-probe')->assertSuccessful();
        $this->assertSame([], Storage::disk('onboarding-r2')->allFiles());
    }
}
