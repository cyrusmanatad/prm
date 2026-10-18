import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { expect, type Page } from '@playwright/test';

const repo = fileURLToPath(new URL('../../../', import.meta.url));
export function fixture(action: 'seed' | 'seed-unverified', email: string): { email: string };
export function fixture(action: 'seed-workspaces', email: string): { own: string; foreign: string };
export function fixture(action: 'verify' | 'reset', email: string): { url: string };
export function fixture(action: string, email: string): Record<string, string> {
    return JSON.parse(execFileSync('docker', [
        'compose', '--env-file', 'src/.env', '-p', process.env.COMPOSE_PROJECT_NAME ?? 'weavdash-m1',
        'exec', '-T', 'app', 'php', 'tests/Browser/fixture.php', action, email,
    ], { cwd: repo, encoding: 'utf8' }));
}
export function changeRole(email: string, role: string): void {
    execFileSync('docker', [
        'compose', '--env-file', 'src/.env', '-p', process.env.COMPOSE_PROJECT_NAME ?? 'weavdash-m1',
        'run', '--rm', '-T', '--no-deps', 'migrate', 'php', 'tests/Browser/role-fixture.php', email, role,
    ], { cwd: repo, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}
export async function login(page: Page, email: string, password = 'browser-password-123') {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/workspaces$/);
}
export async function mutate(page: Page, path: string, method: string, data: Record<string, string>): Promise<number> {
    return page.evaluate(async ({ path, method, data }) => {
        const csrf = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=') ?? '';
        const response = await fetch(path, {
            method, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(csrf) },
            body: JSON.stringify(data),
        });
        return response.status;
    }, { path, method, data });
}
