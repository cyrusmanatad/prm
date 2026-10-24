import { test, expect } from '@playwright/test';
import { fixture, login, mutate } from './helpers';

test('partner URLs, avatar navigation, modal focus and paginated drawer history', async ({ page }, info) => {
    test.setTimeout(120_000);
    const email = `m1-browser-partner-nav-${info.project.name}-${Date.now()}@example.test`;
    const workspace = fixture('seed-workspaces', email);
    await login(page, email);
    expect(await mutate(page, '/workspace/select', 'POST', { org_id: workspace.own })).toBe(200);
    await page.goto('/partner');
    await expect(page).toHaveURL(/\/partner\/leads$/);
    await page.getByRole('button', { name: '+ Add lead', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Add lead', exact: true });
    await expect(dialog).toBeVisible();
    await expect(page.getByLabel('Brand name', { exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(page.getByRole('button', { name: '+ Add lead', exact: true })).toBeFocused();
    const routes = [
        ['Contacts and reminders', '/partner/contacts'],
        ['Outreach templates', '/partner/outreach-templates'],
        ['Onboarding requests', '/partner/onboarding'],
    ];
    for (const [name, path] of routes) {
        await page.getByRole('button', { name: 'Account menu' }).click();
        await expect(page.getByRole('menuitem').locator('svg')).toHaveCount(5);
        await page.getByRole('menuitem', { name, exact: true }).click();
        await expect(page).toHaveURL(new RegExp(path + '$'));
        await page.goto('/partner/leads');
    }
    await page.getByRole('link', { name: 'Media kit', exact: true }).click();
    await expect(page).toHaveURL(/\/partner\/media-kit$/);
    for (let i = 0; i < 27; i++)
        expect(
            await mutate(page, '/pipeline', 'POST', {
                brand_name: `Route lead ${i}`,
                currency: 'PHP',
                value_minor: '100',
            }),
        ).toBe(201);
    await page.goto('/pipeline?q=Route&stage=lead');
    await expect(page).toHaveURL(/\/partner\/leads\?/);
    await expect(page.getByLabel('Search brands', { exact: true })).toHaveValue('Route');
    await page.getByRole('button', { name: 'Next 25 leads', exact: true }).click();
    const board = page.getByRole('region', { name: 'Five-stage pipeline' });
    await expect(board.getByRole('button')).toHaveCount(2);
    await board.getByRole('button').first().click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await expect(page).toHaveURL(/\/partner\/leads\?.*entry=/);
    await page.goBack();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(board.getByRole('button')).toHaveCount(2);
    await expect(page.getByRole('navigation', { name: 'Main' }).getByText('Inbox', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Inbox', exact: true })).toHaveCount(0);
    await expect(page.getByRole('navigation', { name: 'Main' }).getByText('Campaigns', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Campaigns', exact: true })).toHaveCount(0);
});
