<?php

namespace Tests\Feature;

use App\Http\Middleware\UploadRestrictions;
use App\Services\OnboardingDocuments;
use App\Services\UploadSafety;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StagingFeaturesTest extends TestCase
{
    public function test_trusted_edge_clients_have_separate_anonymous_throttle_buckets(): void
    {
        config(['trustedproxy.proxies' => '*']);
        $this->withServerVariables(['REMOTE_ADDR' => '172.30.0.2']);
        foreach (['/register' => 5, '/forgot-password' => 5, '/onboarding/session' => 6] as $path => $limit) {
            for ($attempt = 0; $attempt < $limit; $attempt++) {
                $this->postJson($path, [], ['X-Forwarded-For' => '198.51.100.10'])->assertUnprocessable();
            }
            $this->postJson($path, [], ['X-Forwarded-For' => '198.51.100.10'])->assertTooManyRequests();
            $this->postJson($path, [], ['X-Forwarded-For' => '198.51.100.11'])->assertUnprocessable();
        }
    }

    public function test_staging_keeps_all_registration_and_recovery_pages_available(): void
    {
        config(['staging.enabled' => true]);
        foreach (['/register', '/brand/register', '/partner/register', '/professional/register'] as $path) {
            $this->get($path)->assertOk();
            $this->postJson($path, [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
        }
        $this->get('/forgot-password')->assertOk();
        $this->get('/reset-password/example')->assertOk();
    }

    public function test_unscanned_permission_requires_both_staging_and_explicit_opt_in(): void
    {
        foreach ([[false, false], [false, true], [true, false], [true, true]] as [$staging, $optIn]) {
            config(['staging.enabled' => $staging, 'uploads.staging_unscanned' => $optIn]);
            $this->assertSame($staging && $optIn, UploadSafety::permits('unscanned'));
            $this->assertTrue(UploadSafety::permits('clean'));
            $this->assertFalse(UploadSafety::permits('pending'));
            $this->assertFalse(UploadSafety::permits('rejected'));
        }
    }

    public function test_disabled_upload_is_rejected_without_creating_a_file(): void
    {
        config(['uploads.enabled' => false]);
        Storage::fake('onboarding');
        $this->postJson('/onboarding/form/00000000-0000-0000-0000-000000000001/documents', ['file' => UploadedFile::fake()->create('reference.txt', 1, 'text/plain')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame([], Storage::disk('onboarding')->allFiles());
        try {
            app(OnboardingDocuments::class)->upload('org', 'request', 1, UploadedFile::fake()->create('reference.txt'));
            $this->fail('Service upload must also be blocked');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_staging_allows_mail_transport_and_explicit_email_actions(): void
    {
        config(['staging.enabled' => true]);
        $mailer = Mail::mailer('array');
        $before = count($mailer->getSymfonyTransport()->messages());
        $this->assertNotNull($mailer->raw('Synthetic staging', fn ($message) => $message->to('recipient@example.test')->subject('Staging')));
        $this->assertCount($before + 1, $mailer->getSymfonyTransport()->messages());

        $request = Request::create('/partner-invitations', 'POST', ['send_email' => true]);
        $response = app(UploadRestrictions::class)->handle($request, fn () => response('Email action available'));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_normal_deployment_preserves_registration_and_mail(): void
    {
        config(['staging.enabled' => false, 'uploads.enabled' => true]);
        $this->get('/register')->assertOk();
        $this->assertNotNull(Mail::mailer('array')->raw('Normal deployment', fn ($message) => $message->to('recipient@example.test')->subject('Normal')));
    }
}
