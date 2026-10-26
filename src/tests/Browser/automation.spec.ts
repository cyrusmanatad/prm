import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { fixture, login } from './helpers';

test('desktop automation preferences persist consent and timezone with confirmed withdrawal', async ({
    page,
}, info) => {
    test.skip(info.project.name.includes('mobile'), 'Small-screen acceptance is assigned to Claude.');
    await page.setViewportSize({ width: 1440, height: 1080 });
    const email = `m1-browser-schedules-${Date.now()}@example.test`;
    fixture('seed', email);
    await login(page, email);
    await page.getByLabel('Workspace name', { exact: true }).fill('Schedule review workspace');
    await page.getByRole('button', { name: 'Create workspace', exact: true }).click();
    await page.getByRole('link', { name: 'Automation preferences', exact: true }).click();
    await expect(page.getByLabel('Weekly digest', { exact: true })).not.toBeChecked();
    await expect(page.getByText('No schedules yet.', { exact: false })).toBeVisible();
    await page.getByRole('combobox', { name: 'Timezone', exact: true }).click();
    await page.getByRole('combobox', { name: 'Search Timezone', exact: true }).fill('New_York');
    await page.keyboard.press('Enter');
    await expect(page.getByRole('combobox', { name: 'Timezone', exact: true })).toBeFocused();
    await page.getByLabel('Weekly digest', { exact: true }).check();
    await page.getByRole('button', { name: 'Save preferences', exact: true }).click();
    await expect(page.getByText('Uses your saved timezone: America/New_York')).toBeVisible();
    await page.getByLabel('Schedule name', { exact: true }).fill('Monday review');
    await page.getByLabel('Local time (HH:MM)', { exact: true }).fill('09:30');
    await page.getByLabel('I consent to this weekly schedule', { exact: false }).check();
    await page.getByRole('button', { name: 'Save weekly schedule', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Monday review', exact: true })).toBeVisible();
    await page.reload();
    await expect(page.getByLabel('Weekly digest', { exact: true })).toBeChecked();
    await expect(page.getByText('Consent active', { exact: false })).toBeVisible();
    for (const width of [1440, 1920]) {
        await page.setViewportSize({ width, height: 1080 });
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.screenshot({ path: info.outputPath(`automation-${width}.png`), fullPage: true });
    }
    const withdraw = page.getByRole('button', { name: 'Withdraw consent for Monday review', exact: true });
    await withdraw.click();
    const dialog = page.getByRole('dialog', { name: 'Withdraw schedule consent?', exact: true });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(withdraw).toBeFocused();
    await withdraw.click();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await dialog.getByRole('button', { name: 'Withdraw schedule consent', exact: true }).click();
    await expect(page.getByText('Disabled · weekly digest', { exact: true })).toBeVisible();
    await page.getByLabel('Weekly digest', { exact: true }).uncheck();
    await page.getByRole('button', { name: 'Save preferences', exact: true }).click();
    const preferencesDialog = page.getByRole('dialog', { name: 'Withdraw notice consent?', exact: true });
    await expect(preferencesDialog.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    await preferencesDialog.getByRole('button', { name: 'Withdraw notice consent', exact: true }).click();
    await page.reload();
    await expect(page.getByLabel('Weekly digest', { exact: true })).not.toBeChecked();
    await expect(page.getByText('Disabled · weekly digest', { exact: true })).toBeVisible();
});
