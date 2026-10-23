import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { fixture, login } from './helpers';

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
    await login(page, email);
    await page.getByLabel('Workspace name', { exact: true }).fill('Notification review brand');
    await page.getByLabel('Workspace type').selectOption('brand');
    await page.getByRole('button', { name: 'Create workspace', exact: true }).click();
    await page.getByRole('link', { name: 'Open brand hub' }).click();
    await page.getByRole('button', { name: '+ Invite partners', exact: true }).click();
    await expect(page.getByLabel('Email this invitation to the recipient')).not.toBeChecked();
    await page.getByLabel('Recipient email').fill(email.replace('@', '-recipient@'));
    await page.getByLabel('Email this invitation to the recipient').check();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await page.screenshot({ path: info.outputPath(`notification-dialog-${info.project.name}.png`), fullPage: true });
    await page.getByRole('button', { name: 'Create and email invitation', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Copy invitation link', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Close invitation dialog' }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    const history = page.getByRole('region', { name: 'Invitation history' });
    await expect(history).toContainText('Email pending');
    deliver(email);
    await page.reload();
    await expect(history).toContainText('Email captured locally; no external delivery');
    await page.getByRole('button', { name: '+ Invite partners', exact: true }).click();
    await expect(page.getByLabel('Email this invitation to the recipient')).not.toBeChecked();
    await page.getByLabel('Recipient email').fill(email.replace('@', '-cancelled@'));
    await page.getByLabel('Email this invitation to the recipient').check();
    await page.getByRole('button', { name: 'Create and email invitation', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Copy invitation link', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Close invitation dialog' }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await history
        .getByRole('listitem')
        .filter({ hasText: 'Email pending' })
        .getByRole('button', { name: 'Revoke invitation' })
        .click();
    deliver(email);
    await page.reload();
    await expect(history).toContainText('Email cancelled');
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: info.outputPath(`notification-history-${info.project.name}.png`), fullPage: true });
});
