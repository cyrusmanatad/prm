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

const BURGUNDY = 'rgb(142, 22, 80)';
const PURPLE = 'rgb(100, 29, 115)';
const PURPLE_SOFT = 'rgb(237, 225, 242)';
const BURGUNDY_SOFT = 'rgb(246, 227, 236)';

const rootAppearance = (page: Page) => page.evaluate(() => document.documentElement.dataset.appearance);
const token = (page: Page, name: string) =>
    page.evaluate((name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim(), name);
const noOverflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);

/** The server's initial HTML, before any client script runs. */
async function initialAppearance(page: Page, path: string): Promise<string | undefined> {
    const html = await (await page.request.get(path, { headers: { Accept: 'text/html' } })).text();
    return /<html[^>]*data-appearance="([a-z]+)"/.exec(html)?.[1];
}

async function openAccountMenu(page: Page) {
    await page.getByRole('button', { name: /Account menu/ }).click();
    return page.getByRole('menu');
}

async function signOut(page: Page) {
    await (await openAccountMenu(page)).getByRole('menuitem', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/login$/);
}

async function choose(page: Page, label: 'Burgundy' | 'Purple') {
    await page.getByRole('radio', { name: new RegExp(`^${label}`) }).check();
    await page.getByRole('button', { name: 'Save appearance', exact: true }).click();
    await expect(page.getByText('Appearance saved')).toBeVisible();
}

test('a saved accent follows the account across pages, overlays, sign-in and other accounts', async ({
    page,
}, info) => {
    test.setTimeout(150_000);
    const mobile = isMobile(info);
    const stamp = `${info.project.name}-${Date.now()}`;
    const email = `m1-browser-appearance-${stamp}@example.test`;
    const otherEmail = `m1-browser-appearance-other-${stamp}@example.test`;
    const workspace = fixture('seed-workspaces', email);
    fixture('seed', otherEmail);

    // Guests and new accounts get Burgundy without saving anything.
    await page.goto('/login');
    expect(await rootAppearance(page)).toBe('burgundy');
    await login(page, email);
    expect(await rootAppearance(page)).toBe('burgundy');
    expect(await token(page, '--wd-brand')).toBe('#8e1650');

    // The account menu (teleported) reaches the Appearance page.
    const menu = await openAccountMenu(page);
    await menu.getByRole('menuitem', { name: 'Appearance' }).click();
    await expect(page).toHaveURL(/\/account\/appearance$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Appearance' })).toBeVisible();
    const group = page.getByRole('group', { name: 'Accent color' });
    await expect(group.getByRole('radio', { name: /^Burgundy/ })).toBeChecked();
    await expect(group.getByText('Current', { exact: true })).toHaveCount(1);
    expect(await noOverflow(page)).toBe(true);
    if (mobile) expect(await smallTouchTargets(page)).toEqual([]);
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);

    // Selecting alone changes nothing; Save applies the server-confirmed value without a reload.
    await page.evaluate(() => ((window as unknown as { marker: number }).marker = 1));
    if (mobile) {
        await group.getByRole('radio', { name: /^Purple/ }).check();
    } else {
        await group.getByRole('radio', { name: /^Burgundy/ }).focus();
        await page.keyboard.press('ArrowDown');
        await expect(group.getByRole('radio', { name: /^Purple/ })).toBeChecked();
    }
    expect(await rootAppearance(page)).toBe('burgundy');
    const save = page.getByRole('button', { name: 'Save appearance', exact: true });
    if (mobile) await save.click();
    else {
        await save.focus();
        await page.keyboard.press('Enter');
    }
    await expect(page.getByText('Appearance saved')).toBeVisible();
    await expect.poll(() => rootAppearance(page)).toBe('purple');
    expect(await page.evaluate(() => (window as unknown as { marker?: number }).marker)).toBe(1);
    expect(await token(page, '--wd-brand')).toBe('#641d73');
    await page.mouse.move(0, 0);
    await save.evaluate((button) => button.blur());
    await expect(save).toHaveCSS('background-color', PURPLE);
    await expect(page.locator('.wd-link').first()).toHaveCSS('color', PURPLE);
    await expect(group.getByRole('radio', { name: /^Purple.*Current/ })).toBeChecked();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);

    // Back restores an earlier page from history with its old props; the confirmed accent stays.
    await page.goBack();
    await expect(page).not.toHaveURL(/about:blank/);
    expect(await rootAppearance(page)).toBe('purple');
    await page.goto('/account/appearance');
    // The server renders the saved value before hydration, and a reload keeps it.
    expect(await initialAppearance(page, '/account/appearance')).toBe('purple');
    await page.reload();
    expect(await rootAppearance(page)).toBe('purple');

    // Inertia navigation into the portal keeps it on the shell, current item and teleported overlays.
    expect(await mutate(page, '/workspace/select', 'POST', { org_id: workspace.own })).toBe(200);
    await page.goto('/partner/home');
    expect(await rootAppearance(page)).toBe('purple');
    if (!mobile) await expect(page.locator('.wd-portal-header')).toHaveCSS('background-color', PURPLE);
    const nav = await portalNavigation(page, 'Partner');
    const current = nav.locator('[aria-current="page"]');
    await expect(current).toHaveText('Home');
    await expect(current).toHaveCSS('background-color', PURPLE_SOFT);
    if (mobile) {
        // The drawer is a teleported Reka dialog; it inherits the root tokens.
        await expect(page.getByRole('dialog', { name: 'Partner menu' })).toBeVisible();
        await nav.getByRole('link', { name: 'My leads', exact: true }).click();
    } else {
        await nav.getByRole('link', { name: 'My leads', exact: true }).click();
    }
    await expect(page).toHaveURL(/\/partner\/leads$/);
    expect(await rootAppearance(page)).toBe('purple');
    const accountMenu = await openAccountMenu(page);
    const firstItem = accountMenu.getByRole('menuitem').first();
    await firstItem.hover();
    await expect(firstItem).toHaveCSS('color', PURPLE);
    await page.keyboard.press('Escape');
    // A partial reload (Home refresh) omits the shared prop and keeps the applied value.
    await page.goto('/partner/home');
    const partial = page.waitForResponse(
        (response) =>
            response.url().includes('/partner/home') && !!response.request().headers()['x-inertia-partial-data'],
    );
    await page.getByRole('button', { name: /Refresh overview/ }).click();
    expect((await (await partial).json()).props.appearance).toBeUndefined();
    expect(await rootAppearance(page)).toBe('purple');

    // An invalid value is rejected and changes nothing.
    expect(await mutate(page, '/account/appearance', 'POST', { appearance: 'teal' })).toBe(422);
    await page.reload();
    expect(await rootAppearance(page)).toBe('purple');

    // Sign-out and the next account in the same browser return to Burgundy.
    await signOut(page);
    expect(await rootAppearance(page)).toBe('burgundy');
    expect(await initialAppearance(page, '/login')).toBe('burgundy');
    await login(page, otherEmail);
    expect(await rootAppearance(page)).toBe('burgundy');
    expect(await token(page, '--wd-brand')).toBe('#8e1650');
    await signOut(page);

    // Signing in again restores Purple, and switching back to Burgundy works.
    await login(page, email);
    expect(await rootAppearance(page)).toBe('purple');
    await page.goto('/account/appearance');
    await choose(page, 'Burgundy');
    await expect.poll(() => rootAppearance(page)).toBe('burgundy');
    // Measure the resting state, not hover or focus.
    await page.mouse.move(0, 0);
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await expect(page.getByRole('button', { name: 'Save appearance', exact: true })).toHaveCSS(
        'background-color',
        BURGUNDY,
    );
});

