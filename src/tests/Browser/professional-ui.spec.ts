import { test, expect, type Page } from '@playwright/test';
import { dismissNotifications } from './feedback';
import { confirmMutation } from './confirmations';
import {
    approvedBrand,
    automationTab,
    fixture,
    login,
    migrationFixture,
    mutate,
    newSchedule,
    openBrandHub,
    smallTouchTargets,
    writeFollowUp,
} from './helpers';
import AxeBuilder from '@axe-core/playwright';
const routes = [
    '',
    'leads',
    'contacts',
    'onboarding',
    'outreach-templates',
    'profile',
    'finance/invoices',
    'finance/payments',
    'automation',
];
async function startProfessional(page: Page, project: string) {
    page.setDefaultTimeout(15_000);
    await page.setViewportSize({ width: project.includes('mobile') ? 390 : 1440, height: 900 });
    const email = `m1-browser-professional-ui-${project}-${Date.now()}@example.test`;
    migrationFixture('professional-fixture.php', email);
    await page.goto('/professional/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-password-123');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/professional$/);
    return email;
}

/** Narrow screens show one Media kit section at a time; wide screens show all of them. */
async function showKitSection(page: Page, name: 'Edit' | 'Preview' | 'Lead links') {
    const toggle = page
        .getByRole('group', { name: 'Show section' })
        .getByRole('button', { name: new RegExp(`^${name}`) });
    if (await toggle.isVisible()) await toggle.click();
}

/** Narrow screens show one Outreach panel at a time; wide screens show all three. */
async function showOutreachPanel(page: Page, name: 'Templates' | 'Edit' | 'Draft') {
    const toggle = page
        .getByRole('group', { name: 'Show panel' })
        .getByRole('button', { name: new RegExp(`^${name}`) });
    if (await toggle.isVisible()) await toggle.click();
}

