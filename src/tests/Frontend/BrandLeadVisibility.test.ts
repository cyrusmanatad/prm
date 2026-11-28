import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';
import BrandDashboard from '../../resources/js/features/portals/BrandDashboard.vue';
import BrandLeadRegistrations from '../../resources/js/pages/BrandLeadRegistrations.vue';
import BrandPartner from '../../resources/js/pages/BrandPartner.vue';
import BrandSharing from '../../resources/js/pages/BrandSharing.vue';
import { confirmation } from '../../resources/js/composables/useConfirmation';
import { relativeTime } from '../../resources/js/features/portals/activity';
import type { PartnerRow } from '../../resources/js/features/portals/types';

const router = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), delete: vi.fn() }));
// The portal sidebar marks the current tool from the address, as on a real visit.
const page = vi.hoisted(() => ({ url: '/brand', props: {} }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router,
    usePage: () => page,
    useForm: (initial: Record<string, unknown>) =>
        reactive({
            ...initial,
            errors: {} as Record<string, string>,
            processing: false,
            post: vi.fn(),
            put: vi.fn(),
            patch: vi.fn(),
            clearErrors: vi.fn(),
            defaults: vi.fn(),
            reset: vi.fn(),
        }),
}));
const wrappers: VueWrapper[] = [];
const stubs = { AccountMenu: true, WorkspaceNotice: true };
HTMLDialogElement.prototype.showModal ??= function (this: HTMLDialogElement) {
    this.open = true;
};
HTMLDialogElement.prototype.close ??= function (this: HTMLDialogElement) {
    this.open = false;
    this.dispatchEvent(new Event('close'));
};
beforeEach(() => vi.clearAllMocks());
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    confirmation.value = null;
});
function show<T>(wrapper: T & VueWrapper): T {
    wrappers.push(wrapper);
    return wrapper;
}

const account = { name: 'Jordan Vale', email: 'jordan@example.test' };
const workspace = { id: 'w', name: 'Northline Living', kind: 'brand' as const };
const stages = [
    { value: 'lead', label: 'Lead' },
    { value: 'repeat-client', label: 'Repeat Client' },
    { value: 'lost', label: 'Lost' },
];
const partner = (extra: Partial<PartnerRow> = {}): PartnerRow => ({
    id: 'p1',
    brand_name: 'Northline Living',
    creator_name: 'Cedar Creator',
    status: 'active',
    accepted_at: '2026-11-01T00:00:00+00:00',
    paused: false,
    leads_total: 0,
    leads_30d: 0,
    last_active: null,
    ...extra,
});
const hub = (partners: PartnerRow[], extra: Record<string, unknown> = {}) => ({
    account,
    workspace,
    relationships: partners,
    partners,
    partnerPagination: { page: 1, perPage: 10, total: 13 },
    partnerFilters: { q: '' },
    metrics: { activePartners: 13, leadsRegistered: 0 },
    canManage: true,
    pipeline: null,
    ...extra,
});
const registration = {
    id: 'r1',
    display_name: 'Liwanag Realty gifting',
    stage: 'repeat-client',
    registered_at: '2026-11-10T08:00:00+00:00',
    shared_updated_at: '2026-11-12T09:30:00+00:00',
    partnership_id: 'p1',
    partner_name: 'Cedar Creator',
};

it('describes elapsed time in words and falls back to a date', () => {
    const now = new Date('2026-11-18T12:00:00Z');
    expect(relativeTime('2026-11-18T11:59:40Z', now)).toBe('Just now');
    expect(relativeTime('2026-11-18T11:59:00Z', now)).toBe('1 minute ago');
    expect(relativeTime('2026-11-18T09:00:00Z', now)).toBe('3 hours ago');
    expect(relativeTime('2026-11-17T10:00:00Z', now)).toBe('Yesterday');
    expect(relativeTime('2026-11-09T12:00:00Z', now)).toBe('9 days ago');
    expect(relativeTime('2026-09-01T12:00:00Z', now)).toBe('1 Sept 2026');
    // A clock slightly behind the server never shows a negative time.
    expect(relativeTime('2026-11-18T12:00:05Z', now)).toBe('Just now');
    expect(relativeTime('not a date', now)).toBe('Not yet recorded');
});

