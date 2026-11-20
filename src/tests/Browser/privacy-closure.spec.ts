import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { fileURLToPath } from 'node:url';

const repo = fileURLToPath(new URL('../../../', import.meta.url));
const password = 'browser-password-123';
function fixture(
    mode: string,
    email: string,
): { secret: string; org: string; kit: string; request: string; label: string; page: number } {
    const compose = process.env.CLOSURE_TEST_COMPOSE;
    if (!compose) throw new Error('Use the disposable closure stack via CLOSURE_TEST_COMPOSE.');
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
                'tests/Browser/closure-fixture.php',
                mode,
                email,
            ],
            { cwd: repo, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
        ),
    );
}
function codes(page: Page, secret: string): () => Promise<string> {
    let used = 0;
    return async () => {
        const counter = Math.max(Math.floor(Date.now() / 30000), used + 1);
        while (counter > Math.floor(Date.now() / 30000) + 1) await page.waitForTimeout(1000);
        used = counter;
        const bits = [...secret]
            .map((char) => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(char).toString(2).padStart(5, '0'))
            .join('');
        const key = Buffer.from(bits.match(/.{8}/g)!.map((byte) => Number.parseInt(byte, 2)));
        const moving = Buffer.alloc(8);
        moving.writeBigUInt64BE(BigInt(counter));
        const hash = createHmac('sha1', key).update(moving).digest();
        return ((hash.readUInt32BE(hash[19]! & 15) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
    };
}
async function login(page: Page, email: string, destination = /\/workspaces$/): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(destination);
}
async function clean(page: Page): Promise<void> {
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}

test('personal closure confirms access loss, signs out and permits verified cancellation', async ({ page }, info) => {
    test.setTimeout(180_000);
    const email = `m1-browser-closure-personal-${info.project.name}-${Date.now()}@example.test`;
    fixture('personal', email);
    await login(page, email);
    await page.goto('/account/closure');
    const review = page.getByRole('region', { name: 'Review closure: Closure Reviewer', exact: true });
    await review.getByLabel('Reason for closure').fill('Synthetic browser closure');
    await review.getByLabel('Current password').fill(password);
    await review.getByRole('button', { name: 'Close account', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    await clean(page);
    if (process.env.CAPTURE_CLOSURE_IMAGES === '1')
        await page.screenshot({ path: info.outputPath('personal-confirmation.png'), fullPage: true });
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(review.getByRole('button', { name: 'Close account', exact: true })).toBeFocused();
    await review.getByRole('button', { name: 'Close account', exact: true }).press('Enter');
    await dialog.getByRole('button', { name: 'Close Closure Reviewer', exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);
    await login(page, email, /\/account\/closure$/);
    await expect(
        page.getByText('Your account is restricted to closure and security pages.', { exact: false }),
    ).toBeVisible();
    expect((await page.request.get('/workspaces', { headers: { Accept: 'application/json' } })).status()).toBe(403);
    await page.getByText('Cancel this closure request', { exact: true }).click();
    await page.getByLabel('Reason for cancellation').fill('Synthetic browser cancellation');
    await page.getByLabel('Current password').fill(password);
    await page.getByRole('button', { name: 'Cancel closure request', exact: true }).click();
    await expect(page.getByText('Closure cancelled', { exact: true })).toBeVisible();
    await clean(page);
    if (process.env.CAPTURE_CLOSURE_IMAGES === '1')
        await page.screenshot({ path: info.outputPath('personal-cancelled.png'), fullPage: true });
    expect((await page.request.get('/workspaces', { headers: { Accept: 'application/json' } })).status()).toBe(200);
});

test('workspace closure revokes publication and cancellation keeps it unpublished', async ({ page }, info) => {
    test.setTimeout(180_000);
    const email = `m1-browser-closure-workspace-${info.project.name}-${Date.now()}@example.test`;
    const data = fixture('workspace', email);
    const code = codes(page, data.secret);
    await login(page, email);
    const selected = page.waitForResponse(
        (response) => response.url().endsWith('/workspace/select') && response.request().method() === 'POST',
    );
    await page.getByRole('button', { name: /Closure review workspace/ }).click();
    await selected;
    await expect(page.getByRole('button', { name: /Closure review workspace/ })).toHaveAttribute(
        'aria-pressed',
        'true',
    );
    await page.goto('/partner/leads');
    await page.goto('/account/closure');
    await page.getByRole('combobox', { name: 'Closure scope' }).click();
    await page.getByRole('option', { name: 'A workspace I own' }).click();
    await page.getByRole('combobox', { name: 'Workspace', exact: true }).click();
    await page.getByRole('option', { name: 'Closure review workspace' }).click();
    await page.getByRole('button', { name: 'Review closure', exact: true }).click();
    const review = page.getByRole('region', { name: 'Review closure: Closure review workspace', exact: true });
    await review.getByLabel('Reason for closure').fill('Synthetic workspace closure');
    await review.getByLabel('Current password').fill(password);
    await review.getByLabel('Authenticator code').fill(await code());
    await review.getByRole('button', { name: 'Close workspace', exact: true }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Close Closure review workspace', exact: true }).click();
    const request = page.getByRole('region', { name: /^Closure review workspace — request / });
    await expect(request.getByText('Closure requested', { exact: true })).toBeVisible();
    await expect(page.getByRole('combobox', { name: 'Closure scope' })).toContainText('My account');
    await expect(page.getByLabel('Reason for closure')).toHaveValue('');
    expect((await page.request.get('/pipeline', { headers: { Accept: 'application/json' } })).status()).toBe(403);
    expect((await page.request.get(`/kits/${data.kit}`)).status()).toBe(404);
    for (const width of [1440, 1024, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 900 });
        await clean(page);
    }
    if (process.env.CAPTURE_CLOSURE_IMAGES === '1')
        await page.screenshot({ path: info.outputPath('workspace-restricted.png'), fullPage: true });
    await request.getByText('Cancel this closure request', { exact: true }).click();
    await request.getByLabel('Reason for cancellation').fill('Reopen retained workspace');
    await request.getByLabel('Current password').fill(password);
    await request.getByLabel('Authenticator code').fill(await code());
    await request.getByRole('button', { name: 'Cancel closure request', exact: true }).click();
    await expect(request.getByText('Closure cancelled', { exact: true })).toBeVisible();
    expect((await page.request.get('/pipeline', { headers: { Accept: 'application/json' } })).status()).toBe(200);
    expect((await page.request.get(`/kits/${data.kit}`)).status()).toBe(404);
    await clean(page);
});

test('platform owner assigns and records a metadata-only privacy review', async ({ page }, info) => {
    test.setTimeout(240_000);
    const email = `m1-browser-closure-operator-${info.project.name}-${Date.now()}@example.test`;
    const data = fixture('operator', email);
    const code = codes(page, data.secret);
    await login(page, email);
    await page.goto(`/admin/privacy-requests?page=${data.page}`);
    await expect(page).toHaveURL(/\/admin\/security$/);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Authenticator code', { exact: true }).fill(await code());
    await page.getByRole('button', { name: 'Verify and continue', exact: true }).click();
    const request = page.getByRole('region', {
        name: `${data.label} — request ${data.request.slice(0, 8)}`,
        exact: true,
    });
    await request.getByText('Handle this request', { exact: true }).click();
    await request.getByLabel('Review reason').fill('Assign verified synthetic case');
    await request.getByLabel('Current password').fill(password);
    await request.getByLabel('Authenticator code').fill(await code());
    await request.getByRole('button', { name: 'Save request update', exact: true }).click();
    // The mutation redirects to page 1; return to the request's original page when needed.
    if (data.page !== 1) await page.goto(`/admin/privacy-requests?page=${data.page}`);
    if (!(await request.getByLabel('Review reason').isVisible()))
        await request.getByText('Handle this request', { exact: true }).click();
    await request.getByRole('combobox', { name: 'Review action' }).click();
    await page.getByRole('option', { name: 'Record review' }).click();
    await request.getByLabel('Review reason').fill('Retained records reviewed; no erasure authorized');
    await request.getByLabel('Current password').fill(password);
    await request.getByLabel('Authenticator code').fill(await code());
    await request.getByRole('button', { name: 'Save request update', exact: true }).click();
    if (data.page !== 1) await page.goto(`/admin/privacy-requests?page=${data.page}`);
    await expect(request.getByText('Review recorded', { exact: true })).toBeVisible();
    await clean(page);
    if (process.env.CAPTURE_CLOSURE_IMAGES === '1')
        await page.screenshot({ path: info.outputPath('operator-review.png'), fullPage: true });
});
