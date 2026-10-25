import { confirmMutation } from './confirmations';
import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page, type TestInfo } from '@playwright/test';
import { fixture, login } from './helpers';
async function scan(page: Page, info: TestInfo, name: string): Promise<void> {
    const result = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa', 'best-practice'])
        .analyze();
    await info.attach(`axe-${name}`, {
        body: JSON.stringify({ violations: result.violations, incomplete: result.incomplete }),
        contentType: 'application/json',
    });
    expect(result.violations.map((v) => v.id)).toEqual([]);
    expect(await page.evaluate(() => document.body.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: info.outputPath(`${name}.png`), fullPage: true });
}

test('creator invites brand, brand resumes, uploads, submits and corrects; creator revokes', async ({
    page,
    browser,
}, info) => {
    test.setTimeout(120_000);
    const email = `m1-browser-onboarding-${Date.now()}@example.test`;
    fixture('seed-workspaces', email);
    await login(page, email);
    await page.getByRole('button', { name: /Role test workspace/ }).click();
    await page.getByRole('link', { name: 'Open partner portal' }).click();
    await page.getByRole('button', { name: '+ Add lead', exact: true }).click();
    await page.getByLabel('Brand name', { exact: true }).fill('Onboarding Studio');
    await page.getByLabel('Private notes (optional)').fill('PRIVATE creator notes must never be shown to brand');
    await page.getByRole('button', { name: 'Capture lead', exact: true }).click();
    await page.getByRole('button', { name: 'Start onboarding', exact: true }).click();
    const link = await page.getByLabel('Private invitation link (copy now)').inputValue();
    const ownerUrl = page.url();
    const context = await browser.newContext({
        viewport: page.viewportSize() ?? { width: 390, height: 844 },
    });
    const brand = await context.newPage();
    await brand.goto(link);
    expect(
        (
            await brand.request.post(new URL('/onboarding/session', link).href, {
                headers: { Accept: 'application/json' },
                data: {},
            })
        ).status(),
    ).toBe(419);
    await expect(brand.getByLabel('Invitation code')).not.toHaveValue('');
    await scan(brand, info, 'invitation');
    await brand.getByRole('button', { name: 'Open invitation', exact: true }).click();
    await expect(brand.getByRole('heading', { level: 1 })).toContainText('Onboarding for Onboarding Studio');
    await expect(brand.getByText('PRIVATE creator notes must never be shown to brand')).toHaveCount(0);
    const formUrl = brand.url();
    await brand.getByLabel('Business name', { exact: true }).fill('Brand business');
    await brand.getByLabel('Contact name', { exact: true }).fill('Brand Contact');
    await brand.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(brand.getByRole('status').filter({ hasText: 'Draft saved' })).toBeVisible();
    await brand.reload();
    await expect(brand.getByLabel('Business name', { exact: true })).toHaveValue('Brand business');
    await brand.getByLabel('Business email', { exact: true }).fill('brand@example.test');
    await brand.getByLabel('Collaboration brief', { exact: true }).fill('A first brief.');
    await brand.getByRole('button', { name: 'Save draft', exact: true }).focus();
    await brand.keyboard.press('Enter');
    await expect(brand.getByRole('status').filter({ hasText: 'Draft saved' })).toBeVisible();
    await brand.getByLabel('Choose reference file').setInputFiles({
        name: 'brief.txt',
        mimeType: 'text/plain',
        buffer: Buffer.from('A synthetic non-sensitive collaboration reference.'),
    });
    await brand.getByRole('button', { name: 'Upload reference', exact: true }).click();
    await expect(brand.getByRole('status').filter({ hasText: 'Upload received' })).toBeVisible();
    await expect(brand.getByRole('link', { name: 'Download brief.txt', exact: true })).toBeVisible();
    const downloadEvent = brand.waitForEvent('download');
    await brand.getByRole('link', { name: 'Download brief.txt', exact: true }).click();
    expect((await downloadEvent).suggestedFilename()).toBe('brief.txt');
    await brand.getByLabel('Choose reference file').setInputFiles({
        name: 'remove-me.txt',
        mimeType: 'text/plain',
        buffer: Buffer.from('Synthetic removable reference.'),
    });
    await brand.getByRole('button', { name: 'Upload reference', exact: true }).click();
    await expect(brand.getByRole('link', { name: 'Download remove-me.txt', exact: true })).toBeVisible();
    await confirmMutation(
        brand,
        brand.getByRole('button', { name: 'Remove remove-me.txt', exact: true }),
        'Remove document',
        info,
    );
    await expect(brand.getByRole('status').filter({ hasText: 'File removed' })).toBeVisible();
    await expect(brand.getByRole('link', { name: 'Download remove-me.txt', exact: true })).toHaveCount(0);
    await scan(brand, info, 'onboarding-form');
    await brand.getByLabel('I agree to share this form and its reference files', { exact: false }).check();
    await brand.getByRole('button', { name: 'Submit for review', exact: true }).click();
    await expect(brand.getByRole('status').filter({ hasText: 'Form submitted for review' })).toBeVisible();
    await page.goto(ownerUrl);
    await expect(page.getByText('Awaiting review', { exact: true })).toBeVisible();
    await page.getByLabel('Correction request', { exact: true }).fill('Please add timing.');
    await page.getByRole('button', { name: 'Request correction', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Correction requested' })).toBeVisible();
    await brand.goto(formUrl);
    await expect(brand.getByText('Requested correction: Please add timing.', { exact: true })).toBeVisible();
    await brand.getByLabel('Collaboration brief', { exact: true }).fill('A corrected brief with timing.');
    await brand.getByLabel('I agree to share this form and its reference files', { exact: false }).check();
    await brand.getByRole('button', { name: 'Submit for review', exact: true }).click();
    await expect(brand.getByRole('status').filter({ hasText: 'Form submitted for review' })).toBeVisible();
    await page.reload();
    await expect(page.locator('summary').filter({ hasText: 'Submission version' })).toHaveCount(2);
    await page.locator('summary').filter({ hasText: 'Submission version' }).last().click();
    await expect(page.getByText('A first brief.', { exact: true })).toBeVisible();
    await scan(page, info, 'onboarding-review');
    // WebKit can finish the redirect near the assertion timeout on a busy host.
    // Synchronize with the actual review response before checking the rendered result.
    await Promise.all([
        page.waitForResponse(
            (response) =>
                response.url() === ownerUrl && response.request().method() === 'GET' && response.status() === 200,
        ),
        page.getByRole('button', { name: 'Accept submission', exact: true }).click(),
    ]);
    await expect(page.getByRole('status').filter({ hasText: 'Onboarding accepted' })).toBeVisible();
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Create replacement invitation', exact: true }),
        'Create replacement invitation',
        info,
    );
    await expect(page.getByRole('alert').filter({ hasText: 'Previous sessions are closed' })).toBeVisible();
    expect((await brand.reload())?.status()).toBe(403);
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Revoke invitation access', exact: true }),
        'Revoke invitation access',
        info,
    );
    await expect(page.getByRole('status').filter({ hasText: 'Onboarding invitation revoked' })).toBeVisible();
    expect((await brand.reload())?.status()).toBe(403);
    await context.close();
});