it('shows real roster figures, links and totals that do not depend on the page', () => {
    const rows = [
        partner(),
        partner({
            id: 'p2',
            creator_name: 'Harbor Creator',
            leads_30d: 4,
            last_active: { at: new Date(Date.now() - 2 * 3600_000).toISOString(), kind: 'lead_updated' },
        }),
    ];
    const wrapper = show(mount(BrandDashboard, { props: { data: hub(rows) }, global: { stubs } }));
    // Totals come from the full result: 13 partners although two rows are on this page.
    const cards = wrapper.findAll('[aria-label="Program summary"] > div');
    expect(cards[0]!.text()).toContain('Active partners13');
    expect(wrapper.text()).toContain('All 13');
    const leads = cards.find((card) => card.text().includes('Leads registered'))!;
    expect(leads.text()).toContain('0');
    expect(leads.text()).not.toContain('Unavailable');
    expect(leads.get('a').attributes('href')).toBe('/brand/lead-registrations');
    const body = wrapper.findAll('tbody tr');
    expect(body[0]!.get('th a').attributes('href')).toBe('/brand/partners/p1');
    expect(body[0]!.get('th .wd-partner-avatar').text()).toBe('CC');
    expect(body[0]!.get('th .wd-partner-avatar').attributes('aria-hidden')).toBe('true');
    // No registrations is a zero that still opens the matching filtered list.
    const zero = body[0]!.findAll('td')[1]!.get('a');
    expect(zero.text()).toBe('0');
    expect(zero.attributes('href')).toBe('/brand/lead-registrations?partner=p1&window=30d');
    expect(body[0]!.findAll('td')[5]!.text()).toBe('Not yet recorded');
    expect(body[1]!.findAll('td')[1]!.get('a').text()).toBe('4');
    expect(body[1]!.findAll('td')[5]!.text()).toContain('2 hours ago');
    expect(body[1]!.findAll('td')[5]!.text()).toContain('Lead updated');
    expect(body[1]!.get('time').attributes('title')).toMatch(/2026/);
    // Unrelated metrics stay unavailable.
    expect(body[0]!.findAll('td')[0]!.text()).toBe('Unavailable');
    expect(wrapper.get('nav[aria-label="Partners pages"]').text()).toContain('Showing 1–2 of 13 matching partners');
    expect(wrapper.get('nav[aria-label="Partners pages"] a').attributes('href')).toBe('/brand?page=2');
    const nav = wrapper.get('nav[aria-label="Brand tools"]');
    expect(nav.get('a[aria-current="page"]').text()).toBe('Partner hub');
    expect(nav.findAll('a').map((link) => link.attributes('href'))).toEqual([
        '/brand/home',
        '/brand',
        '/brand/lead-registrations',
        '/brand/campaigns',
        '/brand/incentives',
    ]);
    // Unavailable programs stay listed, never as links.
    expect(nav.findAll('[aria-disabled="true"]').map((item) => item.text())).toEqual([
        'Enablement, coming Soon',
        'Payouts, coming Soon',
    ]);
});

it('ends a partnership only through its named icon button and hides it from other roles', async () => {
    const wrapper = show(mount(BrandDashboard, { props: { data: hub([partner()]) }, global: { stubs } }));
    const button = wrapper.get('button[aria-label="End partnership with Cedar Creator"]');
    expect(button.attributes('title')).toBe('End partnership with Cedar Creator');
    expect(button.text()).toBe('');
    await button.trigger('click');
    // The dashboard only asks; the page opens the confirmation and nothing is sent from here.
    expect(wrapper.emitted('remove')![0]![0]).toBe('p1');
    expect(router.delete).not.toHaveBeenCalled();
    const viewer = show(
        mount(BrandDashboard, { props: { data: hub([partner()], { canManage: false }) }, global: { stubs } }),
    );
    expect(viewer.find('button[aria-label^="End partnership"]').exists()).toBe(false);
});

