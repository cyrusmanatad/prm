import { expect, type Locator, type Page, type TestInfo } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/** Exercise the real modal lifecycle and count mutation requests, including cancellation. */
export async function confirmMutation(page: Page, trigger: Locator, title: string, info?: TestInfo) {
    const requests: string[] = [];
    const track = (request: import('@playwright/test').Request) => {
        if (['POST', 'PATCH', 'PUT', 'DELETE'].includes(request.method())) requests.push(request.url());
    };
    page.on('request', track);
    const dialog = page.getByRole('dialog', { name: `${title}?`, exact: true });
    try {
        await trigger.click();
        await expect(dialog).toBeVisible();
        expect(await dialog.evaluate((element) => element.matches(':modal'))).toBe(true);
        await expect(dialog.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
        expect(requests).toEqual([]);
        await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
        await expect(dialog).not.toBeVisible();
        await expect(trigger).toBeFocused();
        expect(requests).toEqual([]);
        await trigger.click();
        await page.keyboard.press('Escape');
        await expect(dialog).not.toBeVisible();
        await expect(trigger).toBeFocused();
        expect(requests).toEqual([]);
        await trigger.click();
        const cancel = dialog.getByRole('button', { name: 'Cancel', exact: true });
        const submit = dialog.getByRole('button', { name: title, exact: true });
        await page.keyboard.press('Shift+Tab');
        await expect(submit).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(cancel).toBeFocused();
        const scan = await new AxeBuilder({ page }).analyze();
        expect(scan.violations).toEqual([]);
        if (info)
            await page.screenshot({
                path: info.outputPath(`confirm-${title.toLowerCase().replaceAll(' ', '-')}.png`),
                fullPage: true,
            });
        await submit.click();
        await expect.poll(() => requests.length).toBe(1);
    } finally {
        page.off('request', track);
    }
}
