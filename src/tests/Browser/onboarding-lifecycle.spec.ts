import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { fixture, login } from './helpers';

function deliver(email: string): Record<string, string> {
    return JSON.parse(
        execFileSync(
            'docker',
            [
                'compose',
                '--env-file',
                'src/.env',
                '-p',
                process.env.COMPOSE_PROJECT_NAME ?? 'weavdash-m1',
                'exec',
                '-T',
                'app',
                'php',
                'tests/Browser/notification-fixture.php',
                email,
                '--lifecycle-links',
            ],
            { cwd: fileURLToPath(new URL('../../../', import.meta.url)), encoding: 'utf8' },
        ),
    ).links;
}

test('desktop onboarding lifecycle controls queue explicit outcomes and open fresh scoped return links', async ({
    page,
    browser,
}, info) => {
    test.skip(info.project.name.includes('mobile'), 'Small-screen acceptance is assigned to Claude.');
    test.setTimeout(150_000);
    await page.setViewportSize({ width: 1440, height: 1000 });
    const email = `m1-browser-notifications-lifecycle-${Date.now()}@example.test`;
    fixture('seed', email);
    await login(page, email);
    await page.getByLabel('Workspace name', { exact: true }).fill('Lifecycle review workspace');
    await page.getByRole('button', { name: 'Create workspace', exact: true }).click();
    await page.getByRole('link', { name: 'Open partner portal' }).click();
    await page.getByRole('button', { name: '+ Add lead', exact: true }).click();
    await page.getByLabel('Brand name', { exact: true }).fill('Lifecycle Studio');
    await page.getByRole('button', { name: 'Capture lead', exact: true }).click();
    await expect(page.getByLabel('Email me when this form is submitted')).not.toBeChecked();
    await page.getByLabel('Email me when this form is submitted').check();
    await page.getByRole('button', { name: 'Start onboarding', exact: true }).click();
    const invitation = await page.getByLabel('Private invitation link (copy now)').inputValue();
    const reviewUrl = page.url();
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const recipient = await context.newPage();
    try {
        await recipient.goto(invitation);
        await recipient.getByRole('button', { name: 'Open invitation', exact: true }).click();
        await recipient.getByLabel('Business name', { exact: true }).fill('Lifecycle business');
        await recipient.getByLabel('Contact name', { exact: true }).fill('Synthetic Contact');
        await recipient.getByLabel('Business email', { exact: true }).fill('untrusted@example.test');
        await recipient.getByLabel('Collaboration brief', { exact: true }).fill('Initial collaboration brief.');
        await recipient.getByLabel(/I agree to share/).check();
        await recipient.getByRole('button', { name: 'Submit for review', exact: true }).click();
        await page.goto(reviewUrl);
        const history = page.getByRole('region', { name: 'Onboarding email history' });
        await expect(history).toContainText('Submission alert');
        await expect(page.getByLabel('Email this review outcome')).not.toBeChecked();
        await expect(page.getByLabel('Outcome recipient email')).toHaveCount(0);
        await page.getByLabel('Correction request', { exact: true }).fill('Please clarify the deliverables.');
        await page.getByLabel('Email this review outcome').focus();
        await page.keyboard.press('Space');
        await page.keyboard.press('Tab');
        await expect(page.getByLabel('Outcome recipient email')).toBeFocused();
        await expect(page.getByLabel('Outcome recipient email')).toHaveValue('');
        await page.getByLabel('Outcome recipient email').fill('explicit@example.test');
        for (const width of [1440, 1920]) {
            await page.setViewportSize({ width, height: 1080 });
            expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
            await page.screenshot({ path: info.outputPath(`outcome-review-${width}.png`), fullPage: true });
        }
        await page.getByRole('button', { name: 'Request correction', exact: true }).click();
        await expect(history).toContainText('Correction request');
        const correctedLink = deliver(email)['onboarding.corrected'];
        expect(correctedLink).toContain('/onboarding#token=');
        await page.reload();
        await expect(history).toContainText('Email captured locally; no external delivery');
        // A completely fresh browser receives access without the original session.
        await context.clearCookies();
        await recipient.goto(correctedLink!);
        await recipient.getByRole('button', { name: 'Open invitation', exact: true }).click();
        await expect(recipient.getByText('Requested correction: Please clarify the deliverables.')).toBeVisible();
        await recipient.getByLabel('Collaboration brief', { exact: true }).fill('Updated collaboration deliverables.');
        await recipient.getByLabel(/I agree to share/).check();
        await recipient.getByRole('button', { name: 'Submit for review', exact: true }).click();
        await page.goto(reviewUrl);
        await page.getByLabel('Email this review outcome').check();
        await page.getByLabel('Outcome recipient email').fill('explicit@example.test');
        await page.getByRole('button', { name: 'Accept submission', exact: true }).click();
        await expect(history).toContainText('Acceptance');
        const acceptedLink = deliver(email)['onboarding.accepted'];
        await context.clearCookies();
        await recipient.goto(acceptedLink!);
        await recipient.getByRole('button', { name: 'Open invitation', exact: true }).click();
        await expect(recipient.getByText('Status: accepted', { exact: true })).toBeVisible();
        await expect(recipient.getByText('This form is read-only.', { exact: false })).toBeVisible();
        await expect(recipient.getByRole('button', { name: 'Save draft', exact: true })).toHaveCount(0);
        expect((await new AxeBuilder({ page: recipient }).analyze()).violations).toEqual([]);
        await recipient.screenshot({ path: info.outputPath('accepted-return-desktop.png'), fullPage: true });
        await page.reload();
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
        await page.screenshot({ path: info.outputPath('lifecycle-history-desktop.png'), fullPage: true });
    } finally {
        await context.close();
    }
});
