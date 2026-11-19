import { confirmMutation } from './confirmations';
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { approvedBrand, fixture, login, openBrandHub } from './helpers';

function deliver(email: string): void {
    execFileSync(
        'docker',
        [
            'compose',
            '--env-file',
            'src/.env',
            '-p',
            process.env.COMPOSE_PROJECT_NAME ?? 'weavdash',
            'exec',
            '-T',
            'app',
            'php',
            'tests/Browser/notification-fixture.php',
            email,
        ],
        {
            cwd: fileURLToPath(new URL('../../../', import.meta.url)),
            encoding: 'utf8',
        },
    );
}

test('explicit invitation email shows queue capture and cancellation without external mail', async ({ page }, info) => {
    const email = `m1-browser-notifications-${info.project.name}-${Date.now()}@example.test`;
    fixture('seed', email);
    approvedBrand(email, 'Notification review brand');
    await login(page, email);
    await openBrandHub(page, 'Notification review brand');
    await page.getByRole('button', { name: '+ Invite partners', exact: true }).click();
    await expect(page.getByLabel('Email this invitation to the recipient')).not.toBeChecked();
    await page.getByLabel('Recipient email').fill(email.replace('@', '-recipient@'));
    await page.getByLabel('Email this invitation to the recipient').check();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
        await page.screenshot({
            path: info.outputPath(`notification-dialog-${info.project.name}.png`),
            fullPage: true,
        });
    await page.getByRole('button', { name: 'Create and email invitation', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Copy invitation link', exact: true })).toBeVisible();
    const queued = page.locator('.Toastify__toast').filter({ hasText: 'Invitation email queued for' });
    await expect(queued).toBeVisible();
    await expect(queued).not.toContainText('sent');
    await expect(page.getByLabel('Invitation link', { exact: true })).toBeFocused();
    await expect
        .poll(
            () =>
                queued.evaluate((toast) => {
                    const box = toast.getBoundingClientRect();
                    return toast.contains(
                        document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2),
                    );
                }),
            { timeout: 3000 },
        )
        .toBe(true);
    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
        await page.screenshot({
            path: info.outputPath(`notification-queued-${info.project.name}.png`),
            fullPage: true,
        });
    await page.getByRole('button', { name: 'Close invitation dialog' }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    const history = page.getByRole('region', { name: 'Invitations', exact: true });
    await expect(
        history.getByRole('rowheader', { name: email.replace('@', '-recipient@'), exact: true }),
    ).toBeVisible();
    await expect(history).toContainText('Email queued');
    deliver(email);
    // Refresh status reloads only this section.
    await history.getByRole('button', { name: 'Refresh status' }).click();
    await expect(history).toContainText('Email captured locally; no external delivery');
    await page.getByRole('button', { name: '+ Invite partners', exact: true }).click();
    await expect(page.getByLabel('Email this invitation to the recipient')).not.toBeChecked();
    await page.getByLabel('Recipient email').fill(email.replace('@', '-cancelled@'));
    await page.getByLabel('Email this invitation to the recipient').check();
    await page.getByRole('button', { name: 'Create and email invitation', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Copy invitation link', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Close invitation dialog' }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    const cancelled = email.replace('@', '-cancelled@');
    await confirmMutation(
        page,
        history.getByRole('button', { name: `Revoke invitation to ${cancelled}`, exact: true }),
        'Revoke invitation',
        info,
    );
    const filters = history.getByRole('group', { name: 'Filter invitations by status' });
    await expect(filters.getByRole('button', { name: 'Revoked 1' })).toBeVisible();
    deliver(email);
    await page.reload();
    await filters.getByRole('button', { name: 'Revoked 1' }).click();
    await expect(filters.getByRole('button', { name: 'Revoked 1' })).toHaveAttribute('aria-pressed', 'true');
    await expect(history.getByRole('row', { name: new RegExp(cancelled) })).toContainText('Email cancelled');
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
        await page.screenshot({
            path: info.outputPath(`notification-history-${info.project.name}.png`),
            fullPage: true,
        });
});
