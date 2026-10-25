import { dismissNotifications } from './feedback';
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { fixture, login } from './helpers';

test('repeated actions, history and network recovery retain honest accessible feedback', async ({ page }, info) => {
    const email = `m1-browser-flash-${info.project.name}-${Date.now()}@example.test`;
    fixture('seed-workspaces', email);
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await login(page, email);
    await page.getByRole('button', { name: /Role test workspace/ }).click();
    await dismissNotifications(page);
    await expect(page.locator('.Toastify__toast')).toHaveCount(0);
    const workspace = page.getByRole('region', { name: /Role test workspace/ });
    const save = workspace.getByRole('button', { name: 'Save name' });
    await save.click();
    const saved = page.locator('.Toastify__toast').filter({ hasText: 'Workspace updated' });
    await expect(saved).toHaveCount(1);
    const firstAnnouncement = await saved.first().getAttribute('id');
    await save.click();
    await expect
        .poll(() =>
            saved.evaluateAll((nodes, previous) => nodes.some((node) => node.id !== previous), firstAnnouncement),
        )
        .toBe(true);
    expect(await saved.last().evaluate((node) => getComputedStyle(node).animationName)).toBe('wd-toast-lifetime');
    await dismissNotifications(page);
    await expect(page.locator('.Toastify__toast')).toHaveCount(0);
    await page.getByRole('link', { name: 'Private finance records', exact: true }).click();
    await expect(page).toHaveURL(/\/finance\/invoices$/);
    await page.goBack();
    await expect(workspace).toBeVisible();
    await expect(saved).toHaveCount(0);
    // Abort a mutation before it reaches the server; keep advice until recovery.
    await page.route('**/workspace', (route) => route.abort('failed'));
    await save.click();
    const network = page.locator('.Toastify__toast').filter({ hasText: 'Connection lost' });
    await expect(network).toContainText('Check the current record before trying again');
    await expect(network).toBeVisible();
    await page.screenshot({ path: info.outputPath('network-feedback.png'), fullPage: true });
    if (info.project.name === 'desktop') {
        const original = page.viewportSize()!;
        for (const width of [320, 768, 1024, 1440]) {
            await page.setViewportSize({ width, height: original.height });
            const box = await network.boundingBox();
            expect(box).not.toBeNull();
            expect(box!.x).toBeGreaterThanOrEqual(0);
            expect(box!.x + box!.width).toBeLessThanOrEqual(width);
            await page.screenshot({ path: info.outputPath(`network-feedback-${width}.png`), fullPage: true });
        }
        await page.setViewportSize(original);
    }
    await page.unroute('**/workspace');
    // The PATCH never reached the server in this controlled test, so retry is safe.
    await save.click();
    await expect(network).toHaveCount(0);
    // Reduced motion must preserve automatic dismissal, without a moving progress bar.
    await page.mouse.move(0, 0);
    await expect(saved).toHaveCount(0, { timeout: 10_000 });
    expect(errors).toEqual([]);
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
