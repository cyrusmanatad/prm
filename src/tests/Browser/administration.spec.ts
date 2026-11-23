import { test, expect, type Page, type TestInfo } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { createHmac, randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { confirmMutation } from './confirmations';
import { fixture, login } from './helpers';

// Each test seeds its own synthetic administrator, so the tests are independent and may run in parallel.
test.describe.configure({ mode: 'parallel' });

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
function adminFixture(...args: string[]) {
    const compose = ['compose', '--env-file', 'src/.env', '-p', process.env.COMPOSE_PROJECT_NAME ?? 'weavdash-m1'];
    const file = process.env.ADMIN_TEST_COMPOSE ?? process.env.DELIVERY_TEST_COMPOSE;
    if (file) compose.push('-f', file);
    return JSON.parse(
        execFileSync(
            'docker',
            [
                ...compose,
                'run',
                '--rm',
                '-T',
                '--no-deps',
                'migrate',
                'php',
                'tests/Browser/admin-fixture.php',
                ...args,
            ],
            { cwd: repo, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
        ),
    );
}
// The administrator seeded by the test running in this worker, released afterwards.
let seeded: string | undefined;
function seedAdministrator(email: string): { secret: string; peer: number; peerEmail: string; owner: number } {
    seeded = email;
    return adminFixture(email);
}
// Every test seeds a platform owner, and the operator list shows every grant on one page. Removing this test's grants
// keeps that page, and its accessibility scans, from growing with each run. Accounts and audit events are kept.
test.afterEach(({}, info) => {
    const email = seeded;
    seeded = undefined;
    if (!email) return;
    try {
        adminFixture(email, 'release');
    } catch {
        // An app image built before the release step cannot remove grants; the test result itself still stands.
        info.annotations.push({ type: 'warning', description: `Platform grants for ${email} were not released.` });
    }
});
async function clean(page: Page) {
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}
// Optional temporary captures for visual inspection; written only to the test output directory.
async function capture(page: Page, info: TestInfo, name: string) {
    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
        await page.screenshot({
            path: info.outputPath(`${name.toLowerCase().replaceAll(' ', '-')}.png`),
            fullPage: true,
        });
}
const local = (date: Date) => new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);

/**
 * Codes for one account's authenticator. The server accepts a code for step k while the clock is within one step of k,
 * and only for a step newer than the last one it accepted. So up to three codes are available at once without waiting:
 * take the oldest unused step that stays valid for `margin` milliseconds, and wait only when every such step is spent.
 */
function authenticator(page: Page, secret: string) {
    let used = -1;
    return async (margin: number) => {
        for (;;) {
            const now = Date.now();
            const wanted = Math.max(used + 1, Math.ceil((now + margin) / 30000) - 2);
            if (wanted <= Math.floor(now / 30000) + 1) {
                used = wanted;
                return totp(secret, wanted);
            }
            // Wait until the step before the wanted one begins, plus a little room for clock rounding.
            await page.waitForTimeout((wanted - 1) * 30000 - now + 500);
        }
    };
}

type Administrator = {
    email: string;
    workspace: string;
    peer: number;
    peerEmail: string;
    mobile: boolean;
    base: number;
    /** Current password and a fresh single-use code for a sensitive change. Enter it last, just before submitting. */
    prove: () => Promise<void>;
    /** Completes the administration challenge once; the session stays verified for the rest of the test. */
    verify: () => Promise<void>;
};

/** A signed-in workspace owner who is about to receive a platform-owner grant. */
async function workspaceOwner(page: Page, info: TestInfo) {
    const mobile = info.project.name.includes('mobile');
    const base = mobile ? 390 : 1440;
    await page.setViewportSize({ width: base, height: 1000 });
    const email = `m1-browser-admin-${info.project.name}-${Date.now()}-${randomBytes(3).toString('hex')}@example.test`;
    const workspace = fixture('seed-workspaces', email).own;
    await login(page, email);
    // Selecting the workspace lets this same person act as its owner, for example when approving support access.
    await page.getByRole('button', { name: /Role test workspace/ }).click();
    return { email, workspace, mobile, base };
}

/** Grants platform owner to the signed-in account and returns helpers for its authenticator. */
function grantOwner(page: Page, owner: Awaited<ReturnType<typeof workspaceOwner>>): Administrator {
    const { secret, peer, peerEmail } = seedAdministrator(owner.email);
    const code = authenticator(page, secret);
    return {
        ...owner,
        peer,
        peerEmail,
        // The confirmation dialog checks that follow take a few seconds, so the code must stay valid for a while.
        prove: async () => {
            await page.getByLabel('Your password', { exact: true }).fill(password);
            await page.getByLabel('Your authenticator code', { exact: true }).fill(await code(20000));
        },
        verify: async () => {
            await page.getByLabel('Password', { exact: true }).fill(password);
            await page.getByLabel('Authenticator code', { exact: true }).fill(await code(8000));
            await page.getByRole('button', { name: 'Verify and continue', exact: true }).click();
        },
    };
}

/** A verified platform owner on the accounts directory. */
async function administrator(page: Page, info: TestInfo): Promise<Administrator> {
    const admin = grantOwner(page, await workspaceOwner(page, info));
    await page.goto('/admin/accounts');
    await expect(page).toHaveURL(/\/admin\/security$/);
    await admin.verify();
    await expect(page).toHaveURL(/\/admin\/accounts$/);
    return admin;
}

/** Audit events recorded by this administrator, unaffected by tests running alongside. */
const ownAudit = (page: Page, email: string, query = '') =>
    page.goto(`/admin/audit?q=${encodeURIComponent(email)}${query}`);

test('administration needs a grant and verification, and stays closed to other accounts', async ({
    page,
    browser,
}, info) => {
    const owner = await workspaceOwner(page, info);
    // Without a platform grant the account menu does not offer administration.
    await page.getByRole('button', { name: /Account menu/ }).click();
    await expect(page.getByRole('menuitem', { name: 'Sign out' })).toBeVisible();
    await expect(page.getByRole('menuitem', { name: 'Platform administration' })).toHaveCount(0);
    await page.keyboard.press('Escape');
    const { email, workspace, mobile, verify } = grantOwner(page, owner);

    // With a grant the account menu links to administration, which still asks for verification.
    await page.reload();
    await page.getByRole('button', { name: /Account menu/ }).click();
    await page.getByRole('menuitem', { name: 'Platform administration' }).click();
    await expect(page).toHaveURL(/\/admin\/security$/);

    // An unverified visit returns to the challenge and then to the page that was asked for.
    await page.goto('/admin/accounts');
    await expect(page).toHaveURL(/\/admin\/security$/);
    await expect(page.getByRole('heading', { name: 'Verify your identity', level: 1 })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Administration', exact: true }).getByRole('link')).toHaveCount(
        mobile ? 0 : 1,
    );
    await clean(page);
    await verify();
    await expect(page).toHaveURL(/\/admin\/accounts$/);
    await expect(page.getByText('Administration', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('Environment: Development')).toBeAttached();

    // The audit records denied requests separately. Actions read in plain language; codes stay on each event's page.
    await ownAudit(page, email, '&outcome=denied');
    await expect(page.getByRole('table', { name: 'Audit events' })).toContainText('Request denied');
    await expect(
        page.getByRole('navigation', { name: 'Outcome' }).getByRole('link', { name: /^Denied/ }),
    ).toHaveAttribute('aria-current', 'true');

    // A workspace owner without a grant gets a generic denial and no administration data.
    const other = await browser.newContext();
    const outsider = await other.newPage();
    const outsiderEmail = `m1-browser-outsider-${info.project.name}-${Date.now()}@example.test`;
    fixture('seed-workspaces', outsiderEmail);
    await login(outsider, outsiderEmail);
    for (const path of [
        '/admin',
        '/admin/accounts',
        `/admin/workspaces/${workspace}`,
        '/admin/operators',
        '/admin/audit',
    ]) {
        const response = await outsider.goto(path);
        expect(response?.status()).toBe(403);
        await expect(
            outsider.getByRole('heading', { name: 'Administration is not available to this account' }),
        ).toBeVisible();
        expect(await outsider.content()).not.toContain(email);
    }
    expect((await new AxeBuilder({ page: outsider }).analyze()).violations).toEqual([]);
    expect((await outsider.request.get('/admin/accounts', { headers: { Accept: 'application/json' } })).status()).toBe(
        403,
    );
    await other.close();
});

// Every module renders without accessibility violations or page-level overflow at each reference width.
// Small groups keep each test short when several run at once; each axe scan takes seconds on a busy host.
for (const modules of [
    [
        ['/admin', 'Overview'],
        ['/admin/accounts', 'Accounts'],
    ],
    [
        ['/admin/workspaces', 'Workspaces'],
        ['/admin/plans', 'Plans'],
    ],
    [
        ['/admin/assignments', 'Assignments'],
        ['/admin/notifications', 'Notifications'],
    ],
    [
        ['/admin/operations', 'Jobs and health'],
        ['/admin/audit', 'Audit'],
    ],
    [
        ['/admin/operators', 'Platform access'],
        ['/admin/support-access', 'Support access'],
    ],
    [['/admin/cleanup', 'Data cleanup']],
] as const) {
    test(`modules render cleanly at every width: ${modules.map(([, heading]) => heading).join(', ')}`, async ({
        page,
    }, info) => {
        const { base } = await administrator(page, info);
        for (const [path, heading] of modules) {
            await page.goto(path);
            await expect(page.getByRole('heading', { name: heading, level: 1, exact: true })).toBeVisible();
            for (const width of info.project.name === 'desktop' ? [1440, 1024, 768] : [390, 320]) {
                await page.setViewportSize({ width, height: 1000 });
                await clean(page);
            }
            await page.setViewportSize({ width: base, height: 1000 });
            await capture(page, info, heading);
        }
        // Cleanup is a preview: the landing page says so before anything is created.
        if (modules.some(([path]) => path === '/admin/cleanup'))
            await expect(page.getByText('Preview only: nothing can be deleted here', { exact: false })).toBeVisible();
    });
}

test('directory filters report truthful counts and restore from history', async ({ page }, info) => {
    const { email, mobile } = await administrator(page, info);
    // Quick views carry counts; search and dates scope them.
    await expect(
        page.getByRole('navigation', { name: 'Show accounts' }).getByRole('link', { name: /^All/ }),
    ).toHaveAttribute('aria-current', 'true');
    const search = page.getByLabel('Name, email or account ID', { exact: true });
    await search.fill(email);
    await search.press('Enter');
    await expect(page).toHaveURL((url) => url.searchParams.get('q') === email);
    await expect(page.getByText('Showing 1–1 of 1 matching accounts, all pages', { exact: true })).toBeVisible();
    await expect(page.getByRole('list', { name: 'Applied filters' })).toContainText(`Search: ${email}`);
    await expect(page.getByRole('navigation', { name: 'Show accounts' })).toContainText('All 1');
    await search.fill('no-such-account-zzz');
    await search.press('Enter');
    await expect(page.getByRole('heading', { name: 'No accounts match these filters' })).toBeVisible();
    await page.goBack();
    await expect(search).toHaveValue(email);
    await expect(page.getByText('Showing 1–1 of 1 matching accounts, all pages', { exact: true })).toBeVisible();
    // Joined-date range: labelled date inputs, inclusive end date, reported as applied and restored from the URL.
    const today = new Date().toISOString().slice(0, 10);
    if (mobile) await page.getByRole('button', { name: /^Filters/ }).click();
    await page.getByRole('button', { name: 'Joined: any time' }).click();
    await page.getByLabel('Joined from', { exact: true }).fill(today);
    await page.getByLabel('Joined to (inclusive)', { exact: true }).fill(today);
    await page.getByRole('button', { name: 'Apply dates', exact: true }).click();
    await expect(page).toHaveURL(
        (url) => url.searchParams.get('from') === today && url.searchParams.get('to') === today,
    );
    await expect(page.getByRole('list', { name: 'Applied filters' })).toContainText(`Joined to: ${today}`);
    await expect(page.getByText('Showing 1–1 of 1 matching accounts, all pages', { exact: true })).toBeVisible();
    await clean(page);
    await page.getByLabel('Joined from', { exact: true }).fill('2000-01-01');
    await page.getByLabel('Joined to (inclusive)', { exact: true }).fill('2000-01-02');
    await page.getByRole('button', { name: 'Apply dates', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'No accounts match these filters' })).toBeVisible();
    await page.goBack();
    await expect(page.getByLabel('Joined from', { exact: true })).toHaveValue(today);
    await page.getByRole('table', { name: 'Accounts' }).getByRole('link').first().click();
    await expect(page.getByText('You cannot suspend your own account', { exact: false })).toBeVisible();
    await capture(page, info, 'account');
    await expect(page.getByText('Not collected', { exact: true })).toBeVisible();
    await clean(page);
    const sections = page.getByRole('navigation', { name: 'Account sections', exact: true });
    await sections.getByRole('link', { name: 'Security', exact: true }).click();
    await expect(sections.getByRole('link', { name: 'Security', exact: true })).toHaveAttribute('aria-current', 'page');
    await expect(page.getByText('Platform owner', { exact: true }).last()).toBeVisible();
    await clean(page);
    await sections.getByRole('link', { name: 'Activity', exact: true }).click();
    await clean(page);
    // Section tabs carry counts, such as "Workspaces 1".
    await sections.getByRole('link', { name: /^Workspaces \d+$/ }).click();
    await clean(page);
});

test('workspace entitlements: reviewed, confirmed, version-checked assignment', async ({ page }, info) => {
    const { workspace } = await administrator(page, info);
    await page.goto(`/admin/workspaces/${workspace}`);
    await expect(page.getByRole('button', { name: 'Suspend workspace', exact: true })).toBeVisible();
    const tabs = page.getByRole('navigation', { name: 'Workspace sections', exact: true });
    for (const tab of [/^Members \d+$/, 'Operations', 'Activity', 'Entitlements']) {
        const link =
            typeof tab === 'string'
                ? tabs.getByRole('link', { name: tab, exact: true })
                : tabs.getByRole('link', { name: tab });
        await link.click();
        await expect(link).toHaveAttribute('aria-current', 'page');
        await clean(page);
    }
    await expect(page.getByText('Unassigned (development use)').first()).toBeVisible();
    await expect(page.getByRole('cell', { name: 'No plan configured' }).first()).toBeVisible();
    await page.getByRole('link', { name: 'Record assignment', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Record assignment', level: 1 })).toBeVisible();
    await page.getByLabel('Plan name', { exact: true }).fill('Example pilot plan');
    await page.locator('#assign-start').fill(local(new Date(Date.now() - 120000)));
    await page.locator('#assign-end').fill(local(new Date(Date.now() + 86400000)));
    for (const resource of [
        'pipeline_entries',
        'brand_contacts',
        'reminders',
        'onboarding_requests',
        'outreach_templates',
        'invoice_drafts',
        'manual_payments',
    ])
        await page.locator(`#quota-${resource}`).fill('25');
    await page.getByLabel('Reason', { exact: true }).fill('Administration journey assignment');
    await capture(page, info, 'assign');
    await clean(page);
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Record assignment', exact: true }),
        'Record subscription assignment',
        info,
    );
    await expect(page).toHaveURL(new RegExp(`/admin/workspaces/${workspace}\\?tab=entitlements`));
    await expect(page.getByText(/Example pilot plan v\d+/).first()).toBeVisible();
    await expect(page.getByRole('link', { name: 'Replace assignment', exact: true })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'Within limit' }).first()).toBeVisible();
    await capture(page, info, 'workspace-entitlements');
    await clean(page);
    await page.goto('/admin/plans');
    await page
        .getByRole('table', { name: 'Plan versions' })
        .getByRole('link', { name: 'Example pilot plan' })
        .first()
        .click();
    await expect(page.getByRole('heading', { name: /^Example pilot plan v\d+$/, level: 1 })).toBeVisible();
    await expect(page.getByRole('table', { name: 'Create limits' }).getByRole('row')).toHaveCount(8);
    await clean(page);
});

