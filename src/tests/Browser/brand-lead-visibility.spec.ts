import { test, expect, type Page, type TestInfo } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { confirmMutation } from './confirmations';
import { dismissNotifications } from './feedback';
import {
    fixture,
    isMobile,
    login,
    migrationFixture,
    mutate,
    openBrandHub,
    openPortalTool,
    portalNavigation,
    reviewWidths,
    smallTouchTargets,
    reloadAndSettleHistory,
} from './helpers';

type Seed = { brand: string; lead: string; partners: { org: string; relationship: string; name: string }[] };

/** Accessibility scan, no document overflow and touch targets, at each review width for this project. */
async function review(page: Page, info: TestInfo, label: string): Promise<void> {
    for (const width of reviewWidths(info)) {
        await page.setViewportSize({ width, height: 900 });
        const scan = await new AxeBuilder({ page }).analyze();
        expect(scan.violations, `${label} at ${width}px`).toEqual([]);
        expect(
            await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
            `${label} overflows at ${width}px`,
        ).toBe(true);
        if (isMobile(info)) expect(await smallTouchTargets(page), `${label} touch targets at ${width}px`).toEqual([]);
    }
    await page.setViewportSize({ width: reviewWidths(info)[0]!, height: 900 });
    // Temporary inspection images only, written to the Playwright output directory outside the repository.
    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
        await page.screenshot({
            path: info.outputPath(`${label.toLowerCase().replaceAll(' ', '-')}.png`),
            fullPage: true,
        });
}

