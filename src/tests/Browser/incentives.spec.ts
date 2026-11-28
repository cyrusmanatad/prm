import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { fixture, login, migrationFixture, mutate, openPortalTool } from './helpers';

async function checkPage(page: Page): Promise<void> {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
}

/** Opt-in captures for visual review; Playwright writes them to its output directory outside the repository. */
async function capture(page: Page, path: string): Promise<void> {
    if (process.env.CAPTURE_BROWSER_IMAGES === '1') await page.screenshot({ path, fullPage: true });
}

test('a Brand shares an incentive, the Partner claims it and the Brand approves and fulfils it', async ({
    page,
}, info) => {
    const suffix = info.project.name + '-' + Date.now();
    const brand = 'm1-browser-incentive-brand-' + suffix + '@example.test';
    const partner = 'm1-browser-incentive-partner-' + suffix + '@example.test';
    fixture('seed', brand);
    fixture('seed', partner);
    const brandName = `Reward ${suffix}`.slice(0, 60);
    const seed = JSON.parse(migrationFixture('lead-visibility-fixture.php', brand, partner, brandName));
    const partnerName: string = seed.partners[0].name;

    // Brand: create a draft, fill the offer and share it after confirmation.
    await login(page, brand);
    expect(await mutate(page, '/workspace/select', 'POST', { org_id: seed.brand })).toBe(200);
    await page.goto('/brand/home');
    await openPortalTool(page, 'Brand', 'Incentives');
    await page.getByRole('button', { name: 'New incentive' }).click();
    const create = page.getByRole('dialog', { name: 'New incentive' });
    await expect(create.getByLabel('Incentive name')).toBeFocused();
    await create.getByLabel('Incentive name').fill('Holiday onboarding bonus');
    await create.getByRole('button', { name: 'Create draft' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Holiday onboarding bonus' })).toBeVisible();
    await page.getByRole('button', { name: 'Share incentive…' }).click();
    // Missing fields are listed in a focused summary.
    await expect(page.locator('.ic-error-summary')).toBeFocused();
    await expect(page.locator('.ic-error-summary')).toContainText('Choose at least one Partner.');
    await page.getByRole('textbox', { name: 'Amount per award' }).fill('2000');
    await page.getByRole('textbox', { name: 'Terms' }).fill('Paid by bank transfer within 30 days of approval.');
    await page.getByLabel('Ends', { exact: true }).fill('2027-12-31');
    await page.getByRole('checkbox', { name: partnerName }).check();
    await expect(page.locator('.ic-preview')).toContainText('Holiday onboarding bonus');
    await checkPage(page);
    await capture(page, info.outputPath('incentive-form.png'));
    await page.getByRole('button', { name: 'Share incentive…' }).click();
    const confirm = page.getByRole('dialog', { name: 'Share incentive?' });
    await expect(confirm.getByRole('button', { name: 'Cancel' })).toBeFocused();
    await expect(confirm).toContainText(partnerName);
    await confirm.getByRole('button', { name: 'Share incentive' }).click();
    await expect(page.locator('.wd-record-meta .wd-badge')).toHaveText('Active');
    await expect(page.getByRole('button', { name: 'End incentive…' })).toBeVisible();
    await checkPage(page);
    await capture(page, info.outputPath('incentive-record.png'));

    // Partner: claim with the lead that meets the rule.
    await page.context().clearCookies();
    await login(page, partner);
    expect(await mutate(page, '/workspace/select', 'POST', { org_id: seed.partners[0].org })).toBe(200);
    // The seeded private lead is onboarded, then registered with the Brand.
    expect(await mutate(page, `/pipeline/${seed.lead}/stage`, 'POST', { version: '1', stage: 'onboarded' })).toBe(200);
    await page.goto(`/partner/brand-sharing?lead=${seed.lead}`);
    const share = page.getByRole('dialog', { name: 'Share a lead with a Brand' });
    await share.getByRole('combobox', { name: 'Brand', exact: true }).click();
    await page.getByRole('option', { name: brandName, exact: true }).click();
    await share.getByRole('button', { name: 'Share lead', exact: true }).click();
    await expect(share).toHaveCount(0);
    await page.goto('/partner/home');
    await openPortalTool(page, 'Partner', 'Incentives');
    const card = page.locator('.ic-offer', { hasText: 'Holiday onboarding bonus' });
    await expect(card).toContainText('1 lead ready');
    await card.getByRole('button', { name: 'Claim award…' }).click();
    const claim = page.getByRole('dialog', { name: 'Claim award' });
    await claim.getByRole('checkbox', { name: /Kanto Cafe linens/ }).check();
    await expect(claim.locator('#claim-count')).toHaveText('1 of 1 selected');
    await capture(page, info.outputPath('claim-dialog.png'));
    expect((await new AxeBuilder({ page }).include('dialog[open]').analyze()).violations).toEqual([]);
    await claim.getByRole('button', { name: 'Submit claim' }).click();
    await expect(page.getByText('Claim sent to the Brand for review.').first()).toBeVisible();
    await expect(page.getByRole('table', { name: 'Your awards' })).toContainText('In review');
    await checkPage(page);
    await capture(page, info.outputPath('partner-incentives.png'));

    // Brand: review the claim in the side panel, approve it and record delivery.
    await page.context().clearCookies();
    await login(page, brand);
    expect(await mutate(page, '/workspace/select', 'POST', { org_id: seed.brand })).toBe(200);
    await page.goto('/brand/incentives?tab=awards');
    await page.getByRole('link', { name: partnerName }).click();
    const panel = page.getByRole('dialog', { name: `Award for ${partnerName}` });
    await expect(panel).toContainText('Meets the rule');
    await capture(page, info.outputPath('award-panel.png'));
    expect((await new AxeBuilder({ page }).include('dialog[open]').analyze()).violations).toEqual([]);
    await panel.getByRole('button', { name: 'Approve award' }).click();
    await expect(panel.getByText('Award approved.')).toBeVisible();
    await expect(panel.getByLabel('Delivered on')).toBeFocused();
    await expect(panel.getByRole('button', { name: 'Mark fulfilled' })).toBeVisible();
    await panel.getByLabel('Reference (optional)').fill('BDO 0042');
    await panel.getByRole('button', { name: 'Mark fulfilled' }).click();
    await expect(panel.getByText('Award marked fulfilled.')).toBeVisible();
    await expect(panel).toContainText('Marked fulfilled · BDO 0042');
    await page.keyboard.press('Escape');
    await expect(panel).toBeHidden();
    await capture(page, info.outputPath('brand-incentives.png'));
});
