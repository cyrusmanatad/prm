import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import {
    approvedBrand,
    fixture,
    isMobile,
    login,
    migrationFixture,
    mutate,
    openBrandHub,
    portalNavigation,
    smallTouchTargets,
} from './helpers';

const noOverflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);

async function check(page: Page, mobile: boolean): Promise<void> {
    expect(await noOverflow(page)).toBe(true);
    if (mobile) expect(await smallTouchTargets(page)).toEqual([]);
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
}

test('Partner Home is a separate destination with real tasks, figures and the shared navigation', async ({
    page,
}, info) => {
    test.setTimeout(120_000);
    const mobile = isMobile(info);
    const email = `m1-browser-home-partner-${info.project.name}-${Date.now()}@example.test`;
    const workspace = fixture('seed-workspaces', email);
    await login(page, email);
    expect(await mutate(page, '/workspace/select', 'POST', { org_id: workspace.own })).toBe(200);
    const overdue = new Date(Date.now() - 3_600_000).toISOString();
    expect(
        await mutate(page, '/pipeline', 'POST', {
            brand_name: 'Tidewater Coffee',
            currency: 'PHP',
            value_minor: '1250000',
            next_action_due: overdue,
        }),
    ).toBe(201);
    expect(await mutate(page, '/reminders', 'POST', { title: 'Call Tidewater', due_at: overdue })).toBe(201);

    // The existing entry still lands on My leads; Home is reached from the navigation.
    await page.goto('/partner');
    await expect(page).toHaveURL(/\/partner\/leads$/);
    let nav = await portalNavigation(page, 'Partner');
    await expect(nav.getByRole('link', { name: 'My leads', exact: true })).toHaveAttribute('aria-current', 'page');
    await nav.getByRole('link', { name: 'Home', exact: true }).click();
    await expect(page).toHaveURL(/\/partner\/home$/);
    if (mobile) await expect(page.getByRole('dialog', { name: 'Partner menu' })).not.toBeVisible();
    await expect(page.getByRole('heading', { level: 1, name: 'Home' })).toBeVisible();
    nav = await portalNavigation(page, 'Partner');
    await expect(nav.locator('[aria-current="page"]')).toHaveText('Home');
    await expect(nav.getByText('Autopilot', { exact: true })).toBeVisible();
    await page.keyboard.press('Escape');

    const tasks = page.getByRole('region', { name: 'Your tasks' });
    await expect(tasks.getByRole('link', { name: /Call Tidewater/ })).toContainText('Overdue');
    await expect(tasks.getByRole('link', { name: /1 lead with a next action due/ })).toHaveAttribute(
        'href',
        '/partner/leads?due=1',
    );
    await expect(page.getByText('PHP 12,500.00').first()).toBeVisible();
    await expect(page.getByRole('img', { name: /^Leads by stage: Lead 1,/ })).toBeVisible();
    await expect(page.locator('.wd-home-stage-list li')).toHaveCount(7);
    await expect(page.getByText('Feed')).toBeVisible();
    await expect(page.getByRole('tab', { name: 'Feed' })).toHaveCount(0);
    await check(page, mobile);

    const analytics = page.getByRole('tab', { name: 'Analytics' });
    await analytics.focus();
    await page.keyboard.press('ArrowRight');
    await expect(page.getByRole('tab', { name: 'Activity' })).toBeFocused();
    await expect(page.getByRole('tabpanel', { name: 'Activity' })).toContainText('Tidewater Coffee: Lead captured');

    await page.getByRole('link', { name: 'Tidewater Coffee', exact: true }).first().click();
    await expect(page).toHaveURL(/\/partner\/leads\?entry=/);
    await expect(page.getByRole('dialog')).toBeVisible();
});

test('Professional Home sits beside the Professional Hub and keeps the drawer accessible', async ({ page }, info) => {
    test.setTimeout(120_000);
    const mobile = isMobile(info);
    const email = `m1-browser-professional-home-${info.project.name}-${Date.now()}@example.test`;
    migrationFixture('professional-fixture.php', email);
    await page.goto('/professional/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-password-123');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    // Sign-in keeps landing on the Hub.
    await expect(page).toHaveURL(/\/professional$/);
    await page.goto('/partner/home');
    await expect(page).toHaveURL(/\/professional\/home$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Home' })).toBeVisible();
    await expect(page.getByText('Brand partnerships')).toHaveCount(0);
    await expect(
        page.getByRole('region', { name: /Publish your profile|Share your client inquiry link/ }),
    ).toBeVisible();
    await check(page, mobile);

    if (mobile) {
        const menu = page.getByRole('button', { name: 'Menu', exact: true });
        await menu.focus();
        await page.keyboard.press('Enter');
        const drawer = page.getByRole('dialog', { name: 'Professional menu' });
        await expect(drawer).toBeVisible();
        await expect(drawer.getByRole('link', { name: 'Home', exact: true })).toHaveAttribute('aria-current', 'page');
        await expect(drawer.getByRole('link', { name: 'Professional Hub', exact: true })).not.toHaveAttribute(
            'aria-current',
            'page',
        );
        // Focus stays in the drawer and the page behind does not scroll.
        for (let i = 0; i < 20; i++) await page.keyboard.press('Tab');
        expect(await drawer.evaluate((element) => element.contains(document.activeElement))).toBe(true);
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
        await page.keyboard.press('Escape');
        await expect(drawer).not.toBeVisible();
        await expect(menu).toBeFocused();
    } else {
        for (const width of [1024, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            expect(await noOverflow(page)).toBe(true);
        }
        const sidebar = page.getByRole('navigation', { name: 'Professional tools', exact: true });
        await sidebar.getByRole('link', { name: 'Professional Hub', exact: true }).click();
        await expect(page).toHaveURL(/\/professional$/);
        await expect(page.getByRole('heading', { level: 1, name: 'Professional Hub' })).toBeVisible();
        await expect(sidebar.locator('[aria-current="page"]')).toHaveText('Professional Hub');
    }
});

test('Brand Home shows relationships and registrations with Brand programs still unavailable', async ({
    page,
}, info) => {
    test.setTimeout(120_000);
    const mobile = isMobile(info);
    const email = `m1-browser-home-brand-${info.project.name}-${Date.now()}@example.test`;
    fixture('seed', email);
    approvedBrand(email, 'Habi Home');
    await login(page, email);
    await openBrandHub(page, 'Habi Home');
    await expect(page).toHaveURL(/\/brand$/);
    await page.goto('/brand/home');
    await expect(page.getByRole('heading', { level: 1, name: 'Home' })).toBeVisible();
    await expect(page.getByRole('banner')).toContainText('Habi Home');
    await expect(page.getByRole('heading', { name: 'Active partners' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Registered leads', exact: true })).toBeVisible();
    await expect(page.getByRole('img', { name: /Registered leads by stage/ })).toHaveCount(0);
    await expect(page.getByText('Leads your partners register with you will appear here.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Open Partner hub' })).toHaveAttribute('href', '/brand');
    const nav = await portalNavigation(page, 'Brand');
    for (const name of ['Campaigns', 'Incentives', 'Enablement', 'Payouts']) {
        await expect(nav.getByText(name, { exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name, exact: true })).toHaveCount(0);
    }
    await page.keyboard.press('Escape');
    await check(page, mobile);
    if (!mobile) {
        // A long workspace name stays on one line in the header without widening the page.
        await page.setViewportSize({ width: 1024, height: 900 });
        expect(await noOverflow(page)).toBe(true);
    }
});