test('a partner registers a lead and the brand follows it through the hub, list, records and paging', async ({
    browser,
}, info) => {
    test.setTimeout(180_000);
    const suffix = `${info.project.name}-${Date.now()}`;
    const partnerEmail = `m1-browser-blv-partner-${suffix}@example.test`;
    const brandEmail = `m1-browser-blv-brand-${suffix}@example.test`;
    const brandName = `Northline ${suffix}`.slice(0, 60);
    fixture('seed', partnerEmail);
    fixture('seed', brandEmail);
    const seed: Seed = JSON.parse(migrationFixture('lead-visibility-fixture.php', brandEmail, partnerEmail, brandName));
    const [first, second] = seed.partners;
    const partner = await browser.newContext();
    const brand = await browser.newContext();
    const pp = await partner.newPage();
    const bp = await brand.newPage();
    try {
        // Brand: nothing is shared yet by the first partner; seeded registrations of another partner are counted.
        await login(bp, brandEmail);
        await openBrandHub(bp, brandName);
        await expect(bp.getByRole('heading', { name: 'Partner hub', exact: true })).toBeVisible();
        const summary = bp.getByRole('region', { name: 'Program summary' });
        await expect(summary).toContainText('Active partners13');
        await expect(summary).toContainText('Leads registered3');
        const roster = bp.getByRole('region', { name: 'Partners table' });
        await expect(roster.getByRole('row')).toHaveCount(11);
        const firstRow = roster.getByRole('row', { name: new RegExp(first!.name) });
        await expect(firstRow.getByRole('link', { name: /0 leads registered by/ })).toHaveText('0');
        await expect(firstRow).toContainText('Not yet recorded');
        await expect(
            roster
                .getByRole('row', { name: new RegExp(second!.name) })
                .getByRole('link', { name: /leads registered by/ }),
        ).toHaveText('2');
        await expect(bp.getByRole('navigation', { name: 'Partners pages' })).toContainText(
            'Showing 1–10 of 13 matching partners',
        );
        await review(bp, info, 'Brand hub');

        // Partner: the private lead is registered explicitly from its drawer.
        await pp.goto('/partner/login');
        await pp.getByLabel('Email address').fill(partnerEmail);
        await pp.getByLabel('Password', { exact: true }).fill('browser-password-123');
        await pp.getByRole('button', { name: 'Sign in', exact: true }).click();
        await expect(pp).toHaveURL(/\/partner\/leads$/);
        await pp.goto(`/partner/leads?entry=${seed.lead}`);
        await pp.getByRole('link', { name: 'Share with a Brand', exact: true }).click();
        await expect(pp).toHaveURL(new RegExp(`/partner/brand-sharing\\?lead=${seed.lead}$`));
        await expect(pp.getByRole('heading', { name: 'Brand sharing', level: 1 })).toBeVisible();
        await expect(pp.getByText('Nothing from your pipeline is visible to any Brand.')).toBeVisible();
        // Arriving from the lead opens the share dialog with that lead chosen.
        await expect(pp.getByRole('dialog', { name: 'Share a lead with a Brand' })).toBeVisible();
        await expect(pp.getByRole('note')).toContainText('Never shared');
        await review(pp, info, 'Brand sharing form');
        await pp.getByRole('combobox', { name: 'Brand', exact: true }).click();
        await pp.getByRole('option', { name: brandName, exact: true }).click();
        await expect(pp.getByLabel('Lead name shown to the Brand')).toHaveValue('Kanto Cafe linens');
        await pp.getByLabel('Lead name shown to the Brand').fill('Kanto Cafe chain');
        await pp.getByLabel('Summary for the Brand (optional)').fill('Table linens for six branches');
        await pp.getByRole('button', { name: 'Share lead', exact: true }).click();
        await expect(pp).toHaveURL(/\/partner\/brand-sharing$/);
        const own = pp.getByRole('region', { name: 'Leads registered with Brands table' });
        await expect(own.getByRole('row', { name: /Kanto Cafe chain/ })).toContainText('Shared');
        await expect(pp.getByRole('dialog', { name: 'Share a lead with a Brand' })).toHaveCount(0);
        await expect(pp.getByRole('region', { name: 'Connected Brands' })).toContainText('1 lead shared');
        await dismissNotifications(pp);
        await review(pp, info, 'Brand sharing list');
        // The shared profile is optional; an unsafe address is refused with a field error.
        await pp.getByRole('button', { name: 'Edit shared profile', exact: true }).click();
        await pp.getByLabel('Website or public profile').fill('javascript:alert(1)');
        await pp.getByRole('button', { name: 'Save shared profile', exact: true }).click();
        await expect(pp.getByLabel('Website or public profile')).toHaveAttribute('aria-invalid', 'true');
        await pp.getByLabel('Website or public profile').fill('https://example.test/roster-partner');
        await pp.getByLabel('Business contact name').fill('Bea Santos');
        await pp.getByRole('button', { name: 'Save shared profile', exact: true }).click();
        await expect(pp.getByRole('dialog', { name: 'Edit shared profile' })).toHaveCount(0);
        await expect(pp.getByRole('region', { name: 'Your shared profile' })).toContainText(
            'https://example.test/roster-partner',
        );
        await expect(pp.getByRole('button', { name: 'Edit shared profile', exact: true })).toBeFocused();
        await dismissNotifications(pp);

        // Brand: totals, roster figures and activity now include the registration and the sign-in.
        await bp.reload();
        await expect(summary).toContainText('Leads registered4');
        await expect(firstRow.getByRole('link', { name: /1 leads registered by/ })).toHaveText('1');
        await expect(firstRow).toContainText('Lead registered');
        await expect(firstRow.locator('time')).toHaveAttribute('title', /\d{4}/);
        // Paging keeps totals, and Back/Forward and reload keep the page.
        await bp.getByRole('navigation', { name: 'Partners pages' }).getByRole('link', { name: 'Next' }).click();
        await expect(bp).toHaveURL(/\/brand\?page=2$/);
        await expect(roster.getByRole('row')).toHaveCount(4);
        await expect(summary).toContainText('Active partners13');
        await expect(
            bp.getByRole('region', { name: 'Partners', exact: true }).getByText('All 13', { exact: true }),
        ).toBeVisible();
        // Back must not race Inertia's asynchronous history write for the reloaded page.
        await reloadAndSettleHistory(bp);
        await expect(roster.getByRole('row')).toHaveCount(4);
        await bp.goBack();
        await expect(bp).toHaveURL(/\/brand$/);
        await expect(roster.getByRole('row')).toHaveCount(11);
        // Search narrows the roster only, and returns to the first page.
        await bp.getByLabel('Search partners by name').fill('Roster Partner 1');
        await bp.getByRole('button', { name: 'Search', exact: true }).click();
        await expect(bp).toHaveURL(/\/brand\?q=Roster(\+|%20)Partner(\+|%20)1$/);
        await expect(roster.getByRole('row')).toHaveCount(5);
        await expect(summary).toContainText('Active partners13');
        await bp.getByRole('button', { name: 'Clear search', exact: true }).click();
        await expect(roster.getByRole('row')).toHaveCount(11);

        // The 30-day count opens exactly the matching registrations.
        await roster
            .getByRole('row', { name: new RegExp(second!.name) })
            .getByRole('link', { name: /leads registered by/ })
            .click();
        await expect(bp).toHaveURL(
            new RegExp(`/brand/lead-registrations\\?partner=${second!.relationship}&window=30d$`),
        );
        await expect(bp.getByRole('heading', { name: 'Lead registrations', level: 1 })).toBeVisible();
        await expect(
            (await portalNavigation(bp, 'Brand')).getByRole('link', { name: 'Lead registrations' }),
        ).toHaveAttribute('aria-current', 'page');
        await bp.keyboard.press('Escape');
        const list = bp.getByRole('region', { name: 'Lead registrations table' });
        await expect(list.getByRole('row')).toHaveCount(3);
        await expect(bp.getByRole('navigation', { name: 'Lead registrations pages' })).toContainText(
            'of 2 matching registrations',
        );
        // Phones keep Partner, Registered and Sort behind a Filters button.
        if (isMobile(info)) await bp.getByRole('button', { name: /^Filters/ }).click();
        await expect(bp.getByRole('combobox', { name: 'Partner' })).toContainText(second!.name);
        await expect(bp.getByRole('combobox', { name: 'Registered' })).toContainText('Last 30 days');
        await review(bp, info, 'Lead registrations');
        await bp.getByRole('button', { name: 'Clear filters', exact: true }).click();
        await expect(bp).toHaveURL(/\/brand\/lead-registrations$/);
        await expect(list.getByRole('row')).toHaveCount(5);
        await expect(bp.locator('body')).not.toContainText('Private journey note 4242');
        await expect(bp.locator('body')).not.toContainText('(private)');
        // The summary card opens the same unfiltered list.
        await openPortalTool(bp, 'Brand', 'Partner hub');
        await summary.getByRole('link', { name: 'View lead registrations' }).click();
        await expect(bp.getByRole('navigation', { name: 'Lead registrations pages' })).toContainText(
            'of 4 matching registrations',
        );

        // Stage chips (a Stage select on phones) count registrations and filter as they are chosen.
        if (isMobile(info)) {
            await expect(bp.getByRole('combobox', { name: 'Stage' })).toContainText('All stages (4)');
        } else {
            const chips = bp.getByRole('navigation', { name: 'Filter by stage' });
            await expect(chips.getByRole('link', { name: 'All 4' })).toHaveAttribute('aria-current', 'true');
        }
        // Sorting by lead name keeps working with the list state.
        if (isMobile(info)) {
            // Phones show cards without column headers; the sort order has its own control.
            await bp.getByRole('button', { name: /^Filters/ }).click();
            await bp.getByRole('combobox', { name: 'Sort by' }).click();
            await bp.getByRole('option', { name: 'Lead name A–Z' }).click();
        } else {
            await list.getByRole('link', { name: 'Lead, sort ascending' }).click();
        }
        await expect(bp).toHaveURL(/\/brand\/lead-registrations\?sort=lead_asc$/);
        await expect(list.getByRole('columnheader', { name: /^Lead/ })).toHaveAttribute('aria-sort', 'ascending');
        await bp.goto('/brand/lead-registrations');

        // Filter as the name is typed, open a lead in the drawer over the list, and close it to the same list.
        await bp.getByLabel('Lead name', { exact: true }).fill('kanto');
        await expect(bp).toHaveURL(/\/brand\/lead-registrations\?q=kanto$/);
        await expect(list.getByRole('row')).toHaveCount(2);
        await expect(bp.getByLabel('Lead name', { exact: true })).toHaveValue('kanto');
        await list.getByRole('link', { name: 'Kanto Cafe chain', exact: true }).click();
        await expect(bp).toHaveURL(/\/brand\/lead-registrations\?q=kanto&registration=[0-9a-f-]+$/);
        const shared = bp.getByRole('dialog', { name: 'Kanto Cafe chain' });
        await expect(shared).toBeVisible();
        await expect(shared).toContainText('Table linens for six branches');
        await expect(shared).toContainText('Not shared by the partner');
        await expect(shared).toContainText('Lead');
        for (const hidden of [
            'Private journey note 4242',
            'ana.reyes@example.test',
            'Ana Reyes',
            'Kanto Cafe linens',
            '40,000',
        ])
            await expect(bp.locator('body')).not.toContainText(hidden);
        await expect(shared.getByRole('button')).toHaveCount(1); // Only Close: the record is read-only.
        await expect(shared.getByRole('button', { name: 'Close lead registration' })).toBeFocused();
        await review(bp, info, 'Registered lead');
        await shared.getByRole('button', { name: 'Close lead registration' }).click();
        await expect(bp).toHaveURL(/\/brand\/lead-registrations\?q=kanto$/);
        await expect(shared).toHaveCount(0);
        await expect(list.getByRole('row')).toHaveCount(2);
        await bp.goBack();
        await expect(shared).toBeVisible();

        // Partner record: shared profile, figures and this partner's registrations only.
        await shared.getByRole('link', { name: /Open partner/ }).click();
        await expect(bp).toHaveURL(new RegExp(`/brand/partners/${first!.relationship}$`));
        await expect(bp.getByRole('heading', { name: first!.name, level: 1 })).toBeVisible();
        await expect(bp.getByRole('region', { name: 'Shared profile' })).toContainText('Bea Santos');
        await expect(bp.getByRole('link', { name: 'https://example.test/roster-partner' })).toHaveAttribute(
            'rel',
            /noopener/,
        );
        await expect(bp.getByRole('region', { name: 'Shared profile' })).toContainText('Not shared');
        await expect(bp.locator('body')).not.toContainText(partnerEmail);
        await expect(bp.getByRole('region', { name: 'Partnership', exact: true })).toContainText('Lead registered');
        const related = bp.getByRole('region', { name: `Leads registered by ${first!.name} table` });
        await expect(related.getByRole('row')).toHaveCount(2);
        await review(bp, info, 'Partner record');
        // From the Partner record a lead opens in the drawer over that partner's registrations.
        await related.getByRole('link', { name: 'Kanto Cafe chain', exact: true }).click();
        await expect(bp).toHaveURL(
            new RegExp(`/brand/lead-registrations\\?partner=${first!.relationship}&registration=[0-9a-f-]+$`),
        );
        await expect(list.getByRole('row')).toHaveCount(2);
        await bp
            .getByRole('dialog', { name: 'Kanto Cafe chain' })
            .getByRole('link', { name: /Open partner/ })
            .click();
        await expect(bp).toHaveURL(new RegExp(`/brand/partners/${first!.relationship}$`));

        // A stage move by the partner reaches the Brand without re-registration.
        expect(await mutate(pp, `/pipeline/${seed.lead}/stage`, 'POST', { stage: 'negotiating', version: '1' })).toBe(
            200,
        );
        await bp.reload();
        await expect(related.getByRole('row', { name: /Kanto Cafe chain/ })).toContainText('Negotiating');
        await expect(bp.getByRole('region', { name: 'Partnership', exact: true })).toContainText('Lead updated');

        // Withdrawing is confirmed and removes the Brand's access at once.
        await pp.reload();
        await confirmMutation(
            pp,
            pp.getByRole('button', { name: 'Withdraw registration of Kanto Cafe chain', exact: true }),
            'Withdraw registration',
            info,
        );
        await expect(own.getByRole('row', { name: /Kanto Cafe chain/ })).toContainText(
            'You withdrew this registration.',
        );
        await bp.reload();
        await expect(bp.getByText('No leads registered yet')).toBeVisible();
        await bp.goto('/brand/lead-registrations');
        await expect(list.getByRole('row')).toHaveCount(4);
        await expect(bp.locator('body')).not.toContainText('Kanto Cafe chain');

        // Ending a partnership from its icon button: keyboard, confirmation, and a last page that empties.
        await bp.goto('/brand?page=2');
        await expect(roster.getByRole('row')).toHaveCount(4);
        const last = seed.partners[12]!;
        const end = bp.getByRole('button', { name: `End partnership with ${last.name}`, exact: true });
        await end.focus();
        await bp.keyboard.press('Enter');
        const dialog = bp.getByRole('dialog', { name: 'End partnership?', exact: true });
        await expect(dialog).toContainText(last.name);
        await expect(dialog).toContainText('Shared relationship access ends on both sides');
        await expect(dialog.getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
        await bp.keyboard.press('Escape');
        await expect(end).toBeFocused();
        for (const row of seed.partners.slice(10)) {
            await bp.goto('/brand?page=2');
            await confirmMutation(
                bp,
                bp.getByRole('button', { name: `End partnership with ${row.name}`, exact: true }),
                'End partnership',
                info,
            );
            await expect(bp).toHaveURL(/\/brand$/);
            await dismissNotifications(bp);
        }
        await expect(summary).toContainText('Active partners10');
        await bp.goto('/brand?page=2');
        await expect(roster.getByRole('row')).toHaveCount(11);
        await expect(bp.getByRole('navigation', { name: 'Partners pages' })).toContainText(
            'Showing 1–10 of 10 matching partners',
        );
    } finally {
        await partner.close();
        await brand.close();
    }
});

test('a partner can name the brand while adding a lead, or leave it private', async ({ browser }, info) => {
    test.setTimeout(120_000);
    const suffix = `${info.project.name}-${Date.now()}`;
    const partnerEmail = `m1-browser-blv-capture-partner-${suffix}@example.test`;
    const brandEmail = `m1-browser-blv-capture-brand-${suffix}@example.test`;
    const brandName = `Capture ${suffix}`.slice(0, 60);
    fixture('seed', partnerEmail);
    fixture('seed', brandEmail);
    const seed: Seed = JSON.parse(migrationFixture('lead-visibility-fixture.php', brandEmail, partnerEmail, brandName));
    const partner = await browser.newContext();
    const brand = await browser.newContext();
    const pp = await partner.newPage();
    const bp = await brand.newPage();
    try {
        await pp.goto('/partner/login');
        await pp.getByLabel('Email address').fill(partnerEmail);
        await pp.getByLabel('Password', { exact: true }).fill('browser-password-123');
        await pp.getByRole('button', { name: 'Sign in', exact: true }).click();
        await expect(pp).toHaveURL(/\/partner\/leads$/);
        const add = async (name: string, forBrand: boolean) => {
            await pp.getByRole('button', { name: '+ Add lead', exact: true }).click();
            const dialog = pp.getByRole('dialog', { name: 'Add lead', exact: true });
            await expect(dialog).toBeVisible();
            const choice = dialog.getByRole('combobox', { name: 'Share with a Brand (optional)', exact: true });
            // Optional and private by default.
            await expect(choice).toHaveText('No Brand — keep private');
            await expect(dialog).toContainText('Notes, value and contact stay private.');
            await pp.getByLabel('Brand name', { exact: true }).fill(name);
            if (forBrand) {
                await choice.click();
                await pp.getByRole('option', { name: brandName, exact: true }).click();
                await expect(choice).toHaveText(brandName);
                const scan = await new AxeBuilder({ page: pp }).analyze();
                expect(scan.violations).toEqual([]);
                expect(await pp.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
            }
            await dialog.getByRole('button', { name: 'Add lead', exact: true }).click();
            await expect(dialog).not.toBeVisible();
            await dismissNotifications(pp);
            await pp.goto('/partner/leads');
        };
        await add('Private capture', false);
        await add('Captured for the brand', true);
        // The next lead starts private again.
        await pp.getByRole('button', { name: '+ Add lead', exact: true }).click();
        await expect(pp.getByRole('combobox', { name: 'Share with a Brand (optional)', exact: true })).toHaveText(
            'No Brand — keep private',
        );
        await pp.keyboard.press('Escape');
        await pp.goto('/partner/brand-sharing');
        const own = pp.getByRole('region', { name: 'Leads registered with Brands table' });
        await expect(own.getByRole('row')).toHaveCount(2);
        await expect(own.getByRole('row', { name: /Captured for the brand/ })).toContainText('Shared');

        await login(bp, brandEmail);
        await openBrandHub(bp, brandName);
        await expect(bp.getByRole('region', { name: 'Program summary' })).toContainText('Leads registered4');
        const row = bp
            .getByRole('region', { name: 'Partners table' })
            .getByRole('row', { name: new RegExp(seed.partners[0]!.name) });
        await expect(row.getByRole('link', { name: /1 leads registered by/ })).toHaveText('1');
        await row.getByRole('link', { name: /1 leads registered by/ }).click();
        const list = bp.getByRole('region', { name: 'Lead registrations table' });
        await expect(list.getByRole('row')).toHaveCount(2);
        await expect(list).toContainText('Captured for the brand');
        await expect(bp.locator('body')).not.toContainText('Private capture');
    } finally {
        await partner.close();
        await brand.close();
    }
});
