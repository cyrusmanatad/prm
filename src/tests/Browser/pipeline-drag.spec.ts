import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { changeRole, fixture, login, mutate, migrationFixture } from './helpers';

test('board drag persists, retains filters, recovers stale versions and keeps the stage selector accessible', async ({
    page,
}, info) => {
    test.setTimeout(120_000);
    const email = `m1-browser-drag-${info.project.name}-${Date.now()}@example.test`;
    const workspace = fixture('seed-workspaces', email);
    await login(page, email);
    expect(await mutate(page, '/workspace/select', 'POST', { org_id: workspace.own })).toBe(200);
    const id = await page.evaluate(async () => {
        const csrf = decodeURIComponent(
            document.cookie
                .split('; ')
                .find((row) => row.startsWith('XSRF-TOKEN='))!
                .slice(11),
        );
        const response = await fetch('/pipeline', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf },
            body: JSON.stringify({
                brand_name: 'Drag studio',
                currency: 'USD',
                value_minor: '12345',
                next_action_due: '2020-01-01T00:00:00Z',
            }),
        });
        return (await response.json()).id as string;
    });
    await page.goto('/partner/leads?q=Drag&due=1');
    const board = page.locator('.pipeline-card');
    const card = board.locator('.lead-card');
    const column = (name: string) => board.getByRole('region', { name, exact: true });
    const move = async (name: string) => {
        // Native mouse dragging on desktop; mobile uses the existing stage selector below.
        await expect(card).toHaveAttribute('draggable', 'true');
        await card.dragTo(column(name));
        await expect(page.getByText('Moving lead… Counts and totals update after saving.')).toHaveCount(0);
    };
    if (info.project.name === 'desktop') {
        const dataTransfer = await page.evaluateHandle(() => new DataTransfer());
        await card.dispatchEvent('dragstart', { dataTransfer });
        await column('Pitched').dispatchEvent('dragover', { dataTransfer });
        await expect(column('Pitched')).toHaveClass(/column-over/);
        if (process.env.CAPTURE_BROWSER_IMAGES === '1')
            await page.screenshot({ path: info.outputPath('pipeline-drag.spec-capture-2273.png'), fullPage: true });
        await card.dispatchEvent('dragend', { dataTransfer });
        await dataTransfer.dispose();
        await page.route(
            `**/pipeline/${id}/stage`,
            async (route) => {
                await expect(column('Pitched').locator('.lead-card')).toHaveClass(/lead-moving/);
                await expect(card).toHaveAttribute('draggable', 'false');
                if (process.env.CAPTURE_BROWSER_IMAGES === '1')
                    await page.screenshot({
                        path: info.outputPath('pipeline-drag.spec-capture-2769.png'),
                        fullPage: true,
                    });
                await route.continue();
            },
            { times: 1 },
        );
        await move('Pitched');
        await expect(column('Pitched').locator('.lead-card')).toHaveCount(1);
        await expect(page.getByRole('dialog')).not.toBeVisible();
        expect(new URL(page.url()).searchParams.get('q')).toBe('Drag');
        expect(new URL(page.url()).searchParams.get('due')).toBe('1');
        expect(new URL(page.url()).searchParams.has('entry')).toBe(false);
        await page.reload();
        await expect(column('Pitched').locator('.lead-card')).toHaveCount(1);
        // Another writer wins after the board's displayed version was loaded.
        expect(await mutate(page, `/pipeline/${id}/stage`, 'POST', { stage: 'active', version: '2' })).toBe(200);
        let writes = 0;
        page.on('request', (request) => {
            if (request.method() === 'POST' && request.url().endsWith(`/pipeline/${id}/stage`)) writes++;
        });
        await move('Negotiating');
        await expect(page.getByRole('alert').filter({ hasText: 'latest filtered board' })).toBeVisible();
        await expect(column('Active').locator('.lead-card')).toHaveCount(1);
        expect(writes).toBe(1);
        expect(new URL(page.url()).searchParams.get('q')).toBe('Drag');
        expect(new URL(page.url()).searchParams.get('due')).toBe('1');
        await expect(page.getByRole('dialog')).not.toBeVisible();
        // Recovery permits a deliberate new move using the freshly loaded version.
        await move('Pitched');
        await expect(column('Pitched').locator('.lead-card')).toHaveCount(1);
    }
    await card.click();
    const selector = page.getByRole('combobox', { name: 'Stage', exact: true });
    await selector.focus();
    await page.keyboard.press('Enter');
    await page.getByRole('option', { name: 'Negotiating', exact: true }).click();
    await page.getByRole('button', { name: 'Save lead', exact: true }).click();
    await expect(page.locator('#lead-stage')).toHaveValue('negotiating');
    await expect(page.getByRole('status').filter({ hasText: 'Stage updated to Negotiating' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await page.reload();
    await expect(column('Negotiating').locator('.lead-card')).toHaveCount(1);
    await card.scrollIntoViewIfNeeded();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    if (process.env.CAPTURE_BROWSER_IMAGES === '1')
        await page.screenshot({
            path: info.outputPath('pipeline-drag.spec-capture-5409.png'),
            fullPage: true,
        });
    changeRole(email, 'viewer');
    await page.reload();
    await expect(card).toHaveAttribute('draggable', 'false');
    expect(await mutate(page, `/pipeline/${id}/stage`, 'POST', { stage: 'active', version: '1', board: '1' })).toBe(
        403,
    );
});

for (const state of ['active', 'expired']) {
    test(`Professional ${state} subscription applies board move access and retains routes`, async ({ page }, info) => {
        const email = `m1-browser-drag-expired-${info.project.name}-${Date.now()}@example.test`;
        const data = JSON.parse(migrationFixture('pipeline-access-fixture.php', email, state));
        await login(page, email);
        expect(await mutate(page, '/workspace/select', 'POST', { org_id: data.org })).toBe(200);
        await page.goto('/professional/leads');
        const card = page.getByRole('button', { name: /Open Saved client/ });
        if (state === 'active') {
            await expect(card).toHaveAttribute('draggable', 'true');
            if (info.project.name === 'desktop') {
                await card.dragTo(page.getByRole('region', { name: 'Pitched', exact: true }));
                await expect(page.getByRole('status').filter({ hasText: 'Stage updated to Pitched' })).toBeVisible();
                await expect(page).toHaveURL(/\/professional\/leads$/);
                await expect(page.getByRole('dialog')).not.toBeVisible();
                await page.reload();
                await expect(page.getByRole('region', { name: 'Pitched', exact: true })).toContainText('Saved client');
            }
            return;
        }
        await expect(card).toHaveAttribute('draggable', 'false');
        expect(
            await mutate(page, `/pipeline/${data.id}/stage`, 'POST', { stage: 'active', version: '1', board: '1' }),
        ).toBe(422);
        await page.reload();
        await expect(page.getByRole('region', { name: 'Lead', exact: true })).toContainText('Saved client');
        await expect(page).toHaveURL(/\/professional\/leads$/);
    });
}
