// Mockup only: synthetic data, no network requests, no storage. Every product link is a demo link.
(function () {
    'use strict';

    const DAY = 86_400_000;
    const NOW = Date.now();
    const ago = (days) => new Date(NOW - days * DAY).toISOString();
    const PER_PAGE = 10; // Partner record page size (brand-lead-visibility contract).
    const PARTNERSHIP = 'demo-partnership';

    const STAGES = {
        lead: { label: 'Lead', dot: 'var(--wd-stage-lead)' },
        pitched: { label: 'Pitched', dot: 'var(--wd-stage-pitched)' },
        negotiating: {
            label: 'Negotiating',
            dot: 'var(--wd-stage-negotiating)',
        },
        onboarded: { label: 'Onboarded', dot: 'var(--wd-stage-onboarded)' },
        active: { label: 'Active', dot: 'var(--wd-brand)' },
        'repeat-client': { label: 'Repeat Client', dot: 'var(--wd-brand)' },
        lost: { label: 'Lost', dot: 'var(--wd-muted)' },
    };

    const LEADS = [
        'Northwind Supply Co.',
        'Harbor Analytics',
        'Cedar & Pine Studio',
        'Lumen Retail Group',
        'Orchard Fitness',
        'Bayside Dental Clinic',
        'Kite Logistics',
        'Marigold Bakeshop',
        'Summit Outdoor Gear',
        'Tala Wellness Spa',
        'Riverstone Cafe',
        'Pantay Hardware',
        'Ilaw Lighting Co.',
        'Bluefin Seafood Market',
        'Copperleaf Interiors',
        'Daloy Water Systems',
        'Granite Peak Builders',
        'Hiraya Events',
        'Juniper Home Goods',
        'Kalye Street Kitchen',
        'Luntian Garden Supply',
        'Mabuhay Travel Desk',
        'Nayon Crafts Collective',
    ];
    const STAGE_CYCLE = ['lead', 'pitched', 'negotiating', 'onboarded', 'active', 'repeat-client', 'lost'];

    function registrations(count, names) {
        return Array.from({ length: count }, (_, i) => {
            const registered = 1.5 + i * 4.3;
            return {
                id: `demo-registration-${i + 1}`,
                display_name: names[i % names.length],
                stage: STAGE_CYCLE[(i * 3) % STAGE_CYCLE.length],
                registered_at: ago(registered),
                // Some registrations have not changed since they were shared.
                shared_updated_at: i % 3 === 0 ? ago(registered) : ago(Math.max(0.1, registered - 3 - (i % 5))),
                partnership_id: PARTNERSHIP,
            };
        });
    }

    const profile = {
        contact_name: 'Ana Reyes',
        contact_email: 'ana@studio-alon.example',
        website_url: 'https://studio-alon.example',
        description:
            'Brand partnerships studio working with home and lifestyle retailers across Luzon. We register qualified leads after a first discovery call.',
    };

    // The same shape as PartnerRecord. Paused records carry no figures, activity, profile or registrations.
    const STATES = {
        populated: {
            workspace: 'Habi Home',
            partner: {
                creator_name: 'Studio Alon Creative',
                accepted_at: ago(210),
                paused: false,
                leads_total: 23,
                leads_30d: 7,
                last_active: { at: ago(0.17), kind: 'lead_updated' },
                profile,
            },
            rows: registrations(23, LEADS),
        },
        empty: {
            workspace: 'Habi Home',
            partner: {
                creator_name: 'Studio Alon Creative',
                accepted_at: ago(3),
                paused: false,
                leads_total: 0,
                leads_30d: 0,
                last_active: { at: ago(1), kind: 'signed_in' },
                profile,
            },
            rows: [],
        },
        noprofile: {
            workspace: 'Habi Home',
            partner: {
                creator_name: 'Studio Alon Creative',
                accepted_at: ago(64),
                paused: false,
                leads_total: 4,
                leads_30d: 4,
                last_active: null,
                profile: null,
            },
            rows: registrations(4, LEADS),
        },
        paused: {
            workspace: 'Habi Home',
            partner: {
                creator_name: 'Studio Alon Creative',
                accepted_at: ago(210),
                paused: true,
                leads_total: null,
                leads_30d: null,
                last_active: null,
                profile: null,
            },
            rows: [],
        },
        long: {
            workspace: 'Habi Home Furnishings and Living Philippines Incorporated',
            partner: {
                creator_name: 'Studio Alon Creative Partnerships and Community Collective of Metro Manila',
                accepted_at: ago(420),
                paused: false,
                leads_total: 23,
                leads_30d: 7,
                last_active: { at: ago(12), kind: 'signed_in' },
                profile: {
                    contact_name: null,
                    contact_email: 'partnerships.and.community.relations@studio-alon-creative-collective.example',
                    website_url:
                        'https://studio-alon-creative-collective.example/partners/brand-collaborations/home-and-living',
                    description:
                        'We are a collective of eleven independent creators and two account leads who introduce home, living and lifestyle retailers across Luzon and the Visayas to brands we already trust.\n\nEvery lead we register has had at least one discovery call. We only share contact details when the business has agreed to be introduced, and we keep the summary up to date when the conversation moves on.',
                },
            },
            rows: registrations(
                23,
                LEADS.map((name, i) =>
                    i % 4 === 0
                        ? `${name} — regional franchise expansion programme for Northern Luzon and Metro Manila branches`
                        : name,
                ),
            ),
        },
    };

    const ACTIVITY = {
        signed_in: 'Signed in',
        lead_registered: 'Lead registered',
        lead_updated: 'Lead updated',
    };

    // ---- formatting, matching features/portals/activity.ts and RegistrationTable.vue ----
    const esc = (value) =>
        String(value).replace(
            /[&<>"']/g,
            (c) =>
                ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;',
                })[c],
        );
    function relativeTime(iso) {
        const seconds = Math.max(0, Math.round((NOW - new Date(iso).getTime()) / 1000));
        if (seconds < 60) return 'Just now';
        const plural = (n, unit) => `${n} ${unit}${n === 1 ? '' : 's'} ago`;
        if (seconds < 3600) return plural(Math.floor(seconds / 60), 'minute');
        if (seconds < 86400) return plural(Math.floor(seconds / 3600), 'hour');
        const days = Math.floor(seconds / 86400);
        if (days === 1) return 'Yesterday';
        if (days < 30) return `${days} days ago`;
        return dateOnly(iso);
    }
    const dateOnly = (iso) =>
        new Date(iso).toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
    const exact = (iso) =>
        new Date(iso).toLocaleString('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            timeZoneName: 'short',
        });
    function when(iso) {
        const date = new Date(iso);
        if (NOW - date.getTime() < 30 * DAY) return relativeTime(iso);
        const sameYear = date.getFullYear() === new Date(NOW).getFullYear();
        return date.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            ...(sameYear ? {} : { year: 'numeric' }),
        });
    }
    const time = (iso, text) => `<time datetime="${iso}" title="${esc(exact(iso))}">${esc(text)}</time>`;
    const icon = (id, size = 16) =>
        `<svg class="i" width="${size}" height="${size}" aria-hidden="true"><use href="#i-${id}"/></svg>`;
    const initials = (name) =>
        name
            .split(/\s+/)
            .filter((part) => /[A-Za-z0-9]/.test(part[0] ?? ''))
            .slice(0, 2)
            .map((part) => part[0].toUpperCase())
            .join('');
    const number = (n) => n.toLocaleString('en');

    // Demo link: shows the address the app would open instead of navigating.
    const demo = (target, text, cls = '', extra = '') =>
        `<a href="#demo" class="${cls}" data-demo="${esc(target)}" ${extra}>${text}</a>`;
    const listHref = (params) =>
        `/brand/lead-registrations?${new URLSearchParams({ partner: PARTNERSHIP, ...params }).toString()}`;

    // ---- navigation (portalNavigation.ts, Brand) ----
    const NAV = [
        { items: [{ label: 'Home', icon: 'house', href: '/brand/home' }] },
        {
            label: 'Partners',
            items: [
                {
                    label: 'Partner hub',
                    icon: 'users',
                    href: '/brand',
                    current: true,
                },
                {
                    label: 'Lead registrations',
                    icon: 'clipboard',
                    href: '/brand/lead-registrations',
                },
            ],
        },
        {
            label: 'Programs',
            items: [
                { label: 'Campaigns', icon: 'megaphone' },
                { label: 'Incentives', icon: 'gift' },
                { label: 'Enablement', icon: 'cap' },
                { label: 'Payouts', icon: 'wallet' },
            ],
        },
    ];
    function renderNav(prefix) {
        const groups = NAV.map((group, g) => {
            const heading = group.label
                ? `<p id="${prefix}-group-${g}" class="wd-portal-nav-heading"><span class="nav-text">${group.label}</span></p>`
                : '';
            const items = group.items
                .map((item) => {
                    const inner = `${icon(item.icon, 20)}<span class="wd-portal-nav-text nav-text"><span>${item.label}</span>${
                        item.href ? '' : '<span class="wd-portal-nav-status">Not available yet</span>'
                    }</span>`;
                    if (!item.href)
                        return `<li><span class="wd-portal-nav-item is-unavailable" aria-disabled="true" title="${item.label}: Not available yet">${inner}</span></li>`;
                    return `<li>${demo(
                        item.href,
                        inner,
                        'wd-portal-nav-item',
                        `${item.current ? 'aria-current="page"' : ''} title="${item.label}"`,
                    )}</li>`;
                })
                .join('');
            return `<div class="wd-portal-nav-group">${heading}<ul ${group.label ? `aria-labelledby="${prefix}-group-${g}"` : ''}>${items}</ul></div>`;
        }).join('');
        return `<nav class="wd-portal-nav" aria-label="Brand tools">${groups}</nav>`;
    }

    // ---- page ----
    let stateKey = 'populated';
    let page = 1;

    function header(data) {
        const { partner } = data;
        const paused = partner.paused;
        const tones = ['blush', 'mint', 'sand', 'lilac', 'sky', 'clay'];
        const tone = paused ? 'paused' : tones[partner.creator_name.length % tones.length];
        const status = paused
            ? `<span class="wd-badge wd-badge-warning">${icon('alert', 14)}Paused</span>`
            : `<span class="wd-badge wd-badge-success">${icon('check', 14)}Active</span>`;
        const lastActive = paused
            ? ''
            : `<div class="pr-meta-item"><dt>Last active</dt><dd>${
                  partner.last_active
                      ? `${time(partner.last_active.at, relativeTime(partner.last_active.at))} · ${ACTIVITY[partner.last_active.kind]}`
                      : 'Not yet recorded'
              }</dd></div>`;
        const email = !paused && partner.profile?.contact_email;
        const actions = paused
            ? ''
            : `<div class="pr-actions">
                ${
                    email
                        ? demo(
                              `mailto:${email}`,
                              `${icon('mail')}<span>Email ${esc(partner.profile.contact_name || 'business contact')}</span>`,
                              'wd-button wd-button-secondary',
                          )
                        : ''
                }
                ${demo(listHref({}), `<span>Open in Lead registrations</span>${icon('arrow')}`, 'wd-button wd-button-primary')}
            </div>`;
        const summary = paused
            ? `<p class="wd-warning pr-notice" role="status">${icon('alert', 18)}<span>This partner's workspace is currently unavailable. The partnership is kept, and their shared profile and registered leads will be shown again when the workspace is available.</span></p>`
            : `<div class="pr-summary">
                <h2 class="wd-sr-only">Registration summary</h2>
                <ul class="pr-figures">
                    <li>${demo(
                        listHref({}),
                        `<span class="pr-figure-value">${number(partner.leads_total)}</span><span class="pr-figure-label">Leads registered<span class="pr-figure-scope">All dates and stages</span></span>${icon('chev-r')}`,
                        'pr-figure',
                        `aria-label="${number(partner.leads_total)} leads registered, all dates and stages. View in Lead registrations"`,
                    )}</li>
                    <li>${demo(
                        listHref({ window: '30d' }),
                        `<span class="pr-figure-value">${number(partner.leads_30d)}</span><span class="pr-figure-label">In the last 30 days<span class="pr-figure-scope">Newly registered with you</span></span>${icon('chev-r')}`,
                        'pr-figure',
                        `aria-label="${number(partner.leads_30d)} leads registered in the last 30 days. View last 30 days in Lead registrations"`,
                    )}</li>
                </ul>
                <p class="pr-summary-note">Counts include only leads ${esc(partner.creator_name)} currently shares with ${esc(data.workspace)} — not sales, revenue or their private pipeline.</p>
            </div>`;
        return `<section class="wd-card pr-header" aria-labelledby="partner-name">
            <div class="pr-identity">
                <span class="wd-partner-avatar is-48 is-${tone}" aria-hidden="true">${esc(initials(partner.creator_name))}</span>
                <div class="pr-identity-text">
                    <p class="pr-eyebrow">Partner record</p>
                    <h1 id="partner-name">${esc(partner.creator_name)}</h1>
                    <dl class="pr-meta">
                        <div class="pr-meta-item"><dt class="wd-sr-only">Partnership status</dt><dd>${status}</dd></div>
                        <div class="pr-meta-item"><dt>Partner since</dt><dd>${time(partner.accepted_at, dateOnly(partner.accepted_at))}</dd></div>
                        ${lastActive}
                    </dl>
                </div>
                ${actions}
            </div>
            ${summary}
        </section>`;
    }

    function stageChip(stage) {
        const s = STAGES[stage];
        return `<span class="wd-stage-chip" data-stage="${stage}"><span class="wd-stage-chip-dot" style="background:${s.dot}" aria-hidden="true"></span>${s.label}</span>`;
    }

    // ---- registered leads: search, stage filter, registration window and sort ----
    // Mirrors the Lead registrations list (BrandLeadRegistrations.vue): literal lead-name search, stage chips whose
    // counts ignore only their own filter, Any time / Last 30 days, and the same four sort orders.
    const SORTS = {
        '': { label: 'Newest first', compare: (a, b) => b.registered_at.localeCompare(a.registered_at) },
        registered_asc: { label: 'Oldest first', compare: (a, b) => a.registered_at.localeCompare(b.registered_at) },
        lead_asc: { label: 'Lead name A–Z', compare: (a, b) => a.display_name.localeCompare(b.display_name, 'en') },
        lead_desc: { label: 'Lead name Z–A', compare: (a, b) => b.display_name.localeCompare(a.display_name, 'en') },
    };
    const SORT_COLUMNS = {
        display_name: { label: 'Lead', asc: 'lead_asc', desc: 'lead_desc' },
        registered_at: { label: 'Registered', asc: 'registered_asc', desc: '' },
    };
    const emptyFilters = () => ({ q: '', stage: '', window: '', sort: '' });
    let filters = emptyFilters();

    const matches = (row, f, ignore) =>
        (ignore === 'q' || !f.q || row.display_name.toLowerCase().includes(f.q.toLowerCase())) &&
        (ignore === 'stage' || !f.stage || row.stage === f.stage) &&
        (ignore === 'window' || !f.window || NOW - new Date(row.registered_at).getTime() <= 30 * DAY);
    const isFiltered = () => Boolean(filters.q || filters.stage || filters.window);

    /** The address the app would load for the current list state. */
    function recordHref(extra = {}) {
        const query = new URLSearchParams(
            Object.entries({ ...filters, page: page > 1 ? page : '', ...extra }).filter(
                ([, v]) => v !== '' && v != null,
            ),
        ).toString();
        return `/brand/partners/${PARTNERSHIP}${query ? `?${query}` : ''}`;
    }

    function sortState(key) {
        const col = SORT_COLUMNS[key];
        if (filters.sort === col.asc) return 'ascending';
        if (filters.sort === col.desc) return 'descending';
        return 'none';
    }
    /** As RegistrationTable: a first click sorts names A–Z and dates newest first; the next click reverses. */
    function nextSort(key) {
        const col = SORT_COLUMNS[key];
        const state = sortState(key);
        if (key === 'display_name') return state === 'ascending' ? col.desc : col.asc;
        return state === 'descending' ? col.asc : col.desc;
    }
    function sortHeader(key) {
        const col = SORT_COLUMNS[key];
        const state = sortState(key);
        const next = nextSort(key) === col.asc ? 'ascending' : 'descending';
        const glyph = state === 'ascending' ? 'up' : state === 'descending' ? 'down' : 'updown';
        return `<th scope="col" aria-sort="${state}"><a href="#sort" data-col="${key}" class="wd-sort-link${state === 'none' ? '' : ' is-sorted'}" data-sort="${nextSort(key)}" aria-label="${col.label}, sort ${next}">${col.label}${icon(glyph, 15)}</a></th>`;
    }

    function leadsSection(data) {
        const { partner, rows } = data;
        let body;
        if (partner.paused) {
            body = `<div class="wd-empty-state"><h3>Registered leads are not shown while paused</h3><p>They return when the partner's workspace is available again.</p></div>`;
        } else if (!rows.length) {
            body = `<div class="wd-empty-state"><h3>No leads registered yet</h3><p>A lead appears here after ${esc(partner.creator_name)} registers it with ${esc(data.workspace)}. Partners choose which leads to share.</p></div>`;
        } else {
            const sortOptions = Object.entries(SORTS)
                .map(([value, s]) => `<option value="${value}">${s.label}</option>`)
                .join('');
            body = `<div class="pr-chips" id="leads-chips"></div>
                <form class="pr-toolbar" role="search" aria-label="Filter registered leads" id="leads-toolbar">
                    <div class="pr-search">
                        <label for="leads-search" class="wd-sr-only">Lead name</label>
                        ${icon('search')}
                        <input id="leads-search" name="q" type="search" maxlength="160" placeholder="Search leads" autocomplete="off" />
                    </div>
                    <div class="pr-select">
                        <label for="leads-window">Registered</label>
                        <select id="leads-window" name="window"><option value="">Any time</option><option value="30d">Last 30 days</option></select>
                    </div>
                    <div class="pr-select pr-sort">
                        <label for="leads-sort">Sort by</label>
                        <select id="leads-sort" name="sort">${sortOptions}</select>
                    </div>
                </form>
                <div class="pr-status" id="leads-status"></div>
                <div id="leads-results"></div>`;
        }
        return `<section class="wd-card pr-leads" aria-labelledby="leads-title">
            <header class="pr-section-head">
                <div>
                    <h2 id="leads-title" tabindex="-1">Registered leads${!partner.paused && rows.length ? ` <span class="pr-count">${number(rows.length)}</span>` : ''}</h2>
                    <p class="wd-description">Leads ${esc(partner.creator_name)} registered with ${esc(data.workspace)}. Open a lead to see its shared summary and contact.</p>
                </div>
            </header>
            ${body}
        </section>`;
    }

    /** Re-renders chips, status, table and paging; the search box and selects stay mounted so focus is kept. */
    function updateResults() {
        const data = STATES[stateKey];
        const results = document.getElementById('leads-results');
        if (!results) return;
        const { partner, rows } = data;

        const stageBase = rows.filter((row) => matches(row, filters, 'stage'));
        const chips = [{ value: '', label: 'All stages', count: stageBase.length }].concat(
            Object.entries(STAGES).map(([value, s]) => ({
                value,
                label: s.label,
                count: stageBase.filter((row) => row.stage === value).length,
            })),
        );
        document.getElementById('leads-chips').innerHTML =
            `<div class="wd-filter-chips is-compact" role="group" aria-label="Filter by stage"><ul>${chips
                .map(
                    (chip) =>
                        `<li><button type="button" data-stage="${chip.value}" aria-pressed="${chip.value === filters.stage}">${chip.label} <span class="wd-filter-chip-count">${number(chip.count)}</span></button></li>`,
                )
                .join('')}</ul></div>`;

        const visible = rows.filter((row) => matches(row, filters)).sort(SORTS[filters.sort].compare);
        const total = visible.length;
        const pages = Math.max(1, Math.ceil(total / PER_PAGE));
        page = Math.min(page, pages);
        const slice = visible.slice((page - 1) * PER_PAGE, page * PER_PAGE);

        const active = [
            filters.q && `“${esc(filters.q)}”`,
            filters.stage && STAGES[filters.stage].label,
            filters.window && 'Last 30 days',
        ].filter(Boolean);
        document.getElementById('leads-status').innerHTML = isFiltered()
            ? `<p role="status">${number(total)} of ${number(rows.length)} match ${active.join(' · ')}</p><button type="button" class="wd-link pr-clear" data-clear>Clear filters</button>`
            : `<p role="status" class="wd-sr-only">${number(total)} registrations</p>`;

        if (!total) {
            results.innerHTML = `<div class="wd-empty-state"><h3>No registrations match these filters</h3><p>Try another name or stage, or clear the filters to see all ${number(rows.length)} leads ${esc(partner.creator_name)} registered with you.</p><button type="button" class="wd-button wd-button-secondary pr-empty-clear" data-clear>Clear filters</button></div>`;
            return;
        }
        const bodyRows = slice
            .map((row) => {
                const updated = new Date(row.shared_updated_at) > new Date(row.registered_at);
                return `<tr>
                    <th scope="row" data-label="Lead">${demo(
                        listHref({ registration: row.id }),
                        highlight(row.display_name),
                        'wd-registration-lead',
                        `title="${esc(row.display_name)}"`,
                    )}</th>
                    <td data-label="Stage">${stageChip(row.stage)}</td>
                    <td data-label="Registered">${time(row.registered_at, when(row.registered_at))}</td>
                    <td data-label="Last update">${
                        updated
                            ? time(row.shared_updated_at, when(row.shared_updated_at))
                            : '<span class="wd-registration-none"><span aria-hidden="true">—</span><span class="wd-sr-only">No updates yet</span></span>'
                    }</td>
                </tr>`;
            })
            .join('');
        const first = (page - 1) * PER_PAGE + 1;
        const last = first + slice.length - 1;
        const pager = (target, label, iconId, disabled) => {
            const inner = `${iconId === 'chev-l' ? icon(iconId) : ''}${label}${iconId === 'chev-r' ? icon(iconId) : ''}`;
            return disabled
                ? `<span aria-disabled="true">${inner}</span>`
                : `<a href="#page-${target}" data-page="${target}" aria-label="${label} page, registered leads">${inner}</a>`;
        };
        results.innerHTML = `<div class="wd-data-region" tabindex="0" role="region" aria-label="Registered leads table, scrolls sideways when narrow">
                <table class="wd-data-table pr-table">
                    <caption>Leads registered by ${esc(partner.creator_name)}${isFiltered() ? ', filtered' : ''}, ${SORTS[filters.sort].label.toLowerCase()}</caption>
                    <thead><tr>${sortHeader('display_name')}<th scope="col">Stage</th>${sortHeader('registered_at')}<th scope="col">Last update</th></tr></thead>
                    <tbody>${bodyRows}</tbody>
                </table>
            </div>
            <nav class="wd-pagination" aria-label="Registered leads pages">
                <p>Showing ${first}–${last} of ${number(total)} ${isFiltered() ? 'matching ' : ''}registrations</p>
                ${
                    pages > 1
                        ? `<div class="wd-pagination-links">${pager(page - 1, 'Previous', 'chev-l', page === 1)}${pager(page + 1, 'Next', 'chev-r', page === pages)}</div>`
                        : ''
                }
            </nav>`;
    }
    /** Marks the searched text in lead names; the literal match follows the server's strpos rule. */
    function highlight(name) {
        if (!filters.q) return esc(name);
        const at = name.toLowerCase().indexOf(filters.q.toLowerCase());
        if (at < 0) return esc(name);
        return `${esc(name.slice(0, at))}<mark>${esc(name.slice(at, at + filters.q.length))}</mark>${esc(name.slice(at + filters.q.length))}`;
    }

    /** Applies a filter change as the app would: back to page 1, results replaced, address reported. */
    function apply(change, announce = true) {
        filters = { ...filters, ...change };
        page = 1;
        syncControls();
        updateResults();
        if (announce) status.textContent = `Prototype filtering, no request. In the app: ${recordHref()}`;
    }
    function syncControls() {
        const search = document.getElementById('leads-search');
        if (!search) return;
        if (document.activeElement !== search) search.value = filters.q;
        document.getElementById('leads-window').value = filters.window;
        document.getElementById('leads-sort').value = filters.sort;
    }

    function profileSection(data) {
        const { partner } = data;
        let body;
        if (partner.paused) {
            body = `<p class="pr-muted">Not shown while the partnership is paused.</p>`;
        } else if (!partner.profile) {
            body = `<div class="wd-empty-state is-compact"><h3>No shared profile yet</h3><p>${esc(partner.creator_name)} hasn't added business contact details for the Brands they work with.</p></div>`;
        } else {
            const p = partner.profile;
            const website = /^https?:\/\//i.test(p.website_url ?? '') ? p.website_url : '';
            const missing = '<span class="pr-missing">Not shared</span>';
            body = `<dl class="wd-detail-list pr-profile">
                <dt>Business contact</dt><dd>${p.contact_name ? esc(p.contact_name) : missing}</dd>
                <dt>Email</dt><dd>${p.contact_email ? demo(`mailto:${p.contact_email}`, esc(p.contact_email), 'wd-link pr-break') : missing}</dd>
                <dt>Website or public profile</dt><dd>${
                    website
                        ? demo(
                              website,
                              `<span class="pr-break">${esc(website.replace(/^https?:\/\//i, ''))}</span>${icon('external', 14)}<span class="wd-sr-only"> (opens in a new tab)</span>`,
                              'wd-link pr-external',
                          )
                        : missing
                }</dd>
                <dt>About</dt><dd class="pr-about">${p.description ? esc(p.description) : missing}</dd>
            </dl>`;
        }
        return `<section class="wd-card pr-profile-card" aria-labelledby="profile-title">
            <header>
                <h2 id="profile-title">Shared profile</h2>
                <p class="pr-owner">${icon('lock', 14)}Managed by the partner · read-only</p>
            </header>
            ${body}
        </section>`;
    }

    function explainer(data) {
        return `<section class="pr-explainer" aria-labelledby="explainer-title">
            <details>
                <summary><h2 id="explainer-title">What this record includes</h2></summary>
                <ul>
                    <li>Only what ${esc(data.partner.creator_name)} chooses to share with ${esc(data.workspace)}: registered leads and an optional business profile.</li>
                    <li>Their private pipeline, notes, values, reminders and other Brands are never shown.</li>
                    <li><strong>Last active</strong> is the latest of this partner signing in and a change to a lead they registered with you.</li>
                </ul>
            </details>
        </section>`;
    }

    function render() {
        const data = STATES[stateKey];
        document.querySelectorAll('[data-bind="workspace"]').forEach((el) => {
            el.textContent = data.workspace;
            el.title = data.workspace;
        });
        document.title = `Mockup – ${data.partner.creator_name} · WeavDash`;
        const crumb = `<nav class="pr-crumb" aria-label="Breadcrumb"><ol><li>${demo('/brand', 'Partner hub', 'wd-link')}</li><li aria-current="page"><span>${esc(data.partner.creator_name)}</span></li></ol></nav>`;
        document.getElementById('main-content').innerHTML = `<div class="wd-portal-page pr-layout">
            ${crumb}
            ${header(data)}
            <div class="pr-columns">
                <div class="pr-primary">${leadsSection(data)}</div>
                <div class="pr-secondary">${profileSection(data)}${explainer(data)}</div>
            </div>
        </div>`;
        syncControls();
        updateResults();
    }

    // ---- interactions ----
    const status = document.getElementById('proto-status');
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[data-demo], button[data-demo]');
        if (link) {
            event.preventDefault();
            const target = link.dataset.demo;
            status.textContent = target.startsWith('/')
                ? `Demo link — in the app this opens ${target}`
                : target.startsWith('mailto:')
                  ? `Demo link — in the app this opens an email to ${target.slice(7)}`
                  : target.startsWith('http')
                    ? `Demo link — in the app this opens ${target} in a new tab`
                    : `Demo — ${target}`;
            return;
        }
        const pager = event.target.closest('a[data-page]');
        if (pager) {
            event.preventDefault();
            page = Number(pager.dataset.page);
            updateResults();
            // Like a page visit, focus returns to the section heading.
            document.getElementById('leads-title').focus();
            status.textContent = `Prototype paging, no request. In the app: ${recordHref()}`;
            return;
        }
        const chip = event.target.closest('button[data-stage]');
        if (chip) {
            apply({ stage: chip.dataset.stage });
            document.querySelector(`#leads-chips button[data-stage="${chip.dataset.stage}"]`)?.focus();
            return;
        }
        const sorter = event.target.closest('a[data-sort]');
        if (sorter) {
            event.preventDefault();
            apply({ sort: sorter.dataset.sort });
            document.querySelector(`a[data-col="${sorter.dataset.col}"]`)?.focus();
            return;
        }
        if (event.target.closest('[data-clear]')) {
            clearTimeout(typing);
            apply(emptyFilters());
            document.getElementById('leads-search')?.focus();
        }
    });

    // Search as the list does: applied 350 ms after typing stops, or at once on Enter.
    let typing;
    document.addEventListener('input', (event) => {
        if (event.target.id !== 'leads-search') return;
        clearTimeout(typing);
        const value = event.target.value.trim();
        typing = setTimeout(() => value !== filters.q && apply({ q: value }), 350);
    });
    document.addEventListener('submit', (event) => {
        if (event.target.id !== 'leads-toolbar') return;
        event.preventDefault();
        clearTimeout(typing);
        apply({ q: document.getElementById('leads-search').value.trim() });
    });
    document.addEventListener('change', (event) => {
        if (event.target.id === 'leads-window') apply({ window: event.target.value });
        if (event.target.id === 'leads-sort') apply({ sort: event.target.value });
    });

    document.getElementById('proto-state').addEventListener('change', (event) => {
        stateKey = event.target.value;
        page = 1;
        filters = emptyFilters();
        render();
        status.textContent = `Showing the “${event.target.selectedOptions[0].text}” state.`;
    });
    document.getElementById('proto-palette').addEventListener('change', (event) => {
        if (event.target.value === 'purple') document.documentElement.dataset.appearance = 'purple';
        else delete document.documentElement.dataset.appearance;
    });

    const portal = document.getElementById('portal');
    const collapse = document.getElementById('collapse');
    collapse.addEventListener('click', () => {
        const collapsed = portal.classList.toggle('is-collapsed');
        collapse.setAttribute('aria-expanded', String(!collapsed));
        collapse.querySelector('.collapse-label').innerHTML = collapsed
            ? '<span class="wd-sr-only">Expand navigation</span>'
            : 'Collapse<span class="wd-sr-only"> navigation</span>';
    });

    const drawer = document.getElementById('drawer');
    const opener = document.getElementById('menu-open');
    opener.addEventListener('click', () => drawer.showModal());
    document.getElementById('menu-close').addEventListener('click', () => drawer.close());
    drawer.addEventListener('close', () => opener.focus());
    drawer.addEventListener('click', (event) => {
        if (event.target === drawer) drawer.close();
    });

    // Optional query parameters for review links, e.g. ?state=paused&palette=purple. Never persisted.
    const params = new URLSearchParams(location.search);
    if (STATES[params.get('state')]) {
        stateKey = params.get('state');
        document.getElementById('proto-state').value = stateKey;
    }
    if (params.get('palette') === 'purple') {
        document.documentElement.dataset.appearance = 'purple';
        document.getElementById('proto-palette').value = 'purple';
    }

    document.querySelector('[data-nav="sidebar"]').innerHTML = renderNav('sidebar');
    document.querySelector('[data-nav="drawer"]').innerHTML = renderNav('drawer');
    render();
})();
