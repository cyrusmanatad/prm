// Mockup only: synthetic data, no network requests, no storage. Product links outside this proposal are demo links.
(function () {
    'use strict';

    // ---------- helpers ----------
    const TODAY = '2026-11-28';
    const esc = (value) =>
        String(value ?? '').replace(
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
    const icon = (id, size = 16) =>
        `<svg class="i" width="${size}" height="${size}" aria-hidden="true"><use href="#i-${id}" /></svg>`;
    const day = (iso) => new Date(iso + 'T00:00:00');
    const fmt = (iso) =>
        iso
            ? day(iso).toLocaleDateString('en-US', {
                  month: 'short',
                  day: 'numeric',
                  year: 'numeric',
              })
            : '—';
    const time = (iso) => `<time datetime="${iso}">${fmt(iso)}</time>`;
    const daysBetween = (a, b) => Math.round((day(b) - day(a)) / 86_400_000);
    const peso = (minor) => '₱' + (minor / 100).toLocaleString('en-PH', { maximumFractionDigits: 0 });
    const plural = (n, one, many = one + 's') => `${n} ${n === 1 ? one : many}`;
    const demo = (target, text, cls = '', extra = '') =>
        `<a href="#demo" class="${cls}" data-demo="${esc(target)}" ${extra}>${text}</a>`;
    const annot = (text) => `<span class="ci-annot" title="Design note">${icon('info', 12)}${esc(text)}</span>`;
    const uid = (prefix) => prefix + Math.random().toString(36).slice(2, 8);

    const STAGES = {
        lead: { label: 'Lead', dot: 'var(--wd-stage-lead)', rank: 0 },
        pitched: { label: 'Pitched', dot: 'var(--wd-stage-pitched)', rank: 1 },
        negotiating: {
            label: 'Negotiating',
            dot: 'var(--wd-stage-negotiating)',
            rank: 2,
        },
        onboarded: {
            label: 'Onboarded',
            dot: 'var(--wd-stage-onboarded)',
            rank: 3,
        },
        active: { label: 'Active', dot: 'var(--wd-brand)', rank: 4 },
        'repeat-client': {
            label: 'Repeat Client',
            dot: 'var(--wd-brand-hover)',
            rank: 5,
        },
        lost: { label: 'Lost', dot: 'var(--wd-muted)', rank: -1 },
    };
    const stageChip = (stage) =>
        `<span class="wd-stage-chip"><span class="wd-stage-chip-dot" style="background:${STAGES[stage].dot}"></span>${STAGES[stage].label}</span>`;

    const BADGES = {
        draft: ['neutral', 'lock', 'Private draft'],
        shared: ['success', 'check', 'Shared'],
        ended: ['neutral', 'x', 'Ended'],
        relationshipEnded: ['warning', 'alert', 'Relationship ended'],
        pending: ['warning', 'clock', 'Pending review'],
        approved: ['brand', 'check', 'Approved'],
        fulfilled: ['success', 'award', 'Fulfilled'],
        rejected: ['danger', 'x', 'Rejected'],
        cancelled: ['neutral', 'x', 'Cancelled'],
    };
    const badge = (key, label) => {
        const [tone, ic, text] = BADGES[key];
        return `<span class="wd-badge wd-badge-${tone}">${icon(ic, 14)}${esc(label ?? text)}</span>`;
    };

    const PARTNERS = {
        alon: { name: 'Studio Alon Creative', initials: 'SA', tone: 'blush' },
        mabuhay: { name: 'Mabuhay Media', initials: 'MM', tone: 'mint' },
        tala: { name: 'Tala Collective', initials: 'TC', tone: 'sand' },
    };
    const avatar = (key) =>
        `<span class="wd-partner-avatar is-${relActive(key) ? PARTNERS[key].tone : 'paused'}" style="width:28px;height:28px;font-size:11px" aria-hidden="true">${PARTNERS[key].initials}</span>`;

    // ---------- synthetic data ----------
    function seed(scenario) {
        if (scenario === 'empty') {
            return {
                campaigns: [],
                registrations: [],
                incentives: [],
                awards: [],
                pipeline: pipelineLeads(),
            };
        }
        const campaigns = [
            {
                id: 'c1',
                brand: 'Habi Home',
                title: 'Holiday home refresh',
                partner: 'alon',
                state: 'shared',
                due: '2026-12-15',
                created: '2026-10-30',
                sharedOn: '2026-11-02',
                brief: 'Introduce the Habi Home holiday collection to small hospitality and retail businesses that are refreshing their spaces before Christmas.\n\nFocus on cafés, boutique hotels and gift shops in Metro Manila and Cebu. Register a lead once the owner agrees to a product walkthrough.\n\nDeliverables: one short reel per onboarded business and a list of the pieces they chose.',
            },
            {
                id: 'c2',
                brand: 'Habi Home',
                title: 'Rainy-season essentials',
                partner: 'mabuhay',
                state: 'shared',
                due: '2026-10-30',
                created: '2026-09-15',
                sharedOn: '2026-09-20',
                brief: 'Reach hardware and home-goods resellers ahead of the rainy season with the storage and drying range.',
            },
            {
                id: 'c3',
                brand: 'Habi Home',
                title: "Valentine's gifting edit",
                partner: null,
                state: 'draft',
                due: '2027-01-31',
                created: '2026-11-24',
                brief: 'Curated gift sets for florists and gift shops. ',
            },
            {
                id: 'c4',
                brand: 'Habi Home',
                title: 'Back-to-school storage',
                partner: 'tala',
                state: 'shared',
                due: '2026-08-15',
                created: '2026-07-01',
                sharedOn: '2026-07-10',
                brief: 'Dorm and study-space storage for school supply stores.',
            },
            // Shared with Studio Alon by another Brand: visible only in the Partner view.
            {
                id: 'c5',
                brand: 'Lakbay Outdoors',
                title: 'Trail season launch',
                partner: 'alon',
                state: 'shared',
                due: '2027-02-28',
                created: '2026-11-18',
                sharedOn: '2026-11-20',
                brief: 'Introduce the new trail range to outdoor gear shops and travel agencies.',
            },
        ];
        const reg = (id, partner, name, stage, registered, campaign, linked) => ({
            id,
            partner,
            brand: 'Habi Home',
            name,
            stage,
            registered,
            campaign,
            linked: campaign ? linked || registered : null,
            state: 'active',
        });
        const registrations = [
            reg('r1', 'alon', 'Harbor Analytics', 'onboarded', '2026-11-03', 'c1'),
            reg('r2', 'alon', 'Lumen Retail Group', 'onboarded', '2026-11-05', 'c1'),
            reg('r3', 'alon', 'Orchard Fitness', 'onboarded', '2026-10-28', 'c1', '2026-11-20'),
            reg('r4', 'alon', 'Cedar & Pine Studio', 'negotiating', '2026-11-09', 'c1'),
            reg('r5', 'alon', 'Kite Logistics', 'pitched', '2026-11-14', 'c1'),
            reg('r6', 'alon', 'Marigold Bakeshop', 'lead', '2026-11-25', 'c1'),
            reg('r7', 'alon', 'Summit Outdoor Gear', 'onboarded', '2026-11-12', null),
            reg('r8', 'alon', 'Tala Wellness Spa', 'negotiating', '2026-11-16', null),
            reg('r9', 'alon', 'Riverside Co-working', 'onboarded', '2026-11-22', 'c1'),
            reg('m1', 'mabuhay', 'Bayside Dental Clinic', 'negotiating', '2026-10-02', 'c2'),
            reg('m2', 'mabuhay', 'Pantay Hardware', 'onboarded', '2026-09-28', 'c2'),
            reg('m3', 'mabuhay', 'Ilaw Lighting Co.', 'negotiating', '2026-10-10', 'c2'),
            reg('m4', 'mabuhay', 'Daloy Water Systems', 'onboarded', '2026-10-05', 'c2'),
            reg('t1', 'tala', 'Nayon Crafts Collective', 'active', '2026-07-22', 'c4'),
        ];
        const incentives = [
            {
                id: 'i1',
                title: 'Holiday onboarding bonus',
                type: 'cash',
                amount: 200000,
                reward: '',
                campaign: 'c1',
                audience: ['alon'],
                stage: 'onboarded',
                perAward: 1,
                start: '2026-11-01',
                end: '2027-01-31',
                capPartner: 10,
                capTotal: 10,
                state: 'shared',
                sharedOn: '2026-11-02',
                terms: 'Paid by bank transfer or GCash within 14 days of approval. One award per onboarded business.',
            },
            {
                id: 'i2',
                title: 'Starter bundle for three negotiating leads',
                type: 'noncash',
                amount: 0,
                reward: 'Habi Home starter bundle (about ₱4,500 retail)',
                campaign: null,
                audience: ['alon', 'mabuhay'],
                stage: 'negotiating',
                perAward: 3,
                start: '2026-10-01',
                end: '2026-12-31',
                capPartner: 2,
                capTotal: 6,
                state: 'shared',
                sharedOn: '2026-10-01',
                terms: 'Delivered by courier to your registered business address.',
            },
            {
                id: 'i3',
                title: 'Q1 first-order bonus',
                type: 'percent',
                amount: 0,
                reward: '10% of the first order value, up to ₱5,000',
                campaign: null,
                audience: ['tala'],
                stage: 'active',
                perAward: 1,
                start: '2027-01-01',
                end: '2027-03-31',
                capPartner: 5,
                capTotal: null,
                state: 'draft',
                terms: '',
            },
            {
                id: 'i4',
                title: 'Rainy-season sprint',
                type: 'cash',
                amount: 100000,
                reward: '',
                campaign: 'c2',
                audience: ['mabuhay'],
                stage: 'onboarded',
                perAward: 1,
                start: '2026-09-20',
                end: '2026-10-31',
                capPartner: 5,
                capTotal: 5,
                state: 'shared',
                sharedOn: '2026-09-20',
                terms: 'Paid by bank transfer.',
            },
        ];
        const awards = [
            {
                id: 'a1',
                incentive: 'i1',
                partner: 'alon',
                regs: ['r1'],
                amount: 200000,
                status: 'fulfilled',
                claimed: '2026-11-08',
                decided: '2026-11-10',
                fulfilled: '2026-11-14',
                reference: 'GCash ref 8823 1047',
            },
            {
                id: 'a2',
                incentive: 'i1',
                partner: 'alon',
                regs: ['r2'],
                amount: 200000,
                status: 'approved',
                claimed: '2026-11-20',
                decided: '2026-11-22',
            },
            {
                id: 'a3',
                incentive: 'i1',
                partner: 'alon',
                regs: ['r3'],
                amount: 200000,
                status: 'pending',
                claimed: '2026-11-26',
                note: 'Owner signed off on the walkthrough on Nov 24.',
            },
            {
                id: 'a4',
                incentive: 'i2',
                partner: 'mabuhay',
                regs: ['m1', 'm3', 'm4'],
                amount: 0,
                status: 'pending',
                claimed: '2026-11-27',
            },
            {
                id: 'a5',
                incentive: 'i4',
                partner: 'mabuhay',
                regs: ['m2'],
                amount: 100000,
                status: 'fulfilled',
                claimed: '2026-10-08',
                decided: '2026-10-09',
                fulfilled: '2026-10-15',
                reference: 'BPI transfer 55120',
            },
            {
                id: 'a6',
                incentive: 'i4',
                partner: 'mabuhay',
                regs: ['m4'],
                amount: 100000,
                status: 'rejected',
                claimed: '2026-10-20',
                decided: '2026-10-21',
                reason: 'Daloy Water Systems was already a Habi Home customer before this campaign.',
            },
        ];
        if (scenario === 'ended') {
            awards.find((a) => a.id === 'a3').status = 'cancelled';
            awards.find((a) => a.id === 'a3').reason = 'Relationship ended on Nov 25, 2026.';
        }
        return {
            campaigns,
            registrations,
            incentives,
            awards,
            pipeline: pipelineLeads(),
        };
    }
    function pipelineLeads() {
        return [
            { id: 'p1', name: 'Riverstone Cafe', stage: 'pitched' },
            { id: 'p2', name: 'Bluefin Seafood Market', stage: 'lead' },
            { id: 'p3', name: 'Copperleaf Interiors', stage: 'negotiating' },
        ];
    }

    let role = 'brand';
    let scenario = 'populated';
    let db = seed(scenario);
    const relActive = (partner) => !(scenario === 'ended' && partner === 'alon');
    const ENDED_ON = '2026-11-25';

    // ---------- derived data ----------
    const campaign = (id) => db.campaigns.find((c) => c.id === id);
    const incentive = (id) => db.incentives.find((i) => i.id === id);
    const registration = (id) => db.registrations.find((r) => r.id === id);
    const campaignStatus = (c) =>
        c.state === 'draft' ? 'draft' : relActive(c.partner) ? 'shared' : 'relationshipEnded';
    const incentiveStatus = (i) =>
        i.state === 'draft' ? 'draft' : i.state === 'ended' || i.end < TODAY ? 'ended' : 'shared';
    const brandCampaigns = () => db.campaigns.filter((c) => c.brand === 'Habi Home');
    // Brand reads only active registrations in active relationships (existing visibility rules).
    const brandRegs = (campaignId) =>
        db.registrations.filter((r) => r.campaign === campaignId && r.state === 'active' && relActive(r.partner));
    const partnerCampaigns = () =>
        db.campaigns.filter(
            (c) => c.partner === 'alon' && c.state === 'shared' && (c.brand !== 'Habi Home' || relActive('alon')),
        );
    const myRegs = () => db.registrations.filter((r) => r.partner === 'alon');
    const linkedIncentive = (campaignId) =>
        db.incentives.find((i) => i.campaign === campaignId && incentiveStatus(i) !== 'draft');
    const awardsFor = (incentiveId, partner) =>
        db.awards.filter((a) => a.incentive === incentiveId && (!partner || a.partner === partner));
    const used = (list) => list.filter((a) => a.status === 'approved' || a.status === 'fulfilled').length;
    const pendingAwards = () => db.awards.filter((a) => a.status === 'pending');

    function rewardValue(i, amount) {
        if (i.type === 'cash') return peso(amount ?? i.amount);
        return i.reward || '—';
    }
    function rewardKind(i) {
        return i.type === 'cash'
            ? 'Cash · paid outside WeavDash'
            : i.type === 'percent'
              ? 'Percentage bonus · paid outside WeavDash'
              : 'Non-cash reward · delivered outside WeavDash';
    }
    const rewardIcon = (i) => (i.type === 'cash' ? 'banknote' : i.type === 'percent' ? 'percent' : 'package');
    function ruleLines(i) {
        const lines = [`A registered lead reaches <strong>${STAGES[i.stage].label}</strong> or later`];
        if (i.perAward > 1) lines.push(`<strong>${i.perAward} qualifying leads</strong> per award`);
        if (i.campaign && campaign(i.campaign))
            lines.push(`Registered with <strong>${esc(campaign(i.campaign).title)}</strong> as its source campaign`);
        lines.push(`Between ${fmt(i.start)} and ${fmt(i.end)}`);
        if (i.capPartner) lines.push(`Up to ${plural(i.capPartner, 'award')} per Partner`);
        lines.push('Habi Home reviews and approves every award');
        return lines;
    }
    // Why a registration can or cannot support an award for this incentive.
    function eligibility(reg, inc, ignoreAward) {
        if (reg.state !== 'active') return { ok: false, why: 'Registration is withdrawn' };
        if (STAGES[reg.stage].rank < STAGES[inc.stage].rank)
            return {
                ok: false,
                why: `Stage is ${STAGES[reg.stage].label} — needs ${STAGES[inc.stage].label}`,
            };
        if (inc.campaign && reg.campaign !== inc.campaign)
            return {
                ok: false,
                why: `Source campaign isn't ${campaign(inc.campaign).title}`,
            };
        const usedBy = db.awards.find(
            (a) =>
                a.id !== ignoreAward &&
                a.incentive === inc.id &&
                a.regs.includes(reg.id) &&
                !['rejected', 'cancelled'].includes(a.status),
        );
        if (usedBy)
            return {
                ok: false,
                why: 'Already used in an award for this incentive',
            };
        return { ok: true, why: `Meets the rule · ${STAGES[reg.stage].label}` };
    }

    // ---------- navigation ----------
    function nav() {
        if (role === 'brand') {
            return [
                {
                    items: [{ label: 'Home', icon: 'house', demo: '/brand/home' }],
                },
                {
                    label: 'Partners',
                    items: [
                        { label: 'Partner hub', icon: 'users', demo: '/brand' },
                        {
                            label: 'Lead registrations',
                            icon: 'clipboard',
                            demo: '/brand/lead-registrations',
                        },
                    ],
                },
                {
                    label: 'Programs',
                    items: [
                        {
                            label: 'Campaigns',
                            icon: 'megaphone',
                            route: '#/brand/campaigns',
                        },
                        {
                            label: 'Incentives',
                            icon: 'gift',
                            route: '#/brand/incentives',
                        },
                        { label: 'Enablement', icon: 'cap' },
                        { label: 'Payouts', icon: 'wallet' },
                    ],
                },
            ];
        }
        // Proposal: Campaigns moves from Outreach into a Programs group beside Incentives, mirroring Brand.
        return [
            {
                items: [{ label: 'Home', icon: 'house', demo: '/partner/home' }],
            },
            {
                label: 'Work',
                items: [
                    {
                        label: 'My leads',
                        icon: 'kanban',
                        demo: '/partner/leads',
                    },
                    {
                        label: 'Contacts and reminders',
                        icon: 'contact',
                        demo: '/partner/contacts',
                    },
                    {
                        label: 'Onboarding requests',
                        icon: 'clipboard-list',
                        demo: '/partner/onboarding',
                    },
                    {
                        label: 'Brand sharing',
                        icon: 'share',
                        demo: '/partner/brand-sharing',
                    },
                    { label: 'Inbox', icon: 'inbox' },
                ],
            },
            {
                label: 'Programs',
                items: [
                    {
                        label: 'Campaigns',
                        icon: 'megaphone',
                        route: '#/partner/campaigns',
                    },
                    {
                        label: 'Incentives',
                        icon: 'gift',
                        route: '#/partner/incentives',
                    },
                ],
            },
            {
                label: 'Outreach',
                items: [
                    {
                        label: 'Outreach templates',
                        icon: 'file',
                        demo: '/partner/outreach-templates',
                    },
                    {
                        label: 'Automation and approvals',
                        icon: 'zap',
                        demo: '/automation',
                    },
                    { label: 'Autopilot', icon: 'sparkles' },
                ],
            },
            {
                label: 'Profile',
                items: [
                    {
                        label: 'Media kit',
                        icon: 'user',
                        demo: '/partner/media-kit',
                    },
                ],
            },
            {
                label: 'Finance',
                items: [
                    { label: 'Earnings', icon: 'banknote' },
                    {
                        label: 'Private invoice drafts',
                        icon: 'receipt',
                        demo: '/finance/invoices',
                    },
                    {
                        label: 'Manual payment records',
                        icon: 'wallet',
                        demo: '/finance/payments',
                    },
                    {
                        label: 'Pilot entitlements',
                        icon: 'shield',
                        demo: '/workspace/entitlements',
                    },
                ],
            },
        ];
    }
    function renderNav(prefix) {
        const path = location.hash.split('?')[0];
        const groups = nav()
            .map((group, g) => {
                const heading = group.label
                    ? `<p id="${prefix}-group-${g}" class="wd-portal-nav-heading"><span class="nav-text">${group.label}</span></p>`
                    : '';
                const items = group.items
                    .map((item) => {
                        const unavailable = !item.route && !item.demo;
                        const inner = `${icon(item.icon, 20)}<span class="wd-portal-nav-text nav-text"><span>${item.label}</span>${
                            unavailable ? '<span class="wd-portal-nav-status">Not available yet</span>' : ''
                        }</span>`;
                        if (unavailable)
                            return `<li><span class="wd-portal-nav-item is-unavailable" aria-disabled="true" title="${item.label}: Not available yet">${inner}</span></li>`;
                        if (item.demo)
                            return `<li>${demo(item.demo, inner, 'wd-portal-nav-item', `title="${item.label}"`)}</li>`;
                        const current = path.startsWith(item.route);
                        return `<li><a href="${item.route}" class="wd-portal-nav-item" title="${item.label}" ${current ? 'aria-current="page"' : ''}>${inner}</a></li>`;
                    })
                    .join('');
                return `<div class="wd-portal-nav-group">${heading}<ul ${group.label ? `aria-labelledby="${prefix}-group-${g}"` : ''}>${items}</ul></div>`;
            })
            .join('');
        return `<nav class="wd-portal-nav" aria-label="${role === 'brand' ? 'Brand' : 'Partner'} tools">${groups}</nav>`;
    }

    // ---------- shared page parts ----------
    const crumb = (items) =>
        `<nav class="ci-crumb" aria-label="Breadcrumb"><ol>${items
            .map((item, i) =>
                i === items.length - 1
                    ? `<li aria-current="page"><span>${esc(item[0])}</span></li>`
                    : `<li><a class="wd-link" href="${item[1]}">${esc(item[0])}</a></li>`,
            )
            .join('')}</ol></nav>`;
    const figures = (items) =>
        `<ul class="ci-figures">${items
            .map(
                (f) =>
                    `<li class="ci-figure ${f.attention ? 'is-attention' : ''}"><span class="ci-figure-value">${f.value}</span><span class="ci-figure-label">${f.label}</span>${
                        f.scope ? `<span class="ci-figure-scope">${f.scope}</span>` : ''
                    }</li>`,
            )
            .join('')}</ul>`;
    function chips(id, options, current) {
        return `<div class="wd-filter-chips" id="${id}"><ul>${options
            .map(
                (o) =>
                    `<li><button type="button" data-action="filter" data-filter="${id}" data-value="${o.value}" aria-pressed="${o.value === current}">${esc(
                        o.label,
                    )} <span class="wd-filter-chip-count">${o.count}</span></button></li>`,
            )
            .join('')}</ul></div>`;
    }
    function stageSummary(regs) {
        const order = ['lead', 'pitched', 'negotiating', 'onboarded', 'active', 'repeat-client', 'lost'];
        const counts = order.map((s) => [s, regs.filter((r) => r.stage === s).length]).filter(([, n]) => n);
        if (!regs.length) return '';
        const bar = counts
            .map(
                ([s, n]) =>
                    `<span style="width:${(n / regs.length) * 100}%;background:${STAGES[s].dot}" title="${STAGES[s].label}: ${n}"></span>`,
            )
            .join('');
        const legend = counts
            .map(
                ([s, n]) =>
                    `<li><span class="wd-stage-chip-dot" style="background:${STAGES[s].dot}"></span>${STAGES[s].label} <strong>${n}</strong></li>`,
            )
            .join('');
        return `<div class="ci-stagebar" role="img" aria-label="Stage progress: ${counts
            .map(([s, n]) => `${n} ${STAGES[s].label}`)
            .join(', ')}">${bar}</div><ul class="ci-stage-legend">${legend}</ul>`;
    }
    function meter(usedCount, cap, label) {
        if (!cap) return `<p class="ci-small ci-muted">${plural(usedCount, 'award')} approved · no limit</p>`;
        const pct = Math.min(100, (usedCount / cap) * 100);
        return `<div class="ci-meter"><div class="ci-meter-text"><span>${label}</span><strong class="ci-num">${usedCount} of ${cap}</strong></div><div class="ci-meter-bar" role="progressbar" aria-label="${esc(
            label,
        )}" aria-valuemin="0" aria-valuemax="${cap}" aria-valuenow="${usedCount}"><span style="width:${pct}%"></span></div></div>`;
    }
    function rewardBlock(i, size = 48) {
        return `<div class="ci-reward"><span class="ci-reward-icon" style="width:${size}px;height:${size}px">${icon(rewardIcon(i), 22)}</span><div><p class="ci-reward-value ${i.type === 'cash' ? '' : 'is-text'}">${esc(
            rewardValue(i),
        )}</p><p class="ci-reward-kind">${rewardKind(i)}</p></div></div>`;
    }
    const ruleList = (i) =>
        `<ul class="ci-rule">${ruleLines(i)
            .map((l) => `<li>${icon('check', 14)}<span>${l}</span></li>`)
            .join('')}</ul>`;
    const empty = (title, text, action = '') =>
        `<div class="wd-empty-state"><h3>${title}</h3><p>${text}</p>${action ? `<div style="margin-top:14px">${action}</div>` : ''}</div>`;
    function linkedOn(reg) {
        if (!reg.linked) return '<span class="wd-registration-none">—</span>';
        const late = daysBetween(reg.registered, reg.linked);
        return `${time(reg.linked)}${
            late > 7
                ? `<br /><span class="ci-flag">${icon('clock', 12)}Linked ${late} days after registering</span>`
                : ''
        }`;
    }

    // ---------- state for filters ----------
    const filters = {
        campaigns: 'all',
        offers: 'all',
        awards: 'pending',
        incAwards: 'all',
    };

    // ================= BRAND: Campaigns =================
    function brandCampaignList() {
        const list = brandCampaigns();
        const shared = list.filter((c) => campaignStatus(c) === 'shared');
        const leads = list.reduce((n, c) => n + brandRegs(c.id).length, 0);
        const head = `<header class="ci-head"><div><p class="ci-eyebrow">Programs</p><h1 id="page-title" tabindex="-1">Campaigns</h1><p class="wd-description">Briefs you share with one Partner at a time. Leads they register with a brief as the source campaign show up on it.</p></div><div class="ci-actions"><button type="button" class="wd-button wd-button-primary" data-action="new-campaign">${icon(
            'plus',
        )}New campaign</button></div></header>`;
        if (!list.length) {
            return `${head}<section class="wd-card">${empty(
                'No campaigns yet',
                'Write a brief as a private draft, share it with one active Partner, then follow the leads it brings in.',
                `<button type="button" class="wd-button wd-button-primary" data-action="new-campaign">${icon('plus')}Create your first campaign</button>`,
            )}<ol class="ci-rule" style="max-width:520px;margin:18px auto 0">${[
                'Draft the brief and due date — only your workspace sees it.',
                'Share it with one active Partner. The brief becomes read-only.',
                'See their registered leads and progress on the campaign.',
            ]
                .map((t) => `<li>${icon('check', 14)}<span>${t}</span></li>`)
                .join('')}</ol></section>`;
        }
        const groups = {
            all: list,
            draft: list.filter((c) => campaignStatus(c) === 'draft'),
            shared,
            relationshipEnded: list.filter((c) => campaignStatus(c) === 'relationshipEnded'),
        };
        const rows = (groups[filters.campaigns] || list)
            .map((c) => {
                const regs = brandRegs(c.id);
                const inc = linkedIncentive(c.id);
                return `<tr><th scope="row" data-label="Campaign"><div class="ci-cell-title"><a href="#/brand/campaigns/${c.id}">${esc(
                    c.title,
                )}</a><span class="ci-cell-sub">Created ${fmt(c.created)}</span></div></th>
                <td data-label="Partner">${c.partner ? esc(PARTNERS[c.partner].name) : '<span class="wd-registration-none">Not chosen</span>'}</td>
                <td data-label="Status">${badge(campaignStatus(c))}</td>
                <td data-label="Due">${time(c.due)}</td>
                <td data-label="Leads" class="ci-num">${c.state === 'draft' ? '<span class="wd-registration-none">—</span>' : regs.length}</td>
                <td data-label="Incentive">${inc ? `<a class="wd-link" href="#/brand/incentives/${inc.id}">${esc(inc.title)}</a>` : '<span class="wd-registration-none">—</span>'}</td></tr>`;
            })
            .join('');
        return `${head}
        ${figures([
            {
                value: shared.length,
                label: 'Shared briefs',
                scope: 'With an active Partner',
            },
            {
                value: groups.draft.length,
                label: 'Private drafts',
                scope: 'Only your workspace',
            },
            {
                value: leads,
                label: 'Leads from campaigns',
                scope: 'Registered with a source campaign',
            },
            {
                value: pendingAwards().length,
                label: 'Awards to review',
                scope: `<a class="wd-link" href="#/brand/incentives/awards">Review in Incentives</a>`,
                attention: pendingAwards().length > 0,
            },
        ])}
        <section class="wd-card" aria-labelledby="list-title">
            <div class="ci-section-head"><h2 id="list-title">All campaigns <span class="ci-count">${list.length}</span></h2></div>
            <div class="pr-chips">${chips(
                'campaigns',
                [
                    { value: 'all', label: 'All', count: list.length },
                    {
                        value: 'draft',
                        label: 'Drafts',
                        count: groups.draft.length,
                    },
                    { value: 'shared', label: 'Shared', count: shared.length },
                    {
                        value: 'relationshipEnded',
                        label: 'Relationship ended',
                        count: groups.relationshipEnded.length,
                    },
                ].filter((o) => o.value === 'all' || o.count || o.value === filters.campaigns),
                filters.campaigns,
            )}</div>
            <div class="wd-data-region"><table class="wd-data-table"><caption>Campaigns</caption><thead><tr><th scope="col">Campaign</th><th scope="col">Partner</th><th scope="col">Status</th><th scope="col">Due</th><th scope="col">Leads ${annot(
                'D43',
            )}</th><th scope="col">Incentive</th></tr></thead><tbody>${
                rows || `<tr><td colspan="6">${empty('No campaigns match', 'Choose another status.')}</td></tr>`
            }</tbody></table></div>
        </section>`;
    }

    function brandCampaignDetail(id) {
        const c = campaign(id);
        if (!c || c.brand !== 'Habi Home') return notFound('#/brand/campaigns', 'Campaigns');
        if (c.state === 'draft') return brandCampaignDraft(c);
        const status = campaignStatus(c);
        const regs = brandRegs(c.id);
        const inc = linkedIncentive(c.id);
        const p = PARTNERS[c.partner];
        const ended = status === 'relationshipEnded';
        const header = `<section class="ci-record" aria-labelledby="page-title">
            <div class="ci-record-top"><div class="ci-record-title"><p class="ci-eyebrow">Campaign</p><h1 id="page-title" tabindex="-1">${esc(c.title)}</h1></div>
            <div class="ci-actions">${demo(
                `/brand/lead-registrations?campaign=${c.id}`,
                `${icon('clipboard')}Open in Lead registrations`,
                'wd-button wd-button-secondary',
            )}${
                inc || ended
                    ? ''
                    : `<a class="wd-button wd-button-primary" href="#/brand/incentives/new?campaign=${c.id}">${icon('gift')}Add incentive</a>`
            }</div></div>
            <dl class="ci-meta"><div><dt class="wd-sr-only">Status</dt><dd>${badge(status)}</dd></div>
            <div><dt>Partner</dt><dd style="display:flex;align-items:center;gap:6px">${avatar(c.partner)}${demo(
                '/brand/partners/' + c.partner,
                esc(p.name),
                'wd-link',
            )}</dd></div>
            <div><dt>Due</dt><dd>${time(c.due)}</dd></div><div><dt>Shared</dt><dd>${time(c.sharedOn)}</dd></div></dl>
            ${
                ended
                    ? `<div class="ci-callout is-warning" role="note">${icon('alert')}<p><strong>The relationship with ${esc(
                          p.name,
                      )} ended on ${fmt(ENDED_ON)}.</strong> They can no longer read this brief, and their registrations are no longer shared with you. Approved awards stay in Incentives.</p></div>`
                    : ''
            }
        </section>`;
        const rows = regs
            .map(
                (r) =>
                    `<tr><th scope="row" data-label="Lead">${demo(
                        `/brand/lead-registrations/${r.id}`,
                        esc(r.name),
                        'wd-registration-lead',
                    )}</th><td data-label="Stage">${stageChip(r.stage)}</td><td data-label="Registered">${time(
                        r.registered,
                    )}</td><td data-label="Linked">${linkedOn(r)}</td></tr>`,
            )
            .join('');
        const leads = `<section class="wd-card" aria-labelledby="leads-title">
            <div class="ci-section-head"><h2 id="leads-title">Registered leads <span class="ci-count">${regs.length}</span>${annot(
                'D43 source campaign',
            )}</h2></div>
            <p class="wd-description" style="margin:-8px 0 14px">Leads ${esc(
                p.name,
            )} registered with this campaign as the source. Open a lead for its shared summary and contact.</p>
            ${
                regs.length
                    ? `${stageSummary(regs)}<div class="wd-data-region"><table class="wd-data-table"><caption>Registered leads for ${esc(
                          c.title,
                      )}</caption><thead><tr><th scope="col">Lead</th><th scope="col">Stage</th><th scope="col">Registered</th><th scope="col">Linked to campaign</th></tr></thead><tbody>${rows}</tbody></table></div>`
                    : empty(
                          ended ? 'No shared leads' : 'No leads linked yet',
                          ended
                              ? 'Registrations stop being shared when a relationship ends.'
                              : `When ${esc(p.name)} registers a lead and chooses this campaign as its source, it appears here with its stage.`,
                      )
            }
        </section>`;
        const brief = `<section class="wd-card" aria-labelledby="brief-title"><div class="ci-section-head"><h2 id="brief-title">Brief</h2><span class="ci-small ci-muted" style="display:inline-flex;gap:6px;align-items:center">${icon(
            'lock',
            14,
        )}Read-only since ${fmt(c.sharedOn)}</span></div><p class="ci-brief">${esc(c.brief)}</p></section>`;
        const side = `<aside class="ci-stack" aria-label="Campaign details">
            <section class="wd-card ${inc ? 'ci-teaser' : ''}" aria-labelledby="inc-title">
                <div class="ci-section-head" style="margin-bottom:4px"><h2 id="inc-title">Incentive</h2>${annot('D44')}</div>
                ${
                    inc
                        ? `<p><a class="wd-link" href="#/brand/incentives/${inc.id}">${esc(inc.title)}</a></p>${rewardBlock(inc, 40)}${meter(
                              used(awardsFor(inc.id, c.partner)),
                              inc.capPartner,
                              'Awards approved for ' + p.name,
                          )}${
                              awardsFor(inc.id, c.partner).some((a) => a.status === 'pending')
                                  ? `<p class="ci-flag">${icon('clock', 12)}${plural(
                                        awardsFor(inc.id, c.partner).filter((a) => a.status === 'pending').length,
                                        'claim',
                                    )} waiting for review</p>`
                                  : ''
                          }`
                        : `<div class="wd-empty-state is-compact"><h3>No incentive</h3><p>Reward ${esc(
                              p.name,
                          )} for leads from this campaign. You approve every award.</p></div>${
                              ended
                                  ? ''
                                  : `<a class="wd-button wd-button-secondary" href="#/brand/incentives/new?campaign=${c.id}">${icon('gift')}Add incentive</a>`
                          }`
                }
            </section>
            <section class="wd-card" aria-labelledby="details-title"><h2 id="details-title" style="margin-bottom:12px">Details</h2>
                <dl class="wd-detail-list"><dt>Partner</dt><dd>${esc(p.name)}</dd><dt>Due date</dt><dd>${fmt(c.due)}${
                    c.due < TODAY
                        ? ' <span class="ci-muted">(past)</span>'
                        : ` <span class="ci-muted">(in ${daysBetween(TODAY, c.due)} days)</span>`
                }</dd><dt>Shared</dt><dd>${fmt(c.sharedOn)}</dd><dt>Created</dt><dd>${fmt(c.created)}</dd></dl>
            </section>
        </aside>`;
        return `${crumb([['Campaigns', '#/brand/campaigns'], [c.title]])}${header}<div class="ci-columns"><div class="ci-stack">${leads}${brief}</div>${side}</div>`;
    }

    function brandCampaignDraft(c) {
        const partners = Object.keys(PARTNERS).filter(relActive);
        const header = `<section class="ci-record" aria-labelledby="page-title"><div class="ci-record-top"><div class="ci-record-title"><p class="ci-eyebrow">Campaign</p><h1 id="page-title" tabindex="-1">${esc(
            c.title,
        )}</h1></div></div><dl class="ci-meta"><div><dt class="wd-sr-only">Status</dt><dd>${badge('draft')}</dd></div><div><dt>Created</dt><dd>${time(
            c.created,
        )}</dd></div><div><dt>Visible to</dt><dd>Your workspace only</dd></div></dl></section>`;
        const form = `<form class="ci-form" id="draft-form" data-campaign="${c.id}" novalidate>
            <fieldset class="ci-fieldset"><legend>Brief</legend>
                <div class="ci-field"><label for="draft-brief">What should the Partner do?</label><span class="ci-hint" id="draft-brief-hint">Audience, message, what counts as a lead and any deliverables. Read-only once shared.</span>
                <textarea id="draft-brief" name="brief" maxlength="8000" aria-describedby="draft-brief-hint">${esc(c.brief)}</textarea></div>
                <div class="ci-row"><div class="ci-field"><label for="draft-due">Due date</label><input type="date" id="draft-due" name="due" value="${c.due || ''}" /></div></div>
            </fieldset>
            <fieldset class="ci-fieldset"><legend>Share</legend>
                <div class="ci-field"><label for="draft-partner">Partner</label><span class="ci-hint" id="draft-partner-hint">One active Partner. Their members can read the brief; other Partners cannot.</span>
                <select id="draft-partner" name="partner" aria-describedby="draft-partner-hint"><option value="">Choose a Partner</option>${partners
                    .map(
                        (k) =>
                            `<option value="${k}" ${c.partner === k ? 'selected' : ''}>${esc(PARTNERS[k].name)}</option>`,
                    )
                    .join('')}</select></div>
            </fieldset>
            <div class="ci-form-actions"><button type="submit" class="wd-button wd-button-secondary">Save private draft</button><span class="ci-spacer"></span><button type="button" class="wd-button wd-button-primary" data-action="share-campaign" id="share-button">${icon(
                'send',
            )}Share brief…</button></div>
        </form>`;
        const side = `<aside class="ci-stack ci-sticky" aria-label="Sharing checklist"><section class="wd-card" aria-labelledby="ready-title"><h2 id="ready-title" style="margin-bottom:12px">Ready to share?</h2><ul class="ci-checklist" id="ready-list"></ul>
            <p class="ci-note" style="margin-top:14px">${icon('info', 14)}<span>Sharing publishes the brief and due date together. You can't edit them afterwards — create a new campaign instead.</span></p>${annot(
                'Replaces the single combined form with a checklist and a confirmation step',
            )}</section></aside>`;
        return `${crumb([['Campaigns', '#/brand/campaigns'], [c.title]])}${header}<div class="ci-columns"><div class="ci-stack">${form}</div>${side}</div>`;
    }
    function draftReadiness() {
        const brief = document.getElementById('draft-brief')?.value.trim();
        const due = document.getElementById('draft-due')?.value;
        const partner = document.getElementById('draft-partner')?.value;
        return [
            ['Brief written', !!brief],
            ['Due date set', !!due],
            ['Partner chosen', !!partner],
        ];
    }
    function syncDraft() {
        const list = document.getElementById('ready-list');
        if (!list) return;
        const items = draftReadiness();
        list.innerHTML = items
            .map(
                ([label, ok]) =>
                    `<li class="ci-check-reason ${ok ? 'is-ok' : ''}" style="font-size:14px">${icon(ok ? 'check' : 'clock', 16)}${label}<span class="wd-sr-only">: ${
                        ok ? 'done' : 'not yet'
                    }</span></li>`,
            )
            .join('');
        const button = document.getElementById('share-button');
        const missing = items.filter(([, ok]) => !ok).map(([l]) => l.toLowerCase());
        button.disabled = missing.length > 0;
        button.title = missing.length ? 'Still needed: ' + missing.join(', ') : '';
    }

    // ================= BRAND: Incentives =================
    function brandIncentives(tab) {
        const list = db.incentives;
        const pending = pendingAwards();
        const approved = db.awards.filter((a) => a.status === 'approved');
        const fulfilled = db.awards.filter((a) => a.status === 'fulfilled');
        const owed = approved.filter((a) => incentive(a.incentive).type === 'cash').reduce((n, a) => n + a.amount, 0);
        const paid = fulfilled.filter((a) => incentive(a.incentive).type === 'cash').reduce((n, a) => n + a.amount, 0);
        const head = `<header class="ci-head"><div><p class="ci-eyebrow">Programs</p><h1 id="page-title" tabindex="-1">Incentives</h1><p class="wd-description">Offer rewards to Partners and approve the awards they earn with registered leads. WeavDash records what is owed; you pay or deliver it outside WeavDash.</p></div><div class="ci-actions"><a class="wd-button wd-button-primary" href="#/brand/incentives/new">${icon(
            'plus',
        )}New incentive</a></div></header>`;
        if (!list.length) {
            return `${head}<section class="wd-card">${empty(
                'No incentives yet',
                'Create an offer — for example a cash bonus per onboarded lead — and share it with selected Partners. They claim awards; you approve each one.',
                `<a class="wd-button wd-button-primary" href="#/brand/incentives/new">${icon('plus')}Create your first incentive</a>`,
            )}</section>`;
        }
        const tabs = `<nav class="ci-tabs" aria-label="Incentive views"><a href="#/brand/incentives" ${tab === 'offers' ? 'aria-current="page"' : ''}>Offers <span class="ci-count">${
            list.length
        }</span></a><a href="#/brand/incentives/awards" ${tab === 'awards' ? 'aria-current="page"' : ''}>Awards ${
            pending.length
                ? `<span class="ci-tab-alert">${pending.length}<span class="wd-sr-only"> to review</span></span>`
                : ''
        }</a></nav>`;
        const figs = figures([
            {
                value: list.filter((i) => incentiveStatus(i) === 'shared').length,
                label: 'Active offers',
                scope: 'Shared and within dates',
            },
            {
                value: pending.length,
                label: 'Awards to review',
                scope: pending.length
                    ? `<a class="wd-link" href="#/brand/incentives/awards">Review now</a>`
                    : 'Nothing waiting',
                attention: pending.length > 0,
            },
            {
                value: peso(owed),
                label: 'Approved, not yet delivered',
                scope: plural(approved.length, 'award'),
            },
            {
                value: peso(paid),
                label: 'Delivered this year',
                scope: plural(fulfilled.length, 'award') + ' fulfilled',
            },
        ]);
        return `${head}${figs}${tabs}${tab === 'awards' ? awardsSection() : offersSection()}`;
    }
    function offersSection() {
        const list = db.incentives;
        const by = (s) => list.filter((i) => incentiveStatus(i) === s);
        const shown = filters.offers === 'all' ? list : by(filters.offers);
        const rows = shown
            .map((i) => {
                const st = incentiveStatus(i);
                return `<tr><th scope="row" data-label="Incentive"><div class="ci-cell-title"><a href="#/brand/incentives/${i.id}">${esc(
                    i.title,
                )}</a><span class="ci-cell-sub">${i.campaign ? 'Campaign: ' + esc(campaign(i.campaign).title) : 'Any lead'}</span></div></th>
                <td data-label="Reward">${esc(rewardValue(i))}<br /><span class="ci-cell-sub">${rewardKind(i).split(' · ')[0]}</span></td>
                <td data-label="Audience">${i.audience.map((k) => esc(PARTNERS[k].name)).join(', ')}</td>
                <td data-label="Status">${badge(st)}</td>
                <td data-label="Awards" class="ci-num">${st === 'draft' ? '<span class="wd-registration-none">—</span>' : `${used(awardsFor(i.id))}${i.capTotal ? ' of ' + i.capTotal : ''}`}${
                    awardsFor(i.id).some((a) => a.status === 'pending')
                        ? `<br /><span class="ci-flag">${icon('clock', 12)}${awardsFor(i.id).filter((a) => a.status === 'pending').length} pending</span>`
                        : ''
                }</td>
                <td data-label="Runs">${fmt(i.start)} – ${fmt(i.end)}</td></tr>`;
            })
            .join('');
        return `<section class="wd-card" aria-labelledby="offers-title"><div class="ci-section-head"><h2 id="offers-title">Offers</h2></div>
            <div class="pr-chips">${chips(
                'offers',
                [
                    { value: 'all', label: 'All', count: list.length },
                    {
                        value: 'draft',
                        label: 'Drafts',
                        count: by('draft').length,
                    },
                    {
                        value: 'shared',
                        label: 'Active',
                        count: by('shared').length,
                    },
                    {
                        value: 'ended',
                        label: 'Ended',
                        count: by('ended').length,
                    },
                ],
                filters.offers,
            )}</div>
            <div class="wd-data-region"><table class="wd-data-table"><caption>Incentive offers</caption><thead><tr><th scope="col">Incentive</th><th scope="col">Reward</th><th scope="col">Audience</th><th scope="col">Status</th><th scope="col">Awards</th><th scope="col">Runs</th></tr></thead><tbody>${
                rows || `<tr><td colspan="6">${empty('No offers match', 'Choose another status.')}</td></tr>`
            }</tbody></table></div></section>`;
    }
    function awardRows(list, showIncentive = true) {
        return list
            .map((a) => {
                const i = incentive(a.incentive);
                return `<tr><th scope="row" data-label="Partner"><div class="ci-cell-title"><button type="button" class="ci-row-button" data-action="review-award" data-award="${a.id}">${esc(
                    PARTNERS[a.partner].name,
                )}</button>${showIncentive ? `<span class="ci-cell-sub">${esc(i.title)}</span>` : ''}</div></th>
                <td data-label="Leads">${a.regs.map((r) => esc(registration(r).name)).join(', ')}</td>
                <td data-label="Reward" class="ci-num">${i.type === 'cash' ? peso(a.amount) : esc(i.reward)}</td>
                <td data-label="Status">${badge(a.status)}</td>
                <td data-label="Updated">${time(a.fulfilled || a.decided || a.claimed)}</td></tr>`;
            })
            .join('');
    }
    function awardsTable(list, chipId, current, showIncentive) {
        const count = (s) => (s === 'all' ? list.length : list.filter((a) => a.status === s).length);
        const shown = current === 'all' ? list : list.filter((a) => a.status === current);
        const emptyText = {
            pending: [
                'Nothing to review',
                'New claims from Partners appear here. You approve or reject each one with a reason.',
            ],
            approved: ['No approved awards waiting', 'Approved awards stay here until you mark them fulfilled.'],
        }[current] || ['No awards match', 'Choose another status.'];
        return `<div class="pr-chips">${chips(
            chipId,
            ['pending', 'approved', 'fulfilled', 'rejected', 'cancelled', 'all']
                .filter((s) => s === 'all' || s === 'pending' || count(s))
                .map((s) => ({
                    value: s,
                    label: s === 'all' ? 'All' : s === 'pending' ? 'To review' : BADGES[s][2],
                    count: count(s),
                })),
            current,
        )}</div>${
            shown.length
                ? `<div class="wd-data-region"><table class="wd-data-table"><caption>Awards</caption><thead><tr><th scope="col">Partner</th><th scope="col">Leads</th><th scope="col">Reward</th><th scope="col">Status</th><th scope="col">Updated</th></tr></thead><tbody>${awardRows(
                      shown,
                      showIncentive,
                  )}</tbody></table></div>`
                : empty(emptyText[0], emptyText[1])
        }`;
    }
    function awardsSection() {
        return `<section class="wd-card" aria-labelledby="awards-title"><div class="ci-section-head"><h2 id="awards-title">Awards</h2><span class="ci-small ci-muted">Open a Partner to review, approve or mark fulfilled.</span></div>${awardsTable(
            db.awards,
            'awards',
            filters.awards,
            true,
        )}</section>`;
    }

    function brandIncentiveDetail(id) {
        const i = incentive(id);
        if (!i) return notFound('#/brand/incentives', 'Incentives');
        if (i.state === 'draft') return incentiveForm(i);
        const st = incentiveStatus(i);
        const list = awardsFor(i.id);
        const header = `<section class="ci-record" aria-labelledby="page-title"><div class="ci-record-top"><div class="ci-record-title"><p class="ci-eyebrow">Incentive</p><h1 id="page-title" tabindex="-1">${esc(
            i.title,
        )}</h1></div><div class="ci-actions">${
            st === 'shared'
                ? `<button type="button" class="wd-button wd-button-secondary" data-action="end-incentive" data-incentive="${i.id}">End incentive…</button>`
                : ''
        }</div></div>
            <dl class="ci-meta"><div><dt class="wd-sr-only">Status</dt><dd>${badge(st)}</dd></div><div><dt>Runs</dt><dd>${fmt(i.start)} – ${fmt(i.end)}</dd></div>${
                i.campaign
                    ? `<div><dt>Campaign</dt><dd><a class="wd-link" href="#/brand/campaigns/${i.campaign}">${esc(campaign(i.campaign).title)}</a></dd></div>`
                    : ''
            }<div><dt>Shared</dt><dd>${time(i.sharedOn)}</dd></div></dl></section>`;
        const awardsCard = `<section class="wd-card" aria-labelledby="inc-awards-title"><div class="ci-section-head"><h2 id="inc-awards-title">Awards <span class="ci-count">${
            list.length
        }</span></h2></div>${awardsTable(list, 'incAwards', filters.incAwards, false)}</section>`;
        const terms = `<section class="wd-card" aria-labelledby="terms-title"><div class="ci-section-head"><h2 id="terms-title">Terms</h2><span class="ci-small ci-muted" style="display:inline-flex;gap:6px;align-items:center">${icon(
            'lock',
            14,
        )}Read-only since shared</span></div><p class="ci-brief">${esc(i.terms || 'No additional terms.')}</p></section>`;
        const audience = i.audience
            .map((k) => {
                const mine = awardsFor(i.id, k);
                return `<li class="ci-check" style="cursor:default">${avatar(k)}<div class="ci-check-body"><div class="ci-check-top"><span class="ci-check-name">${esc(
                    PARTNERS[k].name,
                )}</span>${relActive(k) ? '' : badge('relationshipEnded')}</div>${meter(used(mine), i.capPartner, 'Awards approved')}${
                    mine.some((a) => a.status === 'pending')
                        ? `<span class="ci-flag">${icon('clock', 12)}${mine.filter((a) => a.status === 'pending').length} pending</span>`
                        : ''
                }</div></li>`;
            })
            .join('');
        const side = `<aside class="ci-stack" aria-label="Offer summary"><section class="wd-card ci-teaser" aria-labelledby="offer-title"><h2 id="offer-title">Offer</h2>${rewardBlock(
            i,
        )}${ruleList(i)}${i.capTotal ? meter(used(list), i.capTotal, 'All awards approved') : ''}</section>
            <section class="wd-card" aria-labelledby="aud-title"><h2 id="aud-title" style="margin-bottom:12px">Audience</h2><ul class="ci-checklist">${audience}</ul></section></aside>`;
        return `${crumb([['Incentives', '#/brand/incentives'], [i.title]])}${header}<div class="ci-columns"><div class="ci-stack">${awardsCard}${terms}</div>${side}</div>`;
    }

    // New incentive form; also opens existing drafts.
    function incentiveForm(existing, presetCampaign) {
        if (presetCampaign && !campaign(presetCampaign)) presetCampaign = null;
        const i = existing || {
            id: null,
            title: '',
            type: 'cash',
            amount: 200000,
            reward: '',
            campaign: presetCampaign || '',
            audience: presetCampaign ? [campaign(presetCampaign).partner] : [],
            stage: 'onboarded',
            perAward: 1,
            start: TODAY,
            end: '2027-02-28',
            capPartner: 10,
            capTotal: null,
            terms: '',
        };
        const campaigns = brandCampaigns().filter((c) => campaignStatus(c) === 'shared');
        const partners = Object.keys(PARTNERS).filter(relActive);
        const title = existing ? existing.title : 'New incentive';
        const radio = (value, label, sub, ic) =>
            `<label>${''}<input type="radio" name="type" value="${value}" ${i.type === value ? 'checked' : ''} /><span><span class="ci-choice-title">${icon(
                ic,
                14,
            )} ${label}</span><span class="ci-choice-sub">${sub}</span></span></label>`;
        const form = `<form class="ci-form" id="incentive-form" data-incentive="${i.id || ''}" novalidate>
            <div id="form-errors" tabindex="-1"></div>
            <fieldset class="ci-fieldset"><legend>Offer</legend>
                <div class="ci-field"><label for="inc-title">Title</label><input type="text" id="inc-title" name="title" maxlength="120" value="${esc(i.title)}" placeholder="e.g. Holiday onboarding bonus" /></div>
                <div class="ci-field"><span class="ci-field-label" id="type-label">Reward type</span><fieldset class="ci-segmented" aria-labelledby="type-label">${radio(
                    'cash',
                    'Cash',
                    'A fixed amount per award',
                    'banknote',
                )}${radio('noncash', 'Non-cash', 'Product, service or perk', 'package')}${radio('percent', 'Percentage bonus', 'Described in your terms', 'percent')}</fieldset></div>
                <div class="ci-field" data-show="cash"><label for="inc-amount">Amount per award</label><div class="ci-money"><span aria-hidden="true">₱</span><input type="number" id="inc-amount" name="amount" min="1" step="1" value="${
                    i.amount ? i.amount / 100 : ''
                }" aria-describedby="inc-amount-hint" /></div><span class="ci-hint" id="inc-amount-hint">Recorded as owed when you approve an award. WeavDash does not send money.</span></div>
                <div class="ci-field" data-show="noncash percent"><label for="inc-reward">Reward</label><input type="text" id="inc-reward" name="reward" maxlength="160" value="${esc(
                    i.reward,
                )}" placeholder="e.g. Habi Home starter bundle" /></div>
                <div class="ci-field"><label for="inc-terms">Terms</label><span class="ci-hint" id="inc-terms-hint">Shown to Partners. Say how and when you deliver the reward.</span><textarea id="inc-terms" name="terms" maxlength="2000" aria-describedby="inc-terms-hint">${esc(
                    i.terms,
                )}</textarea></div>
            </fieldset>
            <fieldset class="ci-fieldset"><legend>Who qualifies</legend>
                <div class="ci-row"><div class="ci-field"><label for="inc-stage">Lead reaches</label><select id="inc-stage" name="stage">${[
                    'pitched',
                    'negotiating',
                    'onboarded',
                    'active',
                    'repeat-client',
                ]
                    .map(
                        (s) =>
                            `<option value="${s}" ${i.stage === s ? 'selected' : ''}>${STAGES[s].label} or later</option>`,
                    )
                    .join('')}</select></div>
                <div class="ci-field"><label for="inc-per">Leads per award</label><input type="number" id="inc-per" name="perAward" min="1" max="20" value="${i.perAward}" /></div></div>
                <div class="ci-field"><label for="inc-campaign">Source campaign <span class="ci-muted">(optional)</span></label><span class="ci-hint" id="inc-campaign-hint">Limit the offer to leads registered with this campaign as their source. ${annot(
                    'D43',
                )}</span><select id="inc-campaign" name="campaign" aria-describedby="inc-campaign-hint"><option value="">Any registered lead</option>${campaigns
                    .map(
                        (c) =>
                            `<option value="${c.id}" ${i.campaign === c.id ? 'selected' : ''}>${esc(c.title)} — ${esc(PARTNERS[c.partner].name)}</option>`,
                    )
                    .join('')}</select></div>
                <div class="ci-field"><span class="ci-field-label" id="aud-label">Partners</span><span class="ci-hint" id="aud-hint"></span><div role="group" aria-labelledby="aud-label" aria-describedby="aud-hint"><ul class="ci-checklist">${partners
                    .map(
                        (k) =>
                            `<li><label class="ci-check">${''}<input type="checkbox" name="audience" value="${k}" ${i.audience.includes(k) ? 'checked' : ''} />${avatar(
                                k,
                            )}<span class="ci-check-body"><span class="ci-check-name">${esc(PARTNERS[k].name)}</span></span></label></li>`,
                    )
                    .join('')}</ul></div></div>
            </fieldset>
            <fieldset class="ci-fieldset"><legend>Dates and limits</legend>
                <div class="ci-row"><div class="ci-field"><label for="inc-start">Starts</label><input type="date" id="inc-start" name="start" value="${i.start}" /></div><div class="ci-field"><label for="inc-end">Ends</label><input type="date" id="inc-end" name="end" value="${i.end}" /></div></div>
                <div class="ci-row"><div class="ci-field"><label for="inc-cap-partner">Awards per Partner</label><input type="number" id="inc-cap-partner" name="capPartner" min="1" value="${
                    i.capPartner || ''
                }" placeholder="No limit" /></div><div class="ci-field"><label for="inc-cap-total">Awards in total <span class="ci-muted">(optional)</span></label><input type="number" id="inc-cap-total" name="capTotal" min="1" value="${
                    i.capTotal || ''
                }" placeholder="No limit" /></div></div>
            </fieldset>
            <div class="ci-form-actions"><a class="wd-button wd-button-secondary" href="#/brand/incentives">Cancel</a><button type="submit" class="wd-button wd-button-secondary">Save draft</button><span class="ci-spacer"></span><button type="button" class="wd-button wd-button-primary" data-action="share-incentive">${icon(
                'send',
            )}Share incentive…</button></div>
        </form>`;
        const side = `<aside class="ci-stack ci-sticky" aria-label="Partner preview"><section class="wd-card ci-teaser" aria-labelledby="preview-title"><p class="ci-preview-label">${icon(
            'eye',
            14,
        )}<span id="preview-title">What Partners see</span></p><div id="incentive-preview" aria-live="polite"></div>${annot(
            'Live preview of the Partner offer card',
        )}</section></aside>`;
        return `${crumb([['Incentives', '#/brand/incentives'], [title]])}<header class="ci-head"><div><p class="ci-eyebrow">Incentive</p><h1 id="page-title" tabindex="-1">${esc(
            title,
        )}</h1><p class="wd-description">${existing ? badge('draft') + ' ' : ''}Only your workspace sees this until you share it.</p></div></header><div class="ci-columns is-form"><div class="ci-stack">${form}</div>${side}</div>`;
    }
    function readIncentiveForm() {
        const f = document.getElementById('incentive-form');
        if (!f) return null;
        const data = new FormData(f);
        return {
            id: f.dataset.incentive || null,
            title: (data.get('title') || '').trim(),
            type: data.get('type'),
            amount: Math.round(Number(data.get('amount') || 0) * 100),
            reward: (data.get('reward') || '').trim(),
            terms: (data.get('terms') || '').trim(),
            stage: data.get('stage'),
            perAward: Math.max(1, Number(data.get('perAward') || 1)),
            campaign: data.get('campaign') || null,
            // A campaign fixes the audience; disabled boxes are not submitted, so read it from the campaign.
            audience: data.get('campaign') ? [campaign(data.get('campaign')).partner] : data.getAll('audience'),
            start: data.get('start'),
            end: data.get('end'),
            capPartner: Number(data.get('capPartner')) || null,
            capTotal: Number(data.get('capTotal')) || null,
        };
    }
    function syncIncentiveForm() {
        const f = document.getElementById('incentive-form');
        if (!f) return;
        const v = readIncentiveForm();
        f.querySelectorAll('[data-show]').forEach((el) => {
            el.hidden = !el.dataset.show.split(' ').includes(v.type);
        });
        // A campaign is shared with one Partner, so it fixes the audience.
        const hint = document.getElementById('aud-hint');
        const boxes = f.querySelectorAll('input[name="audience"]');
        if (v.campaign) {
            const owner = campaign(v.campaign).partner;
            boxes.forEach((b) => {
                b.checked = b.value === owner;
                b.disabled = true;
            });
            hint.textContent = `Set by the campaign: it is shared with ${PARTNERS[owner].name} only.`;
        } else {
            boxes.forEach((b) => (b.disabled = false));
            hint.textContent = 'Active Partners only. Each sees the offer and only their own awards.';
        }
        const preview = {
            ...readIncentiveForm(),
            title: v.title || 'Untitled incentive',
        };
        document.getElementById('incentive-preview').innerHTML =
            `<p class="ci-offer-from">From Habi Home</p><p class="ci-preview-title">${esc(preview.title)}</p>${rewardBlock(preview, 40)}<div style="margin-top:12px">${ruleList(preview)}</div>${
                preview.terms ? `<p class="ci-small" style="margin-top:12px">${esc(preview.terms)}</p>` : ''
            }`;
    }
    function validateIncentive(v, sharing) {
        const errors = [];
        if (!v.title) errors.push(['inc-title', 'Enter a title.']);
        if (v.type === 'cash' && !(v.amount > 0)) errors.push(['inc-amount', 'Enter an amount greater than zero.']);
        if (v.type !== 'cash' && !v.reward) errors.push(['inc-reward', 'Describe the reward.']);
        if (sharing) {
            if (!v.audience.length) errors.push(['aud-label', 'Choose at least one Partner.']);
            if (!v.start || !v.end || v.end < v.start)
                errors.push(['inc-end', 'The end date must be on or after the start date.']);
            if (v.end && v.end < TODAY) errors.push(['inc-end', 'The end date has passed.']);
        }
        const f = document.getElementById('incentive-form');
        f.querySelectorAll('.ci-error').forEach((e) => e.remove());
        f.querySelectorAll('[aria-invalid]').forEach((e) => e.removeAttribute('aria-invalid'));
        const box = document.getElementById('form-errors');
        if (!errors.length) {
            box.innerHTML = '';
            return true;
        }
        errors.forEach(([id, msg]) => {
            const el = document.getElementById(id);
            el.setAttribute('aria-invalid', 'true');
            el.closest('.ci-field').insertAdjacentHTML(
                'beforeend',
                `<span class="ci-error" id="${id}-error">${msg}</span>`,
            );
        });
        box.innerHTML = `<div class="ci-callout is-warning" role="alert">${icon('alert')}<div><strong>Check ${plural(
            errors.length,
            'field',
        )}</strong><ul style="margin:4px 0 0;padding-left:18px">${errors
            .map(
                ([id, msg]) =>
                    `<li><a class="wd-link" href="#${id}" data-action="jump" data-target="${id}">${msg}</a></li>`,
            )
            .join('')}</ul></div></div>`;
        box.focus();
        return false;
    }

    // ================= PARTNER =================
    function partnerCampaignList() {
        const list = partnerCampaigns();
        const head = `<header class="ci-head"><div><p class="ci-eyebrow">Programs</p><h1 id="page-title" tabindex="-1">Campaigns</h1><p class="wd-description">Briefs that Brands share with your workspace. Register leads with a brief as their source campaign so the Brand sees what it brought in.</p></div></header>`;
        if (!list.length) {
            return `${head}<section class="wd-card">${empty(
                'No shared briefs',
                scenario === 'ended'
                    ? 'Briefs from Habi Home are no longer available because the relationship ended. Your own leads and registrations stay in My leads.'
                    : 'Campaign briefs appear here when a connected Brand shares one with your workspace.',
            )}</section>`;
        }
        const endedNote =
            scenario === 'ended'
                ? `<div class="ci-callout is-warning" role="note">${icon('alert')}<p><strong>Your relationship with Habi Home ended on ${fmt(
                      ENDED_ON,
                  )}.</strong> Its briefs and incentives are no longer shown. Your own leads stay in My leads.</p></div>`
                : '';
        const rows = list
            .map((c) => {
                const mine = myRegs().filter((r) => r.campaign === c.id);
                const inc = linkedIncentive(c.id);
                const dueIn = daysBetween(TODAY, c.due);
                return `<tr><th scope="row" data-label="Brief"><div class="ci-cell-title"><a href="#/partner/campaigns/${c.id}">${esc(c.title)}</a><span class="ci-cell-sub">From ${esc(
                    c.brand,
                )}</span></div></th>
                <td data-label="Due">${time(c.due)}<br /><span class="ci-cell-sub">${dueIn < 0 ? 'Past due' : `In ${dueIn} days`}</span></td>
                <td data-label="Your leads" class="ci-num">${mine.length}</td>
                <td data-label="Incentive">${inc ? `<span class="wd-badge wd-badge-brand">${icon('gift', 14)}${esc(rewardValue(inc))}</span>` : '<span class="wd-registration-none">—</span>'}</td></tr>`;
            })
            .join('');
        return `${head}${endedNote}<section class="wd-card" aria-labelledby="list-title"><div class="ci-section-head"><h2 id="list-title">Shared briefs <span class="ci-count">${
            list.length
        }</span></h2></div><div class="wd-data-region"><table class="wd-data-table"><caption>Shared briefs</caption><thead><tr><th scope="col">Brief</th><th scope="col">Due</th><th scope="col">Your leads</th><th scope="col">Incentive</th></tr></thead><tbody>${rows}</tbody></table></div></section>`;
    }

    function partnerBrief(id) {
        const c = partnerCampaigns().find((x) => x.id === id);
        if (!c) return notFound('#/partner/campaigns', 'Campaigns');
        const mine = myRegs().filter((r) => r.campaign === c.id);
        const inc = linkedIncentive(c.id);
        const dueIn = daysBetween(TODAY, c.due);
        const header = `<section class="ci-record" aria-labelledby="page-title"><div class="ci-record-top"><div class="ci-record-title"><p class="ci-eyebrow">Brief from ${esc(
            c.brand,
        )}</p><h1 id="page-title" tabindex="-1">${esc(c.title)}</h1></div><div class="ci-actions"><button type="button" class="wd-button wd-button-primary" data-action="register-lead" data-campaign="${
            c.id
        }">${icon('plus')}Register a lead for this brief</button></div></div>
            <dl class="ci-meta"><div><dt>Due</dt><dd>${fmt(c.due)} <span class="ci-muted">(${dueIn < 0 ? 'past' : 'in ' + dueIn + ' days'})</span></dd></div><div><dt>Shared</dt><dd>${time(
                c.sharedOn,
            )}</dd></div><div><dt class="wd-sr-only">Access</dt><dd class="ci-muted" style="display:inline-flex;gap:6px;align-items:center">${icon('lock', 14)}Read-only</dd></div></dl></section>`;
        const status = (r) => {
            const a =
                inc &&
                db.awards.find((x) => x.incentive === inc.id && x.regs.includes(r.id) && x.status !== 'rejected');
            return a ? badge(a.status) : '<span class="wd-registration-none">—</span>';
        };
        const rows = mine
            .map(
                (r) =>
                    `<tr><th scope="row" data-label="Lead">${demo(`/partner/leads?entry=${r.id}`, esc(r.name), 'wd-registration-lead')}</th><td data-label="Stage">${stageChip(
                        r.stage,
                    )}</td><td data-label="Registered">${time(r.registered)}</td>${inc ? `<td data-label="Award">${status(r)}</td>` : ''}</tr>`,
            )
            .join('');
        const leads = `<section class="wd-card" aria-labelledby="leads-title"><div class="ci-section-head"><h2 id="leads-title">Your leads for this brief <span class="ci-count">${
            mine.length
        }</span>${annot('D43')}</h2></div>${
            mine.length
                ? `${stageSummary(mine)}<div class="wd-data-region"><table class="wd-data-table"><caption>Your leads for ${esc(
                      c.title,
                  )}</caption><thead><tr><th scope="col">Lead</th><th scope="col">Stage</th><th scope="col">Registered</th>${
                      inc ? '<th scope="col">Award</th>' : ''
                  }</tr></thead><tbody>${rows}</tbody></table></div>`
                : empty(
                      'No leads registered for this brief',
                      `Register a lead with ${esc(c.brand)} and choose this brief as its source campaign. ${esc(c.brand)} sees it on the campaign.`,
                  )
        }</section>`;
        const brief = `<section class="wd-card" aria-labelledby="brief-title"><h2 id="brief-title" style="margin-bottom:12px">Brief</h2><p class="ci-brief">${esc(c.brief)}</p></section>`;
        let side = '';
        if (inc) {
            const my = awardsFor(inc.id, 'alon');
            const open = incentiveStatus(inc) === 'shared' && (!inc.capPartner || used(my) < inc.capPartner);
            const ready = open ? myRegs().filter((r) => eligibility(r, inc).ok).length : 0;
            side = `<aside class="ci-stack" aria-label="Incentive"><section class="wd-card ci-teaser" aria-labelledby="teaser-title"><p class="ci-preview-label">${icon(
                'gift',
                14,
            )}<span id="teaser-title">Incentive on this brief</span></p><h3 style="font-size:17px">${esc(inc.title)}</h3>${rewardBlock(inc, 40)}${ruleList(
                inc,
            )}${meter(used(my), inc.capPartner, 'Your awards approved')}<p class="ci-small ci-muted">${
                ['fulfilled', 'approved', 'pending']
                    .map((s) => [s, my.filter((a) => a.status === s).length])
                    .filter(([, n]) => n)
                    .map(([s, n]) => `${n} ${BADGES[s][2].toLowerCase()}`)
                    .join(' · ') || 'No claims yet'
            }</p>
                <button type="button" class="wd-button wd-button-primary" data-action="claim" data-incentive="${inc.id}" ${ready >= inc.perAward ? '' : 'disabled'}>${icon(
                    'award',
                )}Claim award</button><p class="ci-small ci-muted">${ready ? plural(ready, 'lead') + ' ready to claim' : 'No leads qualify yet'} · <a class="wd-link" href="#/partner/incentives">All incentives</a></p></section></aside>`;
        }
        return `${crumb([['Campaigns', '#/partner/campaigns'], [c.title]])}${header}<div class="${side ? 'ci-columns is-incentive-first' : 'ci-stack'}"><div class="ci-stack">${leads}${brief}</div>${side}</div>`;
    }

    function partnerIncentives() {
        const offers = relActive('alon')
            ? db.incentives.filter((i) => i.audience.includes('alon') && incentiveStatus(i) === 'shared')
            : [];
        const my = relActive('alon') ? db.awards.filter((a) => a.partner === 'alon') : [];
        const head = `<header class="ci-head"><div><p class="ci-eyebrow">Programs</p><h1 id="page-title" tabindex="-1">Incentives</h1><p class="wd-description">Rewards Brands offer your workspace. Claim an award when your registered leads qualify. The Brand approves it and delivers the reward outside WeavDash.</p></div></header>`;
        if (!offers.length && !my.length) {
            return `${head}<section class="wd-card">${empty(
                'No incentives yet',
                scenario === 'ended'
                    ? 'Offers and awards from Habi Home are no longer shown because the relationship ended.'
                    : 'When a connected Brand offers you a reward, it appears here with what qualifies.',
            )}</section>`;
        }
        const owed = my
            .filter((a) => a.status === 'approved' && incentive(a.incentive).type === 'cash')
            .reduce((n, a) => n + a.amount, 0);
        const got = my
            .filter((a) => a.status === 'fulfilled' && incentive(a.incentive).type === 'cash')
            .reduce((n, a) => n + a.amount, 0);
        const cards = offers
            .map((i) => {
                const mine = awardsFor(i.id, 'alon');
                const ready = myRegs().filter((r) => eligibility(r, i).ok).length;
                const canClaim = ready >= i.perAward && (!i.capPartner || used(mine) < i.capPartner);
                return `<article class="wd-card ci-offer" aria-labelledby="offer-${i.id}"><div><p class="ci-offer-from">From Habi Home${
                    i.campaign
                        ? ` · <a class="wd-link" href="#/partner/campaigns/${i.campaign}">${esc(campaign(i.campaign).title)}</a>`
                        : ''
                }</p><h2 id="offer-${i.id}" style="font-size:18px;margin-top:2px">${esc(i.title)}</h2></div>${rewardBlock(i)}${ruleList(i)}${meter(
                    used(mine),
                    i.capPartner,
                    'Your awards approved',
                )}${i.terms ? `<p class="ci-small">${esc(i.terms)}</p>` : ''}<footer><span class="ci-small ${canClaim ? '' : 'ci-muted'}">${
                    canClaim
                        ? `${icon('check', 14)} ${plural(ready, 'lead')} ready`
                        : ready < i.perAward
                          ? `Needs ${plural(i.perAward, 'qualifying lead')}; ${ready} ready`
                          : 'Award limit reached'
                }</span><button type="button" class="wd-button wd-button-primary" data-action="claim" data-incentive="${i.id}" ${canClaim ? '' : 'disabled'}>${icon(
                    'award',
                )}Claim award</button></footer></article>`;
            })
            .join('');
        const rows = my
            .map((a) => {
                const i = incentive(a.incentive);
                const detail =
                    a.status === 'fulfilled'
                        ? `Delivered ${fmt(a.fulfilled)}${a.reference ? ' · ' + esc(a.reference) : ''}`
                        : a.status === 'approved'
                          ? `Approved ${fmt(a.decided)} · awaiting delivery`
                          : a.status === 'rejected'
                            ? esc(a.reason)
                            : a.status === 'cancelled'
                              ? esc(a.reason)
                              : `Claimed ${fmt(a.claimed)}`;
                return `<tr><th scope="row" data-label="Incentive"><div class="ci-cell-title"><span>${esc(i.title)}</span><span class="ci-cell-sub">${a.regs
                    .map((r) => esc(registration(r).name))
                    .join(
                        ', ',
                    )}</span></div></th><td data-label="Reward" class="ci-num">${i.type === 'cash' ? peso(a.amount) : esc(i.reward)}</td><td data-label="Status">${badge(
                    a.status,
                )}</td><td data-label="Details">${detail}</td></tr>`;
            })
            .join('');
        return `${head}${figures([
            {
                value: offers.length,
                label: 'Available offers',
                scope: 'From connected Brands',
            },
            {
                value: my.filter((a) => a.status === 'pending').length,
                label: 'Claims in review',
                scope: 'The Brand approves each one',
            },
            {
                value: peso(owed),
                label: 'Approved, awaiting delivery',
                scope: 'Paid outside WeavDash',
            },
            {
                value: peso(got),
                label: 'Received',
                scope: 'Marked fulfilled by the Brand',
            },
        ])}<section aria-labelledby="offers-title" class="ci-stack" style="gap:12px"><h2 id="offers-title" style="font-family:var(--wd-font-display);font-size:20px">Offers</h2>${
            offers.length
                ? `<div class="ci-offers">${cards}</div>`
                : `<div class="wd-card">${empty('No current offers', 'Ended offers keep their awards below.')}</div>`
        }</section>
        <section class="wd-card" aria-labelledby="my-title"><div class="ci-section-head"><h2 id="my-title">Your awards <span class="ci-count">${my.length}</span></h2></div>${
            my.length
                ? `<div class="wd-data-region"><table class="wd-data-table"><caption>Your awards</caption><thead><tr><th scope="col">Incentive</th><th scope="col">Reward</th><th scope="col">Status</th><th scope="col">Details</th></tr></thead><tbody>${rows}</tbody></table></div>`
                : empty('No awards yet', 'Claim an award from an offer above when your leads qualify.')
        }</section>`;
    }

    function notFound(back, label) {
        return `<section class="wd-card">${empty('Not available', 'This record does not exist in this state, or you cannot see it.', `<a class="wd-button wd-button-secondary" href="${back}">Back to ${label}</a>`)}</section>`;
    }

    // ================= modal system =================
    const modal = document.getElementById('modal');
    let returnFocus = null;
    function openModal(html, { panel = false, wide = false, focus } = {}) {
        // A confirmation opened from the review panel replaces it; focus later returns to the original trigger.
        if (modal.open) modal.close();
        else returnFocus = document.activeElement;
        modal.className = panel ? 'ci-panel' : 'ci-dialog' + (wide ? ' is-wide' : '');
        modal.innerHTML = `<div class="ci-dialog-inner">${html}</div>`;
        const label = modal.querySelector('h2');
        if (label) {
            label.id = 'modal-title';
            modal.setAttribute('aria-labelledby', 'modal-title');
        }
        modal.showModal();
        (focus && modal.querySelector(focus))?.focus();
    }
    function closeModal() {
        if (modal.open) modal.close();
    }
    modal.addEventListener('close', () => {
        if (modal.open) return; // reopened with new content
        modal.innerHTML = '';
        if (returnFocus && document.body.contains(returnFocus)) returnFocus.focus();
        else document.getElementById('page-title')?.focus();
    });
    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal();
    });
    const dialogHead = (title, sub = '') =>
        `<header><div><h2>${title}</h2>${sub ? `<p class="wd-description">${sub}</p>` : ''}</div><button type="button" class="ci-close" data-action="close">${icon(
            'x',
            20,
        )}<span class="wd-sr-only">Close</span></button></header>`;

    // ConfirmationDialog: names the record and the consequence, explicit action, Cancel focused first.
    function confirm({ title, subject, subjectSub, consequences, action, tone = 'primary', onConfirm }) {
        openModal(
            `${dialogHead(title)}<div class="ci-dialog-body"><div class="ci-confirm-subject"><strong>${subject}</strong>${
                subjectSub ? `<span class="ci-small ci-muted">${subjectSub}</span>` : ''
            }</div><ul class="ci-consequences">${consequences.map((c) => `<li>${c}</li>`).join('')}</ul></div><footer><button type="button" class="wd-button wd-button-secondary" data-action="close" id="confirm-cancel">Cancel</button><button type="button" class="wd-button wd-button-${tone}" id="confirm-go">${action}</button></footer>`,
            { focus: '#confirm-cancel' },
        );
        modal.querySelector('#confirm-go').addEventListener('click', (event) => {
            event.currentTarget.disabled = true; // no duplicate submissions
            onConfirm();
        });
    }

    // ---------- Brand: review an award (side panel) ----------
    function reviewAward(id) {
        const a = db.awards.find((x) => x.id === id);
        const i = incentive(a.incentive);
        const p = PARTNERS[a.partner];
        const mine = awardsFor(i.id, a.partner);
        const leads = a.regs
            .map((rid) => {
                const r = registration(rid);
                const e = eligibility(r, i, a.id);
                return `<li class="ci-check" style="cursor:default"><span class="ci-check-body"><span class="ci-check-top"><span class="ci-check-name">${esc(r.name)}</span>${stageChip(
                    r.stage,
                )}</span><span class="ci-small ci-muted">Registered ${fmt(r.registered)}${
                    r.campaign
                        ? ` · Source: ${esc(campaign(r.campaign).title)}, linked ${fmt(r.linked)}`
                        : ' · No source campaign'
                }</span>${
                    r.campaign && daysBetween(r.registered, r.linked) > 7
                        ? `<span class="ci-flag">${icon('clock', 12)}Linked ${daysBetween(r.registered, r.linked)} days after registering</span>`
                        : ''
                }${a.status === 'pending' ? `<span class="ci-check-reason ${e.ok ? 'is-ok' : ''}">${icon(e.ok ? 'check' : 'alert', 14)}${e.why}</span>` : ''}</span></li>`;
            })
            .join('');
        const history = [
            [`Claimed by ${esc(p.name)}`, a.claimed, true],
            a.decided && [a.status === 'rejected' ? 'Rejected' : 'Approved', a.decided, true],
            a.fulfilled && [`Marked fulfilled${a.reference ? ' · ' + esc(a.reference) : ''}`, a.fulfilled, true],
            a.status === 'cancelled' && ['Cancelled automatically', ENDED_ON, true],
        ]
            .filter(Boolean)
            .map(([t, d, done]) => `<li class="${done ? 'is-done' : ''}"><span>${t}<time>${fmt(d)}</time></span></li>`)
            .join('');
        let action = '';
        let footer = `<button type="button" class="wd-button wd-button-secondary" data-action="close">Close</button>`;
        if (a.status === 'pending') {
            const capLeft = i.capPartner ? i.capPartner - used(mine) : null;
            action = `<form id="decision-form" class="ci-form" style="gap:14px" data-award="${a.id}" novalidate>
                <fieldset class="ci-segmented" aria-label="Decision"><label><input type="radio" name="decision" value="approve" checked /><span><span class="ci-choice-title">${icon(
                    'check',
                    14,
                )} Approve</span><span class="ci-choice-sub">${capLeft !== null ? `Uses 1 of ${capLeft} remaining for ${esc(p.name)}` : 'No award limit'}</span></span></label><label><input type="radio" name="decision" value="reject" /><span><span class="ci-choice-title">${icon(
                    'x',
                    14,
                )} Reject</span><span class="ci-choice-sub">The Partner sees your reason</span></span></label></fieldset>
                ${
                    i.type === 'cash'
                        ? `<div class="ci-field" data-when="approve"><label for="award-amount">Amount</label><div class="ci-money"><span aria-hidden="true">₱</span><input type="number" id="award-amount" min="1" value="${
                              a.amount / 100
                          }" aria-describedby="award-amount-hint" /></div><span class="ci-hint" id="award-amount-hint">Offer value ${peso(i.amount)}. Changing it needs a reason.</span></div>`
                        : `<p class="ci-note" data-when="approve">${icon('package', 14)}<span>Reward: ${esc(i.reward)}</span></p>`
                }
                <div class="ci-field"><label for="award-reason">Reason <span class="ci-muted" id="reason-optional">(optional)</span></label><textarea id="award-reason" maxlength="500" style="min-height:84px"></textarea></div>
                ${
                    i.campaign
                        ? `<p class="ci-note" data-when="approve">${icon('link', 14)}<span>Approving also confirms <strong>${esc(
                              campaign(i.campaign).title,
                          )}</strong> as the source campaign of ${plural(a.regs.length, 'lead')}. ${annot('D43 Brand confirmation')}</span></p>`
                        : ''
                }
                </form>`;
            footer = `<button type="button" class="wd-button wd-button-secondary" data-action="close">Cancel</button><button type="button" class="wd-button wd-button-primary" data-action="decide" data-award="${a.id}" id="decide-button">Approve award</button>`;
        } else if (a.status === 'approved') {
            action = `<form id="fulfil-form" class="ci-form" style="gap:14px" novalidate><div class="ci-callout is-success">${icon('check')}<p><strong>Approved ${fmt(
                a.decided,
            )}.</strong> Deliver the reward outside WeavDash, then record it here.</p></div>
                <div class="ci-row"><div class="ci-field"><label for="fulfil-date">Delivered on</label><input type="date" id="fulfil-date" value="${TODAY}" /></div>
                <div class="ci-field"><label for="fulfil-ref">Reference <span class="ci-muted">(optional)</span></label><input type="text" id="fulfil-ref" maxlength="120" placeholder="GCash, bank or courier reference" /></div></div>
                <p class="ci-note">${icon('info', 14)}<span>WeavDash records this for both workspaces. It does not send money.</span></p></form>`;
            footer = `<button type="button" class="ci-row-button" style="margin-right:auto;color:#8a2a1f" data-action="reverse" data-award="${a.id}">Reverse approval…</button><button type="button" class="wd-button wd-button-secondary" data-action="close">Cancel</button><button type="button" class="wd-button wd-button-primary" data-action="fulfil" data-award="${a.id}">Mark fulfilled</button>`;
        } else if (a.reason) {
            action = `<div class="ci-callout ${a.status === 'rejected' ? 'is-warning' : 'is-warning'}">${icon('info')}<p><strong>${
                a.status === 'rejected' ? 'Reason' : 'Cancelled'
            }:</strong> ${esc(a.reason)}</p></div>`;
        }
        openModal(
            `${dialogHead(`Award for ${esc(p.name)}`, `${esc(i.title)} · ${badge(a.status)}`)}<div class="ci-dialog-body">
                <dl class="ci-meta"><div><dt>Reward</dt><dd>${i.type === 'cash' ? peso(a.amount) : esc(i.reward)}</dd></div><div><dt>Claimed</dt><dd>${fmt(a.claimed)}</dd></div></dl>
                ${a.note ? `<p class="ci-note">${icon('mail', 14)}<span><strong>Note from ${esc(p.name)}:</strong> ${esc(a.note)}</span></p>` : ''}
                <section aria-labelledby="cited-title"><h3 id="cited-title" style="font-size:15px;margin-bottom:8px">Qualifying ${a.regs.length === 1 ? 'lead' : 'leads'}</h3><ul class="ci-checklist">${leads}</ul></section>
                ${action}
                <section aria-labelledby="hist-title"><h3 id="hist-title" style="font-size:15px;margin-bottom:8px">History</h3><ol class="ci-timeline">${history}</ol></section>
            </div><footer>${footer}</footer>`,
            {
                panel: true,
                focus: a.status === 'pending' ? 'input[name="decision"]' : '.ci-close',
            },
        );
    }

    // ---------- Partner: claim an award ----------
    function claimDialog(incId) {
        const i = incentive(incId);
        const regs = myRegs();
        const items = regs
            .map((r) => {
                const e = eligibility(r, i);
                return { r, e };
            })
            .sort((x, y) => Number(y.e.ok) - Number(x.e.ok));
        const list = items
            .map(
                ({ r, e }) =>
                    `<li><label class="ci-check"><input type="checkbox" name="regs" value="${r.id}" ${e.ok ? '' : 'disabled'} /><span class="ci-check-body"><span class="ci-check-top"><span class="ci-check-name">${esc(
                        r.name,
                    )}</span>${stageChip(r.stage)}</span><span class="ci-small ci-muted">Registered ${fmt(r.registered)}${
                        r.campaign ? ' · Source: ' + esc(campaign(r.campaign).title) : ''
                    }</span><span class="ci-check-reason ${e.ok ? 'is-ok' : ''}">${icon(e.ok ? 'check' : 'info', 14)}${e.why}</span></span></label></li>`,
            )
            .join('');
        openModal(
            `${dialogHead(`Claim award`, `${esc(i.title)} · from Habi Home`)}<div class="ci-dialog-body">
                ${rewardBlock(i, 40)}
                <form id="claim-form" data-incentive="${i.id}" novalidate class="ci-form" style="gap:14px">
                <fieldset style="border:0;padding:0;margin:0;min-width:0"><legend class="ci-field-label" style="margin-bottom:4px">Choose ${plural(
                    i.perAward,
                    'qualifying lead',
                )}</legend><p class="ci-small ci-muted" id="claim-count" aria-live="polite" style="margin-bottom:8px">0 of ${i.perAward} selected</p><ul class="ci-checklist">${list}</ul></fieldset>
                <div class="ci-field"><label for="claim-note">Note to Habi Home <span class="ci-muted">(optional)</span></label><textarea id="claim-note" maxlength="500" style="min-height:72px"></textarea></div>
                <p class="ci-note">${icon('info', 14)}<span>Habi Home reviews every claim and delivers approved rewards outside WeavDash.</span></p>
                </form></div>
                <footer><button type="button" class="wd-button wd-button-secondary" data-action="close">Cancel</button><button type="button" class="wd-button wd-button-primary" data-action="submit-claim" id="claim-submit" disabled>Submit claim</button></footer>`,
            { wide: true, focus: 'input[name="regs"]:not(:disabled)' },
        );
    }
    function syncClaim() {
        const form = document.getElementById('claim-form');
        if (!form) return;
        const need = incentive(form.dataset.incentive).perAward;
        const boxes = [...form.querySelectorAll('input[name="regs"]')];
        const picked = boxes.filter((b) => b.checked).length;
        boxes.forEach((b) => {
            if (!b.checked && b.closest('label').querySelector('.is-ok')) b.disabled = picked >= need;
        });
        document.getElementById('claim-count').textContent = `${picked} of ${need} selected`;
        document.getElementById('claim-submit').disabled = picked !== need;
    }

    // ---------- Partner: register a lead with a source campaign (D43) ----------
    function registerDialog(campaignId) {
        const c = campaign(campaignId);
        const options = partnerCampaigns().filter((x) => x.brand === c.brand);
        openModal(
            `${dialogHead('Register a lead with ' + esc(c.brand), 'The same registration you make from My leads, with its source campaign chosen.')}<div class="ci-dialog-body">
            <form id="register-form" class="ci-form" style="gap:14px" novalidate>
                <div class="ci-field"><label for="reg-lead">Lead from your pipeline</label><select id="reg-lead">${db.pipeline
                    .map((p) => `<option value="${p.id}">${esc(p.name)} · ${STAGES[p.stage].label}</option>`)
                    .join('')}</select></div>
                <div class="ci-field"><label for="reg-name">Name shown to ${esc(c.brand)}</label><input type="text" id="reg-name" maxlength="160" value="${esc(db.pipeline[0]?.name || '')}" /></div>
                <div class="ci-field"><label for="reg-summary">Summary for ${esc(c.brand)} <span class="ci-muted">(optional)</span></label><textarea id="reg-summary" maxlength="1000" style="min-height:72px"></textarea></div>
                <label class="ci-check"><input type="checkbox" id="reg-contact" /><span class="ci-check-body"><span class="ci-check-name">Share contact name and email</span><span class="ci-small ci-muted">Otherwise ${esc(
                    c.brand,
                )} sees only the name and summary.</span></span></label>
                <div class="ci-field"><label for="reg-campaign">Source campaign ${annot('D43')}</label><select id="reg-campaign" aria-describedby="reg-campaign-hint"><option value="">None</option>${options
                    .map((o) => `<option value="${o.id}" ${o.id === c.id ? 'selected' : ''}>${esc(o.title)}</option>`)
                    .join(
                        '',
                    )}</select><span class="ci-hint" id="reg-campaign-hint">${esc(c.brand)} sees the campaign and the date you set it. You can change it later on the registration.</span></div>
            </form></div><footer><button type="button" class="wd-button wd-button-secondary" data-action="close">Cancel</button><button type="button" class="wd-button wd-button-primary" data-action="submit-register">Register lead</button></footer>`,
            { focus: '#reg-lead' },
        );
    }

    // ================= toast =================
    const toastEl = document.getElementById('toast');
    let toastTimer;
    function toast(text) {
        toastEl.innerHTML = `${icon('check', 18)}<span>${text}</span>`;
        toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => (toastEl.hidden = true), 4500);
    }

    // ================= actions =================
    const actions = {
        close: closeModal,
        filter(el) {
            filters[el.dataset.filter] = el.dataset.value;
            render(false);
            document
                .querySelector(`button[data-filter="${el.dataset.filter}"][data-value="${el.dataset.value}"]`)
                ?.focus();
        },
        jump(el, event) {
            event.preventDefault();
            const target = document.getElementById(el.dataset.target);
            (target?.matches('input, select, textarea')
                ? target
                : target?.closest('.ci-field')?.querySelector('input, select, textarea')
            )?.focus();
        },
        'new-campaign'() {
            openModal(
                `${dialogHead('New campaign', 'Starts as a private draft. Only your workspace can see it.')}<div class="ci-dialog-body"><form id="new-campaign-form" class="ci-form" novalidate><div class="ci-field"><label for="nc-title">Campaign name</label><input type="text" id="nc-title" maxlength="160" placeholder="e.g. Valentine's gifting edit" /></div></form></div><footer><button type="button" class="wd-button wd-button-secondary" data-action="close">Cancel</button><button type="button" class="wd-button wd-button-primary" data-action="create-campaign">Create draft</button></footer>`,
                { focus: '#nc-title' },
            );
        },
        'create-campaign'() {
            const input = document.getElementById('nc-title');
            const title = input.value.trim();
            if (!title) {
                input.setAttribute('aria-invalid', 'true');
                if (!document.getElementById('nc-title-error'))
                    input.insertAdjacentHTML(
                        'afterend',
                        '<span class="ci-error" id="nc-title-error">Enter a campaign name.</span>',
                    );
                input.setAttribute('aria-describedby', 'nc-title-error');
                input.focus();
                return;
            }
            const id = uid('c');
            db.campaigns.unshift({
                id,
                brand: 'Habi Home',
                title,
                partner: null,
                state: 'draft',
                due: '',
                created: TODAY,
                brief: '',
            });
            closeModal();
            location.hash = '#/brand/campaigns/' + id;
            toast('Draft created. Only your workspace can see it.');
        },
        'share-campaign'() {
            const c = campaign(document.getElementById('draft-form').dataset.campaign);
            const partner = document.getElementById('draft-partner').value;
            confirm({
                title: 'Share this brief?',
                subject: esc(c.title),
                subjectSub: `With ${esc(PARTNERS[partner].name)} · due ${fmt(document.getElementById('draft-due').value)}`,
                consequences: [
                    `${esc(PARTNERS[partner].name)}'s members can read the brief and due date.`,
                    'The brief and due date become read-only. To change them, create a new campaign.',
                    'Ending the relationship removes their access.',
                ],
                action: 'Share brief',
                onConfirm() {
                    Object.assign(c, {
                        brief: document.getElementById('draft-brief').value,
                        due: document.getElementById('draft-due').value,
                        partner,
                        state: 'shared',
                        sharedOn: TODAY,
                    });
                    closeModal();
                    render();
                    toast(`Brief shared with ${PARTNERS[partner].name}.`);
                },
            });
        },
        'share-incentive'() {
            const v = readIncentiveForm();
            if (!validateIncentive(v, true)) return;
            confirm({
                title: 'Share this incentive?',
                subject: esc(v.title),
                subjectSub: `${esc(rewardValue(v))} · ${fmt(v.start)} – ${fmt(v.end)}`,
                consequences: [
                    `${v.audience.map((k) => esc(PARTNERS[k].name)).join(' and ')} can see the offer and claim awards from ${fmt(v.start)}.`,
                    'The reward, rule, dates and terms become read-only. You can end the offer early.',
                    'You approve or reject every claim. Approved rewards are paid or delivered outside WeavDash.',
                ],
                action: 'Share incentive',
                onConfirm() {
                    const id = v.id || uid('i');
                    const record = {
                        ...v,
                        id,
                        state: 'shared',
                        sharedOn: TODAY,
                    };
                    const at = db.incentives.findIndex((x) => x.id === id);
                    if (at >= 0) db.incentives[at] = record;
                    else db.incentives.unshift(record);
                    closeModal();
                    location.hash = '#/brand/incentives/' + id;
                    toast('Incentive shared.');
                },
            });
        },
        'end-incentive'(el) {
            const i = incentive(el.dataset.incentive);
            const pending = awardsFor(i.id).filter((a) => a.status === 'pending').length;
            confirm({
                title: 'End this incentive?',
                subject: esc(i.title),
                subjectSub: `Runs until ${fmt(i.end)}`,
                consequences: [
                    'Partners can no longer submit claims.',
                    pending
                        ? `${plural(pending, 'pending claim')} stay open for your review.`
                        : 'No claims are waiting.',
                    'Approved and fulfilled awards are kept.',
                ],
                action: 'End incentive',
                tone: 'danger',
                onConfirm() {
                    i.state = 'ended';
                    i.end = TODAY;
                    closeModal();
                    render();
                    toast('Incentive ended. Existing awards are kept.');
                },
            });
        },
        'review-award'(el) {
            reviewAward(el.dataset.award);
        },
        decide(el) {
            const a = db.awards.find((x) => x.id === el.dataset.award);
            const i = incentive(a.incentive);
            const form = document.getElementById('decision-form');
            const decision = form.querySelector('input[name="decision"]:checked').value;
            const reasonEl = document.getElementById('award-reason');
            const reason = reasonEl.value.trim();
            const amountEl = document.getElementById('award-amount');
            const amount = amountEl ? Math.round(Number(amountEl.value) * 100) : a.amount;
            const needsReason = decision === 'reject' || (i.type === 'cash' && amount !== i.amount);
            form.querySelectorAll('.ci-error').forEach((e) => e.remove());
            if (needsReason && !reason) {
                reasonEl.setAttribute('aria-invalid', 'true');
                reasonEl.insertAdjacentHTML(
                    'afterend',
                    `<span class="ci-error" id="reason-error">${decision === 'reject' ? 'Give a reason. The Partner sees it.' : 'Give a reason for changing the amount.'}</span>`,
                );
                reasonEl.setAttribute('aria-describedby', 'reason-error');
                reasonEl.focus();
                return;
            }
            el.disabled = true;
            Object.assign(
                a,
                decision === 'approve'
                    ? { status: 'approved', amount, decided: TODAY }
                    : { status: 'rejected', decided: TODAY, reason },
            );
            if (decision === 'approve') a.regs.forEach((r) => (registration(r).confirmed = true));
            closeModal();
            render(false);
            toast(
                decision === 'approve'
                    ? `Award approved for ${PARTNERS[a.partner].name}.`
                    : `Award rejected. ${PARTNERS[a.partner].name} sees your reason.`,
            );
        },
        fulfil(el) {
            const a = db.awards.find((x) => x.id === el.dataset.award);
            el.disabled = true;
            Object.assign(a, {
                status: 'fulfilled',
                fulfilled: document.getElementById('fulfil-date').value || TODAY,
                reference: document.getElementById('fulfil-ref').value.trim(),
            });
            closeModal();
            render(false);
            toast('Marked fulfilled. Both workspaces see the delivery date.');
        },
        reverse(el) {
            const a = db.awards.find((x) => x.id === el.dataset.award);
            confirm({
                title: 'Reverse this approval?',
                subject: `${esc(PARTNERS[a.partner].name)} · ${esc(incentive(a.incentive).title)}`,
                subjectSub: `Approved ${fmt(a.decided)}`,
                consequences: [
                    'The award returns to Pending review and no longer counts toward the limit.',
                    `${esc(PARTNERS[a.partner].name)} sees that it is back in review.`,
                ],
                action: 'Reverse approval',
                tone: 'danger',
                onConfirm() {
                    Object.assign(a, { status: 'pending', decided: null });
                    closeModal();
                    render(false);
                    toast('Approval reversed. The award is back in review.');
                },
            });
        },
        claim(el) {
            claimDialog(el.dataset.incentive);
        },
        'submit-claim'(el) {
            const form = document.getElementById('claim-form');
            const regs = [...form.querySelectorAll('input[name="regs"]:checked')].map((b) => b.value);
            const i = incentive(form.dataset.incentive);
            el.disabled = true;
            db.awards.unshift({
                id: uid('a'),
                incentive: i.id,
                partner: 'alon',
                regs,
                amount: i.amount,
                status: 'pending',
                claimed: TODAY,
                note: document.getElementById('claim-note').value.trim(),
            });
            closeModal();
            render(false);
            toast('Claim submitted. Habi Home will review it.');
        },
        'register-lead'(el) {
            registerDialog(el.dataset.campaign);
        },
        'submit-register'(el) {
            const leadId = document.getElementById('reg-lead').value;
            const lead = db.pipeline.find((p) => p.id === leadId);
            const name = document.getElementById('reg-name').value.trim() || lead.name;
            const source = document.getElementById('reg-campaign').value || null;
            el.disabled = true;
            db.registrations.push({
                id: uid('r'),
                partner: 'alon',
                brand: 'Habi Home',
                name,
                stage: lead.stage,
                registered: TODAY,
                campaign: source,
                linked: source ? TODAY : null,
                state: 'active',
            });
            db.pipeline = db.pipeline.filter((p) => p.id !== leadId);
            closeModal();
            render(false);
            toast(`${name} registered with Habi Home${source ? ' for ' + campaign(source).title : ''}.`);
        },
    };

    // ================= routing and render =================
    const SCREENS = {
        brand: [
            ['#/brand/campaigns', 'Campaigns · list'],
            ['#/brand/campaigns/c1', 'Campaign · shared, with leads'],
            ['#/brand/campaigns/c3', 'Campaign · private draft'],
            ['#/brand/incentives', 'Incentives · offers'],
            ['#/brand/incentives/awards', 'Incentives · awards to review'],
            ['#/brand/incentives/i1', 'Incentive · detail'],
            ['#/brand/incentives/new', 'Incentive · new'],
        ],
        partner: [
            ['#/partner/campaigns', 'Campaigns · shared briefs'],
            ['#/partner/campaigns/c1', 'Brief · with incentive'],
            ['#/partner/incentives', 'Incentives · offers and awards'],
        ],
    };
    function route() {
        const [path, query] = location.hash.slice(1).split('?');
        const params = new URLSearchParams(query || '');
        const parts = path.split('/').filter(Boolean);
        const [who, area, id] = parts;
        if (who !== role) return null;
        if (who === 'brand' && area === 'campaigns') return id ? brandCampaignDetail(id) : brandCampaignList();
        if (who === 'brand' && area === 'incentives') {
            if (!id) return brandIncentives('offers');
            if (id === 'awards') return brandIncentives('awards');
            if (id === 'new') return incentiveForm(null, params.get('campaign'));
            return brandIncentiveDetail(id);
        }
        if (who === 'partner' && area === 'campaigns') return id ? partnerBrief(id) : partnerCampaignList();
        if (who === 'partner' && area === 'incentives') return partnerIncentives();
        return null;
    }
    function render(moveFocus = true) {
        const html = route();
        if (html === null) {
            location.replace(SCREENS[role][0][0]);
            return;
        }
        const brand = role === 'brand';
        document.getElementById('portal').classList.toggle('wd-portal-light', brand);
        document
            .querySelectorAll('[data-bind="workspace"]')
            .forEach((el) => (el.textContent = brand ? 'Habi Home' : 'Studio Alon Creative'));
        document.querySelector('[data-bind="initials"]').textContent = brand ? 'HH' : 'SA';
        document.querySelector('[data-bind="experience"]').textContent = brand ? 'Brand' : 'Partner';
        document.querySelector('[data-bind="menu-title"]').textContent = brand ? 'Brand menu' : 'Partner menu';
        document.querySelector('[data-nav="sidebar"]').innerHTML = renderNav('sidebar');
        document.querySelector('[data-nav="drawer"]').innerHTML = renderNav('drawer');
        document.getElementById('main-content').innerHTML = html;
        const title = document.getElementById('page-title');
        document.title = `Mockup – ${title ? title.textContent : 'Campaigns and Incentives'} · WeavDash`;
        const screen = document.getElementById('proto-screen');
        screen.innerHTML = SCREENS[role].map(([h, l]) => `<option value="${h}">${l}</option>`).join('');
        const match = SCREENS[role].find(([h]) => h === location.hash.split('?')[0]);
        if (match) screen.value = match[0];
        else screen.insertAdjacentHTML('afterbegin', '<option value="" selected>(navigated screen)</option>');
        syncDraft();
        syncIncentiveForm();
        if (moveFocus) title?.focus();
    }

    // ================= events =================
    const statusEl = document.getElementById('proto-status');
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[data-demo], button[data-demo]');
        if (link) {
            event.preventDefault();
            const target = link.dataset.demo;
            statusEl.textContent = target.startsWith('/')
                ? `Demo link — in the app this opens ${target}`
                : `Demo — ${target}`;
            return;
        }
        const el = event.target.closest('[data-action]');
        if (el && actions[el.dataset.action]) actions[el.dataset.action](el, event);
    });
    document.addEventListener('submit', (event) => {
        event.preventDefault();
        if (event.target.id === 'draft-form') {
            const c = campaign(event.target.dataset.campaign);
            Object.assign(c, {
                brief: document.getElementById('draft-brief').value,
                due: document.getElementById('draft-due').value,
                partner: document.getElementById('draft-partner').value || null,
            });
            toast('Draft saved. Only your workspace can see it.');
        }
        if (event.target.id === 'incentive-form') {
            const v = readIncentiveForm();
            if (!validateIncentive(v, false)) return;
            const id = v.id || uid('i');
            const record = { ...v, id, state: 'draft' };
            const at = db.incentives.findIndex((x) => x.id === id);
            if (at >= 0) db.incentives[at] = record;
            else db.incentives.unshift(record);
            event.target.dataset.incentive = id;
            toast('Draft saved. Partners cannot see it yet.');
        }
        if (event.target.id === 'new-campaign-form') actions['create-campaign']();
    });
    document.addEventListener('input', (event) => {
        if (event.target.closest('#draft-form')) syncDraft();
        if (event.target.closest('#incentive-form')) syncIncentiveForm();
    });
    document.addEventListener('change', (event) => {
        if (event.target.closest('#draft-form')) syncDraft();
        if (event.target.closest('#incentive-form')) syncIncentiveForm();
        if (event.target.closest('#claim-form')) syncClaim();
        if (event.target.id === 'reg-lead') {
            const lead = db.pipeline.find((p) => p.id === event.target.value);
            document.getElementById('reg-name').value = lead.name;
        }
        if (event.target.name === 'decision') {
            const approve = event.target.value === 'approve';
            modal.querySelectorAll('[data-when="approve"]').forEach((e) => (e.hidden = !approve));
            const button = document.getElementById('decide-button');
            button.textContent = approve ? 'Approve award' : 'Reject award';
            button.className = `wd-button wd-button-${approve ? 'primary' : 'danger'}`;
            document.getElementById('reason-optional').textContent = approve ? '(optional)' : '(required)';
        }
    });
    window.addEventListener('hashchange', () => {
        closeModal();
        render();
    });

    document.getElementById('proto-role').addEventListener('change', (event) => {
        role = event.target.value;
        location.hash = SCREENS[role][0][0];
        statusEl.textContent = `Signed in as ${event.target.selectedOptions[0].text}.`;
    });
    document.getElementById('proto-screen').addEventListener('change', (event) => {
        if (event.target.value) location.hash = event.target.value;
    });
    document.getElementById('proto-state').addEventListener('change', (event) => {
        scenario = event.target.value;
        db = seed(scenario);
        Object.assign(filters, {
            campaigns: 'all',
            offers: 'all',
            awards: 'pending',
            incAwards: 'all',
        });
        render(false);
        statusEl.textContent = `Showing the “${event.target.selectedOptions[0].text}” state. Changes were reset.`;
    });
    document.getElementById('proto-palette').addEventListener('change', (event) => {
        if (event.target.value === 'purple') document.documentElement.dataset.appearance = 'purple';
        else delete document.documentElement.dataset.appearance;
    });
    document.getElementById('proto-annot').addEventListener('change', (event) => {
        document.body.classList.toggle('hide-annot', event.target.value === 'hide');
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
        if (event.target === drawer || event.target.closest('a[href^="#/"]')) drawer.close();
    });

    // Review links: ?state=ended&palette=purple&notes=hide plus a #/route. Never persisted.
    const params = new URLSearchParams(location.search);
    if (['populated', 'empty', 'ended'].includes(params.get('state'))) {
        scenario = params.get('state');
        db = seed(scenario);
        document.getElementById('proto-state').value = scenario;
    }
    if (params.get('palette') === 'purple') {
        document.documentElement.dataset.appearance = 'purple';
        document.getElementById('proto-palette').value = 'purple';
    }
    if (params.get('notes') === 'hide') {
        document.body.classList.add('hide-annot');
        document.getElementById('proto-annot').value = 'hide';
    }
    if (location.hash.startsWith('#/partner')) role = 'partner';
    document.getElementById('proto-role').value = role;
    if (!location.hash.startsWith('#/')) location.replace(SCREENS[role][0][0]);
    render(false);
})();
