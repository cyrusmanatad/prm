import { afterEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import BrandDashboard from '../../resources/js/features/portals/BrandDashboard.vue';
import PartnerDashboard from '../../resources/js/features/portals/PartnerDashboard.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), post: vi.fn() },
    usePage: () => ({ url: '/brand', props: {} }),
}));
vi.mock('../../resources/js/composables/useWorkspaceExperience', async () => {
    const { ref } = await import('vue');
    return {
        useWorkspaceExperience: () => ({
            professional: ref(false),
            path: (value: string) => value,
            clientLabel: 'brand',
        }),
    };
});
const wrappers: VueWrapper[] = [];
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.useRealTimers();
});

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

it.each([
    [0, 'Good morning'],
    [11, 'Good morning'],
    [12, 'Good afternoon'],
    [17, 'Good afternoon'],
    [18, 'Good evening'],
    [23, 'Good evening'],
])('greets the partner in English at local hour %i', (hour, greeting) => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(2026, 10, 17, hour, 0));
    const wrapper = mount(PartnerDashboard, { props: { data: data('creator') }, global: { stubs } });
    wrappers.push(wrapper);
    expect(wrapper.get('h1').text()).toBe(`${greeting}, Jordan`);
});

it('updates the greeting at noon and evening, updates the date at midnight, and stops its clock on unmount', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(2026, 10, 17, 11, 59));
    const wrapper = mount(PartnerDashboard, { props: { data: data('creator') }, global: { stubs } });
    expect(wrapper.get('h1').text()).toBe('Good morning, Jordan');
    await vi.advanceTimersByTimeAsync(60_000);
    expect(wrapper.get('h1').text()).toBe('Good afternoon, Jordan');
    vi.setSystemTime(new Date(2026, 10, 17, 17, 59));
    await vi.advanceTimersByTimeAsync(60_000);
    expect(wrapper.get('h1').text()).toBe('Good evening, Jordan');
    vi.setSystemTime(new Date(2026, 10, 17, 23, 59));
    await vi.advanceTimersByTimeAsync(60_000);
    await nextTick();
    expect(wrapper.get('h1').text()).toBe('Good morning, Jordan');
    expect(wrapper.text()).toContain('Wednesday 18 November');
    wrapper.unmount();
    expect(vi.getTimerCount()).toBe(0);
});

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
    expect(rows[1]!.find('button[aria-label="End partnership with Harbor Creator"]').exists()).toBe(true);
    // A paused partner shows no figures: a zero would read as "no registrations".
    expect(rows[1]!.findAll('td')[1]!.text()).toBe('Paused');
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