test('diagnostics show a job type and error class, never payloads or messages', async ({ page }, info) => {
    await administrator(page, info);
    await page.goto('/admin/operations');
    await expect(page.getByRole('table', { name: 'Failed jobs' })).toContainText('SendNotification');
    await expect(page.getByRole('table', { name: 'Failed jobs' })).toContainText('RuntimeException');
    await expect(page.getByText(/PRIVATE-(PAYLOAD|EXCEPTION)-MARKER/)).toHaveCount(0);
    expect(await page.content()).not.toMatch(/PRIVATE-(PAYLOAD|EXCEPTION)-MARKER/);
    await expect(page.getByRole('table', { name: 'Services' })).toContainText('Unknown');
});

test('operator access: fresh verification, explicit review, confirmed and audited revocation', async ({
    page,
}, info) => {
    const { email, peer, peerEmail, prove } = await administrator(page, info);
    await page.goto(`/admin/operators?account=${peer}`);
    await expect(page.getByRole('heading', { name: 'Grant platform access', level: 2 })).toBeVisible();
    await expect(page.getByText('You cannot change your own grant')).toBeVisible();
    await page.getByLabel('Directory', { exact: true }).check();
    await page.getByLabel('Audit review', { exact: true }).check();
    await page.getByLabel('Reason', { exact: true }).fill('Synthetic reviewer for the administration journey');
    await clean(page);
    await prove();
    await capture(page, info, 'operator-form');
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Review and save', exact: true }),
        'Record platform access',
        info,
    );
    const operators = page.getByRole('table', { name: 'Operators' });
    const row = operators.getByRole('row').filter({ hasText: peerEmail });
    await expect(row).toContainText('Directory, Audit review');
    await expect(row).toContainText('Not enrolled');
    // Row actions sit in a menu; revoking opens the access dialog with the reason and fresh proof.
    await row.getByRole('button', { name: /^Actions for / }).click();
    await page.getByRole('menuitem', { name: 'Revoke platform access', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Revoke platform access', exact: true })).toBeVisible();
    await page.getByLabel('Reason', { exact: true }).fill('Journey complete');
    await prove();
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Revoke platform access', exact: true }),
        'Revoke platform access',
        info,
    );
    await expect(operators.getByRole('row').filter({ hasText: peerEmail })).toContainText('Revoked');
    await clean(page);

    // The audit records the revocation with its reason.
    await ownAudit(page, email);
    await page
        .getByRole('table', { name: 'Audit events' })
        .getByRole('link', { name: 'Platform access revoked', exact: true })
        .first()
        .click();
    await expect(page.getByText('Journey complete', { exact: true })).toBeVisible();
    await capture(page, info, 'audit-event');
    await expect(page.getByText('Not recorded in this event source').first()).toBeVisible();
    await clean(page);
});

