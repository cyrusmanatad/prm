import { test, expect, type Page, type TestInfo } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { confirmMutation } from './confirmations';
import { fixture } from './helpers';

// Workspace-owner MFA (M1-04). Needs an application that enforces it (OWNER_MFA_ENFORCED=true).
const repo = fileURLToPath(new URL('../../../', import.meta.url));
const password = 'browser-password-123';

function totp(secret: string, counter: number): string {
    const bits = [...secret]
        .map((c) => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(c).toString(2).padStart(5, '0'))
        .join('');
    const key = Buffer.from(bits.match(/.{8}/g)!.map((byte) => Number.parseInt(byte, 2)));
    const moving = Buffer.alloc(8);
    moving.writeBigUInt64BE(BigInt(counter));
    const hash = createHmac('sha1', key).update(moving).digest();
    return ((hash.readUInt32BE(hash[19]! & 15) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
}
/** Codes are single use: each call returns a newer step the server will still accept. */
function codes(page: Page, secret: () => string) {
    let used = 0;
    return async (offset = 0) => {
        const wanted = Math.max(Math.floor(Date.now() / 30000) + offset, used + 1);
        while (wanted > Math.floor(Date.now() / 30000) + 1) await page.waitForTimeout(1000);
        used = wanted;
        return totp(secret(), used);
    };
}
function seedOperator(email: string): { secret: string } {
    const compose = ['compose', '--env-file', 'src/.env', '-p', process.env.COMPOSE_PROJECT_NAME ?? 'weavdash-m1'];
    const file = process.env.ADMIN_TEST_COMPOSE ?? process.env.DELIVERY_TEST_COMPOSE;
    if (file) compose.push('-f', file);
    return JSON.parse(
        execFileSync(
            'docker',
            [...compose, 'run', '--rm', '-T', '--no-deps', 'migrate', 'php', 'tests/Browser/admin-fixture.php', email],
            { cwd: repo, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
        ),
    );
}
async function clean(page: Page) {
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}
// Optional temporary captures for visual inspection; written only to the test output directory.
async function capture(page: Page, info: TestInfo, name: string) {
    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
        await page.screenshot({ path: info.outputPath(`${name}.png`), fullPage: true });
}
async function signIn(page: Page, email: string) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.waitForURL(/\/(workspaces|account\/authenticator)$/);
}
async function signOut(page: Page) {
    await page.goto('/account/authenticator');
    await page.evaluate(async () => {
        const token = decodeURIComponent(
            document.cookie
                .split('; ')
                .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
                ?.split('=')
                .slice(1)
                .join('=') ?? '',
        );
        await fetch('/logout', { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token } });
    });
}

test('a workspace owner sets up, uses and recovers an authenticator, and an operator resets it', async ({
    page,
}, info) => {
    test.setTimeout(420_000);
    const mobile = info.project.name.includes('mobile');
    await page.setViewportSize({ width: mobile ? 390 : 1440, height: 1000 });
    const stamp = `${info.project.name}-${Date.now()}`;
    const email = `m1-browser-mfa-owner-${stamp}@example.test`;
    fixture('seed-workspaces', email);

    // Sign-in stops at the authenticator page; nothing else opens until setup is complete.
    await signIn(page, email);
    test.skip(!/\/account\/authenticator$/.test(page.url()), 'This application does not enforce workspace-owner MFA.');
    await expect(page.getByRole('heading', { name: 'Set up an authenticator.', level: 1 })).toBeVisible();
    await clean(page);
    await capture(page, info, 'setup-start');
    await page.goto('/workspaces');
    await expect(page).toHaveURL(/\/account\/authenticator$/);
    await page.goto('/pipeline');
    await expect(page).toHaveURL(/\/account\/authenticator$/);

    await page.getByLabel('Password', { exact: true }).fill('wrong-password-123');
    await page.getByRole('button', { name: 'Generate setup key', exact: true }).click();
    await expect(page.getByText('Enter your current password.')).toBeVisible();
    await expect(page.getByLabel('Password', { exact: true })).toBeFocused();
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Generate setup key', exact: true }).click();
    await expect(page.getByRole('img', { name: 'QR code for authenticator setup' })).toBeVisible();
    let secret = (await page.locator('.wd-copy-reference code').textContent())!.trim();
    expect(secret).toMatch(/^[A-Z2-7]{32}$/);
    const code = codes(page, () => secret);
    await clean(page);
    await capture(page, info, 'setup-key');
    // A reload keeps the pending setup key instead of repeating the request.
    await page.reload();
    await expect(page.locator('.wd-copy-reference code')).toHaveText(secret);

    await page.getByLabel('Authenticator code', { exact: true }).fill('000000');
    await page.getByRole('button', { name: 'Confirm authenticator', exact: true }).click();
    await expect(page.getByText('Enter a fresh authenticator code.')).toBeVisible();
    await page.getByLabel('Authenticator code', { exact: true }).fill(await code(-1));
    await page.getByRole('button', { name: 'Confirm authenticator', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Save your recovery codes.', level: 1 })).toBeVisible();
    const recovery = await page.getByRole('list', { name: 'Recovery codes' }).locator('code').allTextContents();
    expect(new Set(recovery).size).toBe(10);
    await clean(page);
    await capture(page, info, 'recovery-codes');
    await page.getByRole('link', { name: 'I saved my codes', exact: true }).click();
    await expect(page).toHaveURL(/\/pipeline$|\/workspaces$|\/partner/);
    // The codes are shown once.
    await page.goto('/account/authenticator');
    await expect(page.getByRole('heading', { name: 'Your authenticator.', level: 1 })).toBeVisible();
    await expect(page.getByText('10 recovery codes left.')).toBeVisible();
    await expect(page.getByRole('list', { name: 'Recovery codes' })).toHaveCount(0);
    await clean(page);
    await capture(page, info, 'manage');

    // Every sign-in asks for a code. A used code is refused; a recovery code works once.
    await signOut(page);
    await signIn(page, email);
    await expect(page.getByRole('heading', { name: 'Enter your authenticator code.', level: 1 })).toBeVisible();
    await clean(page);
    await capture(page, info, 'challenge');
    await page.goto('/workspaces');
    await expect(page).toHaveURL(/\/account\/authenticator$/);
    await page.getByLabel('Authenticator code', { exact: true }).fill(await code());
    await page.getByRole('button', { name: 'Verify and continue', exact: true }).click();
    await expect(page).toHaveURL(/\/workspaces$/);
    await page.getByText('Account security', { exact: true }).click();
    await expect(page.getByRole('link', { name: 'Authenticator and recovery codes' })).toBeVisible();

    await signOut(page);
    await signIn(page, email);
    await page.getByRole('button', { name: 'Use a recovery code instead', exact: true }).click();
    await expect(page.getByLabel('Recovery code', { exact: true })).toBeFocused();
    await clean(page);
    await page.getByLabel('Recovery code', { exact: true }).fill('AAAAA-AAAAA');
    await page.getByRole('button', { name: 'Use recovery code', exact: true }).click();
    await expect(page.getByText('That recovery code is not valid or was already used.')).toBeVisible();
    await page.getByLabel('Recovery code', { exact: true }).fill(recovery[0]!);
    await page.getByRole('button', { name: 'Use recovery code', exact: true }).click();
    await expect(page.getByText('9 recovery codes left.')).toBeVisible();

    // New recovery codes and a replacement authenticator each need confirmation.
    await page.getByRole('button', { name: 'New recovery codes', exact: true }).click();
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Authenticator code', { exact: true }).fill(await code(1));
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Create new codes', exact: true }),
        'Create new recovery codes',
        info,
    );
    await expect(page.getByRole('heading', { name: 'Save your recovery codes.', level: 1 })).toBeVisible();
    const renewed = await page.getByRole('list', { name: 'Recovery codes' }).locator('code').allTextContents();
    expect(renewed).toHaveLength(10);
    expect(renewed).not.toContain(recovery[1]);
    await page.goto('/account/authenticator');
    await page.getByRole('button', { name: 'Replace authenticator', exact: true }).click();
    await page.getByLabel('Password', { exact: true }).fill(password);
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Replace authenticator', exact: true }),
        'Replace authenticator',
        info,
    );
    await expect(page.getByRole('img', { name: 'QR code for authenticator setup' })).toBeVisible();
    const replacement = (await page.locator('.wd-copy-reference code').textContent())!.trim();
    expect(replacement).not.toBe(secret);
    secret = replacement;
    const replaced = codes(page, () => secret);
    await page.getByLabel('Authenticator code', { exact: true }).fill(await replaced(-1));
    await page.getByRole('button', { name: 'Confirm authenticator', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Save your recovery codes.', level: 1 })).toBeVisible();
    await signOut(page);

    // An operator with access management resets the owner's authenticator after an identity check.
    const operatorEmail = `m1-browser-admin-mfa-${stamp}@example.test`;
    fixture('seed', operatorEmail);
    const operator = seedOperator(operatorEmail);
    const proof = codes(page, () => operator.secret);
    await signIn(page, operatorEmail);
    await expect(page.getByRole('heading', { name: 'Enter your authenticator code.', level: 1 })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Use a recovery code instead' })).toHaveCount(0);
    await page.getByLabel('Authenticator code', { exact: true }).fill(await proof(-1));
    await page.getByRole('button', { name: 'Verify and continue', exact: true }).click();
    await expect(page).toHaveURL(/\/workspaces$/);
    await page.goto(`/admin/accounts?q=${encodeURIComponent(email)}`);
    await expect(page).toHaveURL(/\/admin\/security$/);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Authenticator code', { exact: true }).fill(await proof());
    await page.getByRole('button', { name: 'Verify and continue', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/accounts\?q=/);
    await page.locator('a[href^="/admin/accounts/"]').first().click();
    await page.getByRole('link', { name: 'Security', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Authenticator', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Reset authenticator', exact: true }).click();
    await clean(page);
    await capture(page, info, 'admin-reset');
    await page.getByLabel('Reason', { exact: true }).fill('Identity confirmed through the verified email address');
    await page.getByLabel('Your password', { exact: true }).fill(password);
    await page.getByLabel('Your authenticator code', { exact: true }).fill(await proof(1));
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Reset authenticator', exact: true }),
        'Reset authenticator',
        info,
    );
    await expect(page.getByText('Not set up').first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'Reset authenticator', exact: true })).toHaveCount(0);
    await signOut(page);

    // The owner is back at setup; the old authenticator no longer exists.
    await signIn(page, email);
    await expect(page.getByRole('heading', { name: 'Set up an authenticator.', level: 1 })).toBeVisible();
    await page.goto('/workspaces');
    await expect(page).toHaveURL(/\/account\/authenticator$/);
});
