import { execFileSync } from 'node:child_process';
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { fixture, login } from './helpers';

async function clean(page: Page): Promise<void> {
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}

test('reusable brand codes support signup, verified consent, replacement and safe disable', async ({
    browser,
}, info) => {
    test.setTimeout(120_000);
    const suffix = `${info.project.name}-${Date.now()}`;
    const brandEmail = `m1-browser-join-brand-${suffix}@example.test`;
    const partnerEmail = `m1-browser-join-partner-${suffix}@example.test`;
    fixture('seed', brandEmail);
    const brand = await browser.newContext();
    const partner = await browser.newContext();
    const bp = await brand.newPage();
    const pp = await partner.newPage();
    try {
        await login(bp, brandEmail);
        await bp.getByLabel('Workspace name', { exact: true }).fill('Shared Brand');
        await bp.locator('#workspace-kind').selectOption('brand');
        await bp.getByRole('button', { name: 'Create workspace', exact: true }).click();
        await bp.getByRole('link', { name: 'Open brand hub' }).click();
        await bp.getByRole('button', { name: '+ Invite partners', exact: true }).click();
        await bp.getByRole('button', { name: 'Create sign-up link', exact: true }).click();
        await expect(bp.getByLabel('Sign-up code', { exact: true })).toHaveValue(/^[0-9A-Z]{5}-[0-9A-Z]{5}$/);
        const url = await bp.getByLabel('Sign-up link', { exact: true }).inputValue();
        const oldCode = await bp.getByLabel('Sign-up code', { exact: true }).inputValue();
        await bp.getByRole('button', { name: 'Copy code', exact: true }).click();
        await expect(bp.getByText(/Sign-up code copied|Couldn't copy the sign-up code/)).toBeVisible();
        await clean(bp);
        await bp.screenshot({ path: info.outputPath('brand-link.png'), fullPage: true });
        await bp.getByRole('button', { name: 'Replace link', exact: true }).click();
        const confirmation = bp.getByRole('dialog', { name: 'Replace sign-up link?' });
        await expect(confirmation).toBeVisible();
        await expect(bp.getByRole('dialog')).toHaveCount(1);
        await expect(confirmation.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
        await bp.keyboard.press('Escape');
        await expect(bp.getByRole('button', { name: 'Replace link', exact: true })).toBeFocused();
        await pp.goto(url);
        await expect(pp).toHaveURL(/\/join$/);
        await expect(pp.getByRole('heading', { name: 'Join Shared Brand' })).toBeVisible();
        await clean(pp);
        await pp.getByRole('link', { name: 'Create account', exact: true }).click();
        await expect(pp.getByText(/Continue joining Shared Brand/)).toBeVisible();
        await pp.getByLabel('Your name').fill('Joining Partner');
        await pp.getByLabel('Email address').fill(partnerEmail);
        await pp.getByLabel('Password', { exact: true }).fill('browser-password-123');
        await pp.getByLabel('Confirm password').fill('browser-password-123');
        await pp.getByRole('button', { name: 'Create account', exact: true }).click();
        await expect(pp).toHaveURL(/\/verify-email$/);
        await pp.goto(fixture('verify', partnerEmail).url);
        await expect(pp).toHaveURL(/\/join$/);
        await expect(pp.getByText('Joining Partner', { exact: true })).toBeVisible();
        await clean(pp);
        await pp.screenshot({ path: info.outputPath('join-consent.png'), fullPage: true });
        await pp.getByLabel(/I accept this partnership/).check();
        await pp.getByRole('button', { name: 'Join partnership', exact: true }).click();
        await expect(pp).toHaveURL(/\/partner\/leads$/);
        await bp.getByRole('button', { name: 'Replace link', exact: true }).click();
        await bp
            .getByRole('dialog', { name: 'Replace sign-up link?' })
            .getByRole('button', { name: 'Replace sign-up link', exact: true })
            .click();
        await expect(bp.getByLabel('Sign-up code', { exact: true })).not.toHaveValue(oldCode);
        await expect(bp.getByRole('button', { name: 'Replace link', exact: true })).toBeFocused();
        const currentUrl = await bp.getByLabel('Sign-up link', { exact: true }).inputValue();
        await pp.goto(url);
        await expect(pp.getByRole('alert').filter({ hasText: 'not active' }).first()).toBeVisible();
        await bp.getByRole('button', { name: 'Turn off', exact: true }).click();
        await expect(bp.getByRole('dialog')).toHaveCount(1);
        await bp
            .getByRole('dialog', { name: 'Turn off sign-up link?' })
            .getByRole('button', { name: 'Turn off sign-up link', exact: true })
            .click();
        await expect(bp.getByRole('button', { name: 'Create sign-up link', exact: true })).toBeVisible();
        await bp.getByRole('button', { name: 'Close invitation dialog' }).click();
        await expect(bp.getByRole('cell', { name: 'Joining Partner Active partnership', exact: true })).toBeVisible();
        await pp.goto(currentUrl);
        await expect(pp.getByRole('alert').filter({ hasText: 'not active' }).first()).toBeVisible();
    } finally {
        await brand.close();
        await partner.close();
    }
});

test('existing identity chooses among workspaces and can join from Professional', async ({ page, browser }, info) => {
    test.setTimeout(120_000);
    const suffix = `${info.project.name}-${Date.now()}`;
    const brandEmail = `m1-browser-join-existing-brand-${suffix}@example.test`;
    const email = `m1-browser-join-existing-${suffix}@example.test`;
    fixture('seed', brandEmail);
    fixture('seed-workspaces', email);
    await login(page, brandEmail);
    await page.getByLabel('Workspace name', { exact: true }).fill('Existing Brand');
    await page.locator('#workspace-kind').selectOption('brand');
    await page.getByRole('button', { name: 'Create workspace', exact: true }).click();
    await page.getByRole('link', { name: 'Open brand hub' }).click();
    await page.getByRole('button', { name: '+ Invite partners', exact: true }).click();
    await page.getByRole('button', { name: 'Create sign-up link', exact: true }).click();
    const url = await page.getByLabel('Sign-up link', { exact: true }).inputValue();
    const partner = await browser.newContext();
    const pp = await partner.newPage();
    try {
        await login(pp, email);
        await pp.getByLabel('Workspace name', { exact: true }).fill('Join Consulting');
        await pp.locator('#workspace-kind').selectOption('professional');
        await pp.getByRole('button', { name: 'Create workspace', exact: true }).click();
        await expect(pp.getByRole('link', { name: 'Open Professional Hub', exact: true })).toBeVisible();
        // Sign in again to leave multiple eligible workspaces with no selected context.
        await pp.getByRole('button', { name: /Account menu:/ }).click();
        await pp.getByRole('menuitem', { name: 'Sign out', exact: true }).click();
        await expect(pp).toHaveURL(/\/login$/);
        await pp.goto(url);
        await pp.getByRole('link', { name: 'I already have an account' }).click();
        await pp.getByLabel('Email address').fill(email);
        await pp.getByLabel('Password', { exact: true }).fill('browser-password-123');
        await pp.getByRole('button', { name: 'Sign in', exact: true }).click();
        await expect(pp).toHaveURL(/\/workspaces$/);
        await pp.getByRole('button', { name: /Join Consulting.*Professional/ }).click();
        await expect(pp).toHaveURL(/\/join$/);
        await expect(pp.getByText('Join Consulting', { exact: true })).toBeVisible();
        await pp.getByLabel(/I accept this partnership/).check();
        await pp.getByRole('button', { name: 'Join partnership', exact: true }).click();
        await expect(pp).toHaveURL(/\/professional$/);
        await pp.goto(url);
        await pp.getByLabel(/I accept this partnership/).check();
        await pp.getByRole('button', { name: 'Join partnership', exact: true }).click();
        await expect(pp.getByRole('alert').filter({ hasText: 'already be partnered' }).first()).toBeVisible();
        await expect(pp.getByRole('button', { name: 'Enter a different code' })).toBeVisible();
        execFileSync(
            'docker',
            [
                'compose',
                '--env-file',
                'src/.env',
                '-p',
                process.env.COMPOSE_PROJECT_NAME ?? 'weavdash-professional',
                'exec',
                '-T',
                'app',
                'php',
                'tests/Browser/join-context-fixture.php',
                email,
            ],
            { cwd: '..' },
        );
        await pp.getByRole('button', { name: 'Join partnership', exact: true }).click();
        await expect(pp.getByRole('alert').filter({ hasText: 'expired' }).first()).toBeVisible();
        await expect(pp.getByLabel('Sign-up code', { exact: true })).toBeVisible();
        await pp.getByLabel('Sign-up code', { exact: true }).fill(url.split('#')[1].toLowerCase().replace('-', ' '));
        await pp.getByRole('button', { name: 'Continue', exact: true }).click();
        await expect(pp.getByLabel(/I accept this partnership/)).toBeVisible();
    } finally {
        await partner.close();
    }
});
