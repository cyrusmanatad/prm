<?php

namespace Tests\Feature;

use App\Mail\BrandRequestOutcome;
use App\Mail\FollowUpNotice;
use App\Mail\OnboardingInvitation;
use App\Mail\OnboardingLifecycle;
use App\Mail\PartnerInvitation;
use App\Mail\ScheduledNotice;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/** Presentation only: delivery rules, recipients and budgets are covered by the acceptance suite. */
class MailPresentationTest extends TestCase
{
    private const ID = '0193a5b2-7c1e-7a44-9f10-3b2a6c1d9e77';

    private const TOKEN = 'synthetic-capability-token';

    public function test_every_notice_has_a_branded_html_part_and_its_plain_text_alternative(): void
    {
        $origin = rtrim(config('app.url'), '/');
        $capability = $origin.'/onboarding#token='.self::TOKEN;
        $cases = [
            [new PartnerInvitation($origin.'/partnerships/accept#'.self::TOKEN, '2026-11-25 08:00:00+00', self::ID), $origin.'/partnerships/accept#'.self::TOKEN, 'Review invitation'],
            [new OnboardingInvitation($capability, '2026-11-25 08:00:00+00', self::ID), $capability, 'Open invitation'],
            [new OnboardingLifecycle('onboarding.submitted', $origin.'/partner/onboarding?request='.self::ID, '2026-11-25 08:00:00+00', self::ID), $origin.'/partner/onboarding?request='.self::ID, 'Review submission'],
            [new OnboardingLifecycle('onboarding.corrected', $capability, '2026-11-25 08:00:00+00', self::ID), $capability, 'Open invitation'],
            [new OnboardingLifecycle('onboarding.accepted', $capability, '2026-11-25 08:00:00+00', self::ID), $capability, 'Open invitation'],
            [new BrandRequestOutcome('brand_request.approved', $origin.'/brand/requests/'.self::ID, self::ID), $origin.'/brand/requests/'.self::ID, 'View request'],
            [new BrandRequestOutcome('brand_request.needs_information', $origin.'/brand/requests/'.self::ID, self::ID), $origin.'/brand/requests/'.self::ID, 'View request'],
            [new BrandRequestOutcome('brand_request.declined', $origin.'/brand/requests/'.self::ID, self::ID), $origin.'/brand/requests/'.self::ID, 'View request'],
            [new ScheduledNotice('schedule.reminder', 'Asia/Manila', '2026-11-18 09:00 +08:00', self::ID, self::ID), $origin.'/workspaces', 'Select workspace'],
            [new ScheduledNotice('schedule.weekly_digest', 'Asia/Manila', '2026-11-18 09:00 +08:00', self::ID, self::ID, ['dueReminders' => 2, 'onboardingReviews' => 0]), $origin.'/workspaces', 'Select workspace'],
            [new FollowUpNotice(true, self::ID, '', '', 'owner@example.test'), $origin.'/automation', 'Review draft'],
        ];

        foreach ($cases as [$mail, $url, $label]) {
            $email = $this->deliver($mail);
            $html = $email->getHtmlBody();
            $this->assertNotNull($html, $mail::class);
            $this->assertNotNull($email->getTextBody());
            // The call to action keeps the unchanged destination; the text part and the visible fallback repeat it.
            $this->assertMatchesRegularExpression('~<a href="'.preg_quote($url, '~').'" class="button button-primary"[^>]*>'.$label.'</a>~', $html);
            $this->assertStringContainsString($url, $email->getTextBody());
            $this->assertStringContainsString($origin.'/portal-assets/email-logo.png', $html);
            $this->assertStringContainsString('This is an automated message', $html);
            // Styles are inlined for clients that drop <style>, and nothing unrendered leaks through.
            $this->assertStringContainsString('background-color: #8e1650', $html);
            foreach (['{{', '}}', '{!!', '@if', '@endif', '<x-', 'x-mail', '<pre', '<code'] as $raw) {
                $this->assertStringNotContainsString($raw, $html);
            }
        }
    }

    public function test_approved_follow_up_stays_exact_plain_text(): void
    {
        $email = $this->deliver(new FollowUpNotice(false, self::ID, 'Approved subject', "Exact body.\n<b>Second</b> line.", 'owner@example.test'));
        $this->assertNull($email->getHtmlBody());
        $this->assertSame("Exact body.\n<b>Second</b> line.\n", $email->getTextBody());
        $this->assertSame('owner@example.test', $email->getReplyTo()[0]->getAddress());
    }

    public function test_dynamic_values_are_escaped_and_long_links_survive_markdown(): void
    {
        $url = rtrim(config('app.url'), '/').'/onboarding#token=a-_b_-c*d*e__f__'.str_repeat('Z', 400);
        $html = $this->deliver(new OnboardingInvitation($url, '<script>alert(1)</script> *2026*', self::ID))->getHtmlBody();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; *2026*', $html);
        // Button, fallback href and fallback text: underscores and asterisks in a token are not read as emphasis.
        $this->assertSame(3, substr_count($html, $url));
        $this->assertStringContainsString('word-break: break-all', $html);
    }

    public function test_account_notifications_share_the_branded_layout(): void
    {
        $user = (new User)->forceFill(['id' => 1, 'name' => 'Preview', 'email' => 'preview@example.test']);
        foreach ([(new ResetPassword('preview-token'))->toMail($user), (new VerifyEmail)->toMail($user)] as $message) {
            $html = (string) $message->render();
            $this->assertStringContainsString('/portal-assets/email-logo.png', $html);
            $this->assertStringContainsString('class="button button-primary"', $html);
            $this->assertStringContainsString('This is an automated message', $html);
        }
    }

    public function test_logo_asset_is_a_png_email_clients_can_render(): void
    {
        $this->assertSame(IMAGETYPE_PNG, getimagesize(public_path('portal-assets/email-logo.png'))[2]);
    }

    public function test_preview_command_renders_without_delivering_outside_the_array_transport(): void
    {
        $this->artisan('mail:preview')->expectsOutputToContain('partner-invitation')->assertSuccessful();
        $this->artisan('mail:preview', ['type' => 'onboarding-invitation'])->expectsOutputToContain('Open invitation')->assertSuccessful();
        $this->artisan('mail:preview', ['type' => 'follow-up-approved'])->assertFailed();
        $this->artisan('mail:preview', ['type' => 'follow-up-approved', '--text' => true])->expectsOutputToContain('Synthetic approved message.')->assertSuccessful();
        $this->artisan('mail:preview', ['type' => 'unknown'])->assertFailed();
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('mail:preview', ['type' => 'onboarding-invitation'])->assertFailed();
    }

    private function deliver(Mailable $mail): Email
    {
        Mail::mailer('array')->to('recipient@example.test')->send($mail);

        return Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    }
}
