import { confirmMutation } from './confirmations';
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { changeRole, fixture, login } from './helpers';

test('contacts are reusable and reminders snooze, complete and reopen', async ({ page }, info) => {
    test.setTimeout(90_000);
    const email = `m1-browser-crm-${Date.now()}@example.test`;
    fixture('seed-workspaces', email);
    await login(page, email);
    await page.getByRole('button', { name: /Role test workspace/ }).click();
    await page.getByRole('link', { name: 'Open partner portal' }).click();
    await page.getByRole('button', { name: '+ Add lead', exact: true }).click();
    await page.getByLabel('Brand name', { exact: true }).fill('CRM Studio');
    await page.getByRole('button', { name: 'Capture lead', exact: true }).click();
    await page.getByRole('link', { name: /Contacts & reminders/ }).click();
    await page.getByRole('button', { name: 'New contact', exact: true }).click();
    await page.getByLabel('Contact name', { exact: true }).fill('Sam Contact');
    await page.getByLabel('Contact email', { exact: true }).fill('sam@example.test');
    await page.getByRole('button', { name: 'Save contact', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Contact created' })).toBeVisible();
    await page.getByRole('button', { name: 'New contact', exact: true }).click();
    await page.getByLabel('Contact name', { exact: true }).fill('Unrelated contact draft');
    await page.getByRole('button', { name: 'Close editor', exact: true }).click();
    await page.getByRole('button', { name: 'Link Sam Contact to CRM Studio', exact: true }).click();
    await page.getByRole('button', { name: 'New contact', exact: true }).click();
    await expect(page.getByLabel('Contact name', { exact: true })).toHaveValue('Unrelated contact draft');
    await expect(page.getByRole('region', { name: 'Linked to CRM Studio' })).toContainText('Sam Contact');
    await page.getByRole('button', { name: 'Reminder', exact: true }).click();
    await page.getByLabel('Reminder title', { exact: true }).fill('Check in');
    await page.getByLabel('Due (your local time)').fill('2020-01-01T10:00');
    await page.getByRole('button', { name: 'Save reminder', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Complete: Check in', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Edit Check in', exact: true }).click();
    await page.getByLabel('Snooze until (your local time)').fill('2035-01-01T10:00');
    await page.getByRole('button', { name: 'Snooze reminder', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Reminder snoozed until' })).toBeVisible();
    await page.getByRole('button', { name: 'Contact', exact: true }).click();
    await expect(page.getByLabel('Contact name', { exact: true })).toHaveValue('Unrelated contact draft');
    await page.getByRole('button', { name: 'Close editor', exact: true }).click();
    await page.getByRole('button', { name: 'Due', exact: true }).click();
    await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
    await expect(page.getByText('No reminders match.', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'All', exact: true }).click();
    await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
    await page.getByRole('button', { name: 'Complete: Check in', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Reminder completed' })).toBeVisible();
    await page.getByRole('button', { name: 'Completed', exact: true }).click();
    await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
    await page.getByRole('button', { name: 'Mark pending: Check in', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('status').filter({ hasText: 'Reminder reopened' })).toBeVisible();
    const scan = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa', 'best-practice'])
        .analyze();
    await info.attach('axe-crm', {
        body: JSON.stringify({ violations: scan.violations, incomplete: scan.incomplete }),
        contentType: 'application/json',
    });
    expect(scan.violations.map((item) => item.id)).toEqual([]);
    expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: info.outputPath('crm.png'), fullPage: true });
    await page.getByRole('button', { name: 'All', exact: true }).click();
    await Promise.all([
        page.evaluate(
            () =>
                new Promise<void>((resolve) =>
                    document.addEventListener('inertia:finish', () => resolve(), { once: true }),
                ),
        ),
        page.getByRole('button', { name: 'Apply filters', exact: true }).click(),
    ]);
    await expect(page).toHaveURL(/state=all/);
    await page.getByRole('button', { name: 'Edit Check in', exact: true }).click();
    await expect(page.getByLabel('Reminder title', { exact: true })).toHaveValue('Check in');
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Delete reminder', exact: true }),
        'Delete reminder',
        info,
    );
    await expect(page.getByRole('status').filter({ hasText: 'Reminder deleted' })).toBeVisible();
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Unlink Sam Contact', exact: true }),
        'Unlink contact',
        info,
    );
    await expect(page.getByText('No linked contacts.', { exact: true })).toBeVisible();
    await page
        .getByRole('region', { name: 'Reusable contacts' })
        .getByRole('button', { name: 'Edit Sam Contact', exact: true })
        .click();
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Delete contact', exact: true }),
        'Delete contact',
        info,
    );
    await expect(page.getByRole('status').filter({ hasText: 'Contact deleted' })).toBeVisible();
    changeRole(email, 'viewer');
    await page.reload();
    await expect(page.getByText('Viewer access is read only.', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Save contact', exact: true })).toHaveCount(0);
});