test('Brand keeps its light header and Professional its shell in Purple; public pages stay Burgundy', async ({
    page,
}, info) => {
    test.setTimeout(150_000);
    const mobile = isMobile(info);
    const stamp = `${info.project.name}-${Date.now()}`;

    const brandEmail = `m1-browser-appearance-brand-${stamp}@example.test`;
    fixture('seed', brandEmail);
    approvedBrand(brandEmail, 'Hue Brand');
    await login(page, brandEmail);
    expect(await mutate(page, '/account/appearance', 'POST', { appearance: 'purple' })).toBe(200);
    await page.reload();
    await openBrandHub(page, 'Hue Brand');
    expect(await rootAppearance(page)).toBe('purple');
    await expect(page.locator('.wd-portal-header')).toHaveCSS('background-color', 'rgb(255, 255, 255)');
    const nav = await portalNavigation(page, 'Brand');
    await expect(nav.locator('[aria-current="page"]')).toHaveCSS('background-color', PURPLE_SOFT);
    if (mobile) await page.keyboard.press('Escape');
    expect(await noOverflow(page)).toBe(true);

    // Shared and public pages keep the default accent for a signed-in Purple account.
    await page.goto('/lead');
    expect(await rootAppearance(page)).toBe('burgundy');
    await page.goto('/workspaces');
    await signOut(page);

    const proEmail = `m1-browser-professional-appearance-${stamp}@example.test`;
    migrationFixture('professional-fixture.php', proEmail);
    await page.goto('/professional/login');
    await page.getByLabel('Email address').fill(proEmail);
    await page.getByLabel('Password', { exact: true }).fill('browser-password-123');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/professional$/);
    expect(await rootAppearance(page)).toBe('burgundy');
    const proNav = await portalNavigation(page, 'Professional');
    await expect(proNav.locator('[aria-current="page"]')).toHaveCSS('background-color', BURGUNDY_SOFT);
    if (mobile) await page.keyboard.press('Escape');
    expect(await mutate(page, '/account/appearance', 'POST', { appearance: 'purple' })).toBe(200);
    await page.reload();
    expect(await rootAppearance(page)).toBe('purple');
    const purpleNav = await portalNavigation(page, 'Professional');
    await expect(purpleNav.locator('[aria-current="page"]')).toHaveCSS('background-color', PURPLE_SOFT);
    if (mobile) await page.keyboard.press('Escape');
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
});
