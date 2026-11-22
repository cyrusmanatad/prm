import { test, expect, type Page, type TestInfo } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { confirmMutation } from './confirmations';
import { fixture, login } from './helpers';

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
function seedAdministrator(email: string): { secret: string; peer: number; peerEmail: string; owner: number } {
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
        await page.screenshot({
            path: info.outputPath(`${name.toLowerCase().replaceAll(' ', '-')}.png`),
            fullPage: true,
        });
}
const local = (date: Date) => new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);

test('platform owner reviews directory, entitlements, operations, audit and operator access', async ({
    page,
    browser,
}, info) => {
    // One long journey across every module. Each single-use authenticator code waits for a fresh 30-second step and
    // every module is scanned at three widths, so on a well-used local stack it runs eight to ten minutes.
    test.setTimeout(900_000);
    const mobile = info.project.name.includes('mobile');
    const base = mobile ? 390 : 1440;
    await page.setViewportSize({ width: base, height: 1000 });
    const email = `m1-browser-admin-${info.project.name}-${Date.now()}@example.test`;
    const workspace = fixture('seed-workspaces', email).own;
    await login(page, email);
    // Selecting the workspace lets this same person act as its owner when approving support access later.
    await page.getByRole('button', { name: /Role test workspace/ }).click();
    // Without a platform grant the account menu does not offer administration.
    await page.getByRole('button', { name: /Account menu/ }).click();
    await expect(page.getByRole('menuitem', { name: 'Sign out' })).toBeVisible();
    await expect(page.getByRole('menuitem', { name: 'Platform administration' })).toHaveCount(0);
    await page.keyboard.press('Escape');
    const { secret, peer, peerEmail } = seedAdministrator(email);
    // Codes are single use and only valid near the current 30-second step, so take a fresh, newer one each time.
    let used = 0;
    const code = async (offset = 0) => {
        // An earlier step leaves room for the later single-use codes, but it expires when the clock ticks over.
        // Use it only when the current step has time left for the request to arrive.
        if (offset < 0 && 30000 - (Date.now() % 30000) < 8000) offset = 0;
        const wanted = Math.max(Math.floor(Date.now() / 30000) + offset, used + 1);
        // The server accepts the current step and its neighbours; wait rather than send a step it would reject.
        while (wanted > Math.floor(Date.now() / 30000) + 1) await page.waitForTimeout(1000);
        used = wanted;
        return totp(secret, used);
    };
    // Enter proof last, after any accessibility scan of the form, so the single-use code is still fresh on submit.
    const prove = async () => {
        await page.getByLabel('Your password', { exact: true }).fill(password);
        // Take the next step: the confirmation checks that follow can outlast the current 30-second window.
        await page.getByLabel('Your authenticator code', { exact: true }).fill(await code(1));
    };

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
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Authenticator code', { exact: true }).fill(await code(-1));
    await page.getByRole('button', { name: 'Verify and continue', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/accounts$/);
    await expect(page.getByText('Administration', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('Environment: Development')).toBeAttached();

    // Every module renders without accessibility violations or page-level overflow at each reference width.
    for (const [path, heading] of [
        ['/admin', 'Overview'],
        ['/admin/accounts', 'Accounts'],
        ['/admin/workspaces', 'Workspaces'],
        ['/admin/plans', 'Plans'],
        ['/admin/assignments', 'Assignments'],
        ['/admin/notifications', 'Notifications'],
        ['/admin/operations', 'Jobs and health'],
        ['/admin/audit', 'Audit'],
        ['/admin/operators', 'Platform access'],
        ['/admin/support-access', 'Support access'],
        ['/admin/cleanup', 'Data cleanup'],
    ] as const) {
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
    await expect(page.getByText('Preview only — no data can be deleted here', { exact: true })).toBeVisible();

    // Directory: applied filters, truthful counts, restorable history.
    await page.goto('/admin/accounts');
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
    await sections.getByRole('link', { name: 'Workspaces', exact: true }).click();
    await clean(page);

    // Workspace detail and assignment: reviewed, confirmed, version-checked.
    await page.goto(`/admin/workspaces/${workspace}`);
    await expect(page.getByRole('button', { name: 'Suspend workspace', exact: true })).toBeVisible();
    const tabs = page.getByRole('navigation', { name: 'Workspace sections', exact: true });
    for (const tab of ['Members', 'Operations', 'Activity', 'Entitlements']) {
        await tabs.getByRole('link', { name: tab, exact: true }).click();
        await expect(tabs.getByRole('link', { name: tab, exact: true })).toHaveAttribute('aria-current', 'page');
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

    // Diagnostics show a job type and error class, never payloads or messages.
    await page.goto('/admin/operations');
    await expect(page.getByRole('table', { name: 'Failed jobs' })).toContainText('SendNotification');
    await expect(page.getByRole('table', { name: 'Failed jobs' })).toContainText('RuntimeException');
    await expect(page.getByText(/PRIVATE-(PAYLOAD|EXCEPTION)-MARKER/)).toHaveCount(0);
    expect(await page.content()).not.toMatch(/PRIVATE-(PAYLOAD|EXCEPTION)-MARKER/);
    await expect(page.getByRole('table', { name: 'Services' })).toContainText('Unknown');

    // Operator access: fresh verification, explicit review, confirmed revocation.
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
    await row.getByRole('button', { name: /^Revoke platform access for / }).click();
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

    // Suspension: server-computed impact, reason, fresh proof and confirmation; reactivation restores access only.
    await page.goto(`/admin/accounts/${peer}`);
    await page.getByRole('button', { name: 'Suspend account', exact: true }).click();
    await expect(page.getByText('refuses sign-in on every device', { exact: false })).toBeVisible();
    await page.getByLabel('Reason', { exact: true }).fill('Journey suspension');
    await clean(page);
    await prove();
    await capture(page, info, 'suspend-account');
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Suspend account', exact: true }),
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
        page.getByRole('button', { name: 'Reactivate account', exact: true }),
        'Reactivate account',
        info,
    );
    await expect(page.getByRole('button', { name: 'Suspend account', exact: true })).toBeVisible();
    await clean(page);

    // Support access: requested here, approved by the workspace owner, read-only, ended on demand.
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
        page.getByRole('button', { name: 'End support access', exact: true }).last(),
        'End support access',
        info,
    );
    await expect(page.getByText('Ended', { exact: true })).toBeVisible();
    await expect(page.getByText('Support session: you are viewing', { exact: false })).toHaveCount(0);

    // Cleanup: explicit marks, an exact dry run with reasons, and no way to execute.
    await page.goto('/admin/cleanup');
    await page.getByLabel('Batch name', { exact: true }).fill(`Journey batch ${email}`);
    await page.getByLabel('Purpose', { exact: true }).fill('Administration journey fixtures');
    await page.getByRole('button', { name: 'Create batch', exact: true }).click();
    await expect(page.getByRole('heading', { name: `Journey batch ${email}`, level: 1 })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Run a dry run', exact: true })).toBeDisabled();
    await page.getByLabel('Workspace ID', { exact: true }).fill(workspace);
    await page.getByLabel('Evidence that it is synthetic', { exact: true }).fill('Seeded by the browser journey');
    await page.getByRole('button', { name: 'Mark record', exact: true }).click();
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

    // Workspace suspension blocks its members with a plain message; reactivation restores access.
    await page.goto(`/admin/workspaces/${workspace}`);
    await page.getByRole('button', { name: 'Suspend workspace', exact: true }).click();
    await expect(page.getByText('They are not replayed on reactivation', { exact: false })).toBeVisible();
    await page.getByLabel('Reason', { exact: true }).fill('Journey workspace suspension');
    await clean(page);
    await prove();
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Suspend workspace', exact: true }),
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
        page.getByRole('button', { name: 'Reactivate workspace', exact: true }),
        'Reactivate workspace',
        info,
    );
    await expect(page.getByRole('button', { name: 'Suspend workspace', exact: true })).toBeVisible();
    expect((await page.goto('/partner/leads'))?.status()).toBe(200);

    // Audit records each action with its reason, and denied requests separately.
    await page.goto('/admin/audit');
    const events = page.getByRole('table', { name: 'Audit events' });
    // Actions read in plain language; the recorded codes stay on each event's page.
    for (const action of ['Workspace reactivated', 'Workspace suspended', 'Cleanup dry run', 'Support access ended'])
        await expect(events).toContainText(action);
    await page.goto('/admin/audit?q=grant.revoked');
    await events.getByRole('link', { name: 'Platform access revoked', exact: true }).first().click();
    await expect(page.getByText('Journey complete', { exact: true })).toBeVisible();
    await capture(page, info, 'audit-event');
    await expect(page.getByText('Not recorded in this event source').first()).toBeVisible();
    await clean(page);
    await page.goto('/admin/audit?outcome=denied');
    await expect(page.getByRole('table', { name: 'Audit events' })).toContainText('Request denied');
    await expect(
        page.getByRole('navigation', { name: 'Outcome' }).getByRole('link', { name: /^Denied/ }),
    ).toHaveAttribute('aria-current', 'true');

    // Small screens reach every module through a modal drawer that restores focus.
    if (mobile) {
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
    }

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
