import { router } from '@inertiajs/vue3';

/** Personal accent colors. The server allowlists the same values; anything else shows the default. */
export const appearances = [
    { value: 'burgundy', label: 'Burgundy', description: 'The WeavDash default' },
    { value: 'purple', label: 'Purple', description: 'A deeper violet accent' },
] as const;

export type Appearance = (typeof appearances)[number]['value'];

export function normalizeAppearance(value: unknown): Appearance {
    return appearances.some((option) => option.value === value) ? (value as Appearance) : 'burgundy';
}

/**
 * Sets the accent on the document root, so teleported menus, tooltips and dialogs share it. The initial HTML already
 * carries the server's value.
 */
export function applyAppearance(value: unknown): void {
    document.documentElement.dataset.appearance = normalizeAppearance(value);
}

/**
 * Follows the server-confirmed `appearance` shared prop on every Inertia response, including sign-out and account
 * changes. History restores (Back/Forward) are not server responses, so a page saved before a change cannot bring back
 * the old accent. Partial reloads that omit the prop keep the current value.
 */
export function syncAppearance(): () => void {
    return router.on('success', (event) => {
        const value = (event.detail.page.props as { appearance?: unknown }).appearance;
        if (value !== undefined) applyAppearance(value);
    });
}
