import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { createHmac } from 'node:crypto';
import { login, mutate, migrationFixture } from './helpers';
function seed(email: string, workspaceName: string) {
    return JSON.parse(migrationFixture('professional-fixture.php', email, workspaceName));
}

test('professional workspace, shared client tools, exports and administrator MFA', async ({ page }, info) => {
    test.setTimeout(120_000);
    page.setDefaultTimeout(15_000);
    const email = `m1-browser-professional-${info.project.name}-${Date.now()}@example.test`;
    const workspaceName = `Browser consulting ${info.project.name}-${Date.now()}`;
    const fixture = seed(email, workspaceName);
    await page.goto('/professional/login');
    await expect(page.getByText('professional account', { exact: true })).toBeVisible();
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-password-123');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/professional$/);
    await expect(page.getByRole('heading', { name: 'Professional Hub', exact: true })).toBeVisible();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
        await page.screenshot({
            path: info.outputPath('professional.spec-capture-2359.png'),
            fullPage: true,
        });
    await page
        .getByRole('main')
        .getByRole('link', { name: /^Client leads/ })
        .click();
    await page.getByRole('button', { name: '+ Add lead', exact: true }).click();
    await expect(page.getByLabel('Client name (individual or business)', { exact: true })).toBeVisible();
    await page.getByLabel('Client name (individual or business)', { exact: true }).fill('Jane Individual');
    await page.getByRole('button', { name: 'Capture lead', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Jane Individual', exact: true })).toBeVisible();
    await page.keyboard.press('Escape');
    await page.goto('/professional/profile');
    await expect(page.getByRole('heading', { name: 'Professional profile / portfolio', exact: true })).toBeVisible();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
        await page.screenshot({
            path: info.outputPath('professional.spec-capture-3380.png'),
            fullPage: true,
        });
    await page.goto('/professional');
    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Export leads as CSV', exact: true }).click();
    expect((await downloadPromise).suggestedFilename()).toBe('professional-leads.csv');
    await page.goto('/admin/security');
    await expect(page.getByRole('heading', { name: 'Verify your identity' })).toBeVisible();
    await page.getByLabel('Password', { exact: true }).fill('browser-password-123');
    await page.getByRole('button', { name: 'Generate setup key' }).click();
    const setup = await page.locator('code').textContent();
    expect(setup?.length).toBe(32);
    await expect(page.getByLabel('Authenticator code')).toBeVisible();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    const bits = [...setup!]
        .map((c) => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(c).toString(2).padStart(5, '0'))
        .join('');
    const key = Buffer.from(bits.match(/.{8}/g)!.map((byte) => Number.parseInt(byte, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const hash = createHmac('sha1', key).update(counter).digest();
    const code = ((hash.readUInt32BE(hash[19]! & 15) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
    await page.getByLabel('Password', { exact: true }).fill('browser-password-123');
    await page.getByLabel('Authenticator code').fill(code);
    await page.getByRole('button', { name: 'Verify and continue' }).click();
    await expect(page.getByRole('heading', { name: 'Accounts and workspaces' })).toBeVisible();
    await page.getByLabel('Search names').fill(workspaceName);
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await page.locator('#assign-workspace').selectOption(fixture.org);
    await page.getByLabel('Plan name', { exact: true }).fill('Synthetic renewed subscription');
    await page.locator('#assign-start').fill(new Date(Date.now() - 60000).toISOString());
    await page.locator('#assign-end').fill(new Date(Date.now() + 86400000).toISOString());
    for (const resource of [
        'pipeline_entries',
        'brand_contacts',
        'reminders',
        'onboarding_requests',
        'outreach_templates',
        'invoice_drafts',
        'manual_payments',
    ]) {
        await page.locator(`#quota-${resource}`).fill('50');
    }
    await page.getByLabel('Reason', { exact: true }).fill('Browser acceptance renewal');
    await page.getByRole('button', { name: 'Record assignment', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Record subscription assignment?' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(page.getByRole('button', { name: 'Record assignment', exact: true })).toBeFocused();
    await page.getByRole('button', { name: 'Record assignment', exact: true }).click();
    await dialog.getByRole('button', { name: 'Record subscription assignment', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
        await page.screenshot({
            path: info.outputPath('professional.spec-capture-6858.png'),
            fullPage: true,
        });
    await page.goto('/professional/contacts');
    await expect(page.getByRole('heading', { name: 'Contacts and reminders', exact: true })).toBeVisible();
    for (const route of ['onboarding', 'outreach-templates', 'automation', 'finance/invoices', 'finance/payments']) {
        await page.goto(`/professional/${route}`);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    }
    if (info.project.name === 'desktop') {
        for (const width of [320, 768, 1024, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto('/professional');
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        }
    }
});
