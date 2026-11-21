import { afterEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { ref } from 'vue';
import FoundationShell from '../../resources/js/layouts/FoundationShell.vue';
import Professional from '../../resources/js/pages/Professional.vue';
import WorkspaceNotice from '../../resources/js/components/WorkspaceNotice.vue';
import {
    isProfessionalTool,
    professionalDestination,
    professionalGroups,
} from '../../resources/js/features/portals/professionalNavigation';
const page = vi.hoisted(() => ({ url: '/professional', props: {} }));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    Head: { template: '<span />' },
    router: { post: vi.fn() },
}));
const wrappers: VueWrapper[] = [];
afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()));
it('limits the new shell to Professional tools, including canonical detail URLs', () => {
    for (const [experience, url, expected] of [
        ['professional', '/professional', true],
        ['professional', '/finance/invoices/draft-id', true],
        ['professional', '/partner/contacts?entry=lead', true],
        ['professional', '/automation', true],
        ['professional', '/workspaces', false],
        ['professional', '/admin', false],
        ['professional', '/onboarding/token', false],
        ['partner', '/partner/contacts', false],
        ['brand', '/finance/payments', false],
    ] as const) {
        page.url = url;
        const wrapper = mount(FoundationShell, {
            global: {
                provide: { workspaceExperience: ref(experience) },
                stubs: { ProfessionalShell: { template: '<div class="professional-test"><slot /></div>' } },
            },
        });
        wrappers.push(wrapper);
        expect(wrapper.find('.professional-test').exists(), `${experience}: ${url}`).toBe(expected);
    }
});
it('retains every tool, including Brand sharing, and maps canonical navigation without changing detail IDs', () => {
    expect(professionalGroups.flatMap((group) => group.links)).toHaveLength(10);
    expect(new Set(professionalGroups.flatMap((group) => group.links.map((link) => link.href))).size).toBe(10);
    expect(
        professionalGroups.find((group) => group.label === 'Client work')!.links.map((link) => link.label),
    ).toContain('Brand sharing');
    expect(isProfessionalTool('/partner/brand-sharing?lead=abc')).toBe(true);
    expect(professionalDestination('/finance/invoices/123?version=2')).toBe('/professional/finance/invoices/123');
    expect(professionalDestination('/partner/media-kit')).toBe('/professional/profile');
    expect(isProfessionalTool('/professional/login')).toBe(false);
});
for (const [state, label] of [
    ['active', 'Active subscription'],
    ['pending activation', 'Pending activation'],
    ['scheduled', 'Scheduled subscription'],
    ['expired', 'Expired subscription'],
]) {
    it(`presents ${state} access without repeating an active warning`, () => {
        const wrapper = mount(WorkspaceNotice, {
            props: { compact: true },
            global: { provide: { professionalSubscription: ref({ state }) } },
        });
        wrappers.push(wrapper);
        expect(wrapper.text()).toContain(label);
        expect(wrapper.find('.pro-subscription-paused').exists()).toBe(state !== 'active');
        expect(wrapper.find('a').exists()).toBe(state !== 'active');
    });
}
it('shows readable UTC subscription dates, exact counts and every CSV export on the hub', () => {
    const wrapper = mount(Professional, {
        props: {
            account: { name: 'Sam', email: 'sam@example.test' },
            workspace: { name: 'Studio' },
            subscription: {
                state: 'scheduled',
                plan: {
                    name: 'Assigned plan',
                    starts_at: '2026-11-08 10:00:00+00:00',
                    ends_at: '2026-12-08 10:00:00+00:00',
                },
                usage: [{ resource: 'pipeline_entries', used: 17, limit: 100 }],
            },
            isOwner: true,
            canWrite: false,
            summary: {
                leads: { open: 0, stages: {} },
                reminders: { overdue: 0, dueDay: 0, dueWeek: 0 },
                onboarding: { submitted: 0, correction: 0 },
                invoices: { drafts: 0, dueWeek: 0 },
                payments: 0,
                templates: 0,
                followUps: 0,
                profilePublished: false,
            },
            upNext: { reminderTotal: 0, onboardingTotal: 0, reminders: [], onboarding: [] },
        },
        global: { stubs: { FoundationShell: { template: '<main><slot /></main>' } } },
    });
    wrappers.push(wrapper);
    expect(wrapper.text()).toContain('17 / 100');
    expect(wrapper.text()).toContain('UTC');
    expect(wrapper.text()).not.toContain('10:00:00+00:00');
    expect(wrapper.findAll('a[href^="/professional/exports/"]')).toHaveLength(5);
    expect(wrapper.findAll('.hub-tool-group a')).toHaveLength(9);
});