test('account suspension: server-computed impact, reason, fresh proof; reactivation restores access', async ({
    page,
}, info) => {
    const { peer, peerEmail, prove } = await administrator(page, info);
    await page.goto(`/admin/accounts/${peer}`);
    await page.getByRole('button', { name: 'Suspend account', exact: true }).click();
    await expect(page.getByText('refuses sign-in on every device', { exact: false })).toBeVisible();
    await page.getByLabel('Reason', { exact: true }).fill('Journey suspension');
    await clean(page);
    await prove();
    await capture(page, info, 'suspend-account');
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Review suspension', exact: true }),
        'Suspend account',
        info,
    );
    await expect(page.getByText('Reason: Journey suspension', { exact: false })).toBeVisible();
    await page.goto('/admin/accounts?access=suspended&q=' + encodeURIComponent(peerEmail));
    await expect(page.getByRole('table', { name: 'Accounts' })).toContainText('Suspended');
    await page.goto(`/admin/accounts/${peer}`);
    await page.getByRole('button', { name: 'Reactivate account', exact: true }).click();
    await page.getByLabel('Reason', { exact: true }).fill('Journey reactivation');
    await prove();
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Review reactivation', exact: true }),
        'Reactivate account',
        info,
    );
    await expect(page.getByRole('button', { name: 'Suspend account', exact: true })).toBeVisible();
    await clean(page);
});

