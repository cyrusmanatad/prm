import { test, expect } from '@playwright/test';
import { fixture, login, mutate, openPortalTool, portalNavigation } from './helpers';

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
        ['Brand sharing', '/partner/brand-sharing'],
    ];
    // Each tool opens from the previous one through the portal navigation, which marks it current.
    for (const [name, path] of routes) {
        await openPortalTool(page, 'Partner', name!);
        await expect(page).toHaveURL(new RegExp(path + '$'));
        const nav = await portalNavigation(page, 'Partner');
        await expect(nav.getByRole('link', { name, exact: true })).toHaveAttribute('aria-current', 'page');
        await expect(nav.locator('[aria-current="page"]')).toHaveCount(1);
        await expect(nav.getByRole('link', { name: 'My leads', exact: true })).toBeVisible();
        await expect(nav.getByRole('link', { name: 'Media kit', exact: true })).toBeVisible();
        await page.keyboard.press('Escape');
        // Inside the portal the account menu keeps account destinations and leaves workspace tools to the navigation.
        await page.getByRole('button', { name: 'Account menu' }).click();
        await expect(page.getByRole('menuitem', { name: 'Onboarding requests', exact: true })).toHaveCount(0);
        await expect(page.getByRole('menuitem', { name: 'Workspace / Account security' })).toBeVisible();
        await page.keyboard.press('Escape');
    }
    // Workspace selection, outside the portal shell, still offers the workspace tools in the account menu.
    await page.goto('/workspaces');
    await page.getByRole('button', { name: 'Account menu' }).click();
    await expect(page.getByRole('menuitem').locator('svg')).toHaveCount(8);
    await expect(page.getByRole('menuitem', { name: 'Onboarding requests', exact: true })).toBeVisible();
    await page.keyboard.press('Escape');
    // Automation keeps working inside the Partner shell, distinct from the unavailable Autopilot entry.
    await page.goto('/automation');
    const tools = await portalNavigation(page, 'Partner');
    await expect(tools.getByRole('link', { name: 'Automation and approvals', exact: true })).toHaveAttribute(
        'aria-current',
        'page',
    );
    await expect(tools.getByText('Autopilot', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Autopilot', exact: true })).toHaveCount(0);
    await page.keyboard.press('Escape');
    await page.goto('/partner/leads');
    await openPortalTool(page, 'Partner', 'Media kit');
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
    const nav = await portalNavigation(page, 'Partner');
    await expect(nav.getByRole('link', { name: 'Campaigns', exact: true })).toHaveAttribute(
        'href',
        '/partner/campaigns',
    );
    await expect(nav.getByRole('link', { name: 'Incentives', exact: true })).toHaveAttribute(
        'href',
        '/partner/incentives',
    );
    for (const name of ['Inbox', 'Earnings', 'Autopilot']) {
        await expect(nav.getByText(name, { exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name, exact: true })).toHaveCount(0);
    }
    await expect(nav.locator('.wd-soon-pill').first()).toBeVisible();
});
