import { test, expect, type Page, type TestInfo } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { createHmac } from 'node:crypto';
import { confirmMutation } from './confirmations';
import { fixture, login, migrationFixture, mutate, reviewWidths, smallTouchTargets } from './helpers';

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
/** No accessibility violations, no page-level overflow and no undersized touch targets at each reference width. */
async function clean(page: Page, info: TestInfo, name: string) {
    for (const width of reviewWidths(info)) {
        await page.setViewportSize({ width, height: 1000 });
        expect((await new AxeBuilder({ page }).analyze()).violations, `${name} at ${width}px`).toEqual([]);
        expect(
            await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
            `${name} overflows at ${width}px`,
        ).toBe(true);
        expect(await smallTouchTargets(page), `${name} touch targets at ${width}px`).toEqual([]);
        // Optional temporary captures for visual inspection; written only to the test output directory.
        if (process.env.CAPTURE_BROWSER_IMAGES === '1')
            await page.screenshot({ path: info.outputPath(`${name}-${width}.png`), fullPage: true });
    }
    await page.setViewportSize({ width: reviewWidths(info)[0]!, height: 1000 });
}
const fill = async (page: Page, prefix: string, website: string) => {
    await page.locator(`#${prefix}-name`).fill('Northwind Browser Brand');
    await page.locator(`#${prefix}-website`).fill(website);
    await page.locator(`#${prefix}-role`).fill('Marketing lead');
    await page.locator(`#${prefix}-use`).fill('Run our partner programme with twenty creators.');
};