test('support access: requested, approved by the workspace owner, read-only, ended and audited', async ({
    page,
}, info) => {
    const { email, workspace } = await administrator(page, info);
    await page.goto(`/admin/workspaces/${workspace}`);
    await page.getByRole('link', { name: 'Request support access', exact: true }).click();
    await page.getByLabel('Purpose', { exact: true }).fill('Journey support check');
    await page.getByLabel('Leads', { exact: true }).check();
    await clean(page);
    await page.getByRole('button', { name: 'Send request to workspace owners', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/support-access\/[0-9a-f-]{36}$/);
    const grantUrl = page.url();
    await expect(page.getByText('Awaiting workspace owner', { exact: true })).toBeVisible();
    await expect(page.getByText('Records in scope')).toHaveCount(0);
    await page.goto('/workspace/support-access');
    await expect(page.getByRole('heading', { name: 'Support access requests', level: 1 })).toBeVisible();
    await expect(page.getByText('Needs your decision', { exact: true })).toBeVisible();
    await clean(page);
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Approve', exact: true }),
        'Approve support access',
        info,
    );
    await expect(page.getByText('Access active', { exact: true })).toBeVisible();
    await clean(page);
    await page.goto(grantUrl);
    await expect(page.getByText('Support session: you are viewing', { exact: false })).toBeVisible();
    await page
        .getByRole('navigation', { name: 'Record areas in this grant' })
        .getByRole('link', { name: 'Leads', exact: true })
        .click();
    await expect(page.getByRole('heading', { name: 'No leads in this workspace' })).toBeVisible();
    await clean(page);
    await capture(page, info, 'support-session');
    expect(
        (await page.request.get(`${grantUrl}?area=reminders`, { headers: { Accept: 'application/json' } })).status(),
    ).toBe(403);
    await page.getByRole('button', { name: 'End support access', exact: true }).click();
    await page.getByLabel('Reason', { exact: true }).fill('Support journey complete');
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Review ending', exact: true }),
        'End support access',
        info,
    );
    await expect(page.getByText('Ended', { exact: true })).toBeVisible();
    await expect(page.getByText('Support session: you are viewing', { exact: false })).toHaveCount(0);
    await ownAudit(page, email);
    await expect(page.getByRole('table', { name: 'Audit events' })).toContainText('Support access ended');
});

