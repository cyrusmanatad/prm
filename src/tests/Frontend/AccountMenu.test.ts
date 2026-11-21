import { afterEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';
import AccountMenu from '../../resources/js/components/AccountMenu.vue';

const page = reactive<{ url: string; props: Record<string, unknown> }>({ url: '/workspaces', props: {} });
vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => page,
}));

const wrappers: VueWrapper[] = [];
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    page.url = '/workspaces';
    page.props = {};
});

async function open(props: Record<string, unknown> = {}): Promise<string[]> {
    const wrapper = mount(AccountMenu, { props: { accountName: 'Jordan Vale', ...props }, attachTo: document.body });
    wrappers.push(wrapper);
    await wrapper.get('button').trigger('keydown', { key: 'Enter' });
    await nextTick();
    return [...document.body.querySelectorAll('[role="menuitem"]')].map((item) => item.textContent?.trim() ?? '');
}

it('offers administration only to accounts with platform access', async () => {
    expect(await open()).toEqual(['Switch workspace / account security', 'Privacy exports', 'Sign out']);
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());

    page.props = { platformAccess: true };
    expect(await open()).toEqual([
        'Switch workspace / account security',
        'Privacy exports',
        'Platform administration',
        'Sign out',
    ]);
    const link = document.body.querySelector<HTMLAnchorElement>('a[href="/admin"]');
    expect(link?.getAttribute('role')).toBe('menuitem');
});

it('omits the administration link inside administration and in fixtures', async () => {
    page.props = { platformAccess: true };
    page.url = '/admin/accounts?page=2';
    expect(await open()).not.toContain('Platform administration');
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());

    page.url = '/administrators';
    expect(await open()).toContain('Platform administration');
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());

    expect(await open({ fixture: true })).not.toContain('Platform administration');
});

it('offers only closure and security navigation while the account is closing', async () => {
    page.props = { accountClosing: true };
    expect(await open()).toEqual(['Account closure', 'Authenticator security', 'Sign out']);
});

it("offers the selected workspace's tools on every page", async () => {
    const partnerTools = ['Contacts and reminders', 'Outreach templates', 'Onboarding requests', 'Brand sharing'];
    page.props = { activeWorkspace: { kind: 'creator', experience: 'partner' } };
    for (const url of ['/workspaces', '/automation', '/partner/contacts', '/account/exports']) {
        page.url = url;
        expect(await open()).toEqual([
            'Switch workspace / account security',
            'Privacy exports',
            ...partnerTools,
            'Sign out',
        ]);
        expect(document.body.querySelector('a[href="/partner/onboarding"]')).not.toBeNull();
        wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    }

    // Brand workspaces offer their hub and lead registrations on every page, outside the Brand header too.
    page.props = { activeWorkspace: { kind: 'brand', experience: 'partner' } };
    for (const url of ['/brand', '/workspaces', '/automation']) {
        page.url = url;
        expect(await open()).toEqual([
            'Switch workspace / account security',
            'Privacy exports',
            'Partner hub',
            'Lead registrations',
            'Sign out',
        ]);
        expect(document.body.querySelector('a[href="/brand/lead-registrations"]')).not.toBeNull();
        wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    }

    // Professional workspaces use their own navigation; no selection has no workspace tools.
    for (const activeWorkspace of [{ kind: 'creator', experience: 'professional' }, null]) {
        page.props = { activeWorkspace };
        expect(await open()).toEqual(['Switch workspace / account security', 'Privacy exports', 'Sign out']);
        wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    }

    // A closing account still sees only closure and security navigation.
    page.props = { accountClosing: true, activeWorkspace: { kind: 'creator', experience: 'partner' } };
    expect(await open()).toEqual(['Account closure', 'Authenticator security', 'Sign out']);
});
