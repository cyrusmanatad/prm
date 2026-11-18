import { chromium, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

assert(process.env.PRESENTATION_ROSTER, 'Set PRESENTATION_ROSTER to the private JSON roster');
assert.equal(fs.statSync(process.env.PRESENTATION_ROSTER).mode & 0o777, 0o600);
const roster = JSON.parse(fs.readFileSync(process.env.PRESENTATION_ROSTER, 'utf8'));
assert.equal(roster.version, 'northline-living-v1');
const base = process.env.PRESENTATION_URL || 'https://weavdash.cyrusmanatad.com';
const capture = process.env.PRESENTATION_CAPTURE_DIR;
if (capture) {
    assert(path.resolve(capture).startsWith('/tmp/'), 'Captures must be temporary and outside Git');
    fs.mkdirSync(capture, { recursive: true, mode: 0o700 });
}
const edits = process.env.PRESENTATION_EXERCISE_EDITS === '1';
const browser = await chromium.launch({ headless: true });
const results = [];
const errors = [];
let foreignLead;
async function json(page, route, method = 'GET', data) {
    return page.evaluate(
        async ({ route, method, data }) => {
            const csrf = document.cookie
                .split('; ')
                .find((c) => c.startsWith('XSRF-TOKEN='))
                ?.slice(11);
            const response = await fetch(route, {
                method,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(csrf || ''),
                },
                ...(data ? { body: JSON.stringify(data) } : {}),
            });
            return { status: response.status, data: await response.json() };
        },
        { route, method, data },
    );
}
async function inspect(page, filename) {
    await page.locator('h1').first().waitFor();
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, filename);
    if (capture) await page.screenshot({ path: path.join(capture, filename + '.png'), fullPage: true });
}
try {
    for (const width of [1440, 390]) {
        const accounts =
            width === 1440 ? roster.accounts : [roster.accounts[0], roster.accounts[1], roster.accounts[13]];
        for (const account of accounts) {
            const context = await browser.newContext({ viewport: { width, height: 900 } });
            const page = await context.newPage();
            page.setDefaultTimeout(20000);
            page.setDefaultNavigationTimeout(30000);
            console.error(`Checking ${account.name} at ${width}`);
            page.on('pageerror', (error) => errors.push(error.message));
            try {
                await page.goto(base + account.login);
                await page.getByLabel('Email address').fill(account.email);
                await page.getByLabel('Password', { exact: true }).fill(account.password);
                await page.getByRole('button', { name: 'Sign in', exact: true }).click();
                const brand = account.login === '/brand/login';
                await page.waitForURL(brand ? '**/brand' : '**/partner/leads');
                assert.equal((await json(page, '/admin')).status, 403);
                if (brand) {
                    const result = await json(page, '/brand');
                    assert.equal(result.status, 200);
                    assert.equal(result.data.relationships.length, 13);
                    assert.equal(result.data.pipeline, null);
                    const privatePipeline = await json(page, '/pipeline');
                    assert.equal(privatePipeline.status, 200);
                    assert.equal(privatePipeline.data.entries.length, 0);
                    if (foreignLead) assert.equal((await json(page, `/pipeline?entry=${foreignLead}`)).status, 404);
                    await inspect(page, `brand-${width}`);
                    results.push({ workspace: account.name, width, partners: 13 });
                } else {
                    const index = roster.accounts.indexOf(account);
                    const expected = 14 + index;
                    const pipeline = await json(page, '/pipeline');
                    assert.equal(pipeline.status, 200);
                    assert.equal(
                        pipeline.data.counts.reduce((sum, item) => sum + item.count, 0),
                        expected,
                    );
                    assert(pipeline.data.entries.every((entry) => entry.currency === 'USD'));
                    const lead = pipeline.data.entries[0];
                    if (index === 1) foreignLead = lead.id;
                    if (foreignLead && index !== 1)
                        assert.equal((await json(page, `/pipeline?entry=${foreignLead}`)).status, 404);
                    if (pipeline.data.nextCursor) {
                        const next = await json(page, `/pipeline?cursor=${pipeline.data.nextCursor}`);
                        assert.equal(next.status, 200);
                        assert.equal(pipeline.data.entries.length + next.data.entries.length, expected);
                    }
                    if ([1, 13].includes(index)) {
                        await inspect(page, `partner-${index}-${width}`);
                        await page.getByLabel('Search brands', { exact: true }).fill('Northline');
                        await expect(page).toHaveURL(/q=Northline/);
                        assert.equal((await json(page, '/pipeline?q=Northline')).data.entries.length, 1);
                        await inspect(page, `filtered-${index}-${width}`);
                        await page.goto(`${base}/partner/leads?entry=${lead.id}`);
                        await expect(page.getByLabel('Brand name', { exact: true })).toHaveValue(lead.brand_name);
                        if (edits && index === 1) {
                            try {
                                await page.getByRole('combobox', { name: 'Stage', exact: true }).click();
                                await page
                                    .getByRole('option', {
                                        name: lead.stage === 'pitched' ? 'Lead' : 'Pitched',
                                        exact: true,
                                    })
                                    .click();
                                await page.getByRole('button', { name: 'Save lead', exact: true }).click();
                                await expect(
                                    page.getByRole('status').filter({ hasText: 'Stage updated to' }),
                                ).toBeVisible();
                            } finally {
                                const latest = await json(page, `/pipeline?entry=${lead.id}`);
                                if (latest.data.selected.stage !== lead.stage)
                                    assert.equal(
                                        (
                                            await json(page, `/pipeline/${lead.id}/stage`, 'POST', {
                                                stage: lead.stage,
                                                version: latest.data.selected.version,
                                            })
                                        ).status,
                                        200,
                                    );
                            }
                        }
                        await page.goto(`${base}/partner/contacts?entry=${lead.id}`);
                        const crm = await json(page, `/partner/contacts?entry=${lead.id}`);
                        assert.equal(crm.status, 200);
                        assert.equal(crm.data.linked.length, 1);
                        await expect(
                            page.getByRole('region', { name: `Linked to ${lead.brand_name}`, exact: true }),
                        ).toBeVisible();
                        await inspect(page, `crm-${index}-${width}`);
                        for (const label of ['Due', 'Completed', 'Pending']) {
                            console.error(`Reminder filter ${label}`);
                            await page.getByRole('button', { name: label, exact: true }).click();
                            await expect(page.getByRole('button', { name: label, exact: true })).toHaveAttribute(
                                'aria-pressed',
                                'true',
                            );
                        }
                        if (edits && index === 1) {
                            const current = await json(page, `/partner/contacts?entry=${lead.id}`);
                            const reminder = current.data.reminders.items.find((item) => !item.completed_at);
                            assert(reminder, 'Expected a pending seeded reminder');
                            try {
                                assert.equal(
                                    (
                                        await json(page, `/reminders/${reminder.id}/complete`, 'POST', {
                                            version: reminder.version,
                                        })
                                    ).status,
                                    200,
                                );
                            } finally {
                                const completed = await json(
                                    page,
                                    `/partner/contacts?entry=${lead.id}&state=completed`,
                                );
                                const updated = completed.data.reminders.items.find((item) => item.id === reminder.id);
                                if (updated?.completed_at)
                                    assert.equal(
                                        (
                                            await json(page, `/reminders/${reminder.id}/reopen`, 'POST', {
                                                version: updated.version,
                                            })
                                        ).status,
                                        200,
                                    );
                            }
                        }
                    }
                    results.push({ workspace: account.name, width, leads: expected });
                }
            } finally {
                await context.close();
            }
        }
    }
    assert.deepEqual(errors, []);
    console.log(
        JSON.stringify({ logins: results.length, results, javascript_errors: errors, edits_exercised: edits }, null, 4),
    );
} finally {
    await browser.close();
}
