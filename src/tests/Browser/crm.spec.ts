import { confirmMutation } from './confirmations';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { changeRole, fixture, login } from './helpers';

/** Narrow screens show one list at a time; wide screens show both and hide the switch. */
async function showList(page: Page, name: 'Contacts' | 'Reminders') {
    const toggle = page.getByRole('group', { name: 'Show list' }).getByRole('button', { name: new RegExp(`^${name}`) });
    if (await toggle.isVisible()) await toggle.click();
    await expect(page.getByRole('region', { name, exact: true })).toBeVisible();
}

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
    await expect(page.getByRole('group', { name: 'What to add' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Close editor', exact: true }).click();
    await page.getByRole('button', { name: 'New contact', exact: true }).click();
    await expect(page.getByLabel('Contact name', { exact: true })).toHaveValue('Unrelated contact draft');
    await page.getByRole('button', { name: 'Close editor', exact: true }).click();
    await page.getByRole('button', { name: 'Due', exact: true }).click();
    await expect(page).toHaveURL(/state=due/);
    await expect(page.getByRole('button', { name: 'Due', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByText('Nothing is due. You are all caught up.', { exact: true })).toBeVisible();
    await page.goBack();
    await showList(page, 'Reminders');
    await expect(page.getByRole('button', { name: 'Pending', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('button', { name: 'Complete: Check in', exact: true })).toBeVisible();
    await page.goForward();
    await expect(page.getByRole('button', { name: 'Due', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await showList(page, 'Reminders');
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
    await expect(page.getByText('No completed reminders yet.', { exact: true })).toBeVisible();
    const scan = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa', 'best-practice'])
        .analyze();
    await info.attach('axe-crm', {
        body: JSON.stringify({ violations: scan.violations, incomplete: scan.incomplete }),
        contentType: 'application/json',
    });
    expect(scan.violations.map((item) => item.id)).toEqual([]);
    expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBe(true);
    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
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
    await showList(page, 'Contacts');
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Unlink Sam Contact', exact: true }),
        'Unlink contact',
        info,
    );
    await expect(page.getByText('No linked contacts yet.', { exact: true })).toBeVisible();
    await page
        .getByRole('region', { name: 'Contacts', exact: true })
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
    const contacts = page.getByRole('region', { name: 'Contacts', exact: true });
    const reminders = page.getByRole('region', { name: 'Reminders', exact: true });
    await expect(contacts.getByText('Showing 1–25 of 27 matching contacts, all pages', { exact: true })).toBeVisible();
    await showList(page, 'Reminders');
    await reminders.getByRole('navigation', { name: 'Reminders pages' }).getByRole('link', { name: 'Page 2' }).click();
    await expect.poll(() => new URL(page.url()).searchParams.get('reminders_page')).toBe('2');
    await expect(reminders.getByRole('listitem')).toHaveCount(1);
    await reminders.getByRole('button', { name: 'Completed', exact: true }).click();
    await expect(page).toHaveURL(/state=completed/);
    expect(new URL(page.url()).searchParams.has('reminders_page')).toBe(false);
    await expect(reminders.getByText('Older completed', { exact: true })).toBeVisible();
    await reminders.getByRole('button', { name: 'Pending', exact: true }).click();
    await expect(reminders.getByRole('listitem')).toHaveCount(25);
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
    await showList(page, 'Contacts');
    await contacts.getByRole('navigation', { name: 'Contacts pages' }).getByRole('link', { name: 'Page 2' }).click();
    await expect(contacts.getByText('Showing 26–27 of 27 matching contacts, all pages', { exact: true })).toBeVisible();
    await page.getByLabel('Search contacts', { exact: true }).fill('Older target');
    await expect(contacts.getByRole('row')).toHaveCount(2);
    const filteredUrl = page.url();
    expect(new URL(filteredUrl).searchParams.get('q')).toBe('Older target');
    expect(new URL(filteredUrl).searchParams.has('contacts_page')).toBe(false);
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
    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
        await page.screenshot({ path: info.outputPath('crm-stale-editor.png'), fullPage: true });
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: 'Reload latest records', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Save contact', exact: true })).not.toBeVisible();
    await expect(page).toHaveURL(filteredUrl);
    await expect(contacts.getByText('Older target updated', { exact: true })).toBeVisible();
    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
        await page.screenshot({ path: info.outputPath('crm-paged.png'), fullPage: true });
});

test('contacts support bulk actions, row menus, quick snooze and CSV export', async ({ page }, info) => {
    test.setTimeout(120_000);
    const email = `m1-browser-crm-bulk-${info.project.name}-${Date.now()}@example.test`;
    const workspace = fixture('seed-workspaces', email);
    await login(page, email);
    await page.evaluate(async (org) => {
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
        for (const name of ['Keep me', 'Remove one', 'Remove two'])
            await post('/contacts', { name, email: `${name.replace(' ', '.').toLowerCase()}@example.test` });
        await post('/reminders', { title: 'Overdue call', due_at: '2020-01-01T00:00:00Z' });
    }, workspace.own);
    await page.goto('/partner/contacts?sort=name_asc');
    const contacts = page.getByRole('region', { name: 'Contacts', exact: true });
    await expect(page.getByRole('list', { name: 'Workspace summary' })).toContainText('Overdue reminders1');

    await contacts.getByRole('button', { name: 'More actions for Keep me', exact: true }).click();
    await expect(page.getByRole('menuitem', { name: 'Send email' })).toHaveAttribute(
        'href',
        'mailto:keep.me@example.test',
    );
    await page.keyboard.press('Escape');
    await expect(contacts.getByRole('button', { name: 'More actions for Keep me', exact: true })).toBeFocused();

    await contacts.getByRole('checkbox', { name: 'Select Remove one', exact: true }).check();
    await contacts.getByRole('checkbox', { name: 'Select Remove two', exact: true }).check();
    const bulk = contacts.getByRole('region', { name: 'Selected contacts' });
    await expect(bulk).toContainText('2 contacts selected');
    const download = page.waitForEvent('download');
    await bulk.getByRole('link', { name: 'Export' }).click();
    const file = await (await download).path();
    const csv = (await import('node:fs')).readFileSync(file, 'utf8');
    expect(csv).toContain('Remove one');
    expect(csv).not.toContain('Keep me');
    await confirmMutation(page, bulk.getByRole('button', { name: 'Delete…' }), 'Delete contacts', info);
    await expect(page.getByRole('status').filter({ hasText: '2 contacts deleted' })).toBeVisible();
    await expect(contacts.getByRole('row')).toHaveCount(2);

    await showList(page, 'Reminders');
    const reminders = page.getByRole('region', { name: 'Reminders', exact: true });
    await reminders.getByRole('button', { name: 'Snooze Overdue call', exact: true }).click();
    await page.getByRole('menuitem', { name: /^Tomorrow/ }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Reminder snoozed until' })).toBeVisible();
    await expect(reminders.getByRole('heading', { name: 'Upcoming', level: 3 })).toBeVisible();
    await expect(page.getByRole('list', { name: 'Workspace summary' })).toContainText('Overdue reminders0');

    await page.getByRole('button', { name: 'Export CSV', exact: true }).click();
    const reminderDownload = page.waitForEvent('download');
    await page.getByRole('menuitem', { name: /^Reminders/ }).click();
    expect((await reminderDownload).suggestedFilename()).toBe('reminders.csv');
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map((item) => item.id)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