test('media kit publication and inbound lead links are explicit and revocable', async ({ page, browser }, info) => {
    test.setTimeout(90_000);
    const email = `m1-browser-presence-${Date.now()}@example.test`;
    fixture('seed-workspaces', email);
    await login(page, email);
    await page.getByRole('button', { name: /Role test workspace/ }).click();
    await page.getByRole('link', { name: 'Open partner portal' }).click();
    await page.getByRole('link', { name: 'Media kit', exact: true }).click();
    await page.getByLabel('Public title', { exact: true }).fill('A public creator kit');
    await page.getByLabel('Public introduction', { exact: true }).fill('A public introduction.');
    await page.getByRole('button', { name: 'Add social account', exact: true }).click();
    await page.getByLabel('Account 1 name', { exact: true }).fill('Creator channel');
    await page.getByLabel('Account 1 URL', { exact: true }).fill('https://example.test/channel');
    await page.getByLabel('Publish this media kit', { exact: true }).check();
    await page.getByLabel('I agree to publish this title', { exact: false }).check();
    await page.getByRole('button', { name: 'Save media kit', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Media kit saved' })).toBeVisible();
    const href = await page.getByRole('link', { name: 'Open public media kit', exact: true }).getAttribute('href');
    const context = await browser.newContext({
        viewport: page.viewportSize() ?? { width: 390, height: 844 },
    });
    const publicPage = await context.newPage();
    await publicPage.goto(new URL(href!, page.url()).href);
    await expect(publicPage.getByRole('heading', { name: 'A public creator kit', exact: true })).toBeVisible();
    await expect(publicPage.getByRole('link', { name: 'Creator channel', exact: true })).toHaveAttribute(
        'href',
        'https://example.test/channel',
    );
    await scan(publicPage, info, 'media-kit');
    await page.getByRole('button', { name: 'Create lead link', exact: true }).click();
    const leadLink = await page.getByLabel('Lead link (copy now)').inputValue();
    await scan(page, info, 'public-presence');
    await publicPage.goto(leadLink);
    expect(
        (
            await publicPage.request.post(new URL('/lead', leadLink).href, {
                headers: { Accept: 'application/json' },
                data: {},
            })
        ).status(),
    ).toBe(419);
    await publicPage.getByLabel('Business name', { exact: true }).fill('Inbound Studio');
    await publicPage.getByLabel('Contact name', { exact: true }).fill('Inbound Contact');
    await publicPage.getByLabel('Business email', { exact: true }).fill('contact@example.test');
    await publicPage.getByLabel('I agree to share these details', { exact: false }).check();
    await scan(publicPage, info, 'lead-form');
    await publicPage.getByRole('button', { name: 'Share contact details', exact: true }).click();
    await expect(publicPage.getByRole('status').filter({ hasText: 'Contact details shared' })).toBeVisible();
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Revoke lead link', exact: true }),
        'Revoke lead link',
        info,
    );
    await expect(page.getByRole('status').filter({ hasText: 'Lead link revoked' })).toBeVisible();
    await page.getByLabel('Publish this media kit', { exact: true }).uncheck();
    await confirmMutation(
        page,
        page.getByRole('button', { name: 'Save media kit', exact: true }),
        'Unpublish media kit',
        info,
    );
    await expect(page.getByRole('status').filter({ hasText: 'Media kit saved' })).toBeVisible();
    expect((await publicPage.goto(new URL(href!, page.url()).href))?.status()).toBe(404);
    await page.getByRole('link', { name: 'My leads', exact: true }).click();
    await expect(page.getByRole('button', { name: /Open Inbound Studio/ })).toBeVisible();
    await context.close();
});
