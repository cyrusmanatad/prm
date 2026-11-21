import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import {
    automationTab,
    baseWidth,
    fixture,
    isMobile,
    login,
    newSchedule,
    reviewWidths,
    smallTouchTargets,
} from './helpers';

test('automation preferences persist consent and timezone with confirmed withdrawal', async ({ page }, info) => {
    await page.setViewportSize({ width: baseWidth(info), height: 1080 });
    const email = `m1-browser-schedules-${Date.now()}@example.test`;
    fixture('seed', email);
    await login(page, email);
    await page.getByLabel('Workspace name', { exact: true }).fill('Schedule review workspace');
    await page.getByRole('button', { name: 'Create workspace', exact: true }).click();
    await page.getByRole('link', { name: 'Automation preferences', exact: true }).click();
    await automationTab(page, 'Schedules');
    await expect(page.getByText('No schedules yet', { exact: true })).toBeVisible();
    await automationTab(page, 'Preferences');
    await expect(page).toHaveURL(/\/automation\?tab=preferences$/);
    await expect(page.getByLabel('Weekly digest', { exact: true })).not.toBeChecked();
    const save = page.getByRole('button', { name: 'Save preferences', exact: true });
    await expect(save).toBeDisabled();
    await page.getByRole('combobox', { name: 'Timezone', exact: true }).click();
    await page.getByRole('combobox', { name: 'Search Timezone', exact: true }).fill('New_York');
    await page.keyboard.press('Enter');
    await expect(page.getByRole('combobox', { name: 'Timezone', exact: true })).toBeFocused();
    await page.getByLabel('Weekly digest', { exact: true }).check();
    await expect(page.getByText('Unsaved changes', { exact: true })).toBeVisible();
    await save.click();
    await expect(page.getByRole('status').filter({ hasText: 'Automation preferences saved' })).toBeVisible();
    await expect(page).toHaveURL(/\/automation\?tab=preferences$/);
    await expect(page.getByText('No unsaved changes', { exact: true })).toBeVisible();

    const trigger = page.getByRole('button', { name: 'New schedule', exact: true });
    const dialog = await newSchedule(page, 'Weekly digest', 'Monday review');
    await expect(dialog.getByText('Times use your saved timezone (America/New_York).', { exact: false })).toBeVisible();
    await dialog.getByLabel('Local time (HH:MM)', { exact: true }).fill('09:30');
    await dialog.getByRole('button', { name: 'Save schedule', exact: true }).click();
    await expect(dialog.getByText('The consent field must be accepted.', { exact: false })).toBeVisible();
    await dialog.getByLabel('I consent to this weekly schedule', { exact: false }).check();
    await dialog.getByRole('button', { name: 'Save schedule', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(trigger).toBeFocused();
    await automationTab(page, 'Schedules');
    const row = page.getByRole('row', { name: /Monday review/ });
    await expect(row).toContainText('Every Monday at 09:30');
    await expect(row).toContainText('Consent active');
    // A second active weekly digest is explained before anything is sent.
    const second = await newSchedule(page, 'Weekly digest');
    await expect(second.getByText('You already have an active weekly digest', { exact: false })).toBeVisible();
    await expect(second.getByRole('button', { name: 'Save schedule', exact: true })).toBeDisabled();
    await page.keyboard.press('Escape');
    await page.reload();
    await expect(page).toHaveURL(/\?tab=schedules$/);
    await expect(row).toContainText('Consent active');
    for (const width of reviewWidths(info)) {
        await page.setViewportSize({ width, height: 1080 });
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        if (isMobile(info)) expect(await smallTouchTargets(page)).toEqual([]);
    }
    const withdraw = page.getByRole('button', { name: 'Withdraw consent for Monday review', exact: true });
    await withdraw.click();
    const confirm = page.getByRole('dialog', { name: 'Withdraw schedule consent?', exact: true });
    await expect(confirm).toBeVisible();
    await expect(confirm.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(withdraw).toBeFocused();
    await withdraw.click();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await confirm.getByRole('button', { name: 'Withdraw schedule consent', exact: true }).click();
    await expect(row).toContainText('Disabled');
    await expect(page).toHaveURL(/\?tab=schedules$/);
    await automationTab(page, 'Preferences');
    await page.getByLabel('Weekly digest', { exact: true }).uncheck();
    await page.getByRole('button', { name: 'Save preferences', exact: true }).click();
    const preferencesDialog = page.getByRole('dialog', { name: 'Withdraw notice consent?', exact: true });
    await expect(preferencesDialog.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    await preferencesDialog.getByRole('button', { name: 'Withdraw notice consent', exact: true }).click();
    await page.reload();
    await expect(page.getByLabel('Weekly digest', { exact: true })).not.toBeChecked();
    await automationTab(page, 'Schedules');
    await expect(row).toContainText('Disabled');
    // With the digest switched off, New schedule points back to Preferences instead.
    const off = await newSchedule(page, 'Weekly digest');
    await expect(off.getByText('Weekly digest notices are off in your preferences.', { exact: false })).toBeVisible();
    await off.getByRole('button', { name: 'Open preferences', exact: true }).click();
    await expect(off).not.toBeVisible();
    await expect(page.getByRole('tab', { name: /^Preferences/ })).toBeFocused();
});
