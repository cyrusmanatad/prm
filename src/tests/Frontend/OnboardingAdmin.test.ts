import { afterEach, expect, it, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import OnboardingAdmin from '../../resources/js/pages/OnboardingAdmin.vue';
import { ago, expiry } from '../../resources/js/features/onboarding/requests';
import { confirmation } from '../../resources/js/composables/useConfirmation';

const api = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    router: api,
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
HTMLDialogElement.prototype.showModal = function () {
    this.open = true;
};
HTMLDialogElement.prototype.close = function () {
    this.open = false;
};
const wrappers: VueWrapper[] = [];
afterEach(() => {
    wrappers.forEach((wrapper) => wrapper.unmount());
    wrappers.length = 0;
    confirmation.value = null;
    vi.clearAllMocks();
});
const now = Date.now();
const hours = (count: number) => new Date(now + count * 3_600_000).toISOString();
const rows = [
    {
        id: 'r1',
        label: 'Glow Labs onboarding',
        status: 'submitted' as const,
        version: 3,
        pipeline_entry_id: 'lead-1',
        brand_name: 'Glow Labs',
        created_at: hours(-96),
        expires_at: hours(5 * 24 - 1),
        submitted_at: hours(-2),
    },
    {
        id: 'r2',
        label: 'Paperfold onboarding',
        status: 'draft' as const,
        version: 1,
        pipeline_entry_id: 'lead-2',
        brand_name: 'Paperfold',
        created_at: hours(-30),
        expires_at: hours(-1),
        submitted_at: null,
    },
];
const selected = {
    id: 'r1',
    label: 'Glow Labs onboarding',
    status: 'submitted' as const,
    version: 3,
    business_name: 'Glow Labs Inc.',
    contact_name: 'Mia',
    business_email: 'mia@glow.test',
    website: '',
    brief: 'Three reels.',
    correction_note: '',
    pipeline_entry_id: 'lead-1',
    brand_name: 'Glow Labs',
    created_at: hours(-96),
    expires_at: hours(5 * 24 - 1),
    submitted_at: hours(-2),
    documents: [
        { id: 'd1', name: 'brief.pdf', size: 188_000, scan_state: 'clean', downloadable: true },
        { id: 'd2', name: 'mood.png', size: 1_300_000, scan_state: 'pending', downloadable: false },
    ],
};
function render(overrides: Record<string, unknown> = {}) {
    const wrapper = mount(OnboardingAdmin, {
        props: {
            account: { name: 'Maya', email: 'maya@example.test' },
            workspace: { id: 'org', name: 'Studio' },
            canWrite: true,
            requests: {
                items: rows,
                page: 1,
                perPage: 20,
                total: 2,
                counts: { submitted: 1, draft: 1, correction: 0, accepted: 0, revoked: 0, all: 2 },
            },
            filters: { status: 'all', q: '' },
            leads: [
                { id: 'lead-1', brand_name: 'Glow Labs' },
                { id: 'lead-3', brand_name: 'Northfield' },
            ],
            selected,
            emails: [{ id: 'e1', kind: 'onboarding.invitation', state: 'captured', created_at: hours(-96) }],
            submissions: [],
            issuedLink: null,
            ...overrides,
        },
        global: {
            stubs: { FoundationShell: { template: '<main><slot /></main>' } },
            mocks: { $page: { props: {} } },
        },
        attachTo: document.body,
    });
    wrappers.push(wrapper);
    return wrapper;
}
const button = (wrapper: VueWrapper, name: string) =>
    wrapper.findAll('button').find((node) => node.text() === name || node.attributes('aria-label') === name);

it('lists requests with plain status names, brand, timing and status filters in the address', () => {
    const wrapper = render();
    const items = wrapper.findAll('.onb-items li');
    expect(items[0]!.text()).toContain('Awaiting review');
    expect(items[0]!.text()).toContain('Glow Labs · submitted 2 h ago');
    expect(items[0]!.text()).toContain('Expires in 5 days');
    expect(items[1]!.text()).toContain('Invited');
    expect(items[1]!.text()).toContain('Expired');
    expect(items[0]!.get('a').attributes('href')).toBe('/partner/onboarding?request=r1&status=all');
    expect(items[0]!.get('a').attributes('aria-current')).toBe('page');
    const chips = wrapper.get('nav[aria-label="Filter requests by status"]');
    expect(chips.text()).toContain('Awaiting review 1');
    expect(chips.get('a').attributes('href')).toBe('/partner/onboarding?request=r1&status=submitted');
});

it('shows request details in tabs and reviews with a multi-line correction note', async () => {
    const wrapper = render();
    expect(wrapper.get('#selected-request').text()).toBe('Glow Labs onboarding');
    expect(wrapper.get('.onb-detail-meta').text()).toContain('Lead: Glow Labs');
    expect(wrapper.get('.onb-files').text()).toContain('184 KB');
    expect(wrapper.find('button[aria-label="Retry scan mood.png"]').exists()).toBe(true);
    const tabs = wrapper.findAll('[role="tab"]');
    expect(tabs.map((tab) => tab.text())).toEqual(['Submission', 'History0', 'Emails1']);
    await tabs[2]!.trigger('keydown', { key: 'ArrowLeft' });
    expect(wrapper.get('#request-tab-history').attributes('aria-selected')).toBe('true');
    await wrapper.get('#request-tab-emails').trigger('click');
    expect(wrapper.get('#request-panel-emails').text()).toContain('Invitation');
    expect(wrapper.get('#correction-note').element.tagName).toBe('TEXTAREA');
    expect(button(wrapper, 'Request correction')!.attributes('disabled')).toBeDefined();
    await wrapper.get('#correction-note').setValue('Add usage rights.');
    await button(wrapper, 'Request correction')!.trigger('click');
    expect(api.post.mock.calls[0]![0]).toBe('/onboarding-requests/r1/correct');
    expect(api.post.mock.calls[0]![1]).toMatchObject({ version: 3, note: 'Add usage rights.', send_email: false });
});

it('keeps replacement behind a disclosure and confirms replacement and revocation', async () => {
    const wrapper = render();
    expect(wrapper.find('#replacement-form').exists()).toBe(false);
    await button(wrapper, 'Replace invitation…')!.trigger('click');
    await flushPromises();
    expect(wrapper.find('#replacement-form').exists()).toBe(true);
    await wrapper.get('#replacement-form').trigger('submit');
    expect(confirmation.value?.title).toBe('Create replacement invitation');
    confirmation.value = null;
    await button(wrapper, 'Revoke invitation access')!.trigger('click');
    const pending = () => confirmation.value;
    expect(pending()?.title).toBe('Revoke invitation access');
    pending()!.action();
    expect(api.post.mock.calls[0]![0]).toBe('/onboarding-requests/r1/revoke');
});

it('creates a request for a chosen lead from the dialog with a suggested label', async () => {
    const wrapper = render();
    await button(wrapper, 'New request')!.trigger('click');
    expect((wrapper.get('dialog').element as HTMLDialogElement).open).toBe(true);
    await wrapper.get('#new-request-lead').setValue('lead-3');
    expect((wrapper.get('#new-request-label').element as HTMLInputElement).value).toBe('Northfield onboarding');
    await wrapper.get('dialog form').trigger('submit');
    expect(api.post.mock.calls[0]![0]).toBe('/onboarding-requests');
    expect(api.post.mock.calls[0]![1]).toEqual({
        pipeline_entry_id: 'lead-3',
        label: 'Northfield onboarding',
        send_email: false,
        recipient_email: '',
        notify_submission: false,
    });
});

it('keeps viewers to reading and explains an empty list', () => {
    const wrapper = render({
        canWrite: false,
        leads: [],
        selected: null,
        requests: {
            items: [],
            page: 1,
            perPage: 20,
            total: 0,
            counts: { submitted: 0, draft: 0, correction: 0, accepted: 0, revoked: 0, all: 0 },
        },
    });
    expect(button(wrapper, 'New request')).toBeUndefined();
    expect(wrapper.text()).toContain('No onboarding requests yet');
    expect(wrapper.text()).toContain('Choose a request');
});

it('describes timing in plain words', () => {
    const at = new Date(2026, 10, 21, 12);
    expect(ago(new Date(2026, 10, 21, 11, 30).toISOString(), at)).toBe('30 min ago');
    expect(ago(new Date(2026, 10, 20, 9).toISOString(), at)).toBe('yesterday');
    expect(expiry('accepted', new Date(2026, 10, 30).toISOString(), at)).toBeNull();
    expect(expiry('draft', new Date(2026, 10, 22, 10).toISOString(), at)).toBe('Expires tomorrow');
});
