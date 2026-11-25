import { afterEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { ref } from 'vue';
import FoundationShell from '../../resources/js/layouts/FoundationShell.vue';

const page = vi.hoisted(() => ({ url: '/partner/contacts', props: {} as Record<string, unknown> }));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: vi.fn(), get: vi.fn() },
}));
const wrappers: VueWrapper[] = [];
afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()));

const partner = { kind: 'creator', experience: 'partner' };
it('frames every Partner tool and detail in the Partner portal shell, and nothing else', () => {
    for (const [workspace, url, expected] of [
        [partner, '/partner/contacts', true],
        [partner, '/partner/contacts?entry=lead', true],
        [partner, '/partner/outreach-templates', true],
        [partner, '/partner/onboarding/request-id', true],
        [partner, '/workspaces', false],
        [partner, '/account/exports', false],
        [partner, '/automation', true],
        [partner, '/finance/invoices/draft-id', true],
        [partner, '/workspace/entitlements', true],
        [partner, '/workspace/support-access', false],
        [partner, '/partner/contactsx', false],
        [{ kind: 'brand', experience: 'partner' }, '/partner/contacts', false],
        [null, '/partner/contacts', false],
    ] as const) {
        page.url = url;
        page.props = { activeWorkspace: workspace };
        const wrapper = mount(FoundationShell, {
            props: { accountName: 'Bea Santos', accountEmail: 'bea@example.test' },
            global: {
                provide: { workspaceExperience: ref('partner') },
                stubs: {
                    PartnerChrome: { template: '<div class="partner-test"><slot /></div>' },
                    AccountMenu: true,
                    WorkspaceNotice: true,
                },
            },
            slots: { default: '<p class="page-body">Body</p>' },
        });
        wrappers.push(wrapper);
        const chrome = wrapper.find('.partner-test');
        expect(chrome.exists(), `${workspace?.kind ?? 'none'}: ${url}`).toBe(expected);
        // The page body stays inside one main landmark either way.
        expect(wrapper.findAll('main#main-content')).toHaveLength(1);
        expect(wrapper.get('main#main-content').find('.page-body').exists()).toBe(true);
    }
});