it('keeps the roster search in the address, resets the page and explains an empty result', async () => {
    const wrapper = show(
        mount(BrandDashboard, {
            props: {
                data: hub([], {
                    partnerFilters: { q: 'cedar' },
                    partnerPagination: { page: 1, perPage: 10, total: 0 },
                }),
            },
            global: { stubs },
        }),
    );
    expect(wrapper.text()).toContain('No partners match this search');
    expect(wrapper.text()).toContain('All 13');
    expect((wrapper.get('#partner-search').element as HTMLInputElement).value).toBe('cedar');
    await wrapper.get('#partner-search').setValue('harbor');
    await wrapper.get('form[role="search"]').trigger('submit');
    expect(router.get).toHaveBeenCalledWith('/brand', { q: 'harbor' }, { preserveScroll: true });
});

const registrationList = (extra: Record<string, unknown> = {}) => ({
    account,
    workspace,
    registrations: [registration],
    pagination: { page: 2, perPage: 25, total: 26 },
    filters: { partner: 'p1', stage: '', q: '', window: '30d', sort: '', per_page: '' },
    partners: [
        { id: 'p1', creator_name: 'Cedar Creator' },
        { id: 'p2', creator_name: 'Harbor Creator' },
    ],
    stages,
    facets: { stages: { lead: 20, 'repeat-client': 6 }, stageTotal: 26, partners: { p1: 26, p2: 3 }, partnerTotal: 29 },
    ...extra,
});

it('lists registrations with links that carry the list state and states the sharing scope', () => {
    page.url = '/brand/lead-registrations?stage=pitched&page=2';
    const wrapper = show(mount(BrandLeadRegistrations, { props: registrationList(), global: { stubs } }));
    expect(wrapper.get('nav[aria-label="Brand tools"] a[aria-current="page"]').text()).toBe('Lead registrations');
    page.url = '/brand';
    expect(wrapper.text()).toContain('Their private notes, estimates and other prospects are never shown.');
    expect(wrapper.get('#registered-leads-heading').text()).toBe('Registered leads');
    expect(wrapper.get('.rg-card-head .rg-count').text()).toBe('26');
    expect(wrapper.text()).toContain('Counts are registrations, not sales, revenue or estimates.');
    const row = wrapper.get('tbody tr');
    // A lead opens in the drawer over the list, which keeps its filters and page.
    expect(row.get('th a').attributes('href')).toBe(
        '/brand/lead-registrations?partner=p1&window=30d&page=2&registration=r1',
    );
    expect(row.findAll('td')[0]!.get('a').attributes('href')).toBe('/brand/partners/p1');
    expect(row.findAll('td')[0]!.get('.wd-partner-avatar').classes()).toContain('is-28');
    expect(row.get('[data-stage="repeat-client"]').text()).toBe('Repeat Client');
    expect(row.findAll('td')[2]!.get('time').attributes('datetime')).toBe(registration.registered_at);
    expect(row.findAll('td')[3]!.get('time').attributes('datetime')).toBe(registration.shared_updated_at);
    // Stage chips count registrations under the other filters and keep them when chosen.
    const chips = wrapper.get('nav[aria-label="Filter by stage"]');
    expect(chips.get('a[aria-current="true"]').text()).toBe('All 26');
    const lost = chips.findAll('a').find((chip) => chip.text().startsWith('Lost'))!;
    expect(lost.text()).toBe('Lost 0');
    expect(lost.attributes('href')).toBe('/brand/lead-registrations?partner=p1&stage=lost&window=30d');
    // Sortable headers state their order and keep the filters.
    const headers = wrapper.findAll('thead th');
    expect(headers[3]!.attributes('aria-sort')).toBe('descending');
    expect(headers[0]!.attributes('aria-sort')).toBe('none');
    expect(headers[0]!.get('a').attributes('href')).toBe(
        '/brand/lead-registrations?partner=p1&window=30d&sort=lead_asc',
    );
    expect(headers[3]!.get('a').attributes('href')).toBe(
        '/brand/lead-registrations?partner=p1&window=30d&sort=registered_asc',
    );
    const pages = wrapper.get('nav[aria-label="Lead registrations pages"]');
    expect(pages.text()).toContain('Showing 26–26 of 26 matching registrations');
    expect(pages.get('a[aria-current="page"]').text()).toBe('2');
    expect(pages.get('a[aria-label="Page 1"]').attributes('href')).toBe(
        '/brand/lead-registrations?partner=p1&window=30d',
    );
    expect(wrapper.text()).toContain('Clear filters');
});

