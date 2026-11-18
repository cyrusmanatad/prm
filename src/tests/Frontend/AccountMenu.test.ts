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
    expect(await open()).toEqual(['Switch workspace / account security', 'Sign out']);
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());

    page.props = { platformAccess: true };
    expect(await open()).toEqual(['Switch workspace / account security', 'Platform administration', 'Sign out']);
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
