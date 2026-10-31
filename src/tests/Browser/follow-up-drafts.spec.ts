import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFile } from 'node:fs/promises';
import { fixture, login, mutate } from './helpers';

test('manual follow-up approval, download, edit invalidation and confirmed discard', async ({ page }, info) => {
    test.skip(info.project.name.includes('mobile'), 'Small-screen acceptance belongs to Claude under D28.');
    await page.setViewportSize({ width: 1440, height: 1080 });
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
    await expect(page.getByText('No follow-up drafts yet.', { exact: false })).toBeVisible();
    await page.getByRole('combobox', { name: 'Lead', exact: true }).click();
    await page.getByRole('option', { name: 'Synthetic draft lead', exact: true }).click();
    await page.getByLabel('Recipient email', { exact: true }).fill('recipient@example.test');
    await page.getByLabel('Message subject', { exact: true }).fill('Collaboration next steps');
    await page.getByLabel('Message', { exact: true }).fill('Hello, would you like to discuss the collaboration?');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Collaboration next steps', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Download approved message' })).toHaveCount(0);
    const approve = page.getByRole('button', { name: 'Approve Collaboration next steps', exact: true });
    await approve.click();
    const approval = page.getByRole('dialog', { name: 'Approve follow-up?', exact: true });
    await expect(approval.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(approve).toBeFocused();
    await approve.click();
    await approval.getByRole('button', { name: 'Approve follow-up', exact: true }).click();
    await expect(page.getByText('Approved for manual sending · Version 2', { exact: true })).toBeVisible();
    const downloadEvent = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Download approved message', exact: true }).click();
    const download = await downloadEvent;
    expect(await readFile((await download.path())!, 'utf8')).toContain(
        'To: recipient@example.test\nSubject: Collaboration next steps',
    );
    for (const width of [1440, 1920]) {
        await page.setViewportSize({ width, height: 1080 });
        expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.screenshot({ path: info.outputPath(`follow-up-drafts-${width}.png`), fullPage: true });
    }
    await page.getByRole('button', { name: 'Edit Collaboration next steps', exact: true }).click();
    await page.getByLabel('Message', { exact: true }).fill('An edited message needs fresh approval.');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByText('Needs review · Version 3', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Download approved message' })).toHaveCount(0);
    const discard = page.getByRole('button', { name: 'Discard Collaboration next steps', exact: true });
    await discard.click();
    const dialog = page.getByRole('dialog', { name: 'Discard follow-up?', exact: true });
    await expect(dialog.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await page.keyboard.press('Escape');
    await expect(discard).toBeFocused();
    await discard.click();
    await dialog.getByRole('button', { name: 'Discard follow-up', exact: true }).click();
    await expect(page.getByText('Discarded · Version 4', { exact: true })).toBeVisible();
});

test('scheduled preparation produces a private draft requiring recipient review', async ({ page }, info) => {
    test.skip(info.project.name.includes('mobile'), 'Small-screen acceptance belongs to Claude under D28.');
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
    await page.reload();
    await page.getByLabel('Follow-up drafts', { exact: true }).check();
    await page.getByRole('button', { name: 'Save preferences', exact: true }).click();
    await page.getByLabel('Follow-up schedule name', { exact: true }).fill('Prepare collaboration follow-up');
    await page.getByRole('combobox', { name: 'Follow-up lead', exact: true }).click();
    await page.getByRole('option', { name: 'Scheduled synthetic lead', exact: true }).click();
    const future = await page.evaluate(() => {
        const date = new Date(Date.now() + 3600000);
        return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    });
    await page.getByLabel('Prepare draft at', { exact: true }).fill(future);
    await page
        .getByLabel('I consent to private follow-up draft preparation in this workspace.', { exact: true })
        .check();
    await page.getByRole('button', { name: 'Schedule follow-up draft', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Prepare collaboration follow-up', exact: true })).toBeVisible();
    compose(['run', '--rm', '-T', '--no-deps', 'migrate', 'php', 'tests/Browser/scheduled-notice-fixture.php', email]);
    compose(['exec', '-T', 'app', 'php', 'artisan', 'automation:dispatch']);
    compose(['exec', '-T', 'app', 'php', 'artisan', 'queue:work', 'redis', '--stop-when-empty']);
    compose(['exec', '-T', 'app', 'php', 'artisan', 'automation:drafts']);
    compose(['exec', '-T', 'app', 'php', 'artisan', 'queue:work', 'redis', '--stop-when-empty']);
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Following up', exact: true })).toBeVisible();
    await expect(page.getByText('To: Enter a recipient before approval', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Approve Following up', exact: true }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Approve follow-up', exact: true }).click();
    await expect(page.getByRole('dialog').getByText('The recipient field is required.', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Download approved message' })).toHaveCount(0);
});
