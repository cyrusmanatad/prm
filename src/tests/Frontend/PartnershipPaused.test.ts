import { afterEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import BrandDashboard from '../../resources/js/features/portals/BrandDashboard.vue';
import PartnerDashboard from '../../resources/js/features/portals/PartnerDashboard.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), post: vi.fn() },
    usePage: () => ({ url: '/brand', props: {} }),
}));
vi.mock('../../resources/js/composables/useWorkspaceExperience', () => ({
    useWorkspaceExperience: () => ({
        professional: { value: false },
        path: (value: string) => value,
        clientLabel: 'brand',
    }),
}));
const wrappers: VueWrapper[] = [];
afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()));

const relationship = (paused: boolean) => ({
    id: paused ? 'paused' : 'active',
    brand_name: paused ? 'Harbor Brand' : 'Cedar Brand',
    creator_name: paused ? 'Harbor Creator' : 'Cedar Creator',
    status: 'active',
    accepted_at: '2026-11-01T00:00:00+00:00',
    paused,
});
const data = (kind: 'brand' | 'creator') => ({
    account: { name: 'Jordan Vale', email: 'jordan@example.test' },
    workspace: { id: 'w', name: 'Workspace', kind },
    relationships: [relationship(false), relationship(true)],
    canManage: true,
    pipeline: null,
});
const stubs = {
    AccountMenu: true,
    PartnerChrome: { template: '<div><slot /></div>' },
    LeadManager: true,
    PipelineBoard: true,
};

it('labels a partner whose workspace is suspended as paused on the brand roster, in words, and keeps the partnership manageable', () => {
    const wrapper = mount(BrandDashboard, { props: { data: data('brand') }, global: { stubs } });
    wrappers.push(wrapper);
    const rows = wrapper.findAll('tbody tr');
    expect(rows).toHaveLength(2);
    expect(rows[0]!.text()).toContain('Active partnership');
    expect(rows[0]!.find('.wd-badge').exists()).toBe(false);
    expect(rows[1]!.get('.wd-badge').text()).toBe('Paused');
    expect(rows[1]!.text()).toContain("This partner's workspace is currently unavailable. The partnership is kept.");
    expect(rows[1]!.text()).not.toContain('Active partnership');
    expect(rows[1]!.text()).not.toMatch(/suspend/i);
    expect(rows[1]!.text()).toContain('End partnership');
});

it('labels a brand whose workspace is suspended as paused in the partner portal', () => {
    const wrapper = mount(PartnerDashboard, { props: { data: data('creator') }, global: { stubs } });
    wrappers.push(wrapper);
    const text = wrapper.text();
    expect(text).toContain('Cedar Brand');
    expect(wrapper.findAll('.wd-badge')).toHaveLength(1);
    expect(wrapper.get('.wd-badge').text()).toBe('Paused');
    expect(text).toContain("This brand's workspace is currently unavailable. The partnership is kept.");
    expect(text).not.toMatch(/suspend/i);
});