it('applies registration filters as they are chosen and keeps the sort order', async () => {
    const wrapper = show(
        mount(BrandLeadRegistrations, {
            props: registrationList({
                filters: { partner: '', stage: '', q: '', window: '', sort: 'lead_asc', per_page: '' },
            }),
            global: { stubs },
        }),
    );
    expect(wrapper.text()).not.toContain('Clear filters');
    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: expect.any(Function),
        onFinish: expect.any(Function),
    };
    await wrapper.get('#registration-per-page').setValue('10');
    expect(router.get).toHaveBeenLastCalledWith(
        '/brand/lead-registrations',
        { sort: 'lead_asc', per_page: '10' },
        options,
    );
    await wrapper.get('#registration-search').setValue('kanto');
    await wrapper.get('form[role="search"]').trigger('submit');
    expect(router.get).toHaveBeenLastCalledWith('/brand/lead-registrations', { q: 'kanto', sort: 'lead_asc' }, options);
    // The partner list shows each partner's count and filters at once.
    await wrapper.get('#registration-partner-trigger').trigger('click');
    const harbor = wrapper.findAll('[role="option"]').find((option) => option.text().includes('Harbor Creator'))!;
    expect(harbor.text()).toContain('3');
    await harbor.trigger('click');
    expect(router.get).toHaveBeenLastCalledWith(
        '/brand/lead-registrations',
        { partner: 'p2', sort: 'lead_asc' },
        options,
    );
    expect(wrapper.findAll('thead th')[0]!.attributes('aria-sort')).toBe('ascending');
});

it('opens a shared lead in a read-only drawer over the list and closes back to the same list', async () => {
    const selected = {
        ...registration,
        brand_name: 'Northline Living',
        summary: 'Corporate gifting for 40 agents',
        share_contact: false,
        contact_name: null,
        contact_email: null,
        partner_since: '2026-10-12T08:00:00+00:00',
        partner_leads: 3,
    };
    const wrapper = show(
        mount(BrandLeadRegistrations, {
            props: {
                ...registrationList(),
                summary: { total: 26, recent: 4, partners: 2, updatedWeek: 1 },
                selected,
            },
            global: { stubs },
            attachTo: document.body,
        }),
    );
    await nextTick();
    expect(wrapper.get('ul[aria-label="Registration summary"]').text().replace(/\s+/g, ' ')).toContain(
        'Registrations26Last 30 days4Partners registering2Updated this week1',
    );
    const drawer = wrapper.get('dialog[aria-labelledby="registration-title"]');
    expect((drawer.element as HTMLDialogElement).open).toBe(true);
    expect(drawer.get('h2').text()).toBe('Liwanag Realty gifting');
    expect(drawer.get('[data-stage="repeat-client"]').text()).toBe('Repeat Client');
    expect(drawer.text()).toContain('Partner since Oct 12, 2026 · 3 leads registered');
    expect(drawer.get('a[href="/brand/partners/p1"]').text()).toContain('Open partner');
    expect(drawer.text()).toContain('Corporate gifting for 40 agents');
    // The contact is mentioned once, and an unchanged record says so instead of repeating the time.
    expect(drawer.text().match(/Not shared by the partner/g)).toHaveLength(1);
    expect(drawer.text()).toContain('Read-only');
    expect(drawer.find('form').exists()).toBe(false);
    expect(document.activeElement?.getAttribute('aria-label')).toBe('Close lead registration');
    await drawer.get('button[aria-label="Close lead registration"]').trigger('click');
    expect(router.get).toHaveBeenLastCalledWith(
        '/brand/lead-registrations?partner=p1&window=30d&page=2',
        {},
        { preserveScroll: true, preserveState: true },
    );
    const unchanged = show(
        mount(BrandLeadRegistrations, {
            props: {
                ...registrationList(),
                selected: { ...selected, shared_updated_at: selected.registered_at, summary: '', share_contact: true },
            },
            global: { stubs },
            attachTo: document.body,
        }),
    );
    await nextTick();
    const other = unchanged.get('dialog[aria-labelledby="registration-title"]');
    expect(other.text()).toContain('No changes since registering');
    expect(other.text()).toContain('No summary provided');
    expect(other.text()).toContain('Not provided');
});

