import AxeBuilder from '@axe-core/playwright';
import { test, expect } from '@playwright/test';
import { fixture, login } from './helpers';

test('shared controls preserve keyboard intent and layouts adapt across viewport widths', async ({ page }, info) => {
    test.setTimeout(180_000);
    const email = `m1-browser-ui-refinement-${info.project.name}-${Date.now()}@example.test`;
    fixture('seed-workspaces', email);
    await login(page, email);
    await page.locator('#workspace-kind-trigger').click();
    await page.locator('#workspace-kind-search').press('Tab');
    await page.keyboard.press('Tab');
    await expect(page.getByRole('option', { name: 'Brand', exact: true })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.locator('#workspace-kind-trigger')).toHaveText('Brand');
    await expect(page.locator('#workspace-kind-trigger')).toBeFocused();
    await page.getByRole('button', { name: /Role test workspace/ }).click();
    await expect(page.getByRole('link', { name: 'Open partner portal', exact: true })).toBeVisible();

    for (const width of [1440, 1024, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 1000 });
        for (const [name, path] of [
            ['workspaces', '/workspaces'],
            ['crm', '/partner/contacts'],
            ['templates', '/partner/outreach-templates'],
            ['invoices', '/finance/invoices'],
            ['payments', '/finance/payments'],
        ]) {
            await page.goto(path!);
            await expect(page.getByRole('heading', { name: 'Choose an available workspace to continue.' })).toHaveCount(
                0,
            );
            await expect(page.locator('h1')).toBeVisible();
            await page.evaluate(() => document.fonts.ready);
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
            if (width === 768 && name === 'payments') {
                const cards = page.locator('.wd-finance-columns > section');
                const first = await cards.nth(0).boundingBox();
                const second = await cards.nth(1).boundingBox();
                expect(second!.y).toBeGreaterThanOrEqual(first!.y + first!.height);
            }
            await page.screenshot({ path: info.outputPath(`${name}-${width}.png`), fullPage: true });
        }
        await page.goto('/partner/leads');
        const trigger = page.getByRole('button', { name: '+ Add lead', exact: true });
        await trigger.click();
        const dialog = page.getByRole('dialog', { name: 'Add lead', exact: true });
        await expect(dialog.getByRole('button', { name: 'Cancel', exact: true })).toBeVisible();
        const background = async (name: string) =>
            dialog
                .getByRole('button', { name, exact: true })
                .evaluate((button) => getComputedStyle(button).backgroundColor);
        expect(await background('Cancel')).not.toBe(await background('Capture lead'));
        await expect(page.getByLabel('Brand name', { exact: true })).toBeFocused();
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
        await page.screenshot({ path: info.outputPath(`add-lead-${width}.png`), fullPage: true });
        await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
        await expect(dialog).not.toBeVisible();
        await expect(trigger).toBeFocused();
    }

    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('button', { name: '+ Add lead', exact: true }).click();
    await page.getByLabel('Brand name', { exact: true }).fill('UI review studio');
    await page.getByRole('button', { name: 'Capture lead', exact: true }).click();
    const drawer = page.getByRole('dialog', { name: 'UI review studio', exact: true });
    await expect(drawer).toBeVisible();
    const disclosure = drawer.locator('summary').filter({ hasText: 'Delete lead' });
    await disclosure.focus();
    await page.keyboard.press('Tab');
    await expect(drawer.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    await disclosure.click();
    await disclosure.focus();
    await page.keyboard.press('Tab');
    await expect(drawer.getByRole('button', { name: 'Delete this lead', exact: true })).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    await expect(disclosure).toBeFocused();
    await drawer.getByRole('button', { name: 'Close lead', exact: true }).click();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await page.screenshot({ path: info.outputPath('pipeline-populated-390.png'), fullPage: true });
});
