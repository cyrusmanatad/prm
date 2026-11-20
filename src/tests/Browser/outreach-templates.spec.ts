import { dismissNotifications } from './feedback';
import { confirmMutation } from './confirmations';
import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';
import { changeRole, fixture, login } from './helpers';

/** Narrow screens show one Outreach panel at a time; wide screens show all three. */
async function showOutreachPanel(page: Page, name: 'Templates' | 'Edit' | 'Draft') {
    const toggle = page
        .getByRole('group', { name: 'Show panel' })
        .getByRole('button', { name: new RegExp(`^${name}`) });
    if (await toggle.isVisible()) await toggle.click();
}

test('private templates persist, reject stale edits, merge drafts, copy and delete', async ({ page }, info) => {
    test.setTimeout(120_000);
    const clientErrors: string[] = [];
    page.on('pageerror', (error) => clientErrors.push(error.message));
    const email = `m1-browser-template-${info.project.name}-${Date.now()}@example.test`;
    fixture('seed-workspaces', email);
    await login(page, email);
    await page.getByRole('button', { name: /Role test workspace/ }).click();
    await page.getByRole('link', { name: 'Open partner portal' }).click();
    await expect(page).toHaveURL(/\/partner/);
    await page.getByRole('button', { name: 'Account menu' }).click();
    await page.getByRole('menuitem', { name: 'Outreach templates', exact: true }).click();
    await page.getByLabel('Template name', { exact: true }).fill('Personal pitch');
    await page.getByLabel('Subject', { exact: true }).fill('Hello {{brand_name}}');
    await page.getByLabel('Template body', { exact: true }).fill('Hi {{contact_name}}, from {{creator_name}}.');
    await page.getByRole('button', { name: 'Create template', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Template created' })).toBeVisible();
    await showOutreachPanel(page, 'Templates');
    await page.getByRole('button', { name: 'Personal pitch', exact: true }).click();
    const stale = await page.context().newPage();
    await stale.goto('/outreach-templates');
    await showOutreachPanel(stale, 'Templates');
    await stale.getByRole('button', { name: 'Personal pitch', exact: true }).click();
    await stale.getByLabel('Template body', { exact: true }).fill('Unsaved stale pitch');
    await page.getByLabel('Template name', { exact: true }).fill('Latest pitch');
    await page.getByRole('button', { name: 'Save template', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Template saved' })).toBeVisible();
    await stale.getByRole('button', { name: 'Save template', exact: true }).click();
    await expect(stale.getByRole('alert')).toContainText('changed in another session');
    await expect(stale.getByLabel('Template body', { exact: true })).toHaveValue('Unsaved stale pitch');
    await expect(stale.getByRole('button', { name: 'Save template', exact: true })).toBeDisabled();
    await stale.getByRole('button', { name: 'Reload latest templates', exact: true }).click();
    await showOutreachPanel(stale, 'Templates');
    await expect(stale.getByRole('button', { name: 'Latest pitch', exact: true })).toBeVisible();
    await stale.close();
    await page.reload();
    await showOutreachPanel(page, 'Templates');
    await page.getByRole('button', { name: 'Latest pitch', exact: true }).click();
    await expect(page.getByLabel('Template name', { exact: true })).toHaveValue('Latest pitch');
    // Placeholders insert at the caret of the field used last and are marked in the body.
    await showOutreachPanel(page, 'Edit');
    const body = page.getByLabel('Template body', { exact: true });
    await body.focus();
    await body.press('End');
    await page.getByRole('button', { name: 'Insert Brand name placeholder', exact: true }).click();
    await expect(body).toHaveValue('Hi {{contact_name}}, from {{creator_name}}.{{brand_name}}');
    await expect(page.locator('.wd-placeholder-mirror mark.is-known')).toHaveCount(3);
    await expect(page.getByRole('status').filter({ hasText: 'Unsaved changes' })).toBeVisible();
    await page.getByRole('button', { name: 'Discard', exact: true }).click();
    await expect(body).toHaveValue('Hi {{contact_name}}, from {{creator_name}}.');
    await showOutreachPanel(page, 'Draft');
    await expect(page.getByRole('button', { name: 'Copy draft', exact: true })).toBeDisabled();
    await expect(page.getByRole('region', { name: 'Draft preview' }).locator('.is-missing')).toHaveText([
        'brand name',
        'contact name',
    ]);
    await page.getByLabel('Brand name', { exact: true }).fill('<b>Studio</b>');
    await page.getByLabel('Contact name', { exact: true }).fill('Sam');
    await page.getByLabel('Creator name', { exact: true }).fill('Creator');
    await expect(page.getByRole('region', { name: 'Draft preview' })).toContainText('Hello <b>Studio</b>');
    await page.getByRole('button', { name: 'Edit before copying', exact: true }).click();
    await expect(page.getByLabel('Draft subject', { exact: true })).toHaveValue('Hello <b>Studio</b>');
    await expect(page.getByLabel('Draft body', { exact: true })).toHaveValue('Hi Sam, from Creator.');
    await page.getByLabel('Draft body', { exact: true }).fill('Edited manual draft.');
    // Capture writes without requiring host clipboard support.
    await page.evaluate(() => {
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: {
                writeText: async (text: string) => {
                    (window as unknown as { copied: string }).copied = text;
                },
            },
        });
    });
    await page.getByRole('button', { name: 'Copy draft', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Draft copied' })).toBeVisible();
    expect(await page.evaluate(() => (window as unknown as { copied: string }).copied)).toBe(
        'Hello <b>Studio</b>\n\nEdited manual draft.',
    );
    // A second immediate copy must also settle, even while the first toast remains visible.
    await page.getByRole('button', { name: 'Copy draft', exact: true }).click();
    await expect(page.locator('.Toastify__toast').filter({ hasText: 'Draft copied' })).toHaveCount(2);
    await page.evaluate(() => {
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: {
                writeText: async () => {
                    throw new Error('Clipboard denied');
                },
            },
        });
    });
    await page.getByRole('button', { name: 'Copy draft', exact: true }).click();
    const copyError = page.locator('.wd-field-error').filter({ hasText: "Couldn't copy draft" });
    await expect(copyError).toBeVisible();
    await expect(page.getByRole('button', { name: 'Copy draft', exact: true })).toBeEnabled();
    // Dismissing transient notifications must preserve the manual-copy recovery guidance.
    await dismissNotifications(page);
    await expect(copyError).toBeVisible();
    await expect(page.getByLabel('Draft body', { exact: true })).toHaveValue('Edited manual draft.');
    expect(clientErrors).toEqual([]);
    const scan = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa', 'best-practice'])
        .analyze();
    await info.attach('axe-templates', {
        body: JSON.stringify({ violations: scan.violations, incomplete: scan.incomplete }),
        contentType: 'application/json',
    });
    expect(scan.violations.map((item) => item.id)).toEqual([]);
    expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBe(true);
    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
        await page.screenshot({ path: info.outputPath('templates.png'), fullPage: true });
    await showOutreachPanel(page, 'Edit');
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Delete template', exact: true }),
        'Delete template',
        info,
    );
    await expect(page.getByRole('status').filter({ hasText: 'Template deleted' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Latest pitch', exact: true })).toHaveCount(0);
    changeRole(email, 'viewer');
    await page.reload();
    await expect(page.getByText('Viewer access:', { exact: false })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Create template', exact: true })).toHaveCount(0);
    changeRole(email, 'revoked');
    expect((await page.reload())?.status()).toBe(403);
});