it('shows a partner record with an empty optional profile and never links an unsafe address', () => {
    const props = {
        account,
        workspace,
        stages,
        registrations: [registration],
        pagination: { page: 1, perPage: 10, total: 1 },
        filters: { q: '', stage: '', window: '', sort: '' },
        facets: { stages: { 'repeat-client': 1 }, stageTotal: 1 },
        canManage: true,
        partner: {
            ...partner({ leads_total: 1, leads_30d: 1 }),
            profile: { contact_name: null, contact_email: null, website_url: 'javascript:alert(1)', description: '' },
        },
    };
    const wrapper = show(mount(BrandPartner, { props, global: { stubs } }));
    expect(wrapper.text()).toContain('Not shared');
    expect(wrapper.findAll('a').some((link) => link.attributes('href')?.startsWith('javascript'))).toBe(false);
    expect(wrapper.findAll('a.wd-stat-chip').map((link) => link.attributes('href'))).toEqual([
        '/brand/lead-registrations?partner=p1',
        '/brand/lead-registrations?partner=p1&window=30d',
    ]);
    // From a Partner record, a lead opens in the drawer over that partner's registrations.
    expect(wrapper.get('tbody th a').attributes('href')).toBe('/brand/lead-registrations?partner=p1&registration=r1');
    expect(wrapper.get('.wd-record-identity .wd-partner-avatar').classes()).toContain('is-48');
    // One record title; the partnership status and an Email action from the shared profile sit in the header.
    expect(wrapper.findAll('h1').map((title) => title.text())).toEqual(['Cedar Creator']);
    expect(wrapper.get('.wd-record-meta').text()).toContain('Active');
    expect(wrapper.find('.wd-record-actions a[href^="mailto:"]').exists()).toBe(false); // No email shared.
    expect(wrapper.find('tbody .wd-partner-avatar').exists()).toBe(false); // The record already names its partner.
    const safe = show(
        mount(BrandPartner, {
            props: {
                ...props,
                partner: {
                    ...props.partner,
                    profile: { ...props.partner.profile, website_url: 'https://example.test/p' },
                },
            },
            global: { stubs },
        }),
    );
    expect(safe.get('a[href="https://example.test/p"]').attributes('rel')).toContain('noopener');
    const contact = show(
        mount(BrandPartner, {
            props: {
                ...props,
                partner: {
                    ...props.partner,
                    profile: { ...props.partner.profile, contact_name: 'Ana Reyes', contact_email: 'ana@example.test' },
                },
            },
            global: { stubs },
        }),
    );
    expect(contact.get('.wd-record-actions a[href="mailto:ana@example.test"]').text()).toBe('Email Ana Reyes');
    const paused = show(
        mount(BrandPartner, {
            props: {
                ...props,
                registrations: [],
                partner: { ...partner({ paused: true, leads_total: null, leads_30d: null }), profile: null },
            },
            global: { stubs },
        }),
    );
    expect(paused.text()).toContain('Not shown while the partnership is paused.');
    expect(paused.find('.wd-stat-chip').exists()).toBe(false);
    expect(paused.find('form[role="search"]').exists()).toBe(false);
    expect(paused.find('.wd-record-actions').exists()).toBe(false);
    expect(paused.get('.wd-record-meta').text()).toContain('Paused');
    expect(paused.get('.wd-record-meta').text()).not.toContain('Last active');
    expect(paused.text()).not.toMatch(/suspend/i);
});

