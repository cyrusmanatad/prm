import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { chromium } from '../../../src/node_modules/@playwright/test/index.mjs';

const root = fileURLToPath(new URL('../../../', import.meta.url));
const evidence = fileURLToPath(new URL('./', import.meta.url));
const svg = await readFile(`${root}src/public/favicon.svg`, 'utf8');
const logo = await readFile(`${root}src/resources/js/components/BrandLogo.vue`, 'utf8');
const artwork = (value) => value.match(/<svg[^>]*>([\s\S]*?)<\/svg>/)[1].replace(/\s+/g, '');
assert.equal(artwork(svg), artwork(logo), 'Original logo artwork and colors are preserved');
const browser = await chromium.launch({ channel: 'chromium' });
try {
    const page = await browser.newPage({
        viewport: { width: 720, height: 300 },
        deviceScaleFactor: 1,
    });
    const svgUrl = `data:image/svg+xml;base64,${Buffer.from(svg).toString('base64')}`;
    const frames = [];
    for (const size of [16, 32, 48]) {
        const { pixels, png } = await page.evaluate(
            async ({ url, size }) => {
                const image = new Image();
                image.src = url;
                await image.decode();
                const canvas = document.createElement('canvas');
                canvas.width = canvas.height = size;
                const context = canvas.getContext('2d');
                context.drawImage(image, 0, 0, size, size);
                return {
                    pixels: [...context.getImageData(0, 0, size, size).data],
                    png: canvas.toDataURL(),
                };
            },
            { url: svgUrl, size },
        );
        // Uncompressed 32-bit DIB plus a legacy transparency mask, bottom row first.
        const maskStride = Math.ceil(size / 32) * 4;
        const dib = Buffer.alloc(40 + size * size * 4 + maskStride * size);
        dib.writeUInt32LE(40, 0);
        dib.writeInt32LE(size, 4);
        dib.writeInt32LE(size * 2, 8);
        dib.writeUInt16LE(1, 12);
        dib.writeUInt16LE(32, 14);
        dib.writeUInt32LE(dib.length - 40, 20);
        for (let y = 0; y < size; y++) {
            for (let x = 0; x < size; x++) {
                const source = (y * size + x) * 4;
                const target = 40 + ((size - 1 - y) * size + x) * 4;
                dib.set([pixels[source + 2], pixels[source + 1], pixels[source], pixels[source + 3]], target);
                if (pixels[source + 3] === 0) {
                    dib[40 + size * size * 4 + (size - 1 - y) * maskStride + Math.floor(x / 8)] |= 0x80 >> (x % 8);
                }
                if (x === 0 || y === 0 || x === size - 1 || y === size - 1) {
                    assert.equal(pixels[source + 3], 0, `${size}px outer edge stays transparent`);
                }
            }
        }
        frames.push({ size, dib, png });
    }
    const header = Buffer.alloc(6 + 16 * frames.length);
    header.writeUInt16LE(1, 2);
    header.writeUInt16LE(frames.length, 4);
    let offset = header.length;
    frames.forEach(({ size, dib }, index) => {
        const entry = 6 + index * 16;
        header[entry] = header[entry + 1] = size;
        header.writeUInt16LE(1, entry + 4);
        header.writeUInt16LE(32, entry + 6);
        header.writeUInt32LE(dib.length, entry + 8);
        header.writeUInt32LE(offset, entry + 12);
        offset += dib.length;
    });
    const ico = Buffer.concat([header, ...frames.map(({ dib }) => dib)]);
    if (process.argv.includes('--generate')) await writeFile(`${root}src/public/favicon.ico`, ico);
    assert.deepEqual(
        await readFile(`${root}src/public/favicon.ico`),
        ico,
        'ICO contains the exact rendered 16/32/48px artwork',
    );

    await page.setContent(`<style>
        body { margin:0; font:14px system-ui; background:#fbf7ef; }
        section { height:150px; display:flex; align-items:center; justify-content:space-evenly; }
        section.dark { background:#202027; color:#fbf7ef; }
        figure { margin:0; text-align:center; width:110px; }
        figcaption { margin-top:12px; font-size:12px; }
        img { vertical-align:middle; }
    </style>${['light', 'dark']
        .map(
            (theme) => `<section class="${theme}">
        <figure>${theme} background</figure>
        ${frames.map(({ size, png }) => `<figure><img src="${png}" width="${size}" height="${size}"><figcaption>${size} × ${size}</figcaption></figure>`).join('')}
        <figure><img src="${svgUrl}" width="32" height="32"><figcaption>SVG · 32 × 32</figcaption></figure>
    </section>`,
        )
        .join('')}`);
    await page.locator('img').evaluateAll((images) => Promise.all(images.map((image) => image.decode())));
    await page.screenshot({ path: `${evidence}tab-sizes.png` });

    const iconResponses = [];
    page.on('response', (response) => {
        if (/\/favicon\.(svg|ico)/.test(response.url()))
            iconResponses.push({
                url: response.url(),
                status: response.status(),
            });
    });
    await page.goto('http://localhost:8000/brand/login', {
        waitUntil: 'networkidle',
    });
    const links = await page.locator('link[rel="icon"]').evaluateAll((nodes) =>
        nodes.map((node) => ({
            href: node.href,
            type: node.type,
            sizes: node.sizes.value,
        })),
    );
    assert.equal(links.length, 2);
    for (const [file, type] of [
        ['favicon.ico', 'image/x-icon'],
        ['favicon.svg', 'image/svg+xml'],
    ]) {
        const link = links.find((link) => link.type === type);
        assert.ok(link?.href.endsWith(`/${file}?v=20261108`));
        const response = await page.request.get(link.href);
        assert.equal(response.status(), 200);
        assert.deepEqual(await response.body(), await readFile(`${root}src/public/${file}`));
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.screenshot({
        path: `${evidence}login-desktop.png`,
        fullPage: true,
    });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({
        path: `${evidence}login-mobile.png`,
        fullPage: true,
    });
    const tabIconResponses = [...iconResponses];
    // Decode both delivered files in Chromium, including the ICO fallback.
    for (const link of links) {
        assert.ok(
            await page.evaluate(async (url) => {
                const image = new Image();
                image.src = url;
                await image.decode();
                return image.naturalWidth > 0 && image.naturalHeight > 0;
            }, link.href),
        );
    }
    assert.ok(
        tabIconResponses.some(
            (response) => response.url.endsWith('/favicon.svg?v=20261108') && response.status === 200,
        ),
        'Chromium requests the SVG tab icon',
    );
    const results = {
        artworkUnchanged: true,
        icoSizes: frames.map(({ size }) => size),
        transparentEdges: true,
        links,
        tabIconResponses,
        httpBodiesMatchSource: true,
        browserDecode: true,
    };
    await writeFile(`${evidence}results.json`, `${JSON.stringify(results, null, 4)}\n`);
    console.log(JSON.stringify(results, null, 4));
} finally {
    await browser.close();
}
