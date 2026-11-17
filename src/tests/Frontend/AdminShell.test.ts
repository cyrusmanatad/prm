import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';
import AdminShell from '../../resources/js/layouts/AdminShell.vue';
import { activeHref, adminGroups, visibleGroups } from '../../resources/js/features/admin/navigation';

const page = vi.hoisted(() => ({ value: undefined as unknown as { url: string; props: Record<string, unknown> } }));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page.value,
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    Head: { template: '<span />' },
    router: { post: vi.fn() },
}));
let wrapper: VueWrapper;
beforeEach(() => {
    page.value = reactive({
        url: '/admin/notifications?state=uncertain',
        props: {
            account: { name: 'Morgan Reyes', email: 'morgan@example.test' },
            admin: { capabilities: ['notifications.read'], environment: 'Staging', verified: true },
        },
    });
    HTMLDialogElement.prototype.showModal = function () {
        this.open = true;
    };
    HTMLDialogElement.prototype.close = function () {
        this.open = false;
    };
});
afterEach(() => wrapper?.unmount());
function render() {
    wrapper = mount(AdminShell, {
        props: { title: 'Notifications', description: 'Review delivery state.' },
        slots: { default: '<p>Body</p>' },
        global: { stubs: { AccountMenu: { template: '<span />' }, BrandLogo: { template: '<span />' } } },
        attachTo: document.body,
    });
    return wrapper;
}

it('labels the privileged area and shows only modules the operator may open', () => {
    render();
    expect(wrapper.get('.adm-labels').text()).toContain('Administration');
    expect(wrapper.get('.adm-label-environment').text()).toBe('Environment: Staging');
    expect(wrapper.get('.adm-operator').text()).toBe('Scoped operator');
    expect(wrapper.get('h1').text()).toBe('Notifications');
    const links = wrapper.findAll('.adm-sidebar a');
    expect(links.map((link) => link.text())).toEqual(['Overview', 'Notifications', 'My admin security']);
    expect(links.map((link) => link.attributes('aria-current'))).toEqual([undefined, 'page', undefined]);
    expect(wrapper.get('a.wd-skip-link').attributes('href')).toBe('#main-content');
});

it('offers only the security screen before step-up and everything to a verified owner', () => {
    const context = { capabilities: ['platform.owner'], environment: 'Development', verified: false };
    expect(visibleGroups(context).flatMap((group) => group.links.map((link) => link.href))).toEqual([
        '/admin/security',
    ]);
    expect(visibleGroups({ ...context, verified: true }).flatMap((group) => group.links)).toHaveLength(
        adminGroups.flatMap((group) => group.links).length,
    );
    expect(visibleGroups(undefined).flatMap((group) => group.links.map((link) => link.href))).toEqual([
        '/admin/security',
    ]);
});

it('matches the most specific navigation entry for detail URLs', () => {
    const links = adminGroups.flatMap((group) => group.links);
    expect(activeHref('/admin', links)).toBe('/admin');
    expect(activeHref('/admin/notifications/abc?x=1', links)).toBe('/admin/notifications');
    expect(activeHref('/admin/workspaces/abc?tab=members', links)).toBe('/admin/workspaces');
    expect(activeHref('/workspaces', links)).toBeUndefined();
});

it('opens the navigation drawer as a modal and closes it on navigation', async () => {
    render();
    const drawer = wrapper.get('dialog').element as HTMLDialogElement;
    await wrapper.get('.adm-menu-trigger').trigger('click');
    expect(drawer.open).toBe(true);
    expect(wrapper.get('dialog').attributes('aria-labelledby')).toBe('adm-drawer-title');
    page.value.url = '/admin/security';
    await wrapper.vm.$nextTick();
    expect(drawer.open).toBe(false);
    await wrapper.get('.adm-menu-trigger').trigger('click');
    await wrapper.get('dialog button[aria-label="Close menu"]').trigger('click');
    expect(drawer.open).toBe(false);
});
