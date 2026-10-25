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
    await expect(page).toHaveURL(/state=due/);
    await expect(page.getByRole('button', { name: 'Due', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByText('No reminders match.', { exact: true })).toBeVisible();
    await page.goBack();
    await expect(page.getByRole('button', { name: 'Pending', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('button', { name: 'Complete: Check in', exact: true })).toBeVisible();
    await page.goForward();
    await expect(page.getByRole('button', { name: 'Due', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('button', { name: 'All', exact: true }).click();
    await page.getByRole('button', { name: 'Complete: Check in', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Reminder completed' })).toBeVisible();
    await expect(page).toHaveURL(/state=all/);
    await page.getByRole('button', { name: 'Completed', exact: true }).click();
    await expect(page).toHaveURL(/state=completed/);
    await page.getByRole('button', { name: 'Mark pending: Check in', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('status').filter({ hasText: 'Reminder reopened' })).toBeVisible();
    await expect(page).toHaveURL(/state=completed/);
    await expect(page.getByText('No reminders match.', { exact: true })).toBeVisible();
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

test('CRM filters reset paged lists, restore history and retain actionable stale errors', async ({ page }, info) => {
    test.setTimeout(120_000);
    const email = `m1-browser-crm-filter-${info.project.name}-${Date.now()}@example.test`;
    const workspace = fixture('seed-workspaces', email);
    await login(page, email);
    const records = await page.evaluate(async (org) => {
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
        await post('/workspace/select', { org_id: org });
        const older = await post('/contacts', { name: 'Older target', notes: 'Workspace note' });
        const completed = await post('/reminders', { title: 'Older completed', due_at: '2020-01-01T00:00:00Z' });
        await post(`/reminders/${completed.id}/complete`, { version: 1 });
        for (let i = 0; i < 26; i++) {
            await post('/contacts', { name: `Current contact ${i}` });
            await post('/reminders', { title: `Current reminder ${i}`, due_at: '2099-01-01T00:00:00Z' });
        }
        return { older: older.id };
    }, workspace.own);
    await page.goto('/partner/contacts');
    const contacts = page.getByRole('region', { name: 'Reusable contacts', exact: true });
    const reminders = page.getByRole('region', { name: 'Reminders', exact: true });
    await expect(contacts.getByText('25 on this page', { exact: true })).toBeVisible();
    await reminders.getByRole('button', { name: 'More reminders', exact: true }).click();
    await expect.poll(() => new URL(page.url()).searchParams.has('reminders_cursor')).toBe(true);
    await expect(reminders.locator('li')).toHaveCount(1);
    await reminders.getByRole('button', { name: 'Completed', exact: true }).click();
    await expect(page).toHaveURL(/state=completed/);
    expect(new URL(page.url()).searchParams.has('reminders_cursor')).toBe(false);
    await expect(reminders.getByText('Older completed', { exact: true })).toBeVisible();
    await reminders.getByRole('button', { name: 'Pending', exact: true }).click();
    await expect(reminders.locator('li')).toHaveCount(25);
    await page.goBack();
    await expect(reminders.getByRole('button', { name: 'Completed', exact: true })).toHaveAttribute(
        'aria-pressed',
        'true',
    );
    await page.goForward();
    await expect(reminders.getByRole('button', { name: 'Pending', exact: true })).toHaveAttribute(
        'aria-pressed',
        'true',
    );
    await contacts.getByRole('button', { name: 'More contacts', exact: true }).click();
    await expect(contacts.getByText('2 on this page', { exact: true })).toBeVisible();
    await page.getByLabel('Search contacts', { exact: true }).fill('Older target');
    await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
    await expect(contacts.locator('li')).toHaveCount(1);
    const filteredUrl = page.url();
    expect(new URL(filteredUrl).searchParams.has('contacts_cursor')).toBe(false);
    await page.getByRole('button', { name: 'Edit Older target', exact: true }).click();
    await expect(page.getByText('Visible to authorized members of this workspace.', { exact: true })).toBeVisible();
    await page.getByLabel('Contact name', { exact: true }).fill('Unsaved rename');
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'Edit Older target', exact: true })).toBeFocused();
    await page.getByRole('button', { name: 'Edit Older target', exact: true }).click();
    await expect(page.getByLabel('Contact name', { exact: true })).toHaveValue('Older target');
    // A second editor changes the displayed version before this browser saves.
    const status = await page.evaluate(async (id) => {
        const csrf = decodeURIComponent(
            document.cookie
                .split('; ')
                .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
                ?.slice(11) ?? '',
        );
        return (
            await fetch(`/contacts/${id}`, {
                method: 'PATCH',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf },
                body: JSON.stringify({ name: 'Older target updated', version: 1 }),
            })
        ).status;
    }, records.older);
    expect(status).toBe(200);
    await page.getByLabel('Private notes', { exact: true }).fill('Keep my draft');
    await page.getByRole('button', { name: 'Save contact', exact: true }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'This record changed.' }).first()).toBeVisible();
    await expect(page.getByLabel('Private notes', { exact: true })).toHaveValue('Keep my draft');
    await expect(page.getByRole('button', { name: 'Save contact', exact: true })).toBeDisabled();
    await page.screenshot({ path: info.outputPath('crm-stale-editor.png'), fullPage: true });
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: 'Reload latest records', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Save contact', exact: true })).not.toBeVisible();
    await expect(page).toHaveURL(filteredUrl);
    await expect(contacts.getByText('Older target updated', { exact: true })).toBeVisible();
    await page.screenshot({ path: info.outputPath('crm-paged.png'), fullPage: true });
});
