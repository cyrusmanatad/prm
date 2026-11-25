import { confirmMutation } from './confirmations';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Locator, type Page } from '@playwright/test';
import { fixture, login } from './helpers';
import { settleNotificationAnimations } from './feedback';

/** Opens New draft and fills one line; prices are typed in the currency's units. */
async function newDraft(page: Page, recipient: string, description: string, unit: string): Promise<Locator> {
    await page.getByRole('button', { name: 'New draft', exact: true }).click();
    const editor = page.getByRole('dialog', { name: 'New draft', exact: true });
    await editor.getByLabel('Recipient business name').fill(recipient);
    await editor.getByLabel('Currency code').fill('USD');
    await editor.getByLabel('Line 1 description').fill(description);
    await editor.getByLabel('Line 1 unit price').fill(unit);
    return editor;
}

test('private draft exact money, edit and terminal void', async ({ page }, info) => {
    const email = `m1-browser-finance-${Date.now()}@example.test`;
    fixture('seed-workspaces', email);
    await login(page, email);
    await page.getByRole('button', { name: /Role test workspace/ }).click();
    await expect(page.getByRole('link', { name: 'Open partner portal' })).toBeVisible();
    await page.goto('/finance/invoices');
    await expect(page.getByText('No drafts yet', { exact: true })).toBeVisible();
    const editor = await newDraft(page, 'Synthetic finance', 'Campaign', '90071992547409.93');
    await expect(editor.getByText('Exact total: 9007199254740993 minor units', { exact: false })).toBeVisible();
    await editor.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page).toHaveURL(/\/finance\/invoices\/[a-f0-9-]+$/);
    const drawer = page.getByRole('dialog', { name: 'Synthetic finance', exact: true });
    await expect(drawer.getByText('Draft · Version 1', { exact: true })).toBeVisible();
    await expect(drawer.getByRole('cell', { name: 'USD 90,071,992,547,409.93' })).toHaveAttribute(
        'title',
        '9007199254740993 minor units',
    );
    const stale = await page.context().newPage();
    await stale.goto(page.url());
    const staleDrawer = stale.getByRole('dialog', { name: 'Synthetic finance', exact: true });
    await expect(staleDrawer).toBeVisible();
    await drawer.getByRole('button', { name: 'Edit draft', exact: true }).click();
    const edit = page.getByRole('dialog', { name: 'Edit draft', exact: true });
    await expect(edit.getByLabel('Line 1 unit price')).toHaveValue('90071992547409.93');
    await expect(edit.getByRole('button', { name: 'Save draft', exact: true })).toBeDisabled();
    await edit.getByLabel('Recipient business name').fill('Updated finance');
    await expect(edit.getByText('Unsaved changes', { exact: true })).toBeVisible();
    await edit.getByRole('button', { name: 'Save draft', exact: true }).click();
    const updated = page.getByRole('dialog', { name: 'Updated finance', exact: true });
    await expect(updated.getByText('Draft · Version 2', { exact: true })).toBeVisible();
    await confirmMutation(stale, staleDrawer.getByRole('button', { name: 'Void draft', exact: true }), 'Void draft');
    const staleDialog = stale.getByRole('dialog', { name: 'Void draft?', exact: true });
    await expect(staleDialog.getByRole('alert')).toBeFocused();
    await expect(staleDialog.getByRole('alert')).toContainText('Reload before continuing');
    await expect(staleDialog.getByRole('button', { name: 'Void draft', exact: true })).toBeDisabled();
    await staleDialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    // The refused void brings back the latest version, with the explanation and actions held until a reload.
    const refreshed = stale.getByRole('dialog', { name: 'Updated finance', exact: true });
    await expect(refreshed.getByRole('alert')).toContainText('Reload before continuing');
    await expect(refreshed.getByRole('button', { name: 'Edit draft', exact: true })).toBeDisabled();
    await refreshed.getByRole('link', { name: 'Reload draft' }).click();
    await expect(refreshed.getByText('Draft · Version 2', { exact: true })).toBeVisible();
    await expect(refreshed.getByRole('alert')).toHaveCount(0);
    await stale.close();
    await settleNotificationAnimations(page);
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await confirmMutation(page, updated.getByRole('button', { name: 'Void draft', exact: true }), 'Void draft', info);
    await expect(updated.getByText('Voided · Version 3', { exact: true })).toBeVisible();
    await expect(updated.getByRole('button', { name: 'Edit draft', exact: true })).toHaveCount(0);
    await updated.getByRole('button', { name: 'Close invoice draft', exact: true }).click();
    await expect(page).toHaveURL(/\/finance\/invoices$/);
    await page.getByRole('link', { name: /^Voided/ }).click();
    await expect(page.getByRole('row', { name: /Updated finance/ })).toContainText('Voided');
});

