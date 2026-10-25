import { confirmMutation } from './confirmations';
import AxeBuilder from '@axe-core/playwright';
import { test, expect } from '@playwright/test';
import { changeRole, fixture, login } from './helpers';

test('private pipeline capture, seven stages, stale tabs, snooze, activity, and deletion', async ({ page }, info) => {
    test.setTimeout(120_000);
    const email = `m1-browser-pipeline-${Date.now()}@example.test`;
    fixture('seed-workspaces', email);
    await login(page, email);
    await page.getByRole('button', { name: /Role test workspace/ }).click();
    await page.getByRole('link', { name: 'Open partner portal' }).click();
    await expect(page.getByRole('heading', { name: 'No leads match these filters', exact: true })).toBeVisible();
    await page.getByRole('button', { name: '+ Add lead', exact: true }).click();
    await page.getByLabel('Brand name', { exact: true }).fill('M2 Studio');
    await page.getByLabel('Estimated value', { exact: true }).fill('1234.56');
    await page.getByLabel('Private notes (optional)').fill('Private creator notes');
    await page.getByRole('button', { name: 'Capture lead', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'M2 Studio', exact: true })).toBeVisible();
    await expect(page.getByRole('status').filter({ hasText: 'Lead captured' })).toBeVisible();
    await expect(page.locator('.pipeline-card')).toContainText('PHP 1,234.56');
    const leadUrl = page.url();
    for (const stage of ['pitched', 'negotiating', 'onboarded', 'active', 'repeat-client', 'lost', 'lead']) {
        await page.getByRole('combobox', { name: 'Stage', exact: true }).click();
        await page
            .getByRole('option', {
                name: stage === 'repeat-client' ? 'Repeat Client' : stage.charAt(0).toUpperCase() + stage.slice(1),
                exact: true,
            })
            .click();
        await page.getByRole('button', { name: 'Save lead', exact: true }).click();
        await expect(page.getByRole('status').filter({ hasText: 'Stage updated to' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Save lead', exact: true })).toBeEnabled();
        await page.reload();
        await expect(page.locator('#lead-stage')).toHaveValue(stage);
    }
    const stale = await page.context().newPage();
    await stale.goto(leadUrl);
    await stale.getByLabel('Brand name', { exact: true }).fill('Unsaved stale draft');
    await page.getByLabel('Brand name', { exact: true }).fill('Latest studio');
    await page.getByRole('button', { name: 'Save lead', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Lead saved' })).toBeVisible();
    await stale.getByRole('button', { name: 'Save lead', exact: true }).click();
    await expect(stale.getByRole('alert').filter({ hasText: 'changed in another session' })).toBeVisible();
    await expect(stale.getByLabel('Brand name', { exact: true })).toHaveValue('Unsaved stale draft');
    await expect(stale.getByRole('button', { name: 'Save lead', exact: true })).toBeDisabled();
    await stale.getByRole('button', { name: 'Reload latest lead', exact: true }).click();
    await expect(stale.getByLabel('Brand name', { exact: true })).toHaveValue('Latest studio');
    await stale.close();
    await page.getByLabel('Private notes', { exact: true }).fill('Draft kept across activity updates.');
    await page.getByLabel('Next action', { exact: true }).fill('2035-06-10T09:30');
    await page.getByRole('button', { name: 'Save lead', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Lead saved' })).toBeVisible();
    await page.getByRole('tab', { name: 'Activity', exact: true }).click();
    await page.getByLabel('Details', { exact: true }).fill('Discussed the next collaboration.');
    await Promise.all([
        page.waitForResponse(
            (response) =>
                response.url() === leadUrl && response.request().method() === 'GET' && response.status() === 200,
        ),
        page.getByRole('button', { name: 'Record activity', exact: true }).click(),
    ]);
    await expect(page.getByRole('status').filter({ hasText: 'Activity recorded' })).toBeVisible();
    await expect(page.getByText('Discussed the next collaboration.', { exact: true })).toBeVisible();
    await page.getByRole('tab', { name: 'Details', exact: true }).click();
    await expect(page.getByLabel('Private notes', { exact: true })).toHaveValue('Draft kept across activity updates.');
    await page.getByRole('button', { name: 'Save lead', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Lead saved' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Save lead', exact: true })).toBeEnabled();
    await page.reload();
    await expect(page.getByLabel('Next action', { exact: true })).toHaveValue('2035-06-10T09:30');
    const scan = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa', 'best-practice'])
        .analyze();
    await info.attach('axe-pipeline', {
        body: JSON.stringify({ project: info.project.name, violations: scan.violations, incomplete: scan.incomplete }),
        contentType: 'application/json',
    });
    expect(scan.violations.map((item) => ({ id: item.id, nodes: item.nodes.map((node) => node.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: info.outputPath('pipeline-detail.png'), fullPage: true });
    await page.getByRole('button', { name: 'Close lead', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Close lead', exact: true })).toHaveCount(0);
    await page.getByRole('checkbox', { name: 'Due next actions', exact: true }).check();
    await expect(page.getByRole('heading', { name: 'No leads match these filters', exact: true })).toBeVisible();
    await page.goto(leadUrl);
    await expect(page.getByRole('button', { name: /Open Latest studio/ })).toHaveCount(1);
    await page.getByText('Delete lead', { exact: true }).click();
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Delete this lead', exact: true }),
        'Delete permanently',
        info,
    );
    await expect(page.getByRole('status').filter({ hasText: 'Lead deleted' })).toBeVisible();
    await expect(page.getByRole('button', { name: /Open Latest studio/ })).toHaveCount(0);
});

test('viewer reads private workspace pipeline and revocation blocks further page access', async ({ page }) => {
    const email = `m1-browser-pipeline-viewer-${Date.now()}@example.test`;
    fixture('seed-workspaces', email);
    await login(page, email);
    await page.getByRole('button', { name: /Role test workspace/ }).click();
    await page.getByRole('link', { name: 'Open partner portal' }).click();
    changeRole(email, 'viewer');
    await page.reload();
    await expect(page.getByText('Your viewer role provides read-only access to this pipeline.')).toBeVisible();
    await expect(page.getByRole('button', { name: '+ Add lead', exact: true })).toHaveCount(0);
    changeRole(email, 'revoked');
    const response = await page.reload();
    expect(response?.status()).toBe(403);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('not available');
    await expect(page.getByText('Role test workspace', { exact: true })).toHaveCount(0);
});
