import { confirmMutation } from './confirmations';
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { fixture, login, mutate } from './helpers';

async function clean(page: Parameters<typeof login>[0]) {
    const result = await new AxeBuilder({ page }).analyze();
    expect(result.violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}

test('portal logins share identity and never grant a wrong workspace context', async ({ page }, info) => {
    const email = `m1-browser-portal-entry-${info.project.name}-${Date.now()}@example.test`;
    fixture('seed-workspaces', email);
    for (const portal of ['partner', 'brand']) {
        await page.goto(`/${portal}/login`);
        await expect(
            page.getByRole('link', { name: portal === 'brand' ? 'Partner sign in' : 'Brand sign in' }),
        ).toBeVisible();
        await clean(page);
        await page.getByLabel('Email address').focus();
        await page.keyboard.press('Tab');
        await expect(page.getByLabel('Password', { exact: true })).toBeFocused();
    }
    await page.goto('/brand/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-password-123');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/workspaces$/);
    await page.goto('/brand');
    // No selected workspace means a safe selection error; no brand data is returned.
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Partner hub', exact: true })).toHaveCount(0);
});

test('same relationship appears in both portals, private leads survive removal, automation stays unavailable', async ({
    browser,
}, info) => {
    const suffix = `${info.project.name}-${Date.now()}`;
    const creatorEmail = `m1-browser-portal-creator-${suffix}@example.test`;
    const brandEmail = `m1-browser-portal-brand-${suffix}@example.test`;
    const creatorWs = fixture('seed-workspaces', creatorEmail);
    fixture('seed', brandEmail);
    const partner = await browser.newContext();
    const brand = await browser.newContext();
    const pp = await partner.newPage();
    const bp = await brand.newPage();
    try {
        await login(pp, creatorEmail);
        expect(await mutate(pp, '/workspace/select', 'POST', { org_id: creatorWs.own })).toBe(200);
        expect(
            await mutate(pp, '/pipeline', 'POST', {
                brand_name: 'Private portal prospect',
                notes: 'Private portal note',
                currency: 'PHP',
                value_minor: '900',
            }),
        ).toBe(201);
        await login(bp, brandEmail);
        await bp.getByLabel('Workspace name', { exact: true }).fill('Habi Home');
        await bp.getByLabel('Workspace type').selectOption('brand');
        await bp.getByRole('button', { name: 'Create workspace', exact: true }).click();
        await expect(bp.getByRole('link', { name: 'Open brand hub' })).toBeVisible();
        await bp.getByRole('link', { name: 'Open brand hub' }).click();
        await expect(bp.getByRole('heading', { name: 'Partner hub', exact: true })).toBeVisible();
        await bp.getByRole('button', { name: '+ Invite partners', exact: true }).click();
        const invitationDialog = bp.getByRole('dialog', { name: 'Invite a partner', exact: true });
        await expect(invitationDialog).toBeVisible();
        expect(await invitationDialog.evaluate((element) => element.matches(':modal'))).toBe(true);
        await expect(bp.getByLabel('Recipient email')).toBeFocused();
        await bp.keyboard.press('Escape');
        await expect(invitationDialog).not.toBeVisible();
        await expect(bp.getByRole('button', { name: '+ Invite partners', exact: true })).toBeFocused();
        await bp.getByRole('button', { name: '+ Invite partners', exact: true }).click();
        await expect(bp.getByLabel('Recipient email')).toBeFocused();
        await bp.getByLabel('Recipient email').fill(creatorEmail);
        await bp.getByRole('button', { name: 'Create invitation link', exact: true }).click();
        await expect(bp.getByRole('dialog', { name: 'Copy invitation link', exact: true })).toBeVisible();
        const url = await bp.getByRole('textbox', { name: 'Invitation link' }).inputValue();
        await bp.getByRole('button', { name: 'Close invitation dialog' }).click();
        await expect(bp.getByRole('dialog')).not.toBeVisible();
        expect(await mutate(pp, '/logout', 'POST', {})).toBe(200);
        await pp.goto(url);
        await pp.getByRole('button', { name: 'Sign in to accept', exact: true }).click();
        await expect(pp).toHaveURL(/\/partner\/login$/);
        await pp.getByLabel('Email address').fill(creatorEmail);
        await pp.getByLabel('Password', { exact: true }).fill('browser-password-123');
        await pp.getByRole('button', { name: 'Sign in', exact: true }).click();
        await expect(pp).toHaveURL(/\/partnerships\/accept$/);
        await pp.getByLabel(/I accept this partnership/).check();
        await pp.getByRole('button', { name: 'Accept partnership', exact: true }).click();
        await expect(pp).toHaveURL(/\/partner\/leads$/);
        await expect(pp.getByRole('heading', { name: 'From your brand partners' })).toBeVisible();
        await expect(pp.getByText('Habi Home', { exact: true })).toBeVisible();
        await expect(pp.getByRole('region', { name: 'Five-stage pipeline' })).toContainText('Private portal prospect');
        await expect(pp.getByRole('button', { name: 'Smart follow-ups: unavailable' })).toBeDisabled();
        await clean(pp);
        await pp.screenshot({ path: info.outputPath(`portal-partner-${info.project.name}.png`), fullPage: true });
        const partnerEnd = pp.getByRole('button', { name: 'End partnership', exact: true });
        await partnerEnd.click();
        const partnerConfirmation = pp.getByRole('dialog', { name: 'End partnership?', exact: true });
        await expect(partnerConfirmation).toContainText('Habi Home');
        await clean(pp);
        await pp.screenshot({ path: info.outputPath('confirm-partner-end-partnership.png'), fullPage: true });
        await pp.keyboard.press('Escape');
        await expect(partnerEnd).toBeFocused();
        await bp.reload();
        await expect(bp.getByRole('region', { name: 'Partners table' })).toContainText('Role test workspace');
        await expect(bp.locator('body')).not.toContainText('Private portal prospect');
        await expect(bp.locator('body')).not.toContainText('Private portal note');
        await clean(bp);
        await bp.screenshot({ path: info.outputPath(`portal-brand-${info.project.name}.png`), fullPage: true });
        await confirmMutation(
            bp,
            bp.getByRole('button', { name: 'End partnership', exact: true }),
            'End partnership',
            info,
        );
        await expect(bp.getByText('No active partners. Invite a partner to begin.')).toBeVisible();
        await pp.reload();
        await expect(pp.getByText('No active partnerships. Accept a brand invitation to connect.')).toBeVisible();
        await expect(pp.getByRole('region', { name: 'Five-stage pipeline' })).toContainText('Private portal prospect');
        await pp.goto(url);
        await pp.getByLabel(/I accept this partnership/).check();
        await pp.getByRole('button', { name: 'Accept partnership', exact: true }).click();
        await expect(pp.getByRole('heading', { name: 'We could not find this page.' })).toBeVisible();
    } finally {
        await partner.close();
        await brand.close();
    }
});

test('portal registration survives logout and verification before first workspace provisioning', async ({
    page,
}, info) => {
    const email = `m1-browser-register-${info.project.name}-${Date.now()}@example.test`;
    await page.goto('/brand/register');
    await page.getByLabel('Your name').fill('Registered brand');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-password-123');
    await page.getByLabel('Confirm password').fill('browser-password-123');
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    await expect(page).toHaveURL(/\/verify-email$/);
    const verify = fixture('verify', email);
    await page.getByRole('button', { name: 'Sign out', exact: true }).click();
    await page.goto('/brand/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-password-123');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/verify-email$/);
    await page.goto(verify.url);
    await expect(page).toHaveURL(/\/brand$/);
    await expect(page.getByRole('heading', { name: 'Partner hub', exact: true })).toBeVisible();
    await clean(page);
});