test('manual receipt allocation, reversal, void and entitlement visibility', async ({ page }, info) => {
    test.setTimeout(120_000);
    const email = `m1-browser-finance-payments-${Date.now()}@example.test`;
    fixture('seed-workspaces', email);
    await login(page, email);
    await page.getByRole('button', { name: /Role test workspace/ }).click();
    await expect(page.getByRole('link', { name: 'Private finance records' })).toBeVisible();
    await page.getByRole('link', { name: 'Private finance records' }).click();
    const editor = await newDraft(page, 'Payment recipient', 'Work', '10.00');
    await editor.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page).toHaveURL(/\/finance\/invoices\/[a-f0-9-]+$/);
    const invoice = page.url().split('/').pop()!;
    await page.keyboard.press('Escape');
    await page.getByRole('link', { name: 'Manual payments' }).click();
    await expect(page.getByRole('heading', { name: 'Manual payment records' })).toBeVisible();
    await page.getByRole('button', { name: 'Record receipt', exact: true }).click();
    const record = page.getByRole('dialog', { name: 'Record receipt', exact: true });
    await record.getByLabel('Currency code').fill('USD');
    await record.getByLabel('Amount', { exact: true }).fill('8.00');
    await expect(record.getByText('Exact: 800 minor units', { exact: true })).toBeVisible();
    await record.getByLabel('Received on').fill('2026-10-23');
    await record.getByLabel('Evidence reference').fill('Browser receipt');
    await record.getByRole('button', { name: 'Record receipt', exact: true }).click();
    await expect(record).not.toBeVisible();
    const row = page.getByRole('row', { name: /Browser receipt/ });
    await expect(row).toContainText('USD 8.00 unallocated');
    await row.getByRole('button', { name: 'Allocate Browser receipt', exact: true }).click();
    const allocate = page.getByRole('dialog', { name: 'Allocate receipt', exact: true });
    await allocate.getByRole('combobox', { name: 'Draft', exact: true }).click();
    await page.getByRole('option', { name: 'Payment recipient · balance USD 10.00', exact: true }).click();
    await expect(allocate.getByLabel('Amount (USD)')).toHaveValue('8.00');
    await allocate.getByLabel('Amount (USD)').fill('5.00');
    await allocate.getByRole('button', { name: 'Allocate', exact: true }).click();
    await expect(row).toContainText('Allocated USD 5.00 · USD 3.00 left');
    await expect(row.getByRole('link', { name: 'Payment recipient' })).toHaveAttribute(
        'href',
        `/finance/invoices/${invoice}`,
    );
    await settleNotificationAnimations(page);
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    const menu = row.getByRole('button', { name: 'Actions for Browser receipt', exact: true });
    await menu.click();
    await page.getByRole('menuitem', { name: /Reverse allocation to Payment recipient/ }).click();
    const reverse = page.getByRole('dialog', { name: 'Reverse allocation', exact: true });
    await reverse.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(reverse.getByText('Enter why this allocation is being reversed.')).toBeVisible();
    await reverse.getByLabel('Reason for reversing an allocation').fill('Correct allocation');
    await reverse.getByRole('button', { name: 'Continue', exact: true }).click();
    const confirm = page.getByRole('dialog', { name: 'Reverse allocation?', exact: true });
    await expect(confirm).toContainText('Reason: Correct allocation');
    await expect(confirm.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    await confirm.getByRole('button', { name: 'Reverse allocation', exact: true }).click();
    await expect(row).toContainText('reversed: Correct allocation');
    await expect(menu).toBeFocused();
    await menu.click();
    await page.getByRole('menuitem', { name: 'Void receipt', exact: true }).click();
    const voidDialog = page.getByRole('dialog', { name: 'Void receipt?', exact: true });
    await expect(voidDialog.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    await voidDialog.getByRole('button', { name: 'Void receipt', exact: true }).click();
    await expect(row).toContainText('Voided');
    await page
        .getByRole('navigation', { name: 'Finance', exact: true })
        .getByRole('link', { name: 'Private invoice drafts' })
        .click();
    await page
        .getByRole('navigation', { name: 'Finance', exact: true })
        .getByRole('link', { name: 'Pilot entitlements' })
        .click();
    await expect(page.getByText('State: unassigned-development')).toBeVisible();
    await settleNotificationAnimations(page);
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
    const blocked = await newDraft(page, 'Blocked by pilot quota', 'Blocked', '1.00');
    await blocked.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(blocked.getByRole('alert')).toContainText(
        'Workspace access is inactive or its record quota has been reached',
    );
    await expect(blocked.getByLabel('Recipient business name')).toHaveValue('Blocked by pilot quota');
    await page.goto(`/finance/invoices/${invoice}`);
    await expect(page.getByRole('dialog', { name: 'Payment recipient' }).getByText(/Version 1/)).toBeVisible();
});