test('cleanup: explicit marks, an exact audited dry run with reasons, and no way to execute', async ({
    page,
}, info) => {
    const { email, workspace } = await administrator(page, info);
    await page.goto('/admin/cleanup');
    // Storage reconciliation (PRIV-05) reports here, read-only.
    await expect(page.getByRole('heading', { name: 'Storage reconciliation', level: 2 })).toBeVisible();
    await page.getByRole('button', { name: 'New fixture batch', exact: true }).click();
    await expect(page.getByLabel('Batch name', { exact: true })).toBeFocused();
    await page.getByLabel('Batch name', { exact: true }).fill(`Journey batch ${email}`);
    await page.getByLabel('Purpose', { exact: true }).fill('Administration journey fixtures');
    await page.getByRole('button', { name: 'Create batch', exact: true }).click();
    await expect(page.getByRole('heading', { name: `Journey batch ${email}`, level: 1 })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Run a dry run', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Mark a record', exact: true }).click();
    await page.getByLabel('Workspace ID', { exact: true }).fill(workspace);
    await page.getByLabel('Evidence that it is synthetic', { exact: true }).fill('Seeded by the browser journey');
    await page.getByRole('button', { name: 'Mark record', exact: true }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.getByRole('table', { name: 'Marked records' })).toContainText('Role test workspace');
    await clean(page);
    await page.getByRole('button', { name: 'Run a dry run', exact: true }).click();
    await expect(page.getByRole('heading', { name: /^Dry run of Journey batch/, level: 1 })).toBeVisible();
    const manifest = page.getByRole('table', { name: 'Cleanup manifest' });
    await expect(manifest).toContainText('Blocked');
    await expect(manifest).toContainText('Has a member account that is not marked in this batch');
    await expect(page.getByText('This cleanup cannot be executed from the console', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: /delete|execute|remove data|run cleanup/i })).toHaveCount(0);
    await clean(page);
    await capture(page, info, 'cleanup-run');
    await ownAudit(page, email);
    await expect(page.getByRole('table', { name: 'Audit events' })).toContainText('Cleanup dry run');
});

