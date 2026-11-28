import type { Component } from 'vue';
import {
    Banknote,
    BriefcaseBusiness,
    ClipboardCheck,
    ClipboardList,
    ContactRound,
    FileText,
    Gift,
    GraduationCap,
    House,
    Inbox,
    KanbanSquare,
    LayoutDashboard,
    Megaphone,
    Receipt,
    Share2,
    ShieldCheck,
    Sparkles,
    UserRound,
    Users,
    Wallet,
    Zap,
} from '@lucide/vue';
import { professionalDestination } from './professionalNavigation';

export type PortalExperience = 'brand' | 'partner' | 'professional';

/** A working destination. `match` lists other path prefixes (aliases, legacy and detail URLs) that belong to it. */
export interface PortalLink {
    label: string;
    icon: Component;
    href: string;
    match?: string[];
    /** Exact-path match only, for landing pages whose children belong to other items. */
    exact?: boolean;
    /** Other exact paths that open this destination, such as a redirecting portal entry. */
    aliases?: string[];
    /** Shown only to workspace owners; the server still authorizes every request. */
    ownerOnly?: boolean;
}

/** An existing navigation entry for a capability that is not available. It is never a link. */
export interface PortalUnavailable {
    label: string;
    icon: Component;
    unavailable: true;
}

export type PortalItem = PortalLink | PortalUnavailable;

export interface PortalGroup {
    /** Omitted for the first group, which holds Home. */
    label?: string;
    items: PortalItem[];
}

export const UNAVAILABLE_TEXT = 'Not available yet';

export const portalHome: Record<PortalExperience, string> = {
    brand: '/brand/home',
    partner: '/partner/home',
    professional: '/professional/home',
};

export const experienceLabel: Record<PortalExperience, string> = {
    brand: 'Brand',
    partner: 'Partner',
    professional: 'Professional',
};

const unavailable = (label: string, icon: Component): PortalUnavailable => ({ label, icon, unavailable: true });

export const portalNavigation: Record<PortalExperience, PortalGroup[]> = {
    brand: [
        { items: [{ label: 'Home', icon: House, href: '/brand/home', exact: true }] },
        {
            label: 'Partners',
            items: [
                { label: 'Partner hub', icon: Users, href: '/brand', exact: true, match: ['/brand/partners'] },
                { label: 'Lead registrations', icon: ClipboardCheck, href: '/brand/lead-registrations' },
            ],
        },
        {
            label: 'Programs',
            items: [
                { label: 'Campaigns', icon: Megaphone, href: '/brand/campaigns' },
                unavailable('Incentives', Gift),
                unavailable('Enablement', GraduationCap),
                unavailable('Payouts', Wallet),
            ],
        },
    ],
    partner: [
        { items: [{ label: 'Home', icon: House, href: '/partner/home', exact: true }] },
        {
            label: 'Work',
            items: [
                {
                    label: 'My leads',
                    icon: KanbanSquare,
                    href: '/partner/leads',
                    aliases: ['/partner'],
                    match: ['/pipeline'],
                },
                { label: 'Contacts and reminders', icon: ContactRound, href: '/partner/contacts', match: ['/crm'] },
                {
                    label: 'Onboarding requests',
                    icon: ClipboardList,
                    href: '/partner/onboarding',
                    match: ['/onboarding-requests'],
                },
                { label: 'Brand sharing', icon: Share2, href: '/partner/brand-sharing' },
                unavailable('Inbox', Inbox),
            ],
        },
        {
            // Mirrors the Brand Programs group (campaigns and incentives mockup, approved 2026-11-28).
            label: 'Programs',
            items: [
                { label: 'Campaigns', icon: Megaphone, href: '/partner/campaigns' },
                unavailable('Incentives', Gift),
            ],
        },
        {
            label: 'Outreach',
            items: [
                {
                    label: 'Outreach templates',
                    icon: FileText,
                    href: '/partner/outreach-templates',
                    match: ['/outreach-templates'],
                },
                { label: 'Automation and approvals', icon: Zap, href: '/automation' },
                unavailable('Autopilot', Sparkles),
            ],
        },
        {
            label: 'Profile',
            items: [{ label: 'Media kit', icon: UserRound, href: '/partner/media-kit', match: ['/public-presence'] }],
        },
        {
            label: 'Finance',
            items: [
                unavailable('Earnings', Banknote),
                { label: 'Private invoice drafts', icon: Receipt, href: '/finance/invoices', ownerOnly: true },
                { label: 'Manual payment records', icon: Wallet, href: '/finance/payments', ownerOnly: true },
                { label: 'Pilot entitlements', icon: ShieldCheck, href: '/workspace/entitlements', ownerOnly: true },
            ],
        },
    ],
    professional: [
        { items: [{ label: 'Home', icon: House, href: '/professional/home', exact: true }] },
        {
            items: [{ label: 'Professional Hub', icon: LayoutDashboard, href: '/professional', exact: true }],
        },
        {
            label: 'Client work',
            items: [
                { label: 'Client leads', icon: KanbanSquare, href: '/professional/leads' },
                { label: 'Contacts and reminders', icon: ContactRound, href: '/professional/contacts' },
                { label: 'Client onboarding', icon: ClipboardList, href: '/professional/onboarding' },
                { label: 'Brand sharing', icon: Share2, href: '/professional/brand-sharing' },
            ],
        },
        {
            label: 'Outreach',
            items: [
                { label: 'Outreach templates', icon: FileText, href: '/professional/outreach-templates' },
                { label: 'Automation and approvals', icon: Zap, href: '/professional/automation' },
            ],
        },
        {
            label: 'Profile',
            items: [{ label: 'Profile / portfolio', icon: BriefcaseBusiness, href: '/professional/profile' }],
        },
        {
            label: 'Finance',
            items: [
                { label: 'Private invoice drafts', icon: Receipt, href: '/professional/finance/invoices' },
                { label: 'Manual payment records', icon: Wallet, href: '/professional/finance/payments' },
            ],
        },
    ],
};

