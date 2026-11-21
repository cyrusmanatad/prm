import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { dismissNotifications } from './feedback';
import { readFile } from 'node:fs/promises';
import {
    automationTab,
    baseWidth,
    fixture,
    isMobile,
    login,
    mutate,
    newSchedule,
    reviewWidths,
    smallTouchTargets,
    writeFollowUp,
} from './helpers';

test('manual follow-up approval, download, edit invalidation and confirmed discard', async ({ page }, info) => {
    await page.setViewportSize({ width: baseWidth(info), height: 1080 });
    const email = `m1-browser-drafts-${Date.now()}@example.test`;
    fixture('seed', email);
    await login(page, email);
    await page.getByLabel('Workspace name', { exact: true }).fill('Follow-up workspace');
    await page.getByRole('button', { name: 'Create workspace', exact: true }).click();
    await page.getByRole('link', { name: 'Automation preferences', exact: true }).click();
    await expect(page).toHaveURL(/\/automation$/);
    await expect(
        page.getByText('drafts for your review; approval does not send email.', { exact: false }),
    ).toBeVisible();
    expect(
        await mutate(page, '/pipeline', 'POST', {
            brand_name: 'Synthetic draft lead',
            currency: 'PHP',
            value_minor: '0',
        }),
    ).toBe(201);
    await page.reload();
    await expect(page.getByText('No follow-up drafts yet', { exact: true })).toBeVisible();
    const write = page.getByRole('button', { name: 'Write follow-up', exact: true });
    const drawer = await writeFollowUp(
        page,
        'Synthetic draft lead',
        'recipient@example.test',
        'Collaboration next steps',
        'Hello, would you like to discuss the collaboration?',
    );
    await expect(page).toHaveURL(/\/automation\?draft=[0-9a-f-]{36}$/);
    await expect(drawer.getByText('Needs review · Version 1', { exact: true })).toBeVisible();
    await expect(drawer.getByRole('link', { name: 'Download approved message' })).toHaveCount(0);
    const approve = drawer.getByRole('button', { name: 'Approve Collaboration next steps', exact: true });
    await approve.click();
    const approval = page.getByRole('dialog', { name: 'Approve follow-up?', exact: true });
    await expect(approval.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(approve).toBeFocused();
    await approve.click();
    await approval.getByRole('button', { name: 'Approve follow-up', exact: true }).click();
    await expect(drawer.getByText('Approved for manual sending · Version 2', { exact: true })).toBeVisible();
    const downloadEvent = page.waitForEvent('download');
    await drawer.getByRole('link', { name: 'Download approved message', exact: true }).click();
    const download = await downloadEvent;
    expect(await readFile((await download.path())!, 'utf8')).toContain(
        'To: recipient@example.test\nSubject: Collaboration next steps',
    );
    await dismissNotifications(page);
    for (const width of reviewWidths(info)) {
        await page.setViewportSize({ width, height: 1080 });
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
        if (isMobile(info)) expect(await smallTouchTargets(page)).toEqual([]);
    }
    // Close returns to the list, which shows the approved draft and its Send action.
    await drawer.getByRole('button', { name: 'Close follow-up draft', exact: true }).click();
    await expect(drawer).not.toBeVisible();
    await expect(page).toHaveURL(/\/automation$/);
    await page.getByRole('button', { name: /^All/ }).click();
    const row = page.getByRole('row', { name: /Collaboration next steps/ });
    await expect(row).toContainText('Approved');
    await expect(row.getByRole('button', { name: 'Send Collaboration next steps', exact: true })).toBeVisible();
    await dismissNotifications(page);
    for (const width of reviewWidths(info)) {
        await page.setViewportSize({ width, height: 1080 });
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        if (isMobile(info)) expect(await smallTouchTargets(page)).toEqual([]);
    }
    await row.getByRole('button', { name: 'Collaboration next steps', exact: true }).click();
    await expect(drawer).toBeVisible();
    await drawer.getByRole('button', { name: 'Edit Collaboration next steps', exact: true }).click();
    const save = drawer.getByRole('button', { name: 'Save draft', exact: true });
    await expect(save).toBeDisabled();
    await drawer.getByLabel('Message', { exact: true }).fill('An edited message needs fresh approval.');
    await expect(drawer.getByText('Unsaved changes', { exact: true })).toBeVisible();
    await save.click();
    await expect(drawer.getByText('Needs review · Version 3', { exact: true })).toBeVisible();
    await expect(drawer.getByText('An edited message needs fresh approval.', { exact: true })).toBeVisible();
    await expect(drawer.getByRole('link', { name: 'Download approved message' })).toHaveCount(0);
    const discard = drawer.getByRole('button', { name: 'Discard Collaboration next steps', exact: true });
    await discard.click();
    const dialog = page.getByRole('dialog', { name: 'Discard follow-up?', exact: true });
    await expect(dialog.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await page.keyboard.press('Escape');
    await expect(discard).toBeFocused();
    await discard.click();
    await dialog.getByRole('button', { name: 'Discard follow-up', exact: true }).click();
    await expect(drawer.getByText('Discarded · Version 4', { exact: true })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(drawer).not.toBeVisible();
    await expect(write).toBeVisible();
});

test('scheduled preparation produces a private draft requiring recipient review', async ({ page }, info) => {
    test.setTimeout(120_000);
    const { execFileSync } = await import('node:child_process');
    const { fileURLToPath } = await import('node:url');
    const repo = fileURLToPath(new URL('../../../', import.meta.url));
    const compose = (args: string[]) =>
        execFileSync(
            'docker',
            ['compose', '--env-file', 'src/.env', '-p', process.env.COMPOSE_PROJECT_NAME ?? 'weavdash', ...args],
            { cwd: repo, stdio: 'pipe' },
        );
    const email = `m1-browser-scheduled-drafts-${Date.now()}@example.test`;
    fixture('seed', email);
    await login(page, email);
    await page.getByLabel('Workspace name', { exact: true }).fill('Scheduled draft workspace');
    await page.getByRole('button', { name: 'Create workspace', exact: true }).click();
    await page.getByRole('link', { name: 'Automation preferences', exact: true }).click();
    await expect(page).toHaveURL(/\/automation$/);
    await expect(
        page.getByText('drafts for your review; approval does not send email.', { exact: false }),
    ).toBeVisible();
    expect(
        await mutate(page, '/pipeline', 'POST', {
            brand_name: 'Scheduled synthetic lead',
            currency: 'PHP',
            value_minor: '0',
        }),
    ).toBe(201);
    await page.goto('/automation?tab=preferences');
    await page.getByLabel('Follow-up drafts', { exact: true }).check();
    await page.getByRole('button', { name: 'Save preferences', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Automation preferences saved' })).toBeVisible();
    const schedule = await newSchedule(page, 'Follow-up draft', 'Prepare collaboration follow-up');
    await schedule.getByRole('combobox', { name: 'Follow-up lead', exact: true }).click();
    await page.getByRole('option', { name: 'Scheduled synthetic lead', exact: true }).click();
    const future = await page.evaluate(() => {
        const date = new Date(Date.now() + 3600000);
        return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    });
    await schedule.getByLabel('Prepare draft at', { exact: true }).fill(future);
    await schedule
        .getByLabel('I consent to private follow-up draft preparation in this workspace.', { exact: true })
        .check();
    await schedule.getByRole('button', { name: 'Save schedule', exact: true }).click();
    await expect(schedule).not.toBeVisible();
    await automationTab(page, 'Schedules');
    await expect(page.getByRole('row', { name: /Prepare collaboration follow-up/ })).toContainText(
        'Scheduled synthetic lead · prepares a private draft',
    );
    compose(['run', '--rm', '-T', '--no-deps', 'migrate', 'php', 'tests/Browser/scheduled-notice-fixture.php', email]);
    compose(['exec', '-T', 'app', 'php', 'artisan', 'automation:dispatch']);
    compose(['exec', '-T', 'app', 'php', 'artisan', 'queue:work', 'redis', '--stop-when-empty']);
    compose(['exec', '-T', 'app', 'php', 'artisan', 'automation:drafts']);
    compose(['exec', '-T', 'app', 'php', 'artisan', 'queue:work', 'redis', '--stop-when-empty']);
    await page.goto('/automation');
    const row = page.getByRole('row', { name: /Following up/ });
    await expect(row).toContainText('No recipient yet');
    await row.getByRole('button', { name: 'Following up', exact: true }).click();
    const drawer = page.getByRole('dialog', { name: 'Following up', exact: true });
    await expect(drawer.getByText('Enter a recipient before approval', { exact: true })).toBeVisible();
    await expect(drawer.getByText('By a schedule', { exact: false })).toBeVisible();
    await drawer.getByRole('button', { name: 'Close follow-up draft', exact: true }).click();
    await row.getByRole('button', { name: 'Approve Following up', exact: true }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Approve follow-up', exact: true }).click();
    await expect(page.getByRole('dialog').getByText('The recipient field is required.', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Download approved message' })).toHaveCount(0);
});
