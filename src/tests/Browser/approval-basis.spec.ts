import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { fixture, login, mutate } from './helpers';

test('lead edits remove obsolete approval and require fresh manual work', async ({ page }, info) => {
    const width = info.project.name.includes('mobile') ? 390 : 1440;
    await page.setViewportSize({ width, height: 1080 });
    const email = `m1-browser-approval-basis-${Date.now()}@example.test`;
    fixture('seed', email);
    await login(page, email);
    await page.getByLabel('Workspace name', { exact: true }).fill('Approval basis workspace');
    await page.getByRole('button', { name: 'Create workspace', exact: true }).click();
    await page.getByRole('link', { name: 'Automation preferences', exact: true }).click();
    await expect(page).toHaveURL(/\/automation$/);
    expect(
        await mutate(page, '/pipeline', 'POST', {
            brand_name: 'Approval basis lead',
            currency: 'PHP',
            value_minor: '0',
        }),
    ).toBe(201);
    await page.reload();
    await page.getByRole('combobox', { name: 'Lead', exact: true }).click();
    await page.getByRole('option', { name: 'Approval basis lead', exact: true }).click();
    await page.getByLabel('Recipient email', { exact: true }).fill('recipient@example.test');
    await page.getByLabel('Message subject', { exact: true }).fill('Review exact approval');
    await page.getByLabel('Message', { exact: true }).fill('This message needs a current lead and approval.');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await page.getByRole('button', { name: 'Approve Review exact approval', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Approve follow-up?', exact: true });
    await expect(dialog.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    await dialog.getByRole('button', { name: 'Approve follow-up', exact: true }).click();
    await expect(page.getByRole('link', { name: 'Download approved message', exact: true })).toBeVisible();
    const draft = await page.evaluate(async () => {
        const response = await fetch('/automation', { headers: { Accept: 'application/json' } });
        const data = await response.json();
        return data.drafts[0] as { id: string; pipeline_entry_id: string };
    });
    expect(
        await mutate(page, `/pipeline/${draft.pipeline_entry_id}`, 'PATCH', {
            version: '1',
            brand_name: 'Changed lead',
            currency: 'PHP',
            value_minor: '0',
        }),
    ).toBe(200);
    await page.reload();
    await expect(page.getByText('Discarded · Version 3', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Download approved message', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Approve Review exact approval', exact: true })).toHaveCount(0);
    const oldDownload = await page.request.get(`/automation/drafts/${draft.id}/handoff?version=2`, {
        headers: { Accept: 'application/json' },
    });
    expect(oldDownload.status()).toBe(409);
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
