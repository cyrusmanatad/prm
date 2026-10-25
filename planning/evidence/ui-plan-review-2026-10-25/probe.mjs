// Local synthetic CRM review. Run from the repository root.
import { execFileSync } from "node:child_process";
import { createRequire } from "node:module";
import { fileURLToPath } from "node:url";
import { writeFileSync } from "node:fs";

const root = fileURLToPath(new URL("../../../", import.meta.url));
const output = fileURLToPath(new URL("./", import.meta.url));
const require = createRequire(`${root}src/package.json`);
const { chromium, expect } = require("@playwright/test");
const email = `m1-browser-ui-plan-review-${Date.now()}@example.test`;
const workspace = JSON.parse(
    execFileSync(
        "docker",
        [
            "compose",
            "--env-file",
            "src/.env",
            "-p",
            "weavdash",
            "exec",
            "-T",
            "app",
            "php",
            "tests/Browser/fixture.php",
            "seed-workspaces",
            email,
        ],
        { cwd: root, encoding: "utf8" },
    ),
);
const browser = await chromium.launch();
const results = [];
try {
    for (const width of [1440, 390]) {
        const context = await browser.newContext({
            viewport: { width, height: 1000 },
        });
        const page = await context.newPage();
        await page.goto("http://localhost:8000/login");
        await page.getByLabel("Email address").fill(email);
        await page
            .getByLabel("Password", { exact: true })
            .fill("browser-password-123");
        await page
            .getByRole("button", { name: "Sign in", exact: true })
            .click();
        await expect(page).toHaveURL(/\/workspaces$/);
        await page.evaluate(async (org_id) => {
            const token = decodeURIComponent(
                document.cookie
                    .split("; ")
                    .find((part) => part.startsWith("XSRF-TOKEN="))
                    ?.slice(11) ?? "",
            );
            const request = async (path, data) => {
                const response = await fetch(path, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        Accept: "application/json",
                        "X-XSRF-TOKEN": token,
                    },
                    body: JSON.stringify(data),
                });
                if (!response.ok)
                    throw new Error(`${path}: ${response.status}`);
            };
            await request("/workspace/select", { org_id });
            await request("/reminders", {
                title: "Pending review reminder",
                due_at: "2035-01-01T10:00:00Z",
            });
        }, workspace.own);
        await page.goto("http://localhost:8000/partner/contacts");
        await page
            .getByRole("button", { name: "Completed", exact: true })
            .click();
        const selected = await page
            .getByRole("button", { name: "Completed", exact: true })
            .getAttribute("aria-pressed");
        const pendingStillVisible = await page
            .getByText("Pending review reminder", { exact: true })
            .first()
            .isVisible();
        results.push({
            width,
            check: "Completed segment before distant Apply filters",
            selected,
            pendingStillVisible,
            url: page.url(),
        });
        await page.screenshot({
            path: `${output}crm-filter-${width}.png`,
            fullPage: true,
        });
        await page
            .getByRole("button", { name: "New contact", exact: true })
            .click();
        await expect(
            page.getByRole("dialog", { name: "New contact", exact: true }),
        ).toBeVisible();
        await page.screenshot({
            path: `${output}crm-editor-${width}.png`,
            fullPage: true,
        });
        await page.keyboard.press("Escape");
        results.push({
            width,
            check: "CRM editor Escape focus restoration",
            focusedText: await page.evaluate(() =>
                document.activeElement?.textContent?.trim(),
            ),
            overflow: await page.evaluate(
                () => document.documentElement.scrollWidth > innerWidth,
            ),
        });
        await context.close();
    }
} finally {
    writeFileSync(
        `${output}probe-results.json`,
        `${JSON.stringify(results, null, 4)}\n`,
    );
    await browser.close();
}
