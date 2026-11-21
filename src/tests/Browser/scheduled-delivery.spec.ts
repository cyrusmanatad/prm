import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import {
    automationTab,
    baseWidth,
    fixture,
    isMobile,
    login,
    mutate,
    newSchedule,
    reviewWidths,
    smallTouchTargets,
} from './helpers';

const repo = fileURLToPath(new URL('../../../', import.meta.url));
function compose(args: string[]): void {
    const base = ['compose', '--env-file', 'src/.env', '-p', process.env.COMPOSE_PROJECT_NAME ?? 'weavdash'];
    if (process.env.DELIVERY_TEST_COMPOSE) base.push('-f', process.env.DELIVERY_TEST_COMPOSE);
    execFileSync('docker', [...base, ...args], { cwd: repo, stdio: 'pipe' });
}

test('reminder email consent, scheduled capture, history and withdrawal', async ({ page }, info) => {
    await page.setViewportSize({ width: baseWidth(info), height: 1080 });
    const email = `m1-browser-scheduled-${Date.now()}@example.test`;
    fixture('seed', email);
    await login(page, email);
    await page.getByLabel('Workspace name', { exact: true }).fill('Scheduled delivery workspace');
    await page.getByRole('button', { name: 'Create workspace', exact: true }).click();
    await page.getByRole('link', { name: 'Automation preferences', exact: true }).click();
    expect(
        await mutate(page, '/reminders', 'POST', {
            title: 'Review the proposal',
            due_at: new Date(Date.now() + 86400000).toISOString(),
        }),
    ).toBe(201);
    await page.goto('/automation?tab=preferences');
    await page.getByLabel('Reminder notices', { exact: true }).check();
    await page.getByRole('button', { name: 'Save preferences', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Automation preferences saved' })).toBeVisible();
    const schedule = await newSchedule(page, 'Reminder email', 'Proposal review notice');
    await schedule.getByRole('combobox', { name: 'Reminder', exact: true }).click();
    await page.getByRole('option', { name: 'Review the proposal', exact: true }).click();
    const localFuture = await page.evaluate(() => {
        const date = new Date(Date.now() + 3600000);
        return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    });
    await schedule.getByLabel('Email at', { exact: true }).fill(localFuture);
    await schedule.getByLabel('I consent to this reminder email', { exact: false }).check();
    await schedule.getByRole('button', { name: 'Save schedule', exact: true }).click();
    await expect(schedule).not.toBeVisible();
    await automationTab(page, 'Schedules');
    const row = page.getByRole('row', { name: /Proposal review notice/ });
    await expect(row).toContainText('Review the proposal');
    compose(['run', '--rm', '-T', '--no-deps', 'migrate', 'php', 'tests/Browser/scheduled-notice-fixture.php', email]);
    // Background services are paused for deterministic local capture. Each command uses runtime credentials.
    compose(['exec', '-T', 'app', 'php', 'artisan', 'automation:dispatch']);
    compose(['exec', '-T', 'app', 'php', 'artisan', 'queue:work', 'redis', '--stop-when-empty']);
    compose(['exec', '-T', 'app', 'php', 'artisan', 'automation:notify']);
    compose(['exec', '-T', 'app', 'php', 'artisan', 'queue:work', 'redis', '--stop-when-empty']);
    compose(['exec', '-T', 'app', 'php', 'artisan', 'notifications:dispatch']);
    compose(['exec', '-T', 'app', 'php', 'artisan', 'queue:work', 'redis', '--stop-when-empty']);
    await page.goto('/automation?tab=history');
    await expect(page.getByText('Captured locally; no external email sent', { exact: true })).toBeVisible();
    for (const width of reviewWidths(info)) {
        await page.setViewportSize({ width, height: 1080 });
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        if (isMobile(info)) expect(await smallTouchTargets(page)).toEqual([]);
        if (process.env.DELIVERY_VISUAL_DIR?.startsWith('/tmp/')) {
            if (process.env.CAPTURE_BROWSER_IMAGES === '1')
                await page.screenshot({
                    path: `${process.env.DELIVERY_VISUAL_DIR}/scheduled-delivery-${width}.png`,
                    fullPage: true,
                });
        }
    }
    await automationTab(page, 'Schedules');
    const trigger = page.getByRole('button', { name: 'Withdraw consent for Proposal review notice', exact: true });
    await trigger.click();
    const dialog = page.getByRole('dialog', { name: 'Withdraw schedule consent?', exact: true });
    await expect(dialog.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(trigger).toBeFocused();
    await trigger.click();
    await dialog.getByRole('button', { name: 'Withdraw schedule consent', exact: true }).click();
    await expect(row).toContainText('Disabled');
    await automationTab(page, 'Email history');
    await expect(page.getByText('Captured locally; no external email sent', { exact: true })).toBeVisible();
});
