// Compare the retained references with current production portal components.
// Synthetic fixtures only; no application/database mutations.
import { createRequire } from "node:module";
import { fileURLToPath } from "node:url";
import { readFileSync, writeFileSync } from "node:fs";
import { createHash } from "node:crypto";
import { execFileSync } from "node:child_process";

const root = fileURLToPath(new URL("../../../", import.meta.url));
const output = fileURLToPath(new URL("./", import.meta.url));
const require = createRequire(`${root}src/package.json`);
const { chromium, expect } = require("@playwright/test");
const { PNG } = require("playwright-core/lib/utilsBundle");
const metrics = [];
const hashes = {};
const browser = await chromium.launch();
try {
    for (const width of [1440, 1024, 390]) {
        for (const [portal, reference] of [
            ["partner", "partner-my-leads"],
            ["brand", "brand-partner-hub"],
        ]) {
            const captures = {};
            for (const mode of ["reference", "current"]) {
                const page = await browser.newPage({
                    viewport: { width, height: 1000 },
                    deviceScaleFactor: 1,
                    locale: "en-US",
                    timezoneId: "Asia/Manila",
                    colorScheme: "light",
                    reducedMotion: "reduce",
                });
                const errors = [];
                page.on("pageerror", (error) => errors.push(error.message));
                await page.goto(
                    mode === "reference"
                        ? `http://127.0.0.1:5502/${reference}.html`
                        : `http://127.0.0.1:5503/tests/Visual/index.html?portal=${portal}`,
                );
                if (mode === "current") {
                    await page.locator(".portal-ui").waitFor();
                    if (portal === "partner") {
                        await expect(
                            page.locator(".pipeline-card .lead-card"),
                        ).toHaveCount(6);
                        await expect(
                            page.locator(".pipeline-grid > section"),
                        ).toHaveCount(5);
                    }
                    expect(
                        await page.evaluate(
                            () =>
                                document.documentElement.scrollWidth <=
                                innerWidth,
                        ),
                    ).toBe(true);
                }
                await page.evaluate(() => document.fonts.ready);
                expect(errors).toEqual([]);
                const dimensions = await page.evaluate(() => ({
                    clientWidth: document.documentElement.clientWidth,
                    scrollWidth: document.documentElement.scrollWidth,
                    scrollHeight: document.documentElement.scrollHeight,
                    fontsLoaded:
                        document.fonts.check("14px Manrope") &&
                        document.fonts.check('18px "Bricolage Grotesque"'),
                }));
                expect(dimensions.fontsLoaded).toBe(true);
                const name = `${mode}-${portal}-${width}.png`;
                const buffer = await page.screenshot({
                    path: `${output}${name}`,
                    fullPage: true,
                });
                hashes[name] = createHash("sha256")
                    .update(buffer)
                    .digest("hex");
                captures[mode] = PNG.sync.read(buffer);
                metrics.push({
                    portal,
                    mode,
                    viewportWidth: width,
                    ...dimensions,
                    errors,
                });
                await page.close();
            }
            const a = captures.reference;
            const b = captures.current;
            const canvasWidth = Math.max(a.width, b.width);
            const canvasHeight = Math.max(a.height, b.height);
            const side = new PNG({
                width: canvasWidth * 2,
                height: canvasHeight,
            });
            side.data.fill(255);
            PNG.bitblt(a, side, 0, 0, a.width, a.height, 0, 0);
            PNG.bitblt(b, side, 0, 0, b.width, b.height, canvasWidth, 0);
            const name = `side-by-side-${portal}-${width}.png`;
            const buffer = PNG.sync.write(side);
            writeFileSync(`${output}${name}`, buffer);
            hashes[name] = createHash("sha256").update(buffer).digest("hex");
        }
    }
    // The pixel comparison is intentionally descriptive: different content,
    // dimensions and functional controls cannot use a zero-difference threshold.
    writeFileSync(
        `${output}verification.json`,
        `${JSON.stringify(
            {
                revision: execFileSync("git", ["rev-parse", "HEAD"], {
                    cwd: root,
                    encoding: "utf8",
                }).trim(),
                browser: await browser.version(),
                settings: {
                    widths: [1440, 1024, 390],
                    height: 1000,
                    deviceScaleFactor: 1,
                    locale: "en-US",
                    timezone: "Asia/Manila",
                    colorScheme: "light",
                    reducedMotion: "reduce",
                },
                comparison:
                    "Full-page original on left, current fixture on right; unequal dimensions padded white, never rescaled or cropped.",
                metrics,
                hashes,
                sourceHashes: Object.fromEntries(
                    [
                        "src/tests/Visual/reference.ts",
                        "src/resources/js/features/portals/PartnerDashboard.vue",
                        "src/resources/js/features/portals/BrandDashboard.vue",
                        "src/resources/js/features/portals/PartnerChrome.vue",
                        "src/resources/js/features/pipeline/PipelineBoard.vue",
                        "src/resources/css/portal-theme.css",
                    ].map((path) => [
                        path,
                        createHash("sha256")
                            .update(readFileSync(`${root}${path}`))
                            .digest("hex"),
                    ]),
                ),
            },
            null,
            4,
        )}\n`,
    );
    process.stdout.write(
        "Captured 12 full-page views and six side-by-side comparisons; production-board, fonts, overflow and page-error assertions passed.\n",
    );
} finally {
    await browser.close();
}
