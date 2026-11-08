export const professionalGroups = [
    { label: 'Professional Hub', links: [{ href: '/professional', label: 'Professional Hub' }] },
    {
        label: 'Client work',
        links: [
            { href: '/professional/leads', label: 'Client leads' },
            { href: '/professional/contacts', label: 'Contacts and reminders' },
            { href: '/professional/onboarding', label: 'Client onboarding' },
        ],
    },
    {
        label: 'Outreach',
        links: [
            { href: '/professional/outreach-templates', label: 'Outreach templates' },
            { href: '/professional/automation', label: 'Automation and approvals' },
        ],
    },
    { label: 'Profile / portfolio', links: [{ href: '/professional/profile', label: 'Profile / portfolio' }] },
    {
        label: 'Finance',
        links: [
            { href: '/professional/finance/invoices', label: 'Private invoice drafts' },
            { href: '/professional/finance/payments', label: 'Manual payment records' },
        ],
    },
];
export function professionalDestination(url: string): string {
    return url
        .split('?')[0]!
        .replace(/^\/partner\/media-kit/, '/professional/profile')
        .replace(/^\/partner\//, '/professional/')
        .replace(/^\/finance\//, '/professional/finance/')
        .replace(/^\/automation/, '/professional/automation');
}
export function isProfessionalTool(url: string): boolean {
    const destination = professionalDestination(url);
    return professionalGroups.some((group) =>
        group.links.some(
            (link) =>
                destination === link.href || (link.href !== '/professional' && destination.startsWith(link.href + '/')),
        ),
    );
}