test('workspace suspension blocks members with a plain message; reactivation restores access', async ({
    page,
}, info) => {
    const { email, workspace, prove } = await administrator(page, info);
    await page.goto(`/admin/workspaces/${workspace}`);
    await page.getByRole('button', { name: 'Suspend workspace', exact: true }).click();
    await expect(page.getByText('They are not replayed on reactivation', { exact: false })).toBeVisible();
    await page.getByLabel('Reason', { exact: true }).fill('Journey workspace suspension');
    await clean(page);
    await prove();
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Review suspension', exact: true }),
        'Suspend workspace',
        info,
    );
    await expect(page.getByRole('button', { name: 'Reactivate workspace', exact: true })).toBeVisible();
    expect((await page.goto('/partner/leads'))?.status()).toBe(403);
    await expect(page.getByRole('heading', { name: /This workspace's access is suspended/ })).toBeVisible();
    await page.goto(`/admin/workspaces/${workspace}`);
    await page.getByRole('button', { name: 'Reactivate workspace', exact: true }).click();
    await page.getByLabel('Reason', { exact: true }).fill('Journey workspace reactivation');
    await prove();
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Review reactivation', exact: true }),
        'Reactivate workspace',
        info,
    );
    await expect(page.getByRole('button', { name: 'Suspend workspace', exact: true })).toBeVisible();
    expect((await page.goto('/partner/leads'))?.status()).toBe(200);
    await ownAudit(page, email);
    const events = page.getByRole('table', { name: 'Audit events' });
    for (const action of ['Workspace reactivated', 'Workspace suspended']) await expect(events).toContainText(action);
});

test('small screens reach every module through a modal drawer that restores focus', async ({ page }, info) => {
    test.skip(!info.project.name.includes('mobile'), 'The drawer replaces the sidebar at phone widths only.');
    await administrator(page, info);
    const menu = page.getByRole('button', { name: 'Menu', exact: true });
    await menu.click();
    const drawer = page.getByRole('dialog', { name: 'Administration menu', exact: true });
    // Platform owners see every module, including the privacy request queue.
    await expect(drawer.getByRole('link')).toHaveCount(14);
    await expect(drawer.getByRole('link', { name: 'Privacy requests', exact: true })).toBeVisible();
    await clean(page);
    await page.keyboard.press('Escape');
    await expect(menu).toBeFocused();
    await menu.click();
    await drawer.getByRole('link', { name: 'Workspaces', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Workspaces', level: 1 })).toBeVisible();
    await expect(drawer).not.toBeVisible();
});
