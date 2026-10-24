import { confirmMutation } from './confirmations';
import { test, expect } from '@playwright/test';
import { fixture, login } from './helpers';

test('registration, verification, workspace errors, create, and settings', async ({ page }, info) => {
    const email = `m1-browser-register-${Date.now()}@example.test`;
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/login');
    await page.getByRole('link', { name: 'Skip to main content' }).focus();
    await expect(page.getByRole('link', { name: 'Skip to main content' })).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(page.getByLabel('Email address')).toBeFocused();
    await page.screenshot({ path: info.outputPath('login.png'), fullPage: true });
    await page.getByRole('button', { name: 'Create an account' }).click();
    await page.getByLabel('Your name').fill('Browser Creator');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-password-123');
    await page.getByLabel('Confirm password').fill('browser-password-123');
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    await expect(page).toHaveURL(/\/verify-email$/);
    await page.getByRole('button', { name: 'Resend verification email' }).click();
    await expect(page.getByRole('status')).toContainText('Verification link requested');
    await page.goto(fixture('verify', email).url);
    await expect(page).toHaveURL(/\/workspaces$/);
    const create = page.getByRole('region', { name: 'Start something new.' });
    await create.getByLabel('Workspace name').fill('   ');
    await create.getByRole('button', { name: 'Create workspace' }).click();
    await expect(create.getByRole('alert')).toBeVisible();
    await expect(create.getByLabel('Workspace name')).toBeFocused();
    await page.screenshot({ path: info.outputPath('workspace-error.png'), fullPage: true });
    let release!: () => void;
    const gate = new Promise<void>((resolve) => {
        release = resolve;
    });
    await page.route('**/workspaces', async (route) => {
        if (route.request().method() === 'POST') await gate;
        await route.continue();
    });
    await create.getByLabel('Workspace name').fill('Browser workspace');
    await create.getByRole('button', { name: 'Create workspace' }).click();
    try {
        await expect(create.getByRole('button', { name: 'Creating…' })).toBeDisabled();
        await page.screenshot({ path: info.outputPath('workspace-loading.png'), fullPage: true });
    } finally {
        release();
    }
    const settings = page.getByRole('region', { name: 'Browser workspace', exact: true });
    await expect(settings).toBeVisible();
    await expect(create.getByLabel('Workspace name')).toHaveValue('');
    await settings.getByLabel('Workspace name').fill('Renamed browser workspace');
    await settings.getByRole('button', { name: 'Save name' }).click();
    await expect(page.getByRole('region', { name: 'Renamed browser workspace', exact: true })).toBeVisible();
    await expect(page.locator('#nprogress')).toHaveCount(0);
    await expect(page.locator('body')).toHaveJSProperty('scrollWidth', await page.evaluate(() => innerWidth));
    await page.screenshot({ path: info.outputPath('workspaces.png'), fullPage: true });
    expect(errors).toEqual([]);
});

test('recovery, invalid login, and revocation of two real database sessions', async ({ page, browser }, info) => {
    const email = `m1-browser-security-${Date.now()}@example.test`;
    fixture('seed', email);
    await page.goto('/forgot-password');
    await page.getByLabel('Email address').fill(email);
    await page.getByRole('button', { name: 'Send reset link' }).click();
    await expect(page.getByRole('status')).toContainText('If the account exists');
    await page.goto(fixture('reset', email).url);
    await expect(page.getByLabel('Email address')).toHaveValue(email);
    await page.getByLabel('Password', { exact: true }).fill('reset-password-456');
    await page.getByLabel('Confirm password').fill('reset-password-456');
    await page.getByRole('button', { name: 'Reset password', exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('wrong-password');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page.getByLabel('Email address')).toBeFocused();
    await expect(page.getByLabel('Password', { exact: true })).toHaveValue('');
    await page.getByLabel('Password', { exact: true }).fill('reset-password-456');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/workspaces$/);
    const other = await browser.newContext({ baseURL: info.project.use.baseURL });
    try {
        const second = await other.newPage();
        await login(second, email, 'reset-password-456');
        await page.getByText('Account security', { exact: true }).click();
        await page.getByLabel('Current password').fill('reset-password-456');
        await confirmMutation(
            page,
            page.getByRole('button', { name: 'Sign out all sessions' }),
            'Sign out all sessions',
            info,
        );
        await expect(page).toHaveURL(/\/login$/);
        await second.reload();
        await expect(second).toHaveURL(/\/login$/);
        await page.goBack();
        await expect(page).toHaveURL(/\/login$/);
        await expect(page.getByText('Browser Creator', { exact: true })).toHaveCount(0);
    } finally {
        await other.close();
    }
});
