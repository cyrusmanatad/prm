import { expect, type Page } from '@playwright/test';

export async function dismissNotifications(page: Page): Promise<void> {
    const notifications = page.locator('.Toastify__toast');
    // Notifications can resolve or auto-dismiss between locating and clicking.
    await expect(async () => {
        const close = notifications.getByRole('button', { name: 'close', exact: true }).first();
        if (await close.isVisible()) await close.click({ timeout: 1000 });
        await expect(notifications).toHaveCount(0, { timeout: 250 });
    }).toPass({ timeout: 10_000 });
}
