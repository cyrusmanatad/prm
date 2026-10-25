import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { fixture, login, mutate } from './helpers';

test('pipeline filters query every page and preserve stages, money, URL and drawer history', async ({ page }, info) => {
    test.setTimeout(120_000);
    const email = `m1-browser-pipeline-filter-${info.project.name}-${Date.now()}@example.test`;
    const workspace = fixture('seed-workspaces', email);
    await login(page, email);
    expect(await mutate(page, '/workspace/select', 'POST', { org_id: workspace.own })).toBe(200);
    await page.evaluate(async () => {
        const csrf = decodeURIComponent(
            document.cookie
                .split('; ')
                .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
                ?.slice(11) ?? '',
        );
        async function post(path: string, data: object) {
            const response = await fetch(path, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf },
                body: JSON.stringify(data),
            });
            if (!response.ok) throw new Error(`${path}: ${response.status}`);
            return response.json();
        }
        async function lead(name: string, currency: string, value: string, stage: string, due: string | null = null) {
            const { id } = await post('/pipeline', {
                brand_name: name,
                currency,
                value_minor: value,
                next_action_due: due,
            });
            if (stage !== 'lead') await post(`/pipeline/${id}/stage`, { version: 1, stage });
        }
        await lead('Older target', 'USD', '12345', 'pitched', '2020-01-01T00:00:00Z');
        await lead('Older repeat', 'PHP', '20000', 'repeat-client');
        await lead('Older lost', 'USD', '99999', 'lost', '2020-01-01T00:00:00Z');
        for (let i = 0; i < 27; i++) await lead(`Current lead ${i}`, 'PHP', '100', 'lead');
        await lead('Future active', 'PHP', '400', 'active', '2099-01-01T00:00:00Z');
    });
    await page.goto('/partner/leads');
    const board = page.getByRole('region', { name: 'My pipeline', exact: true });
    const card = (name: string) => board.getByRole('button', { name: new RegExp(`Open ${name}`) });
    const pitched = board.getByRole('region', { name: 'Pitched', exact: true });
    await expect(pitched.getByText('1 leads on other pages', { exact: true })).toBeVisible();
    await expect(pitched.getByRole('heading', { name: 'Pitched', exact: true })).toHaveCount(1);
    await expect(board.getByText('31 matching leads · all pages', { exact: true })).toBeVisible();
    await expect(board.locator('.pipeline-total')).toHaveText(['PHP 231.00', 'USD 123.45']);
    await expect(card('Older target')).toHaveCount(0);
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth),
    ).toBe(true);
    await board.getByRole('button', { name: 'Next 25 leads', exact: true }).click();
    await expect(board.locator('.lead-card')).toHaveCount(6);
    const pagedUrl = page.url();
    expect(new URL(pagedUrl).searchParams.has('cursor')).toBe(true);
    await expect(card('Older lost')).toHaveCount(1);
    await expect(board.getByRole('region', { name: 'Active', exact: true }).getByRole('button')).toHaveCount(0);
    await expect(board.getByRole('region', { name: 'Lost', exact: true })).toContainText('Older lost');
    await expect(board.getByRole('region', { name: 'Repeat Client', exact: true })).toContainText('Older repeat');
    await expect(board.locator('.pipeline-total')).toHaveText(['PHP 231.00', 'USD 123.45']);
    await card('Older target').click();
    await expect(page.getByRole('dialog', { name: 'Older target', exact: true })).toBeVisible();
    await page.goBack();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(page).toHaveURL(pagedUrl);
    await page.getByLabel('Search brands', { exact: true }).fill('Older target');
    await expect(card('Older target')).toBeVisible();
    await expect(board.locator('.lead-card')).toHaveCount(1);
    expect(new URL(page.url()).searchParams.has('cursor')).toBe(false);
    await expect(board.locator('.pipeline-total')).toHaveText(['USD 123.45']);
    await page.goBack();
    await expect(page).toHaveURL(pagedUrl);
    await expect(page.getByLabel('Search brands', { exact: true })).toHaveValue('');
    await page.goForward();
    await expect(page.getByLabel('Search brands', { exact: true })).toHaveValue('Older target');
    await page.getByRole('checkbox', { name: 'Due next actions', exact: true }).check();
    await expect.poll(() => new URL(page.url()).searchParams.get('due')).toBe('1');
    await page.getByRole('combobox', { name: 'Stage filter', exact: true }).click();
    await page.getByRole('option', { name: 'Pitched', exact: true }).click();
    await expect.poll(() => new URL(page.url()).searchParams.get('stage')).toBe('pitched');
    await expect(card('Older target')).toBeVisible();
    await card('Older target').click();
    await page.getByLabel('Private notes', { exact: true }).fill('Filtered draft saved');
    await page.getByRole('button', { name: 'Save lead', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Lead saved' })).toBeVisible();
    expect(new URL(page.url()).searchParams.get('q')).toBe('Older target');
    expect(new URL(page.url()).searchParams.get('stage')).toBe('pitched');
    expect(new URL(page.url()).searchParams.get('due')).toBe('1');
    await page.getByRole('button', { name: 'Close lead', exact: true }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await board.getByRole('button', { name: 'Clear filters', exact: true }).click();
    await expect(board.getByText('31 matching leads · all pages', { exact: true })).toBeVisible();
    await board.getByRole('button', { name: 'Lost', exact: true }).click();
    await expect(board.locator('.lead-card')).toHaveCount(1);
    await expect(card('Older lost')).toBeVisible();
    await expect(board.getByRole('region', { name: 'Active', exact: true })).toHaveCount(0);
    await expect(board.getByText('No non-lost value', { exact: true })).toBeVisible();
    await board.getByRole('button', { name: 'Repeat clients', exact: true }).click();
    await expect(card('Older repeat')).toBeVisible();
    await expect(board.locator('.lead-card')).toHaveCount(1);
    await expect(board.locator('.pipeline-total')).toHaveText(['PHP 200.00']);
    await board.getByRole('button', { name: 'All leads', exact: true }).click();
    await expect(board.getByText('31 matching leads · all pages', { exact: true })).toBeVisible();
    await page.getByLabel('Search brands', { exact: true }).fill('No matching record');
    await expect(board.getByRole('heading', { name: 'No leads match these filters', exact: true })).toBeVisible();
    await board.getByRole('button', { name: 'Clear filters', exact: true }).click();
    await expect(board.locator('.lead-card')).toHaveCount(25);
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth),
    ).toBe(true);
    await page.screenshot({ path: info.outputPath('pipeline-filtered-pages.png'), fullPage: true });
});
