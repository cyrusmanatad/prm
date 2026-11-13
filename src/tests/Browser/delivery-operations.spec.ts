import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { fixture, login, mutate } from './helpers';

const repo = fileURLToPath(new URL('../../../', import.meta.url));
test('explicit approved sending and MFA-protected resolution never replay uncertain email', async ({ page }, info) => {
    test.setTimeout(120_000);
    const width = info.project.name.includes('mobile') ? 390 : 1440;
    await page.setViewportSize({ width, height: 1000 });
    const email = `m1-browser-delivery-${info.project.name}-${Date.now()}@example.test`;
    const workspace = fixture('seed-workspaces', email).own;
    await login(page, email);
    await page.getByRole('button', { name: /Role test workspace/ }).click();
    await page.getByRole('link', { name: 'Open partner portal', exact: true }).click();
    await expect(page).toHaveURL(/\/partner\/leads/);
    await page.goto('/automation');
    await page.getByLabel('Follow-up review emails to my account', { exact: true }).check();
    await page.getByRole('button', { name: 'Save preferences', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Automation preferences saved' })).toBeVisible();
    expect(
        await mutate(page, '/pipeline', 'POST', { brand_name: 'Delivery client', currency: 'PHP', value_minor: '0' }),
    ).toBe(201);
    await page.reload();
    await page.getByRole('combobox', { name: 'Lead', exact: true }).click();
    await page.getByRole('option', { name: 'Delivery client', exact: true }).click();
    await page.getByLabel('Recipient email', { exact: true }).fill('explicit@example.test');
    await page.getByLabel('Message subject', { exact: true }).fill('Approved delivery example');
    await page
        .getByLabel('Message', { exact: true })
        .fill('This is the exact message approved for synthetic delivery.');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Follow-up review email', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Approve Approved delivery example', exact: true }).click();
    await page
        .getByRole('dialog', { name: 'Approve follow-up?', exact: true })
        .getByRole('button', { name: 'Approve follow-up', exact: true })
        .click();
    const send = page.getByRole('button', { name: 'Send approved message', exact: true });
    await send.click();
    const dialog = page.getByRole('dialog', { name: 'Send approved message?', exact: true });
    await expect(dialog.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    await expect(dialog).toContainText('explicit@example.test');
    await page.keyboard.press('Escape');
    await expect(send).toBeFocused();
    await expect(page.getByRole('heading', { name: 'Approved follow-up email', exact: true })).toHaveCount(0);
    await send.click();
    await dialog.getByRole('button', { name: 'Send approved message', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Approved follow-up email', exact: true })).toBeVisible();
    await expect(send).toHaveCount(0);
    if (process.env.DELIVERY_VISUAL_DIR?.startsWith('/tmp/')) {
        if (process.env.CAPTURE_BROWSER_IMAGES === '1')
            await page.screenshot({
                path: `${process.env.DELIVERY_VISUAL_DIR}/automation-${info.project.name}.png`,
                fullPage: true,
            });
    }
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    const compose = ['compose', '--env-file', 'src/.env', '-p', process.env.COMPOSE_PROJECT_NAME ?? 'weavdash-m1'];
    if (process.env.DELIVERY_TEST_COMPOSE) compose.push('-f', process.env.DELIVERY_TEST_COMPOSE);
    const { code } = JSON.parse(
        execFileSync(
            'docker',
            [
                ...compose,
                'run',
                '--rm',
                '-T',
                '--no-deps',
                'migrate',
                'php',
                'tests/Browser/delivery-fixture.php',
                email,
            ],
            { cwd: repo, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
        ),
    );
    await page.goto('/admin/security');
    await page.getByLabel('Password', { exact: true }).fill('browser-password-123');
    await page.getByLabel('Authenticator code', { exact: true }).fill(code);
    await page.getByRole('button', { name: 'Verify and continue', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/notifications$/);
    await page.getByLabel('Workspace ID (optional)', { exact: true }).fill(workspace);
    await page.getByRole('button', { name: 'Filter deliveries', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`workspace=${workspace}`));
    await page.getByRole('button', { name: /^Review resolution / }).click();
    await page.getByLabel('Reason', { exact: true }).fill('Synthetic provider investigation remains inconclusive.');
    await page.getByRole('button', { name: 'Record resolution', exact: true }).click();
    const resolution = page.getByRole('dialog', { name: 'Record delivery resolution?', exact: true });
    await expect(resolution.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await resolution.getByRole('button', { name: 'Record delivery resolution', exact: true }).click();
    await expect(page.getByText('Recorded resolution:', { exact: false })).toBeVisible();
    await expect(page.getByRole('button', { name: /^Review resolution / })).toHaveCount(0);
    for (const size of [320, 768, 1024, 1440]) {
        await page.setViewportSize({ width: size, height: 1000 });
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        if (process.env.DELIVERY_VISUAL_DIR?.startsWith('/tmp/')) {
            if (process.env.CAPTURE_BROWSER_IMAGES === '1')
                await page.screenshot({
                    path: `${process.env.DELIVERY_VISUAL_DIR}/${info.project.name}-${size}.png`,
                    fullPage: true,
                });
        }
    }
});

test('directory navigation reaches delivery diagnostics with scoped read permissions', async ({ page }, info) => {
    const email = `m1-browser-delivery-directory-${info.project.name}-${Date.now()}@example.test`;
    fixture('seed', email);
    await login(page, email);
    const compose = ['compose', '--env-file', 'src/.env', '-p', process.env.COMPOSE_PROJECT_NAME ?? 'weavdash-m1'];
    if (process.env.DELIVERY_TEST_COMPOSE) compose.push('-f', process.env.DELIVERY_TEST_COMPOSE);
    const { code } = JSON.parse(
        execFileSync(
            'docker',
            [
                ...compose,
                'run',
                '--rm',
                '-T',
                '--no-deps',
                'migrate',
                'php',
                'tests/Browser/delivery-fixture.php',
                email,
                'directory',
            ],
            { cwd: repo, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
        ),
    );
    await page.goto('/admin/security');
    await page.getByLabel('Password', { exact: true }).fill('browser-password-123');
    await page.getByLabel('Authenticator code', { exact: true }).fill(code);
    await page.getByRole('button', { name: 'Verify and continue', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await page.setViewportSize({ width: info.project.name.includes('mobile') ? 320 : 1440, height: 1000 });
    const link = page.getByRole('link', { name: 'Delivery diagnostics', exact: true });
    await expect(link).toBeVisible();
    expect((await link.boundingBox())!.height).toBeGreaterThanOrEqual(44);
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    if (process.env.DELIVERY_VISUAL_DIR?.startsWith('/tmp/')) {
        if (process.env.CAPTURE_BROWSER_IMAGES === '1')
            await page.screenshot({
                path: `${process.env.DELIVERY_VISUAL_DIR}/directory-${info.project.name}.png`,
                fullPage: true,
            });
    }
    await link.click();
    await expect(page.getByRole('heading', { name: 'Delivery diagnostics', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: /^Review resolution / })).toHaveCount(0);
});
