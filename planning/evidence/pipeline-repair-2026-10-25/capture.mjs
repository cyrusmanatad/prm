// Local visual fixtures only; production components, synthetic records.
import { createRequire } from "node:module";
import { fileURLToPath } from "node:url";
import { writeFileSync } from "node:fs";
const root = fileURLToPath(new URL("../../../", import.meta.url));
const output = fileURLToPath(new URL("./", import.meta.url));
const require = createRequire(`${root}src/package.json`);
const { chromium, expect } = require("@playwright/test");
const browser = await chromium.launch();
const metrics = [];
try {
    for (const width of [1440, 1024, 390]) {
        const page = await browser.newPage({
            viewport: { width, height: 1000 },
            deviceScaleFactor: 1,
            locale: "en-US",
            timezoneId: "Asia/Manila",
            colorScheme: "light",
        });
        const errors = [];
        page.on("pageerror", (error) => errors.push(error.message));
        for (const portal of ["partner", "brand"]) {
            await page.goto(
                `http://127.0.0.1:5503/tests/Visual/index.html?portal=${portal}`,
            );
            await page.locator(".portal-ui").waitFor();
            await page.evaluate(() => document.fonts.ready);
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
                        document.documentElement.clientWidth,
                ),
            ).toBe(true);
            await page.screenshot({
                path: `${output}fixture-${portal}-${width}.png`,
                fullPage: true,
            });
            metrics.push({
                portal,
                width,
                ...(await page.evaluate(() => ({
                    clientWidth: document.documentElement.clientWidth,
                    scrollWidth: document.documentElement.scrollWidth,
                    height: document.documentElement.scrollHeight,
                }))),
                errors: [...errors],
            });
        }
        await page.close();
    }
    writeFileSync(
        `${output}fixture-metrics.json`,
        `${JSON.stringify({ browser: await browser.version(), height: 1000, deviceScaleFactor: 1, locale: "en-US", timezone: "Asia/Manila", metrics }, null, 4)}\n`,
    );
} finally {
    await browser.close();
}