test('a Brand registrant requests a workspace, answers a question and opens the workspace once approved', async ({
    page,
    browser,
}, info) => {
    test.setTimeout(300_000);
    await page.setViewportSize({ width: reviewWidths(info)[0]!, height: 1000 });
    const stamp = `${info.project.name}-${Date.now()}`;
    const email = `m1-browser-brand-applicant-${stamp}@example.test`;

    // Registration and verification lead to the request form. Nothing is created.
    await page.goto('/brand/register');
    await page.getByLabel('Your name').fill('Ana Browser Applicant');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    await expect(page).toHaveURL(/\/verify-email$/);
    await page.goto(fixture('verify', email).url);
    await expect(page).toHaveURL(/\/brand\/requests$/);
    await expect(page.getByRole('heading', { name: 'Request a Brand workspace', level: 1 })).toBeVisible();
    await expect(page.getByText('One step remains')).toBeVisible();
    await expect(page.getByText(`email updates about this request at ${email}`)).toBeVisible();
    await clean(page, info, 'brand-request-form');

    // The workspace selector offers a request instead of creating a Brand workspace, and the endpoint refuses one.
    await page.goto('/workspaces');
    await page.locator('#workspace-kind').selectOption('brand');
    await expect(page.getByRole('link', { name: 'Request Brand workspace', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Create workspace', exact: true })).toHaveCount(0);
    expect(await mutate(page, '/workspaces', 'POST', { name: 'Bypass', kind: 'brand' })).toBe(422);
    await clean(page, info, 'workspace-selector-brand');
    await page.getByRole('link', { name: 'Request Brand workspace', exact: true }).click();
    await expect(page).toHaveURL(/\/brand\/requests$/);

    // A server validation error keeps what was typed and moves focus to the field.
    await fill(page, 'brand', 'northwind.example.test');
    await page.getByRole('button', { name: 'Submit for review', exact: true }).click();
    await expect(page.getByText('Enter a full web address starting with http:// or https://.')).toBeVisible();
    await expect(page.locator('#brand-website')).toBeFocused();
    await expect(page.locator('#brand-name')).toHaveValue('Northwind Browser Brand');
    await clean(page, info, 'brand-request-error');
    await page.locator('#brand-website').fill('https://northwind.example.test/about');
    await page.getByRole('button', { name: 'Submit for review', exact: true }).click();
    await expect(page).toHaveURL(/\/brand\/requests\/[0-9a-f-]{36}$/);
    const requestUrl = page.url();
    await expect(page.getByText('In review', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Resubmit for review' })).toHaveCount(0);
    await clean(page, info, 'brand-request-pending');
    await page.goto('/brand/requests');
    await expect(page.getByRole('button', { name: 'Submit for review' })).toHaveCount(0);
    await expect(page.getByText('one request open at a time')).toBeVisible();
    await page.goto('/workspaces');
    await expect(page.getByText('You don’t have an active workspace yet')).toBeVisible();

    // A platform operator reviews the request in a separate session.
    const adminEmail = `m1-browser-admin-brand-${stamp}@example.test`;
    fixture('seed', adminEmail);
    const { secret } = JSON.parse(migrationFixture('admin-fixture.php', adminEmail));
    const context = await browser.newContext();
    const admin = await context.newPage();
    await admin.setViewportSize({ width: reviewWidths(info)[0]!, height: 1000 });
    try {
        await login(admin, adminEmail);
        await admin.goto('/admin/brand-requests');
        await expect(admin).toHaveURL(/\/admin\/security$/);
        await admin.getByLabel('Password', { exact: true }).fill(password);
        await admin
            .getByLabel('Authenticator code', { exact: true })
            .fill(totp(secret, Math.floor(Date.now() / 30000)));
        await admin.getByRole('button', { name: 'Verify and continue', exact: true }).click();
        await expect(admin).toHaveURL(/\/admin\/brand-requests$/);
        await expect(admin.getByRole('heading', { name: 'Brand requests', level: 1 })).toBeVisible();
        await clean(admin, info, 'admin-brand-requests');
        await admin.goto('/admin');
        await expect(admin.getByRole('link', { name: 'Review Brand requests' })).toBeVisible();
        await admin.goto(`/admin/brand-requests?q=${encodeURIComponent(email)}`);
        await admin.getByRole('link', { name: 'Northwind Browser Brand', exact: true }).click();
        await expect(admin.getByRole('heading', { name: 'Northwind Browser Brand', level: 1 })).toBeVisible();
        await expect(admin.getByText('Awaiting decision', { exact: true })).toBeVisible();
        await expect(admin.getByText(email)).toBeVisible();
        await clean(admin, info, 'admin-brand-request');

        // Asking for information needs feedback and no confirmation; entered feedback survives a validation error.
        await admin.locator('#brand-feedback').fill('Please link the page that shows your company registration.');
        await admin.getByRole('button', { name: 'Record decision', exact: true }).click();
        await expect(admin.getByText('The decision field is required.')).toBeVisible();
        await expect(admin.locator('#brand-feedback')).toHaveValue(
            'Please link the page that shows your company registration.',
        );
        await admin.locator('#brand-decision').selectOption('request_information');
        await admin.getByRole('button', { name: 'Request more information', exact: true }).click();
        await expect(admin.getByText('Waiting for applicant', { exact: true })).toBeVisible();
        await expect(admin.getByText('Waiting for the applicant to update and resubmit')).toBeVisible();

        // The applicant reads the feedback, revises and resubmits; the earlier submission stays in the history.
        await page.goto(requestUrl);
        await expect(page.getByText('More information needed', { exact: true })).toBeVisible();
        await expect(
            page.getByText('Please link the page that shows your company registration.').first(),
        ).toBeVisible();
        await clean(page, info, 'brand-request-needs-information');
        await page.locator('#revise-website').fill('https://northwind.example.test/registration');
        await page.getByRole('button', { name: 'Resubmit for review', exact: true }).click();
        await expect(page.getByText('In review', { exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Request resubmitted', exact: true })).toBeVisible();
        await expect(page.getByText('https://northwind.example.test/about')).toBeVisible();

        // Approval is confirmed in the shared dialog, which names the applicant, the Brand and the consequence.
        await admin.reload();
        await expect(admin.getByText('Awaiting decision', { exact: true })).toBeVisible();
        await admin.locator('#brand-decision').selectOption('approve');
        await admin.locator('#brand-feedback').fill('Welcome to WeavDash.');
        const approve = admin.getByRole('button', { name: 'Approve request', exact: true });
        await approve.click();
        const dialog = admin.getByRole('dialog', { name: 'Approve request?', exact: true });
        await expect(dialog).toContainText(`Ana Browser Applicant (${email})`);
        await expect(dialog).toContainText('A Brand workspace named Northwind Browser Brand is created now');
        await expect(dialog).toContainText('You do not become a member');
        await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
        await confirmMutation(admin, approve, 'Approve request', info);
        await expect(admin.getByText('Approved', { exact: true }).first()).toBeVisible();
        await expect(admin.getByRole('button', { name: 'Approve request' })).toHaveCount(0);
        await clean(admin, info, 'admin-brand-request-approved');
        // The workspace detail shows who approved it, when, why and how; the reviewer is not a member.
        await admin.getByRole('link', { name: 'Northwind Browser Brand', exact: true }).click();
        await expect(admin).toHaveURL(/\/admin\/workspaces\/[0-9a-f-]{36}$/);
        const approval = admin.getByRole('region', { name: 'Brand approval' });
        await expect(approval).toContainText('Browser Creator');
        await expect(approval).toContainText('Welcome to WeavDash.');
        await expect(approval).toContainText('Request');
        await clean(admin, info, 'admin-workspace-approval');
        await admin.goto(`${admin.url()}?tab=members`);
        await expect(admin.getByRole('link', { name: 'Ana Browser Applicant', exact: true })).toBeVisible();
        await expect(admin.getByRole('table').getByText('Browser Creator')).toHaveCount(0);
        // The outcome email is listed by account in delivery diagnostics, without the address.
        await admin.goto('/admin/notifications?q=Ana%20Browser%20Applicant');
        await expect(admin.getByText('Account: Ana Browser Applicant').first()).toBeVisible();
        await expect(admin.getByText('brand_request.approved').first()).toBeVisible();
        await expect(admin.getByText(email)).toHaveCount(0);

        // The applicant's active workspace did not change during the reviewer's session; opening is their choice.
        await page.goto('/workspaces');
        await expect(page.getByRole('button', { name: /Northwind Browser Brand/ })).toHaveAttribute(
            'aria-pressed',
            'false',
        );
        await page.goto(requestUrl);
        await expect(page.getByText('Approved', { exact: true })).toBeVisible();
        await clean(page, info, 'brand-request-approved');
        await page.getByRole('button', { name: 'Open Brand workspace', exact: true }).click();
        await expect(page).toHaveURL(/\/brand$/);
        await expect(page.getByRole('heading', { name: 'Partner hub', exact: true })).toBeVisible();

        // A declined request is closed with feedback, and a new request is allowed.
        const secondEmail = `m1-browser-brand-declined-${stamp}@example.test`;
        fixture('seed', secondEmail);
        const second = await (await browser.newContext()).newPage();
        await second.setViewportSize({ width: reviewWidths(info)[0]!, height: 1000 });
        await login(second, secondEmail);
        await second.goto('/brand/requests');
        await expect(second.getByText('One step remains')).toHaveCount(0);
        await fill(second, 'brand', 'https://declined.example.test');
        await second.getByRole('button', { name: 'Submit for review', exact: true }).click();
        await expect(second).toHaveURL(/\/brand\/requests\/[0-9a-f-]{36}$/);
        await admin.goto(`/admin/brand-requests/${second.url().split('/').pop()}`);
        await admin.locator('#brand-decision').selectOption('decline');
        await admin.locator('#brand-feedback').fill('We could not confirm this business.');
        await confirmMutation(
            admin,
            admin.getByRole('button', { name: 'Decline request', exact: true }),
            'Decline request',
            info,
        );
        await expect(admin.getByText('Declined', { exact: true }).first()).toBeVisible();
        await second.reload();
        await expect(second.getByText('Not approved', { exact: true })).toBeVisible();
        await expect(second.getByText('We could not confirm this business.').first()).toBeVisible();
        await clean(second, info, 'brand-request-declined');
        await second.getByRole('link', { name: 'Submit a new request', exact: true }).click();
        await expect(second.getByRole('button', { name: 'Submit for review', exact: true })).toBeVisible();
        await second.context().close();
    } finally {
        await context.close();
    }
});