export function isUnavailable(item: PortalItem): item is PortalUnavailable {
    return 'unavailable' in item;
}

/** The groups and items a member of this experience is offered; owner-only tools are hidden from other roles. */
export function portalGroups(experience: PortalExperience, owner: boolean): PortalGroup[] {
    return portalNavigation[experience]
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => isUnavailable(item) || !item.ownerOnly || owner),
        }))
        .filter((group) => group.items.length > 0);
}

function path(url: string): string {
    const bare = url.split(/[?#]/)[0] || '/';
    return bare.length > 1 ? bare.replace(/\/+$/, '') : bare;
}

function covers(prefix: string, url: string, exact: boolean): boolean {
    return url === prefix || (!exact && url.startsWith(prefix + '/'));
}

/**
 * The single current navigation href for a URL: list, detail, alias and query/fragment variants resolve to their
 * tool, and the longest matching prefix wins so Home and the existing hub are never both current.
 */
export function activePortalHref(experience: PortalExperience, url: string): string | null {
    const current = experience === 'professional' ? professionalDestination(path(url)) : path(url);
    let best: { href: string; length: number } | null = null;
    for (const group of portalNavigation[experience]) {
        for (const item of group.items) {
            if (isUnavailable(item)) continue;
            const candidates = [
                { prefix: item.href, exact: item.exact === true },
                ...(item.aliases ?? []).map((prefix) => ({ prefix, exact: true })),
                ...(item.match ?? []).map((prefix) => ({ prefix, exact: false })),
            ];
            for (const candidate of candidates) {
                if (covers(candidate.prefix, current, candidate.exact)) {
                    const length = candidate.prefix.length;
                    if (!best || length > best.length) best = { href: item.href, length };
                }
            }
        }
    }
    return best?.href ?? null;
}

/**
 * Whether the portal shell frames this URL for the selected workspace: Home, every working tool and their details.
 * Shared account, workspace selection, public and administration pages keep their own shells.
 */
export function isPortalPage(experience: PortalExperience, url: string): boolean {
    return activePortalHref(experience, url) !== null;
}
