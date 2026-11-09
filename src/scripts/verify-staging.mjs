import { chromium } from 'playwright';
import fs from 'node:fs';
import assert from 'node:assert/strict';
assert(process.env.STAGING_CREDENTIALS_FILE, 'Set STAGING_CREDENTIALS_FILE to the private reviewers.json path');
const credentials = JSON.parse(fs.readFileSync(process.env.STAGING_CREDENTIALS_FILE, 'utf8'));
const base = process.env.STAGING_TEST_URL || 'https://weavdash.cyrusmanatad.com';
const ignoreHTTPSErrors = process.env.STAGING_LOCAL_TUNNEL === '1';
const browser = await chromium.launch({
    headless: true,
    args:
        process.env.STAGING_LOCAL_TUNNEL === '1'
            ? ['--host-resolver-rules=MAP weavdash.cyrusmanatad.com 127.0.0.1']
            : [],
});
const records = [];
async function json(page, path, method = 'GET', data) {
    return page.evaluate(
        async ({ path, method, data }) => {
            const csrf =
                document.cookie
                    .split('; ')
                    .find((c) => c.startsWith('XSRF-TOKEN='))
                    ?.slice(11) || '';
            const response = await fetch(path, {
                method,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(csrf),
                },
                ...(data ? { body: JSON.stringify(data) } : {}),
            });
            return { status: response.status, data: await response.json() };
        },
        { path, method, data },
    );
}
const errors = [];
for (const width of (process.env.STAGING_WIDTHS || '390,1440').split(',').map(Number)) {
    for (const role of ['partner', 'brand', 'professional']) {
        const context = await browser.newContext({ ignoreHTTPSErrors, viewport: { width, height: 900 } });
        const page = await context.newPage();
        page.on('pageerror', (e) => errors.push(e.message));
        const response = await page.goto(`${base}/${role}/login`);
        assert.equal(response.status(), 200);
        await page.getByLabel('Email address').fill(`${role}@preview.example.test`);
        await page.getByLabel('Password', { exact: true }).fill(credentials[role]);
        await page.getByRole('button', { name: 'Sign in', exact: true }).click();
        await page.waitForURL(new RegExp(role === 'partner' ? '/partner/leads$' : `/${role}$`));
        assert(await page.getByRole('complementary', { name: 'Staging environment' }).isVisible());
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
        assert.equal((await json(page, '/admin')).status, 403);
        const cookie = (await context.cookies()).find((c) => c.name.endsWith('_session'));
        assert(cookie.secure && cookie.httpOnly);
        if (role !== 'brand') {
            const route = role === 'professional' ? '/professional' : '/partner';
            for (const path of [
                '/leads',
                '/contacts',
                '/onboarding',
                '/outreach-templates',
                role === 'professional' ? '/profile' : '/media-kit',
                ...(role === 'professional' ? ['/finance/invoices', '/finance/payments', '/automation'] : []),
            ]) {
                assert.equal((await page.goto(base + route + path)).status(), 200);
                await page.locator('h1').first().waitFor();
                assert.equal(
                    await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),
                    false,
                    `${role} ${path} overflow at ${width}`,
                );
            }
            const pipeline = await json(page, '/pipeline');
            assert.equal(pipeline.status, 200);
            const entries = pipeline.data.entries ?? pipeline.data.pipeline?.entries;
            assert(entries?.length >= 3, JSON.stringify(Object.keys(pipeline.data)));
            const lead = entries[0];
            assert.equal(
                (
                    await json(page, `/pipeline/${lead.id}/stage`, 'POST', {
                        version: lead.version,
                        stage: lead.stage === 'pitched' ? 'lead' : 'pitched',
                    })
                ).status,
                200,
            );
            if (role === 'partner' && width === 1440) {
                const invitation = await json(page, '/onboarding-requests', 'POST', {
                    pipeline_entry_id: lead.id,
                    label: 'Staging browser review',
                });
                assert.equal(invitation.status, 201);
                const guest = await browser.newContext({
                    ignoreHTTPSErrors,
                    viewport: { width: 390, height: 900 },
                });
                const form = await guest.newPage();
                await form.goto(base + '/onboarding');
                const token = new URL(invitation.data.url).hash.slice(1).replace(/^token=/, '');
                const exchange = await json(form, '/onboarding/session', 'POST', { token });
                assert.equal(exchange.status, 200);
                await form.goto(base + '/onboarding/form/' + exchange.data.id);
                await form
                    .getByText(
                        'Staging uploads are not scanned for malware. Use non-sensitive test files. Files are kept in private staging storage.',
                    )
                    .waitFor();
                assert.equal(await form.locator('input[type=file]').count(), 1);
                const detail = await json(form, '/onboarding/form/' + exchange.data.id);
                const fields = {
                    version: detail.data.form.version,
                    business_name: 'Synthetic Review Studio',
                    contact_name: 'Staging Contact',
                    business_email: 'contact@example.test',
                    website: 'https://example.test',
                    brief: 'Synthetic colleague review without attachments.',
                };
                const saved = await json(form, '/onboarding/form/' + exchange.data.id, 'PATCH', fields);
                assert.equal(saved.status, 200, JSON.stringify(saved));
                // A direct API save changes the version; reload before a UI upload.
                await form.reload();
                await form.getByLabel('Choose reference file').waitFor();
                await form.getByLabel('Choose reference file').setInputFiles({
                    name: 'staging-removal-check.txt',
                    mimeType: 'text/plain',
                    buffer: Buffer.alloc(2 * 1024 * 1024, 'x'),
                });
                await form.getByRole('button', { name: 'Upload reference', exact: true }).click();
                const removalName = 'Remove staging-removal-check.txt';
                await form.getByRole('button', { name: removalName, exact: true }).waitFor();
                const beforeRemoval = await json(form, '/onboarding/form/' + exchange.data.id);
                const removedDocumentId = beforeRemoval.data.form.documents.find(
                    (item) => item.name === 'staging-removal-check.txt',
                ).id;
                await form.getByRole('button', { name: removalName, exact: true }).click();
                const confirmation = form.getByRole('dialog', { name: 'Remove document?', exact: true });
                await confirmation.waitFor();
                assert(
                    await confirmation
                        .getByRole('button', { name: 'Cancel', exact: true })
                        .evaluate((el) => el === document.activeElement),
                );
                await confirmation.getByRole('button', { name: 'Cancel', exact: true }).click();
                assert.equal((await json(form, '/onboarding/form/' + exchange.data.id)).data.form.documents.length, 1);
                await form.getByRole('button', { name: removalName, exact: true }).click();
                await confirmation.getByRole('button', { name: 'Remove document', exact: true }).click();
                await form.getByRole('button', { name: removalName, exact: true }).waitFor({ state: 'detached' });
                assert.equal(
                    (await json(form, '/onboarding/form/' + exchange.data.id + '/documents/' + removedDocumentId))
                        .status,
                    404,
                );
                const uploadBytes = 'A non-sensitive staging collaboration reference.';
                await form.getByLabel('Choose reference file').setInputFiles({
                    name: 'staging-reference.txt',
                    mimeType: 'text/plain',
                    buffer: Buffer.from(uploadBytes),
                });
                await form.getByRole('button', { name: 'Upload reference', exact: true }).click();
                await form.getByRole('link', { name: 'Download staging-reference.txt', exact: true }).waitFor();
                const uploaded = await json(form, '/onboarding/form/' + exchange.data.id);
                const document = uploaded.data.form.documents.find((item) => item.name === 'staging-reference.txt');
                assert.equal(document.scan_state, 'unscanned');
                assert.equal(document.downloadable, true);
                const documentPath = '/onboarding/form/' + exchange.data.id + '/documents/' + document.id;
                const downloaded = await form.evaluate(async (path) => {
                    const response = await fetch(path);
                    return { status: response.status, body: await response.text() };
                }, documentPath);
                assert.equal(downloaded.status, 200);
                assert.equal(downloaded.body, uploadBytes);
                if (process.env.STAGING_PERSISTENCE_FILE)
                    fs.writeFileSync(
                        process.env.STAGING_PERSISTENCE_FILE,
                        JSON.stringify({
                            storageState: await guest.storageState(),
                            documentPath,
                            body: uploadBytes,
                            removedDocumentId,
                        }),
                        { mode: 0o600 },
                    );
                if (process.env.STAGING_SCREENSHOT_DIR)
                    await form.screenshot({
                        path: `${process.env.STAGING_SCREENSHOT_DIR}/upload-${width}.png`,
                        fullPage: true,
                    });
                const updated = await json(form, '/onboarding/form/' + exchange.data.id);
                const submitted = await json(form, '/onboarding/form/' + exchange.data.id + '/submit', 'POST', {
                    ...fields,
                    version: updated.data.form.version,
                    consent: true,
                    idempotency_key: crypto.randomUUID(),
                });
                assert.equal(submitted.status, 200, JSON.stringify(submitted));
                assert.equal(
                    (await json(form, '/onboarding/form/' + exchange.data.id + '/documents', 'POST', {})).status,
                    422,
                );
                records.push(
                    'onboarding: confirmed removal with Cancel focus; private unscanned file uploaded, downloaded and submitted; missing-file upload rejected',
                );
                await guest.close();
            }
        }
        if (process.env.STAGING_SCREENSHOT_DIR)
            await page.screenshot({
                path: `${process.env.STAGING_SCREENSHOT_DIR}/${role}-${width}.png`,
                fullPage: true,
            });
        assert.equal((await json(page, '/logout', 'POST', {})).status, 200);
        assert.equal((await json(page, '/account')).status, 401);
        records.push(
            `${role} ${width}x900: login, navigation, banner, no overflow, no platform access, secure session, logout`,
        );
        await context.close();
    }
}
const guest = await browser.newContext({ ignoreHTTPSErrors });
const page = await guest.newPage();
await page.goto(base + '/login');
for (const path of ['/register', '/brand/register', '/partner/register', '/professional/register']) {
    assert.equal((await page.goto(base + path)).status(), 200);
    const result = await json(page, path, 'POST', {});
    assert.equal(result.status, 422);
    assert(result.data.errors.name && result.data.errors.email && result.data.errors.password);
}
assert.equal((await page.goto(base + '/forgot-password')).status(), 200);
records.push('guest: all registration variants and password recovery available; registration validation enforced');
assert.deepEqual(errors, []);
console.log(JSON.stringify({ records, errors }, null, 2));
await browser.close();
