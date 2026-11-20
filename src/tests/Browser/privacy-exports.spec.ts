import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { login } from './helpers';

const repo = fileURLToPath(new URL('../../../', import.meta.url));
function fixture(action: string, email: string): { org: string; secret: string } {
    const compose = process.env.PRIVACY_TEST_COMPOSE;
    if (!compose) throw new Error('Use the disposable privacy stack via PRIVACY_TEST_COMPOSE.');
    return JSON.parse(
        execFileSync(
            'docker',
            [
                'compose',
                '-f',
                compose,
                'run',
                '--rm',
                '-T',
                'migrate',
                'php',
                'tests/Browser/privacy-fixture.php',
                action,
                email,
            ],
            { cwd: repo, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
        ),
    );
}
function code(secret: string): string {
    const bits = [...secret]
        .map((char) => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(char).toString(2).padStart(5, '0'))
        .join('');
    const key = Buffer.from(bits.match(/.{8}/g)!.map((byte) => Number.parseInt(byte, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const hash = createHmac('sha1', key).update(counter).digest();
    return ((hash.readUInt32BE(hash[19]! & 15) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
}
// Export fixtures and archives belong on a dedicated disposable stack, never a shared database.
test.skip(!process.env.PRIVACY_TEST_COMPOSE, 'Requires the disposable privacy stack via PRIVACY_TEST_COMPOSE.');

async function clean(page: Page): Promise<void> {
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}

test('account export checks password, downloads privately and reports changed data', async ({ page }, info) => {
    const email = `m1-browser-privacy-account-${info.project.name}-${Date.now()}@example.test`;
    fixture('plain', email);
    await login(page, email);
    await page.getByText('Account security', { exact: true }).click();
    await page.getByRole('link', { name: 'Download your data' }).click();
    await expect(page.getByRole('heading', { name: 'Privacy exports', exact: true })).toBeVisible();
    await expect(page.getByText('You have not created an export yet.')).toBeVisible();
    await clean(page);
    await page.getByLabel('Current password').fill('wrong-password');
    await page.getByLabel('Current password').press('Enter');
    await expect(page.getByText('Enter your current password.', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Current password')).toBeFocused();
    await page.getByLabel('Current password').fill('browser-password-123');
    await page.getByLabel('Current password').press('Tab');
    await expect(page.getByRole('button', { name: 'Create private export' })).toBeFocused();
    await page.getByRole('button', { name: 'Create private export' }).press('Enter');
    const download = page.getByRole('button', { name: /^Download ZIP for account export created/ });
    await expect(download).toBeEnabled();
    await expect(page.getByLabel('Current password')).toHaveValue('');
    const pending = page.waitForEvent('download');
    await download.click();
    expect((await pending).suggestedFilename()).toMatch(/^weavdash-export-.*\.zip$/);
    await clean(page);
    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
        await page.screenshot({ path: info.outputPath('account-ready.png'), fullPage: true });
    fixture('change', email);
    await download.click();
    await expect(page.getByRole('alert').filter({ hasText: 'Included data or access changed' })).toBeVisible();
    await clean(page);
});

test('workspace owner exports with fresh MFA and the screen reflows', async ({ page }, info) => {
    const email = `m1-browser-privacy-workspace-${info.project.name}-${Date.now()}@example.test`;
    const data = fixture('enrolled', email);
    await login(page, email);
    await page.goto('/account/exports');
    await page.getByRole('combobox', { name: 'Export scope' }).click();
    await page.getByRole('option', { name: 'A workspace I own' }).click();
    await page.getByRole('combobox', { name: 'Workspace', exact: true }).click();
    await page.getByRole('option', { name: 'Privacy review workspace' }).click();
    await page.getByLabel('Current password').fill('browser-password-123');
    await page.getByLabel('Authenticator code').fill(code(data.secret));
    await page.getByRole('button', { name: 'Create private export' }).click();
    await expect(page.getByRole('button', { name: /^Download ZIP for workspace export created/ })).toBeEnabled();
    for (const width of [1440, 1024, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 900 });
        await clean(page);
    }
    if (process.env.CAPTURE_BROWSER_IMAGES === '1') {
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 900 });
            await page.screenshot({ path: info.outputPath(`workspace-${width}.png`), fullPage: true });
        }
    }
});