it('searches, filters and sorts a partner record within that partner only', async () => {
    const props = {
        account,
        workspace,
        stages,
        registrations: [registration],
        pagination: { page: 1, perPage: 10, total: 1 },
        filters: { q: '', stage: '', window: '', sort: 'lead_asc' },
        facets: { stages: { lead: 2, 'repeat-client': 1 }, stageTotal: 3 },
        canManage: true,
        partner: { ...partner({ leads_total: 3, leads_30d: 1 }), profile: null },
    };
    const wrapper = show(mount(BrandPartner, { props, global: { stubs } }));
    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: expect.any(Function),
        onFinish: expect.any(Function),
    };
    // Stage chips carry counts and keep the other filters; no partner choice is offered on a record.
    const chips = wrapper.findAll('.wd-filter-chips a');
    expect(chips.map((chip) => chip.text())).toEqual(['All 3', 'Lead 2', 'Repeat Client 1', 'Lost 0']);
    expect(chips[1]!.attributes('href')).toBe('/brand/partners/p1?stage=lead&sort=lead_asc');
    expect(wrapper.find('#partner-leads-partner-trigger').exists()).toBe(false);
    await wrapper.get('#partner-leads-search').setValue('liwanag');
    await wrapper.get('form[role="search"]').trigger('submit');
    expect(router.get).toHaveBeenLastCalledWith('/brand/partners/p1', { q: 'liwanag', sort: 'lead_asc' }, options);
    // Column headers sort within the record and return to its first page.
    expect(wrapper.findAll('thead th')[0]!.attributes('aria-sort')).toBe('ascending');
    expect(wrapper.get('thead th a').attributes('href')).toBe('/brand/partners/p1?sort=lead_desc');
    expect(wrapper.find('.wd-pagination').exists()).toBe(true);
    // A filter that matches nothing explains itself and offers Clear filters; the header figures stay unfiltered.
    const none = show(
        mount(BrandPartner, {
            props: { ...props, registrations: [], filters: { ...props.filters, q: 'zzz' } },
            global: { stubs },
        }),
    );
    expect(none.text()).toContain('No registrations match these filters');
    expect(none.get('a.wd-stat-chip').text()).toContain('3');
    vi.useFakeTimers();
    router.get.mockClear();
    await none
        .findAll('button')
        .find((button) => button.text() === 'Clear filters')!
        .trigger('click');
    expect(router.get).toHaveBeenLastCalledWith('/brand/partners/p1', { sort: 'lead_asc' }, options);
    // Emptying the search box must not send a second, delayed visit that would cancel the next navigation.
    await vi.advanceTimersByTimeAsync(1000);
    expect(router.get).toHaveBeenCalledTimes(1);
    vi.useRealTimers();
    // Before any lead is registered there is nothing to filter.
    const empty = show(
        mount(BrandPartner, {
            props: { ...props, registrations: [], partner: { ...props.partner, leads_total: 0 } },
            global: { stubs },
        }),
    );
    expect(empty.find('form[role="search"]').exists()).toBe(false);
    expect(empty.text()).toContain('No leads registered yet');
});

it('explains what a Brand will see before a partner registers a lead, and confirms a withdrawal', async () => {
    const own = {
        id: 'r1',
        display_name: 'Liwanag Realty gifting',
        summary: '',
        share_contact: false,
        stage: 'lead',
        state: 'active' as const,
        withdrawn_reason: null,
        version: 4,
        registered_at: '2026-11-10T08:00:00+00:00',
        entry_id: 'e1',
        brand_name: 'Northline Living',
        paused: false,
    };
    const props = {
        account,
        workspace: { id: 'c', name: 'Cedar Creator' },
        canManage: true,
        brands: [
            { id: 'p1', brand_name: 'Northline Living', paused: false },
            { id: 'p2', brand_name: 'Paused Brand', paused: true },
        ],
        lead: {
            id: 'e2',
            brand_name: 'Kanto Café',
            contact_name: 'Ana',
            contact_email: 'ana@example.test',
            stage: 'lead',
            registered_with: [],
        },
        registrations: [
            own,
            {
                ...own,
                id: 'r2',
                state: 'withdrawn' as const,
                withdrawn_reason: 'partnership_ended' as const,
                brand_name: null,
            },
        ],
        pagination: { page: 1, perPage: 25, total: 2 },
        profile: { contact_name: null, contact_email: null, website_url: null, description: '', version: 0 },
        stages,
    };
    const wrapper = show(
        mount(BrandSharing, {
            props,
            global: { stubs: { ...stubs, PartnerChrome: { template: '<div><slot /></div>' } } },
            attachTo: document.body,
        }),
    );
    // Arriving with a lead opens the share dialog with that lead chosen.
    await flushPromises();
    expect((wrapper.get('#share-title').element.closest('dialog') as HTMLDialogElement).open).toBe(true);
    expect(wrapper.text()).toContain('What the Brand will see');
    expect(wrapper.text()).toContain('Never shared');
    expect(wrapper.text()).toContain('Private notes, estimated value, reminders');
    // A paused Brand is not offered.
    expect(wrapper.get('#registration-brand').text()).toContain('Northline Living');
    expect(wrapper.get('#registration-brand').text()).not.toContain('Paused Brand');
    expect((wrapper.get('#registration-name').element as HTMLInputElement).value).toBe('Kanto Café');
    expect(wrapper.text()).not.toContain('plus the contact name and email');
    await wrapper.get('#registration-contact').setValue(true);
    expect(wrapper.text()).toContain('plus the contact name and email');
    const rows = wrapper.findAll('tbody tr');
    expect(rows[1]!.text()).toContain('Ended partnership');
    expect(rows[1]!.text()).toContain('The partnership ended.');
    expect(rows[1]!.find('button').exists()).toBe(false);
    await rows[0]!.get('button[aria-label="Withdraw registration of Liwanag Realty gifting"]').trigger('click');
    expect(router.delete).not.toHaveBeenCalled();
    expect(confirmation.value?.title).toBe('Withdraw registration');
    expect(confirmation.value?.description).toContain('Northline Living');
    expect(confirmation.value?.tone).toBe('danger');
    confirmation.value!.action();
    expect(router.delete).toHaveBeenCalledWith('/lead-registrations/r1', {
        data: { version: 4 },
        preserveScroll: true,
    });
    const viewer = show(
        mount(BrandSharing, {
            props: { ...props, canManage: false },
            global: { stubs: { ...stubs, PartnerChrome: { template: '<div><slot /></div>' } } },
        }),
    );
    expect(viewer.text()).toContain('Only workspace owners and admins');
    expect(viewer.find('button[aria-label^="Withdraw"]').exists()).toBe(false);
    expect(viewer.find('#registration-brand').exists()).toBe(false);
});

