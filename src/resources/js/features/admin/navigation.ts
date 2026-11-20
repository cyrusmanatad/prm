import type { Component } from 'vue';
import {
    Activity,
    BadgeCheck,
    BellRing,
    Building2,
    ClipboardList,
    Eraser,
    KeyRound,
    Layers,
    LayoutDashboard,
    LifeBuoy,
    ScrollText,
    ShieldCheck,
    Users,
} from '@lucide/vue';

export type AdminLink = {
    href: string;
    label: string;
    icon: Component;
    /** Capability that reveals the link; the server still authorizes every request. */
    capability?: string;
    /** Reachable before authenticator step-up. */
    open?: boolean;
};
export type AdminGroup = { label: string; links: AdminLink[] };
export type AdminContext = { capabilities: string[]; environment: string; verified: boolean };

export const adminGroups: AdminGroup[] = [
    { label: 'Overview', links: [{ href: '/admin', label: 'Overview', icon: LayoutDashboard }] },
    {
        label: 'Directory',
        links: [
            { href: '/admin/accounts', label: 'Accounts', icon: Users, capability: 'directory.read' },
            { href: '/admin/workspaces', label: 'Workspaces', icon: Building2, capability: 'directory.read' },
        ],
    },
    {
        label: 'Entitlements',
        links: [
            { href: '/admin/plans', label: 'Plans', icon: Layers, capability: 'plans.assign' },
            { href: '/admin/assignments', label: 'Assignments', icon: ClipboardList, capability: 'plans.assign' },
        ],
    },
    {
        label: 'Operations',
        links: [
            { href: '/admin/notifications', label: 'Notifications', icon: BellRing, capability: 'notifications.read' },
            { href: '/admin/operations', label: 'Jobs and health', icon: Activity, capability: 'operations.read' },
        ],
    },
    {
        label: 'Governance',
        links: [
            { href: '/admin/brand-requests', label: 'Brand requests', icon: BadgeCheck, capability: 'brands.review' },
            { href: '/admin/audit', label: 'Audit', icon: ScrollText, capability: 'audit.read' },
            { href: '/admin/operators', label: 'Platform access', icon: KeyRound, capability: 'platform.owner' },
            { href: '/admin/support-access', label: 'Support access', icon: LifeBuoy, capability: 'support.request' },
            { href: '/admin/cleanup', label: 'Data cleanup', icon: Eraser, capability: 'cleanup.review' },
            {
                href: '/admin/privacy-requests',
                label: 'Privacy requests',
                icon: ShieldCheck,
                capability: 'platform.owner',
            },
        ],
    },
    {
        label: 'Your access',
        links: [{ href: '/admin/security', label: 'My admin security', icon: ShieldCheck, open: true }],
    },
];

export function allowed(context: AdminContext | undefined, capability?: string): boolean {
    if (!capability) return true;
    const granted = context?.capabilities ?? [];
    return granted.includes('platform.owner') || granted.includes(capability);
}

export function visibleGroups(context: AdminContext | undefined): AdminGroup[] {
    // Until step-up succeeds only the security screen is reachable, so offer nothing else.
    return adminGroups
        .map((group) => ({
            ...group,
            links: group.links.filter((link) => link.open || (context?.verified && allowed(context, link.capability))),
        }))
        .filter((group) => group.links.length);
}

export function activeHref(url: string, links: AdminLink[]): string | undefined {
    const path = url.split(/[?#]/)[0] ?? '';
    return links
        .filter((link) => path === link.href || path.startsWith(`${link.href}/`))
        .sort((a, b) => b.href.length - a.href.length)[0]?.href;
}

export function operatorLabel(context: AdminContext | undefined): string {
    return context?.capabilities.includes('platform.owner') ? 'Platform owner' : 'Scoped operator';
}
