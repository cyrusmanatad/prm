// Review probe: synthetic local accounts only. Run from the repository root.
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { writeFileSync } from 'node:fs';

const root = fileURLToPath(new URL('../../../', import.meta.url));
const output = fileURLToPath(new URL('./', import.meta.url));
const require = createRequire(`${root}/src/package.json`);
const { chromium, expect } = require('@playwright/test');
const browser = await chromium.launch();
const results = [];
const email = `m1-browser-ui-review-${Date.now()}@example.test`;
const workspace = JSON.parse(
    execFileSync(
        'docker',
        [
            'compose',
            '--env-file',
            'src/.env',
            '-p',
            'weavdash-m1',
            'exec',
            '-T',
            'app',
            'php',
            'tests/Browser/fixture.php',
            'seed-workspaces',
            email,
        ],
        { cwd: root, encoding: 'utf8' },
    ),
);

async function mutation(page, path, body) {
    const result = await page.evaluate(
        async ({ path, body }) => {
            const csrf =
                document.cookie
                    .split('; ')
                    .find((part) => part.startsWith('XSRF-TOKEN='))
                    ?.slice(11) ?? '';
            const response = await fetch(path, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(csrf),
                },
                body: JSON.stringify(body),
            });
            return { status: response.status, body: await response.json() };
        },
        { path, body },
    );
    if (result.status >= 400) throw new Error(JSON.stringify(result));
    return result.body;
}

try {
    for (const width of [1440, 390]) {
        const context = await browser.newContext({
            viewport: { width, height: 1000 },
        });
        const page = await context.newPage();
        await page.goto('http://localhost:8000/login');
        await page.getByLabel('Email address').fill(email);
        await page.getByLabel('Password', { exact: true }).fill('browser-password-123');
        await page.getByRole('button', { name: 'Sign in', exact: true }).click();
        await expect(page).toHaveURL(/\/workspaces$/);
        await page.getByRole('combobox', { name: 'Workspace type', exact: true }).click();
        await page.keyboard.press('Tab');
        await page.keyboard.press('Tab');
        const focusedOption = await page.evaluate(() => document.activeElement?.textContent?.trim());
        await page.screenshot({
            path: `${output}select-keyboard-${width}.png`,
            fullPage: true,
        });
        await page.keyboard.press('Enter');
        const selectedAfterEnter = await page.locator('#workspace-kind').inputValue();
        results.push({
            width,
            check: 'Tab to Brand then Enter',
            focusedOption,
            selectedAfterEnter,
            expected: 'brand',
        });

        await mutation(page, '/workspace/select', { org_id: workspace.own });
        if (width === 1440) {
            const oldest = await mutation(page, '/pipeline', {
                brand_name: 'Older target',
                currency: 'USD',
                value_minor: '100',
                next_action_due: '2020-01-01T00:00:00Z',
            });
            await mutation(page, `/pipeline/${oldest.id}/stage`, {
                stage: 'pitched',
                version: 1,
            });
            for (let i = 0; i < 26; i++) {
                await mutation(page, '/pipeline', {
                    brand_name: `Recent lead ${i}`,
                    currency: 'USD',
                    value_minor: '100',
                });
            }
        }
        await page.goto('http://localhost:8000/partner/leads');
        await page.evaluate(() => document.fonts.ready);
        await page.screenshot({
            path: `${output}pipeline-page-${width}.png`,
            fullPage: true,
        });
        results.push({
            width,
            check: 'Paged stage count',
            pitchedColumn: await page.getByRole('region', { name: 'Pitched', exact: true }).innerText(),
            total: await page.locator('.pipeline-count').innerText(),
            expectedPitchedCount: 1,
        });
        await page.getByRole('searchbox', { name: 'Search brands', exact: true }).fill('Older target');
        await expect(page.getByRole('heading', { name: 'No leads match these filters' })).toBeVisible();
        await page.screenshot({
            path: `${output}pipeline-missed-search-${width}.png`,
            fullPage: true,
        });
        results.push({
            width,
            check: 'Search older record from board',
            missing:
                (await page
                    .getByRole('button', {
                        name: 'Open Older target',
                        exact: true,
                    })
                    .count()) === 0,
            url: page.url(),
        });
        await page.goto('http://localhost:8000/partner/leads?q=Older%20target');
        await expect(
            page.getByRole('button', {
                name: 'Open Older target',
                exact: true,
            }),
        ).toBeVisible();
        results.push({
            width,
            check: 'Same search via server',
            found: true,
            header: await page.locator('.pipeline-count').innerText(),
        });
        await page.getByRole('searchbox', { name: 'Search brands', exact: true }).fill('Recent');
        results.push({
            width,
            check: 'Change prefiltered search to Recent',
            cards: await page.locator('.lead-card').count(),
            expectedMatchingRecords: 26,
            url: page.url(),
        });
        await page.goto('http://localhost:8000/partner/leads?stage=lost');
        await page.getByRole('button', { name: /^All active/ }).click();
        results.push({
            width,
            check: 'Switch away from server Lost filter',
            cards: await page.locator('.lead-card').count(),
            expectedMatchingRecords: 27,
            url: page.url(),
        });
        await page.goto('http://localhost:8000/partner/leads?q=Older%20target');
        await page.getByRole('button', { name: 'Open Older target', exact: true }).click();
        const close = page.getByRole('button', {
            name: 'Close lead',
            exact: true,
        });
        await expect(close).toBeVisible();
        await close.focus();
        await page.keyboard.press('Shift+Tab');
        results.push({
            width,
            check: 'Reverse Tab from lead drawer first control',
            focusStayedOnClose: await close.evaluate((element) => element === document.activeElement),
            expected: 'Delete lead disclosure summary',
        });
        await page.keyboard.press('Escape');
        await context.close();
    }
} finally {
    writeFileSync(`${output}probe-results.json`, `${JSON.stringify(results, null, 2)}\n`);
    await browser.close();
}
