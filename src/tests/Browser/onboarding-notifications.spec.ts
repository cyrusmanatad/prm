import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { fixture, login } from './helpers';
import { confirmMutation } from './confirmations';

function deliver(email: string): void {
    execFileSync(
        'docker',
        [
            'compose',
            '--env-file',
            'src/.env',
            '-p',
            process.env.COMPOSE_PROJECT_NAME ?? 'weavdash-m1',
            'exec',
            '-T',
            'app',
            'php',
            'tests/Browser/notification-fixture.php',
            email,
        ],
        { cwd: fileURLToPath(new URL('../../../', import.meta.url)), encoding: 'utf8' },
    );
}

test('onboarding email is explicit, captured, usable and safely replaced or revoked', async ({
    page,
    browser,
}, info) => {
    test.setTimeout(120_000);
    const email = `m1-browser-notifications-onboarding-${info.project.name}-${Date.now()}@example.test`;
    fixture('seed', email);
    await login(page, email);
    await page.getByLabel('Workspace name', { exact: true }).fill('Onboarding email review');
    await page.locator('#workspace-kind').selectOption('creator');
    await page.getByRole('button', { name: 'Create workspace', exact: true }).click();
    await page.getByRole('link', { name: 'Open partner portal' }).click();
    await page.getByRole('button', { name: '+ Add lead', exact: true }).click();
    await page.getByLabel('Brand name', { exact: true }).fill('Invitation Studio');
    await page.getByRole('button', { name: 'Capture lead', exact: true }).click();
    await expect(page.getByLabel('Email this onboarding invitation')).not.toBeChecked();
    await expect(page.getByLabel('Invitation recipient email')).toHaveCount(0);
    await page.getByLabel('Email this onboarding invitation').check();
    await page.getByLabel('Invitation recipient email').fill('recipient@example.test');
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await page.screenshot({ path: info.outputPath('onboarding-email-controls.png'), fullPage: true });
    await page.getByRole('button', { name: 'Start onboarding and email invitation', exact: true }).click();
    const history = page.getByRole('region', { name: 'Onboarding email history' });
    await expect(history).toContainText('Email pending');
    const link = await page.getByLabel('Private invitation link (copy now)').inputValue();
    deliver(email);
    await page.reload();
    await expect(history).toContainText('Email captured locally; no external delivery');
    const recipientContext = await browser.newContext({ viewport: page.viewportSize() ?? undefined });
    const recipientPage = await recipientContext.newPage();
    await recipientPage.goto(link);
    await recipientPage.getByRole('button', { name: 'Open invitation', exact: true }).click();
    await expect(recipientPage.getByRole('heading', { level: 1 })).toContainText('Onboarding for Invitation Studio');
    const formUrl = recipientPage.url();

    await expect(page.getByLabel('Email this onboarding invitation')).not.toBeChecked();
    await page.getByLabel('Email this onboarding invitation').check();
    await page.getByLabel('Invitation recipient email').fill('replacement@example.test');
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Create replacement invitation', exact: true }),
        'Create replacement invitation',
        info,
    );
    await expect(history).toContainText('Email pending');
    await expect(page.getByLabel('Email this onboarding invitation')).not.toBeChecked();
    expect((await recipientPage.goto(formUrl))?.status()).toBe(403);
    const replacement = await page.getByLabel('Private invitation link (copy now)').inputValue();
    expect(replacement).not.toBe(link);
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Revoke invitation access', exact: true }),
        'Revoke invitation access',
        info,
    );
    deliver(email);
    await page.reload();
    await expect(history).toContainText('Email cancelled');
    await expect(history).toContainText('Email captured locally; no external delivery');
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: info.outputPath('onboarding-email-history.png'), fullPage: true });
    await recipientPage.goto(replacement);
    await recipientPage.getByRole('button', { name: 'Open invitation', exact: true }).click();
    await expect(
        recipientPage.getByRole('heading', { name: 'We could not find this page.', exact: true }),
    ).toBeVisible();
    await recipientContext.close();
});
