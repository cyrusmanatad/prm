import { tmpdir } from 'node:os';
import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    outputDir: process.env.PLAYWRIGHT_OUTPUT_DIR ?? path.join(tmpdir(), 'weavdash-browser-results'),
    // Functional checks run on shared Docker hosts and both browser engines.
    timeout: 60_000,
    expect: { timeout: 15_000 },
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list'], ['json', { outputFile: 'test-results/results.json' }]],
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8000',
        trace: 'off',
        screenshot: 'off',
    },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
        { name: 'mobile', use: { ...devices['iPhone 13'], defaultBrowserType: 'chromium' } },
        { name: 'webkit-desktop', use: { ...devices['Desktop Safari'] } },
        { name: 'webkit-mobile', use: { ...devices['iPhone 13'] } },
    ],
});