test('Professional presentation, destinations and editor cancellation', async ({ page }, info) => {
    test.setTimeout(240_000);
    const before = process.env.PROFESSIONAL_UI_BEFORE === '1';
    await startProfessional(page, info.project.name);
    await page.setViewportSize({ width: info.project.name.includes('mobile') ? 390 : 1440, height: 900 });
    for (const route of routes) {
        await page.goto(`/professional${route ? '/' + route : ''}`);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        if (!before) {
            await expect(page.getByRole('main')).toHaveCount(1);
            if (route === 'contacts')
                await expect(page.getByRole('heading', { level: 1 })).toHaveCSS(
                    'font-size',
                    info.project.name.includes('mobile') ? '28px' : '34px',
                );
            expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        }
        if (process.env.CAPTURE_BROWSER_IMAGES === '1')
            await page.screenshot({
                path: info.outputPath('professional-ui.spec-capture-3009.png'),
                fullPage: true,
            });
    }
    if (before) return;
    await page.goto('/professional/leads');
    await expect(page.getByRole('heading', { name: 'Client leads', exact: true })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Summary', exact: true })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: "Today's next best actions", exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Autopilot', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'From your brand partners', exact: true })).toBeVisible();
    for (const label of [
        'Smart follow-ups',
        'Onboarding automation',
        'Invoice automation',
        'Automatic payouts',
        'Seasonal re-pitch',
        'Earnings digest',
    ]) {
        const control = page.getByRole('button', { name: `${label}: unavailable`, exact: true });
        await expect(control).toBeDisabled();
        await expect(control).toHaveAttribute('aria-pressed', 'false');
    }
    const reminders = page.getByRole('link', { name: 'View contacts and due reminders →', exact: true });
    await expect(reminders).toHaveAttribute('href', '/professional/contacts?state=due');
    await reminders.click();
    await expect(page).toHaveURL(/\/professional\/contacts\?state=due$/);
    await page.goto('/professional/contacts');
    const add = page.getByRole('button', { name: 'New contact', exact: true });
    await add.click();
    const editor = page.getByRole('dialog');
    await expect(editor).toBeVisible();
    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
        await page.screenshot({
            path: info.outputPath('professional-ui.spec-capture-4770.png'),
            fullPage: true,
        });
    await editor.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(editor).not.toBeVisible();
    await expect(add).toBeFocused();
    const reminder = page.getByRole('button', { name: 'New reminder', exact: true });
    await reminder.click();
    await expect(page.getByLabel('Reminder title', { exact: true })).toBeFocused();
    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
        await page.screenshot({
            path: info.outputPath('professional-ui.spec-capture-5275.png'),
            fullPage: true,
        });
    await editor.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(editor).not.toBeVisible();
    await expect(reminder).toBeFocused();
    if (info.project.name === 'desktop') {
        for (const width of [320, 390, 768, 1024, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            for (const route of routes) {
                await page.goto(`/professional${route ? '/' + route : ''}`);
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
                if (!route) {
                    const brand = page.locator('.pro-brand');
                    const wordmark = brand.locator('.wd-wordmark');
                    const badge = brand.locator('.pro-experience');
                    const wordmarkBox = (await wordmark.boundingBox())!;
                    const badgeBox = (await badge.boundingBox())!;
                    expect(
                        Math.abs(wordmarkBox.y + wordmarkBox.height / 2 - badgeBox.y - badgeBox.height / 2),
                    ).toBeLessThan(1);
                    expect(badgeBox.x - wordmarkBox.x - wordmarkBox.width).toBeCloseTo(8, 0);
                    await expect(badge).toHaveCSS('border-radius', '9999px');
                    await expect(badge).toHaveCSS('background-color', 'rgba(255, 255, 255, 0.12)');
                    await expect(badge).toHaveCSS('font-size', '12px');
                    await expect(badge).toHaveCSS('padding', '3px 8px');
                    // From 360px the brand, Menu and account share one header row.
                    if (width >= 360 && width < 1024) {
                        const brandBox = (await brand.boundingBox())!;
                        const menuBox = (await page.getByRole('button', { name: 'Menu', exact: true }).boundingBox())!;
                        expect(menuBox.y).toBeLessThan(brandBox.y + brandBox.height);
                    }
                    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
                        await page.locator('.pro-header').screenshot({
                            path: info.outputPath('professional-ui.spec-capture-6964.png'),
                        });
                    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
                        await page.screenshot({
                            path: info.outputPath('professional-ui.spec-capture-7138.png'),
                            fullPage: true,
                        });
                    const trigger = page.getByRole('button', {
                        name: width < 1024 ? 'Menu' : 'Client work',
                        exact: true,
                    });
                    await trigger.focus();
                    await page.keyboard.press('Enter');
                    await expect(page.getByRole('menuitem', { name: 'Client leads', exact: true })).toBeVisible();
                    await page.keyboard.press('Escape');
                    await expect(trigger).toBeFocused();
                    const account = page.getByRole('button', { name: /^Account menu:/ });
                    const accountBox = (await account.boundingBox())!;
                    expect(accountBox.x).toBeGreaterThanOrEqual(0);
                    expect(accountBox.x + accountBox.width).toBeLessThanOrEqual(width);
                    await account.focus();
                    await page.keyboard.press('Enter');
                    const security = page.getByRole('menuitem', { name: 'Switch workspace / account security' });
                    await expect(security).toBeVisible();
                    const securityBox = (await security.boundingBox())!;
                    expect(securityBox.x).toBeGreaterThanOrEqual(0);
                    expect(securityBox.x + securityBox.width).toBeLessThanOrEqual(width);
                    await page.keyboard.press('Escape');
                    await expect(account).toBeFocused();
                }
            }
        }
    }
    await page.setViewportSize({ width: 390, height: 900 });
    await page.goto('/professional');
    const menu = page.getByRole('button', { name: 'Menu', exact: true });
    await menu.click();
    const nav = page.getByRole('menu', { name: 'Menu', exact: true });
    await expect(nav.getByRole('menuitem')).toHaveCount(10);
    await expect(nav.getByRole('menuitem', { name: 'Brand sharing', exact: true })).toBeVisible();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
        await page.screenshot({
            path: info.outputPath('professional-ui.spec-capture-9220.png'),
            fullPage: true,
        });
    await page.keyboard.press('Escape');
    await expect(menu).toBeFocused();
    await expect(nav).not.toBeVisible();
    await page.setViewportSize({ width: 1024, height: 900 });
    const clientWork = page.getByRole('button', { name: 'Client work', exact: true });
    await clientWork.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('menuitem', { name: 'Client leads', exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(clientWork).toBeFocused();
});

test('Professional Hub summarizes work, queues what is due and opens Add lead', async ({ page }, info) => {
    test.setTimeout(120_000);
    await startProfessional(page, info.project.name);
    const mobile = info.project.name.includes('mobile');
    expect(
        await mutate(page, '/pipeline', 'POST', { brand_name: 'Harbor Studio', currency: 'USD', value_minor: '0' }),
    ).toBe(201);
    const overdue = new Date(Date.now() - 86_400_000).toISOString();
    expect(await mutate(page, '/reminders', 'POST', { title: 'Send revised proposal', due_at: overdue })).toBe(201);
    await page.goto('/professional');
    await expect(page.getByRole('heading', { level: 1, name: 'Professional Hub' })).toHaveCSS(
        'font-size',
        mobile ? '24px' : '28px',
    );
    await expect(page.getByRole('complementary', { name: 'Professional subscription' })).toHaveCount(0);
    const summary = page.getByRole('list', { name: 'Workspace summary' });
    await expect(summary.getByRole('link')).toHaveCount(4);
    await expect(summary.getByRole('link', { name: /Open client leads/ })).toContainText('1');
    await expect(summary.getByRole('link', { name: /Reminders due/ })).toContainText('1 overdue');
    const queue = page.getByRole('region', { name: 'Up next' });
    await expect(queue.getByRole('link', { name: /Send revised proposal/ })).toContainText('Overdue');
    await queue.getByRole('button', { name: /^Onboarding/ }).click();
    await expect(queue.getByRole('link', { name: /Send revised proposal/ })).toHaveCount(0);
    await queue.getByRole('button', { name: /^All/ }).click();
    await expect(page.getByRole('region', { name: 'Tools' }).getByRole('link')).toHaveCount(9);
    await expect(page.getByRole('region', { name: 'Subscription' })).toContainText('Active');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    if (mobile) expect(await smallTouchTargets(page)).toEqual([]);
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    if (!mobile) {
        await page.setViewportSize({ width: 1024, height: 900 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.setViewportSize({ width: 1440, height: 900 });
    }
    await page.getByRole('link', { name: 'Add client lead', exact: true }).click();
    await expect(page).toHaveURL(/\/professional\/leads\?create=1$/);
    await expect(page.getByRole('dialog', { name: 'Add lead' })).toBeVisible();
});

test('Professional pipeline panels retain empty and populated partnerships across widths', async ({
    page,
    browser,
}, info) => {
    test.setTimeout(180_000);
    const email = await startProfessional(page, info.project.name);
    await page.goto('/professional/leads');
    await expect(page.getByText('No active partnerships. Accept a brand invitation to connect.')).toBeVisible();
    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
        await page.screenshot({
            path: info.outputPath('professional-ui.spec-capture-10277.png'),
            fullPage: true,
        });
    const brandEmail = `m1-browser-panel-brand-${info.project.name}-${Date.now()}@example.test`;
    fixture('seed', brandEmail);
    const brand = await browser.newContext();
    try {
        const bp = await brand.newPage();
        approvedBrand(brandEmail, 'Panel Brand');
        await login(bp, brandEmail);
        await openBrandHub(bp, 'Panel Brand');
        await bp.getByRole('button', { name: '+ Invite partners', exact: true }).click();
        await bp.getByLabel('Recipient email').fill(email);
        await bp.getByRole('button', { name: 'Create invitation link', exact: true }).click();
        const invitation = await bp.getByLabel('Invitation link', { exact: true }).inputValue();
        await page.goto(invitation);
        await page.getByLabel(/I accept this partnership/).check();
        await page.getByRole('button', { name: 'Accept partnership', exact: true }).click();
        // Wait for the acceptance to land before navigating; a server with several workers can answer the next page first.
        await expect(page).toHaveURL(/\/professional\/leads$/);
        await page.goto('/professional/leads');
        await expect(page.getByText('Panel Brand', { exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'From your brand partners' })).toHaveCSS(
            'color',
            'rgb(255, 255, 255)',
        );
        await expect(page.getByText('Active partnership', { exact: true })).toBeVisible();
        await expect(page.getByText('No active partnerships. Accept a brand invitation to connect.')).toHaveCount(0);
        await dismissNotifications(page);
        const widths = info.project.name === 'desktop' ? [320, 390, 768, 1024, 1440] : [390];
        for (const width of widths) {
            await page.setViewportSize({ width, height: 900 });
            const actions = (await page
                .getByRole('heading', { name: "Today's next best actions" })
                .locator('..')
                .boundingBox())!;
            const board = (await page.locator('.wd-pipeline').boundingBox())!;
            const sidebar = (await page.getByRole('main').locator('aside').boundingBox())!;
            const autopilot = (await page.getByRole('heading', { name: 'Autopilot', exact: true }).boundingBox())!;
            const partnerships = (await page.getByRole('heading', { name: 'From your brand partners' }).boundingBox())!;
            expect(actions.y + actions.height).toBeLessThanOrEqual(board.y);
            expect(autopilot.y).toBeLessThan(partnerships.y);
            if (width >= 1440) {
                expect(sidebar.x).toBeGreaterThanOrEqual(board.x + board.width);
                expect(sidebar.y).toBeCloseTo(actions.y, 0);
            } else {
                expect(sidebar.y).toBeGreaterThanOrEqual(board.y + board.height);
            }
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
            expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
            const menu = page.getByRole('button', { name: width < 1024 ? 'Menu' : 'Client work', exact: true });
            await menu.focus();
            await page.keyboard.press('Enter');
            await expect(page.getByRole('menuitem', { name: 'Client leads', exact: true })).toBeVisible();
            await page.keyboard.press('Escape');
            await expect(menu).toBeFocused();
            const account = page.getByRole('button', { name: /^Account menu:/ });
            await account.focus();
            await page.keyboard.press('Enter');
            const security = page.getByRole('menuitem', { name: 'Switch workspace / account security' });
            await expect(security).toBeVisible();
            const bounds = (await security.boundingBox())!;
            expect(bounds.x).toBeGreaterThanOrEqual(0);
            expect(bounds.x + bounds.width).toBeLessThanOrEqual(width);
            await page.keyboard.press('Escape');
            await expect(account).toBeFocused();
            if (process.env.CAPTURE_BROWSER_IMAGES === '1')
                await page.screenshot({
                    path: info.outputPath('professional-ui.spec-capture-14509.png'),
                    fullPage: true,
                });
        }
        const end = page.getByRole('button', { name: 'End partnership', exact: true });
        await end.click();
        const confirmation = page.getByRole('dialog', { name: 'End partnership?', exact: true });
        await expect(confirmation).toContainText('Panel Brand');
        await expect(confirmation.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(end).toBeFocused();
        await confirmMutation(page, end, 'End partnership');
        await expect(page).toHaveURL(/\/professional$/);
        await page.goto('/professional/leads');
        await expect(page.getByText('No active partnerships. Accept a brand invitation to connect.')).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Autopilot', exact: true })).toBeVisible();
    } finally {
        await brand.close();
    }
});

test('Professional tools retain client, onboarding, publishing, finance and automation workflows', async ({
    page,
    browser,
}, info) => {
    test.skip(process.env.PROFESSIONAL_UI_BEFORE === '1', 'Baseline captures only.');
    test.setTimeout(240_000);
    await startProfessional(page, info.project.name);
    const capture = async (name: string) => {
        await dismissNotifications(page);
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
        if (process.env.CAPTURE_BROWSER_IMAGES === '1')
            await page.screenshot({
                path: info.outputPath('professional-ui.spec-capture-16086.png'),
                fullPage: true,
            });
    };
    await page.goto('/professional/leads');
    await page.getByRole('button', { name: '+ Add lead', exact: true }).click();
    await page.getByLabel('Client name (individual or business)', { exact: true }).fill('Independent client');
    await page.getByRole('button', { name: 'Add lead', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Independent client', exact: true })).toBeVisible();
    const leadUrl = page.url();
    await capture('lead-editor');
    await page.getByRole('link', { name: /Contacts & reminders/ }).click();
    await page.getByRole('button', { name: 'New contact', exact: true }).click();
    await page.getByLabel('Contact name', { exact: true }).fill('Alex Client');
    await page.getByRole('button', { name: 'Save contact', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Edit Alex Client', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'New reminder', exact: true }).click();
    await page.getByLabel('Reminder title', { exact: true }).fill('Review next steps');
    await page.getByLabel('Due (your local time)', { exact: true }).fill('2030-06-10T09:30');
    await page.getByRole('button', { name: 'Save reminder', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Edit Review next steps', exact: true })).toBeVisible();
    await capture('contacts');

    await page.goto('/professional/outreach-templates');
    await page.getByLabel('Template name', { exact: true }).fill('Client introduction');
    await page.getByLabel('Subject', { exact: true }).fill('Hello {{brand_name}}');
    await page.getByLabel('Template body', { exact: true }).fill('Hi {{contact_name}}, from {{creator_name}}.');
    await page.getByRole('button', { name: 'Create template', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Template created' })).toBeVisible();
    await showOutreachPanel(page, 'Templates');
    await page.getByRole('button', { name: 'Client introduction', exact: true }).click();
    await expect(page.getByLabel('Template name', { exact: true })).toHaveValue('Client introduction');
    await showOutreachPanel(page, 'Draft');
    await page.getByLabel('Client name (individual or business)', { exact: true }).fill('Independent client');
    await page.getByLabel('Contact name', { exact: true }).fill('Alex');
    await page.getByRole('button', { name: 'Edit before copying', exact: true }).click();
    await expect(page.getByLabel('Draft subject', { exact: true })).toHaveValue('Hello Independent client');
    await capture('templates');

    await page.goto('/professional/profile');
    await page.getByLabel('Public title', { exact: true }).fill('Independent consulting');
    await page.getByLabel('Public introduction', { exact: true }).fill('A synthetic professional profile.');
    await page.getByLabel('Publish this professional profile', { exact: true }).check();
    await page.getByLabel('I agree to publish this title', { exact: false }).check();
    await page.getByRole('button', { name: 'Save profile', exact: true }).click();
    await expect(page.getByRole('link', { name: 'Open public profile', exact: true })).toBeVisible();
    await capture('profile');
    await page.getByLabel('Publish this professional profile', { exact: true }).uncheck();
    await page.getByRole('button', { name: 'Save profile', exact: true }).click();
    const unpublish = page.getByRole('dialog', { name: /Unpublish/ });
    await expect(unpublish.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'Save profile', exact: true })).toBeFocused();
    await showKitSection(page, 'Lead links');
    await page.getByRole('button', { name: 'Create lead link', exact: true }).click();
    await expect(page.getByLabel('Lead link (copy now)', { exact: true })).toBeVisible();
    await confirmMutation(page, page.getByRole('button', { name: /^Revoke lead link/ }), 'Revoke lead link');
    await expect(page.getByRole('status').filter({ hasText: 'Lead link revoked' })).toBeVisible();

    await page.goto('/professional/finance/invoices');
    await page.getByLabel('Recipient business name').fill('Independent client');
    await page.getByLabel('Currency code').fill('USD');
    await page.getByLabel('Description', { exact: true }).fill('Consulting work');
    await page.getByLabel('Unit price in minor units').fill('9007199254740993');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page).toHaveURL(/\/finance\/invoices\/[a-f0-9-]+$/);
    const invoice = page.url().split('/').pop()!;
    await expect(page.locator('.wd-professional')).toBeVisible();
    await expect(page.getByLabel('Unit price in minor units')).toHaveValue('9007199254740993');
    await capture('invoice-detail');
    await page.goto('/professional/finance/payments');
    await page.getByLabel('Currency code').fill('USD');
    await page.getByLabel('Amount in minor units', { exact: true }).fill('800');
    await page.getByLabel('Received on').fill('2026-11-08');
    await page.getByLabel('Unique evidence reference').fill('Professional test receipt');
    await page.getByRole('button', { name: 'Record manual receipt' }).click();
    await expect(page.getByRole('heading', { name: 'Professional test receipt' })).toBeVisible();
    await page.locator('#allocation-payment').selectOption({ label: 'Professional test receipt · USD' });
    await page.locator('#allocation-draft').selectOption(invoice);
    await page.getByLabel('Allocation in minor units').fill('500');
    await page.getByRole('button', { name: 'Allocate receipt', exact: true }).click();
    await expect(page.getByText(/Allocated 500/)).toBeVisible();
    await capture('payments');

    await page.goto(leadUrl);
    await page.getByRole('button', { name: 'Start onboarding…', exact: true }).click();
    await page.getByRole('button', { name: 'Start onboarding', exact: true }).click();
    const invite = await page.getByLabel('Private invitation link (copy now)').inputValue();
    const reviewUrl = page.url();
    const context = await browser.newContext({ viewport: page.viewportSize()! });
    try {
        const client = await context.newPage();
        await client.goto(invite);
        await client.getByRole('button', { name: 'Open invitation', exact: true }).click();
        await client.getByLabel('Client name (individual or business)', { exact: true }).fill('Independent client');
        await client.getByLabel('Contact name', { exact: true }).fill('Alex Client');
        await client.getByLabel('Client email', { exact: true }).fill('alex@example.test');
        await client.getByLabel('Collaboration brief', { exact: true }).fill('A synthetic client brief.');
        await client.getByLabel('I agree to share this form and its reference files', { exact: false }).check();
        await client.getByRole('button', { name: 'Submit for review', exact: true }).click();
        await expect(client.getByRole('status').filter({ hasText: 'Form submitted for review' })).toBeVisible();
        await page.goto(reviewUrl);
        await expect(page.locator('.onb-detail-title')).toContainText('Awaiting review');
        await capture('onboarding-review');
        await page.getByRole('button', { name: 'Accept submission', exact: true }).click();
        await expect(page.getByRole('status').filter({ hasText: 'Onboarding accepted' })).toBeVisible();
    } finally {
        await context.close();
    }

    await page.goto('/professional/automation?tab=preferences');
    await page.getByLabel('Weekly digest', { exact: true }).check();
    await page.getByRole('button', { name: 'Save preferences', exact: true }).click();
    await expect(page).toHaveURL(/\/professional\/automation\?tab=preferences$/);
    const weekly = await newSchedule(page, 'Weekly digest', 'Client weekly review');
    await weekly.getByLabel('I consent to this weekly schedule', { exact: false }).check();
    await weekly.getByRole('button', { name: 'Save schedule', exact: true }).click();
    await expect(weekly).not.toBeVisible();
    await automationTab(page, 'Schedules');
    await expect(page.getByRole('row', { name: /Client weekly review/ })).toContainText('Consent active');
    const drawer = await writeFollowUp(
        page,
        'Independent client',
        'alex@example.test',
        'Next steps',
        'Please review the next steps.',
    );
    await expect(page).toHaveURL(/\/professional\/automation\?draft=/);
    await confirmMutation(
        page,
        drawer.getByRole('button', { name: 'Approve Next steps', exact: true }),
        'Approve follow-up',
    );
    await expect(drawer.getByText('Approved for manual sending · Version 2', { exact: true })).toBeVisible();
    const messageDownload = page.waitForEvent('download');
    await drawer.getByRole('link', { name: 'Download approved message', exact: true }).click();
    expect((await messageDownload).suggestedFilename()).toMatch(/\.txt$/);
    await capture('automation');
    await page.goto('/professional');
    for (const resource of ['leads', 'contacts', 'reminders', 'invoices', 'payments']) {
        const download = page.waitForEvent('download');
        const exportLink = page.getByRole('link', { name: `Export ${resource} as CSV`, exact: true });
        await expect(exportLink).toHaveAttribute('download', `professional-${resource}.csv`);
        await exportLink.click();
        expect((await download).suggestedFilename()).toBe(`professional-${resource}.csv`);
        await expect(page).toHaveURL(/\/professional$/);
    }
});
