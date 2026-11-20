import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';
import BrandDashboard from '../../resources/js/features/portals/BrandDashboard.vue';
import BrandLeadRegistration from '../../resources/js/pages/BrandLeadRegistration.vue';
import BrandLeadRegistrations from '../../resources/js/pages/BrandLeadRegistrations.vue';
import BrandPartner from '../../resources/js/pages/BrandPartner.vue';
import BrandSharing from '../../resources/js/pages/BrandSharing.vue';
import { confirmation } from '../../resources/js/composables/useConfirmation';
import { relativeTime } from '../../resources/js/features/portals/activity';
import type { PartnerRow } from '../../resources/js/features/portals/types';

const router = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), delete: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router,
    usePage: () => ({ url: '/brand', props: {} }),
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
    const nav = wrapper.get('nav[aria-label="Main"]');
    expect(nav.get('a[aria-current="page"]').text()).toBe('Partner hub');
    expect(nav.findAll('a')[1]!.attributes('href')).toBe('/brand/lead-registrations');
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
    const wrapper = show(mount(BrandLeadRegistrations, { props: registrationList(), global: { stubs } }));
    expect(wrapper.get('nav[aria-label="Main"] a[aria-current="page"]').text()).toBe('Lead registrations');
    expect(wrapper.text()).toContain("Partners' other prospects, private notes and estimates are never shown here.");
    expect(wrapper.get('#registered-leads-heading').text()).toBe('Registered leads');
    expect(wrapper.text()).toContain('26 registrations');
    expect(wrapper.text()).toContain('Counts are registrations, not sales, revenue or estimates.');
    const row = wrapper.get('tbody tr');
    expect(row.get('th a').attributes('href')).toBe('/brand/lead-registrations/r1?partner=p1&window=30d&page=2');
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

it('shows only shared lead fields and returns to where the lead was opened from', () => {
    const props = {
        account,
        workspace,
        stages,
        registration: {
            ...registration,
            brand_name: 'Northline Living',
            summary: '',
            share_contact: false,
            contact_name: null,
            contact_email: null,
        },
        back: {
            from: 'list' as const,
            page: 3,
            filters: { partner: '', stage: 'lost', q: 'gift', window: '', sort: '', per_page: '' },
        },
    };
    const wrapper = show(mount(BrandLeadRegistration, { props, global: { stubs } }));
    expect(wrapper.get('h1').text()).toBe('Liwanag Realty gifting');
    expect(wrapper.text()).toContain('Not shared by the partner');
    expect(wrapper.text()).toContain('No summary provided');
    expect(wrapper.text()).toContain('Read-only');
    expect(wrapper.find('form').exists()).toBe(false);
    expect(wrapper.get('.adm-back').attributes('href')).toBe('/brand/lead-registrations?stage=lost&q=gift&page=3');
    const fromPartner = show(
        mount(BrandLeadRegistration, {
            props: { ...props, back: { ...props.back, from: 'partner' as const, page: 1 } },
            global: { stubs },
        }),
    );
    expect(fromPartner.get('.adm-back').attributes('href')).toBe('/brand/partners/p1');
});

it('shows a partner record with an empty optional profile and never links an unsafe address', () => {
    const props = {
        account,
        workspace,
        stages,
        registrations: [registration],
        pagination: { page: 1, perPage: 10, total: 1 },
        canManage: true,
        partner: {
            ...partner({ leads_total: 1, leads_30d: 1 }),
            profile: { contact_name: null, contact_email: null, website_url: 'javascript:alert(1)', description: '' },
        },
    };
    const wrapper = show(mount(BrandPartner, { props, global: { stubs } }));
    expect(wrapper.text()).toContain('Not shared');
    expect(wrapper.findAll('a').some((link) => link.attributes('href')?.startsWith('javascript'))).toBe(false);
    expect(wrapper.findAll('.wd-stat-card a').map((link) => link.attributes('href'))).toEqual([
        '/brand/lead-registrations?partner=p1',
        '/brand/lead-registrations?partner=p1&window=30d',
    ]);
    expect(wrapper.get('tbody th a').attributes('href')).toBe('/brand/lead-registrations/r1?from=partner');
    expect(wrapper.get('.wd-partner-identity .wd-partner-avatar').classes()).toContain('is-48');
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
    expect(paused.find('.wd-stat-card').exists()).toBe(false);
    expect(paused.text()).not.toMatch(/suspend/i);
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
