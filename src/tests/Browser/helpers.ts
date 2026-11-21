import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { expect, type Locator, type Page } from '@playwright/test';

const repo = fileURLToPath(new URL('../../../', import.meta.url));
export function fixture(action: 'seed' | 'seed-unverified', email: string): { email: string };
export function fixture(action: 'seed-workspaces', email: string): { own: string; foreign: string };
export function fixture(action: 'verify' | 'reset', email: string): { url: string };
export function fixture(action: string, email: string): Record<string, string> {
    return JSON.parse(
        execFileSync(
            'docker',
            [
                'compose',
                '--env-file',
                'src/.env',
                '-p',
                process.env.COMPOSE_PROJECT_NAME ?? 'weavdash-m1',
                'exec',
                '-T',
                'app',
                'php',
                'tests/Browser/fixture.php',
                action,
                email,
            ],
            { cwd: repo, encoding: 'utf8' },
        ),
    );
}
export function changeRole(email: string, role: string): void {
    execFileSync(
        'docker',
        [
            'compose',
            '--env-file',
            'src/.env',
            '-p',
            process.env.COMPOSE_PROJECT_NAME ?? 'weavdash-m1',
            'run',
            '--rm',
            '-T',
            '--no-deps',
            'migrate',
            'php',
            'tests/Browser/role-fixture.php',
            email,
            role,
        ],
        { cwd: repo, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
    );
}
export async function login(page: Page, email: string, password = 'browser-password-123') {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/workspaces$/);
}
export async function mutate(page: Page, path: string, method: string, data: Record<string, string>): Promise<number> {
    return page.evaluate(
        async ({ path, method, data }) => {
            const csrf =
                document.cookie
                    .split('; ')
                    .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
                    ?.split('=')
                    .slice(1)
                    .join('=') ?? '';
            const response = await fetch(path, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(csrf),
                },
                body: JSON.stringify(data),
            });
            return response.status;
        },
        { path, method, data },
    );
}

// Fixtures use the same disposable image and stack as the browser, with no host mounts.
export function migrationFixture(script: string, ...args: string[]): string {
    const compose = ['compose', '--env-file', 'src/.env', '-p', process.env.COMPOSE_PROJECT_NAME ?? 'weavdash-m1'];
    const runtimePassword = execFileSync('docker', [...compose, 'exec', '-T', 'app', 'printenv', 'DB_PASSWORD'], {
        cwd: repo,
        encoding: 'utf8',
    }).trim();
    return execFileSync(
        'docker',
        [
            ...compose,
            'run',
            '--rm',
            '-T',
            '--no-deps',
            '-e',
            'BROWSER_RUNTIME_PASSWORD',
            'migrate',
            'php',
            `tests/Browser/${script}`,
            ...args,
        ],
        {
            cwd: repo,
            encoding: 'utf8',
            env: { ...process.env, BROWSER_RUNTIME_PASSWORD: runtimePassword },
        },
    );
}

/** A synthetic approved Brand workspace owned by an existing browser identity. Brand creation itself needs review. */
export function approvedBrand(email: string, name: string): string {
    return JSON.parse(migrationFixture('brand-fixture.php', email, name)).org;
}
/** Select an approved Brand workspace on the workspace screen and open its hub. */
export async function openBrandHub(page: Page, name: string): Promise<void> {
    await page.getByRole('button', { name: new RegExp(name) }).click();
    await page.getByRole('link', { name: 'Open brand hub' }).click();
}

type Project = { project: { name: string } };
/** The mobile projects run each journey at phone widths; desktop projects keep the desktop widths. */
export const isMobile = (info: Project): boolean => info.project.name.includes('mobile');
export const baseWidth = (info: Project): number => (isMobile(info) ? 390 : 1440);
export const reviewWidths = (info: Project): number[] => (isMobile(info) ? [390, 320] : [1440, 1920]);

/**
 * Visible controls in the main content that are smaller than the 44px touch target.
 * Links inside running text are exempt, and a checkbox or radio is measured with its label.
 */
export async function smallTouchTargets(page: Page): Promise<string[]> {
    return page.evaluate(() => {
        const found: string[] = [];
        const controls = document.querySelectorAll<HTMLElement>(
            'main a[href], main button, main input:not([type=hidden]), main select, main textarea, main [role=combobox], [role=dialog] button, [role=dialog] a[href]',
        );
        for (const control of controls) {
            if (!control.checkVisibility({ visibilityProperty: true }) || control.closest('.wd-sr-only')) continue;
            let box = control.getBoundingClientRect();
            if (box.width === 0 || box.height === 0) continue;
            if (control instanceof HTMLInputElement && ['checkbox', 'radio'].includes(control.type)) {
                const label = control.closest('label') ?? document.querySelector(`label[for="${control.id}"]`);
                if (label) box = label.getBoundingClientRect();
            } else if (control.tagName === 'A' && getComputedStyle(control).display === 'inline') {
                const parent = control.parentElement;
                if (parent && (parent.textContent ?? '').trim() !== (control.textContent ?? '').trim()) continue;
            }
            if (box.height < 43.5 || box.width < 43.5) {
                const name = (control.getAttribute('aria-label') ?? control.textContent ?? '').trim().slice(0, 50);
                found.push(
                    `${control.tagName.toLowerCase()} "${name}" ${Math.round(box.width)}×${Math.round(box.height)}`,
                );
            }
        }
        return [...new Set(found)];
    });
}

/** Opens a tab on the Automation page (Approvals, Schedules, Email history or Preferences). */
export async function automationTab(page: Page, name: 'Approvals' | 'Schedules' | 'Email history' | 'Preferences') {
    await page.getByRole('tab', { name: new RegExp(`^${name}`) }).click();
}

/** Opens New schedule, picks its type and, when given, fills the schedule name. */
export async function newSchedule(
    page: Page,
    type: 'Weekly digest' | 'Reminder email' | 'Follow-up draft',
    name?: string,
): Promise<Locator> {
    await page.getByRole('button', { name: 'New schedule', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'New schedule', exact: true });
    await dialog.getByRole('radio', { name: type, exact: true }).click();
    if (name) await dialog.getByLabel('Schedule name', { exact: true }).fill(name);
    return dialog;
}

/** Opens Write follow-up and fills it; the saved draft then opens in its drawer. */
export async function writeFollowUp(page: Page, lead: string, recipient: string, subject: string, message: string) {
    await page.getByRole('button', { name: 'Write follow-up', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Write a follow-up', exact: true });
    await dialog.getByRole('combobox', { name: 'Lead', exact: true }).click();
    await page.getByRole('option', { name: lead, exact: true }).click();
    await dialog.getByLabel('Recipient email', { exact: true }).fill(recipient);
    await dialog.getByLabel('Message subject', { exact: true }).fill(subject);
    await dialog.getByLabel('Message', { exact: true }).fill(message);
    await dialog.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    const drawer = page.getByRole('dialog', { name: subject, exact: true });
    await expect(drawer).toBeVisible();
    return drawer;
}
