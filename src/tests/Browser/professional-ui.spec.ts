import { test, expect, type Page } from '@playwright/test';
import { dismissNotifications } from './feedback';
import { confirmMutation } from './confirmations';
import { fixture, login } from './helpers';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const repo = fileURLToPath(new URL('../../../', import.meta.url));
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
    await page.setViewportSize({ width: project === 'mobile' ? 390 : 1440, height: 900 });
    const email = `m1-browser-professional-ui-${project}-${Date.now()}@example.test`;
    execFileSync(
        'docker',
        [
            'compose',
            '--env-file',
            'src/.env',
            '-p',
            'weavdash-professional',
            'run',
            '--rm',
            '-T',
            '--no-deps',
            '--user',
            '1000:1000',
            '-v',
            `${repo}src:/var/www/html`,
            'migrate',
            'php',
            'tests/Browser/professional-fixture.php',
            email,
        ],
        { cwd: repo, stdio: 'pipe' },
    );
    await page.goto('/professional/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-password-123');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/professional$/);
    return email;
}
test('Professional presentation, destinations and editor cancellation', async ({ page }, info) => {
    test.setTimeout(240_000);
    const before = process.env.PROFESSIONAL_UI_BEFORE === '1';
    await startProfessional(page, info.project.name);
    await page.setViewportSize({ width: info.project.name === 'mobile' ? 390 : 1440, height: 900 });
    for (const route of routes) {
        await page.goto(`/professional${route ? '/' + route : ''}`);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        if (!before) {
            await expect(page.getByRole('main')).toHaveCount(1);
            if (route === 'contacts')
                await expect(page.getByRole('heading', { level: 1 })).toHaveCSS(
                    'font-size',
                    info.project.name === 'mobile' ? '28px' : '34px',
                );
            expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        }
        await page.screenshot({
            path: `../planning/evidence/professional-ui/${before ? 'before' : 'after'}-${info.project.name}-${route.replaceAll('/', '-') || 'hub'}.png`,
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
    await page.screenshot({
        path: `../planning/evidence/professional-ui/editor-${info.project.name}-contact.png`,
        fullPage: true,
    });
    await editor.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(editor).not.toBeVisible();
    await expect(add).toBeFocused();
    const reminder = page.getByRole('button', { name: 'New reminder', exact: true });
    await reminder.click();
    await expect(page.getByLabel('Reminder title', { exact: true })).toBeFocused();
    await page.screenshot({
        path: `../planning/evidence/professional-ui/editor-${info.project.name}-reminder.png`,
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
                    await page.locator('.pro-header').screenshot({
                        path: `../planning/evidence/professional-ui/header-${width}.png`,
                    });
                    await page.screenshot({
                        path: `../planning/evidence/professional-ui/hub-${width}.png`,
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
    await expect(nav.getByRole('menuitem')).toHaveCount(9);
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await page.screenshot({
        path: `../planning/evidence/professional-ui/menu-${info.project.name}.png`,
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

test('Professional pipeline panels retain empty and populated partnerships across widths', async ({
    page,
    browser,
}, info) => {
    test.setTimeout(180_000);
    const email = await startProfessional(page, info.project.name);
    await page.goto('/professional/leads');
    await expect(page.getByText('No active partnerships. Accept a brand invitation to connect.')).toBeVisible();
    await page.screenshot({
        path: `../planning/evidence/professional-ui/panels-empty-${info.project.name}.png`,
        fullPage: true,
    });
    const brandEmail = `m1-browser-panel-brand-${info.project.name}-${Date.now()}@example.test`;
    fixture('seed', brandEmail);
    const brand = await browser.newContext();
    try {
        const bp = await brand.newPage();
        await login(bp, brandEmail);
        await bp.getByLabel('Workspace name', { exact: true }).fill('Panel Brand');
        await bp.locator('#workspace-kind').selectOption('brand');
        await bp.getByRole('button', { name: 'Create workspace', exact: true }).click();
        await bp.getByRole('link', { name: 'Open brand hub' }).click();
        await bp.getByRole('button', { name: '+ Invite partners', exact: true }).click();
        await bp.getByLabel('Recipient email').fill(email);
        await bp.getByRole('button', { name: 'Create invitation link', exact: true }).click();
        const invitation = await bp.getByLabel('Invitation link', { exact: true }).inputValue();
        await page.goto(invitation);
        await page.getByLabel(/I accept this partnership/).check();
        await page.getByRole('button', { name: 'Accept partnership', exact: true }).click();
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
            await page.screenshot({
                path: `../planning/evidence/professional-ui/panels-${info.project.name}-${width}.png`,
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
        await page.screenshot({
            path: `../planning/evidence/professional-ui/workflow-${info.project.name}-${name}.png`,
            fullPage: true,
        });
    };
    await page.goto('/professional/leads');
    await page.getByRole('button', { name: '+ Add lead', exact: true }).click();
    await page.getByLabel('Client name (individual or business)', { exact: true }).fill('Independent client');
    await page.getByRole('button', { name: 'Capture lead', exact: true }).click();
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
    await page.getByRole('button', { name: 'Client introduction', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Client introduction', exact: true })).toHaveAttribute(
        'aria-pressed',
        'true',
    );
    await page.getByLabel('Client name (individual or business)', { exact: true }).fill('Independent client');
    await page.getByLabel('Contact name', { exact: true }).fill('Alex');
    await page.getByRole('button', { name: 'Generate draft', exact: true }).click();
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
    await page.getByRole('button', { name: 'Create lead link', exact: true }).click();
    await expect(page.getByLabel('Lead link (copy now)', { exact: true })).toBeVisible();
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Revoke lead link', exact: true }),
        'Revoke lead link',
    );
    await expect(page.getByText('Revoked', { exact: true })).toBeVisible();

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
        await expect(page.getByText('Awaiting review', { exact: true })).toBeVisible();
        await capture('onboarding-review');
        await page.getByRole('button', { name: 'Accept submission', exact: true }).click();
        await expect(page.getByRole('status').filter({ hasText: 'Onboarding accepted' })).toBeVisible();
    } finally {
        await context.close();
    }

    await page.goto('/professional/automation');
    await page.getByLabel('Weekly digest', { exact: true }).check();
    await page.getByRole('button', { name: 'Save preferences', exact: true }).click();
    await page.getByLabel('Schedule name', { exact: true }).fill('Client weekly review');
    await page.getByLabel('I consent to this weekly schedule', { exact: false }).check();
    await page.getByRole('button', { name: 'Save weekly schedule', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Client weekly review', exact: true })).toBeVisible();
    await page.getByRole('combobox', { name: 'Lead', exact: true }).click();
    await page.getByRole('option', { name: 'Independent client', exact: true }).click();
    await page.getByLabel('Recipient email', { exact: true }).fill('alex@example.test');
    await page.getByLabel('Message subject', { exact: true }).fill('Next steps');
    await page.getByLabel('Message', { exact: true }).fill('Please review the next steps.');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Next steps', exact: true })).toBeVisible();
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Approve Next steps', exact: true }),
        'Approve follow-up',
    );
    await expect(page.getByText('Approved for manual sending · Version 2', { exact: true })).toBeVisible();
    const messageDownload = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Download approved message', exact: true }).click();
    expect((await messageDownload).suggestedFilename()).toMatch(/\.txt$/);
    await capture('automation');
    await page.goto('/professional');
    for (const resource of ['leads', 'contacts', 'reminders', 'invoices', 'payments']) {
        const download = page.waitForEvent('download');
        await page.getByRole('link', { name: `Export ${resource} as CSV`, exact: true }).click();
        expect((await download).suggestedFilename()).toBe(`professional-${resource}.csv`);
    }
});
