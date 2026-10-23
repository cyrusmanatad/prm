import { confirmMutation } from './confirmations';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import AxeBuilder from '@axe-core/playwright';
import { test, expect } from '@playwright/test';
import { fixture, login } from './helpers';

test('private draft exact money, edit and terminal void', async ({ page }, info) => {
    const email = `m1-browser-finance-${Date.now()}@example.test`;
    fixture('seed-workspaces', email);
    await login(page, email);
    await page.getByRole('button', { name: /Role test workspace/ }).click();
    await expect(page.getByRole('link', { name: 'Open partner portal' })).toBeVisible();
    await page.goto('/finance/invoices');
    await page.getByLabel('Recipient business name').fill('Synthetic finance');
    await page.getByLabel('Currency code').fill('USD');
    await page.getByLabel('Description', { exact: true }).fill('Campaign');
    await page.getByLabel('Unit price in minor units').fill('9007199254740993');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page).toHaveURL(/\/finance\/invoices\/[a-f0-9-]+$/);
    await expect(page.getByText(/Version 1/)).toContainText('9007199254740993');
    await expect(page.getByLabel('Unit price in minor units')).toHaveValue('9007199254740993');
    const stale = await page.context().newPage();
    await stale.goto(page.url());
    await stale.getByLabel('Recipient business name').fill('Unsaved stale finance');
    await page.getByLabel('Recipient business name').fill('Updated finance');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByText(/Version 2/)).toBeVisible();
    await confirmMutation(stale, stale.getByRole('button', { name: 'Void draft', exact: true }), 'Void draft');
    const staleDialog = stale.getByRole('dialog', { name: 'Void draft?', exact: true });
    await expect(staleDialog.getByRole('alert')).toBeFocused();
    await expect(staleDialog.getByRole('alert')).toContainText('Reload before continuing');
    await expect(staleDialog.getByRole('button', { name: 'Void draft', exact: true })).toBeDisabled();
    await stale.screenshot({ path: info.outputPath('confirmation-stale-record.png'), fullPage: true });
    await staleDialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(stale.getByRole('alert')).toContainText('Reload before continuing');
    await expect(stale.getByLabel('Recipient business name')).toHaveValue('Unsaved stale finance');
    await expect(stale.getByRole('button', { name: 'Save draft', exact: true })).toBeDisabled();
    await stale.getByRole('link', { name: 'Reload draft' }).click();
    await expect(stale.getByLabel('Recipient business name')).toHaveValue('Updated finance');
    await stale.close();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await page.screenshot({ path: info.outputPath('draft.png'), fullPage: true });
    await confirmMutation(page, page.getByRole('button', { name: 'Void draft', exact: true }), 'Void draft', info);
    await expect(page.getByText(/Version 3/)).toContainText('voided');
    await expect(page.getByRole('button', { name: 'Save draft', exact: true })).toBeDisabled();
});

test('manual receipt allocation, reversal, void and entitlement visibility', async ({ page }, info) => {
    test.setTimeout(120_000);
    const email = `m1-browser-finance-payments-${Date.now()}@example.test`;
    fixture('seed-workspaces', email);
    await login(page, email);
    await page.getByRole('button', { name: /Role test workspace/ }).click();
    await expect(page.getByRole('link', { name: 'Private finance records' })).toBeVisible();
    await page.getByRole('link', { name: 'Private finance records' }).click();
    await page.getByLabel('Recipient business name').fill('Payment recipient');
    await page.getByLabel('Currency code').fill('USD');
    await page.getByLabel('Description', { exact: true }).fill('Work');
    await page.getByLabel('Unit price in minor units').fill('1000');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page).toHaveURL(/\/finance\/invoices\/[a-f0-9-]+$/);
    const invoice = page.url().split('/').pop()!;
    await page.getByRole('link', { name: 'Manual payments' }).click();
    await expect(page.getByRole('heading', { name: 'Manual payment records' })).toBeVisible();
    await page.getByLabel('Currency code').fill('USD');
    await page.getByLabel('Amount in minor units', { exact: true }).fill('800');
    await page.getByLabel('Received on').fill('2026-10-23');
    await page.getByLabel('Unique evidence reference').fill('Browser receipt');
    await page.getByRole('button', { name: 'Record manual receipt' }).click();
    await expect(page.getByRole('heading', { name: 'Browser receipt' })).toBeVisible();
    await page.getByRole('combobox', { name: 'Payment', exact: true }).selectOption({ label: 'Browser receipt · USD' });
    await page.getByRole('combobox', { name: 'Draft', exact: true }).selectOption(invoice);
    await page.getByLabel('Allocation in minor units').fill('500');
    await page.getByRole('button', { name: 'Allocate receipt' }).click();
    await expect(page.getByText(/Allocated 500/)).toBeVisible();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await page.screenshot({ path: info.outputPath('payments.png'), fullPage: true });
    await page.getByLabel('Reason for reversing an allocation').fill('Correct allocation');
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Reverse allocation', exact: true }),
        'Reverse allocation',
        info,
    );
    await expect(page.getByText(' · Reversed: Correct allocation')).toBeVisible();
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Void receipt Browser receipt', exact: true }),
        'Void receipt',
        info,
    );
    await expect(page.getByText(/voided.*Allocated 0/)).toBeVisible();
    await page.getByRole('link', { name: 'Private invoice drafts' }).click();
    await page.getByRole('link', { name: 'Pilot entitlements' }).click();
    await expect(page.getByText('State: unassigned-development')).toBeVisible();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    execFileSync(
        'docker',
        [
            'compose',
            '--env-file',
            'src/.env',
            '-p',
            process.env.COMPOSE_PROJECT_NAME ?? 'weavdash-m1',
            'run',
            '--rm',
            '-T',
            '--no-deps',
            'migrate',
            'php',
            'tests/Browser/pilot-fixture.php',
            email,
        ],
        { cwd: fileURLToPath(new URL('../../../', import.meta.url)), stdio: ['ignore', 'pipe', 'pipe'] },
    );
    await page.reload();
    await expect(page.getByText('State: active', { exact: true })).toBeVisible();
    await expect(page.getByRole('row', { name: 'invoice_drafts 1 0', exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'Finance', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Private invoice drafts' })).toBeVisible();
    await page.getByLabel('Recipient business name').fill('Blocked by pilot quota');
    await page.getByLabel('Currency code').fill('USD');
    await page.getByLabel('Description', { exact: true }).fill('Blocked');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText(
        'Pilot entitlement is inactive or its resource limit has been reached',
    );
    await expect(page.getByLabel('Recipient business name')).toHaveValue('Blocked by pilot quota');
    await page.goto(`/finance/invoices/${invoice}`);
    await expect(page.getByText(/Version 1/)).toBeVisible();
});