it('filters shared leads by state and Brand and summarises Brands and the shared profile', async () => {
    const props = {
        account,
        workspace: { id: 'c', name: 'Cedar Creator' },
        canManage: true,
        brands: [
            { id: 'p1', brand_name: 'Northline Living', paused: false, shared: 3 },
            { id: 'p2', brand_name: 'Lumen Home', paused: true, shared: 1 },
        ],
        lead: null,
        leads: [
            {
                id: 'e1',
                brand_name: 'Glow Labs',
                contact_name: null,
                contact_email: null,
                stage: 'lead',
                registered_with: ['p1'],
            },
        ],
        registrations: [],
        pagination: { page: 2, perPage: 25, total: 30 },
        counts: { shared: 4, not_shared: 26, all: 30 },
        filters: { state: 'not_shared' as const, brand: 'p1', q: '' },
        summary: { brands: 2, shared: 4, notShared: 26 },
        profile: {
            contact_name: 'Bea',
            contact_email: null,
            website_url: 'https://bea.example.test',
            description: '',
            version: 2,
        },
        stages,
    };
    const wrapper = show(
        mount(BrandSharing, {
            props,
            global: { stubs: { ...stubs, PartnerChrome: { template: '<div><slot /></div>' } } },
            attachTo: document.body,
        }),
    );
    await flushPromises();
    expect(wrapper.get('[aria-label="Sharing summary"]').text()).toContain('Withdrawn or ended26');
    const chips = wrapper.get('nav[aria-label="Filter by sharing"]');
    expect(chips.get('a[aria-current="true"]').text()).toContain('Not shared');
    expect(chips.findAll('a')[0]!.attributes('href')).toBe('/partner/brand-sharing?state=shared&brand=p1');
    expect(wrapper.text()).toContain('No shared leads match');
    expect(wrapper.get('[aria-labelledby="brands-title"]').text()).toContain(
        '1 lead shared · not visible while paused',
    );
    expect(wrapper.get('.bs-profile').text()).toContain('https://bea.example.test');
    // Without a lead context the dialog stays closed until Share a lead is used; a lead shared everywhere offers no Brand.
    expect(wrapper.find('#registration-lead').exists()).toBe(false);
    await wrapper
        .findAll('button')
        .find((node) => node.text() === 'Share a lead')!
        .trigger('click');
    await flushPromises();
    expect(wrapper.find('#registration-lead').exists()).toBe(true);
});
