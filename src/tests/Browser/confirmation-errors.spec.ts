import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { fixture, login } from './helpers';

test('confirmation prevents duplicate requests while pending and retains password errors', async ({ page }, info) => {
    const email = `m1-browser-confirmation-error-${info.project.name}-${Date.now()}@example.test`;
    fixture('seed-workspaces', email);
    await login(page, email);
    await page.getByText('Account security', { exact: true }).click();
    await page.getByLabel('Current password').fill('wrong-password');
    const trigger = page.getByRole('button', { name: 'Sign out all sessions', exact: true });
    await trigger.click();
    const dialog = page.getByRole('dialog', { name: 'Sign out all sessions?', exact: true });
    let release: (() => Promise<void>) | undefined;
    let requests = 0;
    const intercepted = new Promise<void>((ready) => {
        void page.route('**/sessions', async (route) => {
            requests++;
            await new Promise<void>((resume) => {
                release = async () => {
                    await route.continue();
                    resume();
                };
                ready();
            });
        });
    });
    const submission = dialog.getByRole('button', { name: 'Sign out all sessions', exact: true }).click();
    try {
        await intercepted;
        await expect(dialog.getByRole('button', { name: 'Working…', exact: true })).toBeDisabled();
        await expect(dialog.getByRole('button', { name: 'Cancel', exact: true })).toBeDisabled();
        await dialog.locator('form').evaluate((form: HTMLFormElement) => {
            form.requestSubmit();
            form.requestSubmit();
        });
        await page.keyboard.press('Escape');
        await expect(dialog).toBeVisible();
        expect(requests).toBe(1);
    } finally {
        await release?.();
    }
    await submission;
    const alert = dialog.getByRole('alert');
    await expect(alert).toContainText('password');
    await expect(alert).toBeFocused();
    expect(requests).toBe(1);
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await page.screenshot({ path: info.outputPath('confirmation-password-error.png'), fullPage: true });
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(trigger).toBeFocused();
    await expect(page).toHaveURL(/\/workspaces$/);
});
