import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { fixture, login } from './helpers';

test('confirmed reply clears next action and cancels approved follow-up history', async ({ page }, info) => {
    test.skip(info.project.name.includes('mobile'), 'Small-screen acceptance belongs to Claude under D28.');
    await page.setViewportSize({ width: 1440, height: 1080 });
    const email = `m1-browser-reply-${Date.now()}@example.test`;
    fixture('seed-workspaces', email);
    await login(page, email);
    await page.getByRole('button', { name: /Role test workspace/ }).click();
    await page.getByRole('link', { name: 'Open partner portal' }).click();
    await page.getByRole('button', { name: '+ Add lead', exact: true }).click();
    await page.getByLabel('Brand name', { exact: true }).fill('Synthetic reply lead');
    await page.getByRole('button', { name: 'Capture lead', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Synthetic reply lead', exact: true })).toBeVisible();
    const leadUrl = page.url();
    const lead = new URL(leadUrl).searchParams.get('entry')!;
    await page.getByLabel('Next action', { exact: true }).fill('2035-06-10T09:30');
    await page.getByRole('button', { name: 'Save lead', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Lead saved' })).toBeVisible();
    const draft = await page.evaluate(async (lead) => {
        const csrf = decodeURIComponent(
            document.cookie
                .split('; ')
                .find((item) => item.startsWith('XSRF-TOKEN='))!
                .split('=')
                .slice(1)
                .join('='),
        );
        const headers = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf };
        const response = await fetch('/automation/drafts', {
            method: 'POST',
            headers,
            body: JSON.stringify({
                pipeline_entry_id: lead,
                recipient: 'recipient@example.test',
                subject: 'Obsolete follow-up',
                body: 'Please review our collaboration.',
            }),
        });
        const data = await response.json();
        if (response.status !== 201) throw new Error(JSON.stringify(data));
        const approved = await fetch(`/automation/drafts/${data.id}/approve`, {
            method: 'POST',
            headers,
            body: JSON.stringify({ version: 1 }),
        });
        if (!approved.ok) throw new Error('Approval fixture failed');
        return data.id as string;
    }, lead);
    await page.getByLabel('Private notes', { exact: true }).fill('Retain this unsaved note.');
    await page.getByRole('tab', { name: 'Activity', exact: true }).click();
    await page.getByRole('combobox', { name: 'Type', exact: true }).click();
    await page.getByRole('option', { name: 'Reply received', exact: true }).click();
    await page.getByLabel('Details', { exact: true }).fill('The brand replied through my email client.');
    const trigger = page.getByRole('button', { name: 'Record activity', exact: true });
    await trigger.click();
    const dialog = page.getByRole('dialog', { name: 'Record reply?', exact: true });
    await expect(dialog).toContainText('Synthetic reply lead');
    await expect(dialog.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await page.screenshot({ path: info.outputPath('reply-confirmation-1440.png'), fullPage: true });
    await page.keyboard.press('Escape');
    await expect(trigger).toBeFocused();
    expect(await page.request.get(`/automation/drafts/${draft}/handoff?version=2`).then((r) => r.status())).toBe(200);
    await trigger.click();
    await dialog.getByRole('button', { name: 'Record reply', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(
        page.getByRole('status').filter({ hasText: 'Reply recorded; existing follow-ups cancelled' }),
    ).toBeVisible();
    await expect(page.getByText('The brand replied through my email client.', { exact: true })).toBeVisible();
    await page.getByRole('tab', { name: 'Details', exact: true }).click();
    await expect(page.getByLabel('Next action', { exact: true })).toHaveValue('');
    await expect(page.getByLabel('Private notes', { exact: true })).toHaveValue('Retain this unsaved note.');
    expect(
        await page.request
            .get(`/automation/drafts/${draft}/handoff?version=2`, { headers: { Accept: 'application/json' } })
            .then((r) => r.status()),
    ).toBe(409);
    await page.goto('/automation');
    await expect(page.getByText('Cancelled after a reply · Version 3', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Download approved message' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Edit Obsolete follow-up', exact: true })).toHaveCount(0);
    for (const width of [1440, 1920]) {
        await page.setViewportSize({ width, height: 1080 });
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.screenshot({ path: info.outputPath(`reply-inbox-${width}.png`), fullPage: true });
    }
});
