import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page, type TestInfo } from '@playwright/test';
import { fixture, login } from './helpers';

async function audit(page: Page, info: TestInfo, state: string) {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa', 'best-practice']).analyze();
    await info.attach(`axe-${state}`, { body: JSON.stringify({
        state, viewport: info.project.name, violations: results.violations, incomplete: results.incomplete,
    }, null, 2), contentType: 'application/json' });
    expect(results.violations.map(({ id, nodes }) => ({ id, nodes: nodes.map(node => node.target) })), state).toEqual([]);
}

test('auth, reset, verification, and generic error pages have no automated accessibility violations', async ({ page }, info) => {
    test.setTimeout(60_000);
    for (const path of ['/login', '/register', '/forgot-password', '/reset-password/synthetic?email=creator@example.test', '/missing-page']) {
        await page.goto(path);
        await expect(page.locator('main')).toBeVisible();
        await audit(page, info, path);
    }
    const email = `m1-browser-a11y-${Date.now()}@example.test`;
    fixture('seed-unverified', email);
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-password-123');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/verify-email$/);
    await expect(page.getByRole('button', { name: 'Resend verification email' })).toBeEnabled();
    await audit(page, info, 'verification');
    const link = fixture('verify', email).url;
    await page.goto(link.replace(/signature=[^&]+/, 'signature=invalid'));
    await expect(page.getByRole('heading', { level: 1 })).toContainText('not available');
    await audit(page, info, 'denied-verification');
});

test('workspace, security disclosure, validation focus, and 200-percent root font remain accessible', async ({ page }, info) => {
    test.setTimeout(60_000);
    const email = `m1-browser-layout-${Date.now()}@example.test`;
    fixture('seed-workspaces', email);
    await login(page, email);
    await page.getByRole('button', { name: /Role test workspace/ }).click();
    await expect(page.getByRole('button', { name: 'Save name' })).toBeVisible();
    await page.getByText('Account security', { exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByLabel('Current password')).toBeVisible();
    await audit(page, info, 'workspace-security');
    await page.getByLabel('Current password').fill('wrong-password');
    await page.getByRole('button', { name: 'Sign out all sessions' }).click();
    await expect(page.getByLabel('Current password')).toBeFocused();
    await expect(page.getByRole('alert')).toBeVisible();
    await audit(page, info, 'security-error');
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    await expect(page.locator('body')).toHaveJSProperty('scrollWidth', await page.evaluate(() => innerWidth));
    await audit(page, info, 'large-text');
});
