import { test, expect } from '@playwright/test';
import { changeRole, fixture, login, mutate } from './helpers';

test('all five roles enforce server permissions and revoked pages drop stale workspace data', async ({ page }) => {
    test.setTimeout(90_000);
    const email = `m1-browser-roles-${Date.now()}@example.test`;
    const { own, foreign } = fixture('seed-workspaces', email);
    await login(page, email);
    expect(await mutate(page, '/workspace/select', 'POST', { org_id: own })).toBe(200);
    for (const role of ['owner', 'admin', 'manager', 'member', 'viewer']) {
        changeRole(email, role);
        await page.reload();
        const settings = page.getByRole('region', { name: 'Role test workspace', exact: true });
        await expect(settings).toBeVisible();
        await expect(settings.getByRole('button', { name: 'Save name' })).toHaveCount(['owner', 'admin'].includes(role) ? 1 : 0);
        expect(await mutate(page, '/workspace/select', 'POST', { org_id: foreign })).toBe(403);
        expect(await mutate(page, '/workspace', 'PATCH', { name: 'Role test workspace', org_id: foreign, role: 'owner' }))
            .toBe(['owner', 'admin'].includes(role) ? 200 : 403);
        await expect(page.getByText('Hidden foreign workspace')).toHaveCount(0);
    }
    changeRole(email, 'revoked');
    expect(await mutate(page, '/workspace', 'PATCH', { name: 'Revoked write' })).toBe(403);
    await page.reload();
    await expect(page.getByRole('alert')).toContainText('Your access to the previous workspace has changed');
    await expect(page.getByText('Role test workspace', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Save name' })).toHaveCount(0);
});

test('session fixation, CSRF failure, logout replay, and reset of two live sessions', async ({ page, browser }, info) => {
    test.skip(info.project.name === 'mobile', 'Viewport-independent session attacks run once; mobile recovery/revocation remains covered by foundation journeys.');
    test.setTimeout(60_000);
    const email = `m1-browser-replay-${Date.now()}@example.test`;
    fixture('seed', email);
    await page.goto('/login');
    const beforeLogin = await page.context().cookies();
    const denied = await page.request.post('/login', {
        headers: { Accept: 'application/json', 'Sec-Fetch-Site': 'cross-site' },
        data: { email, password: 'browser-password-123' },
    });
    expect(denied.status()).toBe(419);
    await login(page, email);
    const authenticated = await page.context().cookies();
    const replay = await browser.newContext({ baseURL: info.project.use.baseURL });
    const other = await browser.newContext({ baseURL: info.project.use.baseURL });
    const reset = await browser.newContext({ baseURL: info.project.use.baseURL });
    try {
        await replay.addCookies(beforeLogin);
        expect((await replay.request.get('/account', { headers: { Accept: 'application/json' } })).status()).toBe(401);
        await page.getByRole('button', { name: 'Sign out', exact: true }).click();
        await expect(page).toHaveURL(/\/login$/);
        await replay.clearCookies();
        await replay.addCookies(authenticated);
        expect((await replay.request.get('/account', { headers: { Accept: 'application/json' } })).status()).toBe(401);
        await login(page, email);
        const second = await other.newPage();
        await login(second, email);
        const resetPage = await reset.newPage();
        const resetUrl = fixture('reset', email).url;
        await resetPage.goto(resetUrl);
        await resetPage.getByLabel('Password', { exact: true }).fill('replaced-password-789');
        await resetPage.getByLabel('Confirm password').fill('replaced-password-789');
        await resetPage.getByRole('button', { name: 'Reset password', exact: true }).click();
        await expect(resetPage).toHaveURL(/\/login$/);
        for (const session of [page, second]) {
            expect((await session.request.get('/account', { headers: { Accept: 'application/json' } })).status()).toBe(401);
            await session.reload();
            await expect(session).toHaveURL(/\/login$/);
        }
        // A consumed reset capability cannot change the password again.
        await resetPage.goto(resetUrl);
        await resetPage.getByLabel('Password', { exact: true }).fill('replayed-password-000');
        await resetPage.getByLabel('Confirm password').fill('replayed-password-000');
        await resetPage.getByRole('button', { name: 'Reset password', exact: true }).click();
        await expect(resetPage.getByRole('alert')).toBeVisible();
        await login(resetPage, email, 'replaced-password-789');
    } finally {
        await Promise.all([replay.close(), other.close(), reset.close()]);
    }
});
