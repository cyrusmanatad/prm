import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { approvedBrand, fixture, isMobile, login, mutate, openBrandHub, smallTouchTargets } from './helpers';

const noOverflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
const box = async (page: Page, selector: string) => (await page.locator(selector).first().boundingBox())!;

test('Partner header: search after the branding, workspace beside the avatar, unchanged lead search', async ({
    page,
}, info) => {
    test.setTimeout(150_000);
    const mobile = isMobile(info);
    const email = `m1-browser-header-${info.project.name}-${Date.now()}@example.test`;
    const workspace = fixture('seed-workspaces', email);
    await login(page, email);
    expect(await mutate(page, '/workspace/select', 'POST', { org_id: workspace.own })).toBe(200);
    expect(
        await mutate(page, '/pipeline', 'POST', {
            brand_name: 'Tidewater Coffee',
            currency: 'PHP',
            value_minor: '1250000',
        }),
    ).toBe(201);
    await page.goto('/partner/home');

    const header = page.getByRole('banner');
    const search = header.getByRole('searchbox', { name: 'Search leads' });
    const account = header.getByRole('button', { name: /^Account menu: Role test workspace\s+BC$/ });
    await expect(search).toBeVisible();
    await expect(account).toBeVisible();
    await expect(account.locator('.wd-account-workspace')).toHaveText('Role test workspace');
    // The masthead keeps the experience; the workspace name is not repeated there.
    await expect(header.locator('.wd-portal-masthead')).not.toContainText('Role test workspace');
    await expect(header.locator('.wd-portal-search')).toHaveCSS('background-color', 'rgba(255, 255, 255, 0.14)');
    await expect(search).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
    await expect(search).toHaveCSS('color', 'rgb(255, 255, 255)');

    const masthead = await box(page, '.wd-portal-masthead');
    const field = await box(page, '.wd-portal-search');
    const trigger = (await account.boundingBox())!;
    if (!mobile) {
        // One row: branding, then search growing into the middle (≤720px), then the account on the right.
        expect(field.x).toBeGreaterThan(masthead.x + masthead.width);
        expect(field.x - (masthead.x + masthead.width)).toBeLessThan(40);
        expect(field.width).toBeLessThanOrEqual(720);
        expect(field.width).toBeGreaterThan(400);
        expect(trigger.x).toBeGreaterThan(field.x + field.width);
        expect(Math.abs(field.y + field.height / 2 - (trigger.y + trigger.height / 2))).toBeLessThan(2);
        expect(field.height).toBeGreaterThanOrEqual(44);
    } else {
        // Phones: Menu and branding first; search and the account share the second row in desktop order.
        expect(field.y).toBeGreaterThanOrEqual(masthead.y + masthead.height);
        expect(Math.abs(field.y + field.height / 2 - (trigger.y + trigger.height / 2))).toBeLessThan(2);
        expect(trigger.x).toBeGreaterThan(field.x + field.width);
        expect(field.width).toBeGreaterThan(120);
        expect(await smallTouchTargets(page)).toEqual([]);
    }
    expect(await noOverflow(page)).toBe(true);
    expect((await new AxeBuilder({ page }).include('header').analyze()).violations).toEqual([]);

    // The account button opens the same account menu; it does not switch workspaces itself.
    await account.click();
    const menu = page.getByRole('menu');
    await expect(menu.getByRole('menuitem', { name: 'Switch workspace / account security' })).toBeVisible();
    await expect(menu.getByRole('menuitem', { name: 'Appearance' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(account).toBeFocused();

    // Lead search keeps its label, `q`, Enter submission, current value and My leads destination.
    await search.fill('Tidewater');
    await search.press('Enter');
    await expect(page).toHaveURL(/\/partner\/leads\?q=Tidewater$/);
    await expect(page.getByRole('banner').getByRole('searchbox', { name: 'Search leads' })).toHaveValue('Tidewater');

    // Switching workspace updates the label from the server, never a stale value.
    expect(await mutate(page, '/workspaces', 'POST', { name: 'Second header studio' })).toBe(201);
    await page.goto('/partner/home');
    await expect(
        page.getByRole('banner').getByRole('button', { name: /^Account menu: Second header studio\s+BC$/ }),
    ).toBeVisible();
    await expect(page.getByRole('banner')).not.toContainText('Role test workspace');

    // Long names truncate inside the button without overflowing the header.
    const longName = 'An exceptionally long creative studio workspace name for truncation';
    expect(await mutate(page, '/workspaces', 'POST', { name: longName })).toBe(201);
    for (const width of mobile ? [390, 320] : [1440, 1024, 768]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto('/partner/home');
        const label = page.getByRole('banner').locator('.wd-account-workspace');
        await expect(label).toHaveAttribute('title', longName);
        expect(await label.evaluate((node) => node.scrollWidth > node.clientWidth)).toBe(true);
        expect(await noOverflow(page)).toBe(true);
    }
});

test('Brand keeps its white header, gains the workspace label and no search', async ({ page }, info) => {
    test.setTimeout(120_000);
    const email = `m1-browser-header-brand-${info.project.name}-${Date.now()}@example.test`;
    fixture('seed', email);
    approvedBrand(email, 'Header Brand');
    await login(page, email);
    await openBrandHub(page, 'Header Brand');
    const header = page.getByRole('banner');
    await expect(header).toHaveCSS('background-color', 'rgb(255, 255, 255)');
    await expect(header.getByRole('searchbox')).toHaveCount(0);
    const account = header.getByRole('button', { name: /^Account menu: Header Brand\s+BC$/ });
    await expect(account).toBeVisible();
    await expect(account).toHaveCSS('color', 'rgb(34, 26, 38)');
    expect(await noOverflow(page)).toBe(true);
    expect((await new AxeBuilder({ page }).include('header').analyze()).violations).toEqual([]);
});
