const labels: Record<string, string> = {
    pipeline_entries: 'Client leads',
    brand_contacts: 'Client contacts',
    reminders: 'Reminders',
    onboarding_requests: 'Client onboarding requests',
    outreach_templates: 'Outreach templates',
    invoice_drafts: 'Private invoice drafts',
    manual_payments: 'Manual payment records',
};
export function recordResourceLabel(resource: string): string {
    return labels[resource] ?? resource.replaceAll('_', ' ');
}
