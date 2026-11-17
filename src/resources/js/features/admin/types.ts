export type Usage = { resource: string; used: number; limit: number | null };
export type Entitlement = {
    state: string;
    assignment: { id: string; version: number; starts_at: string; ends_at: string; created_at: string } | null;
    plan: { id: string; name: string; version: number } | null;
    usage: Usage[];
};
export const quotaResources = [
    'pipeline_entries',
    'brand_contacts',
    'reminders',
    'onboarding_requests',
    'outreach_templates',
    'invoice_drafts',
    'manual_payments',
] as const;
export type AssignmentRow = {
    id: string;
    org_id: string;
    workspace: string;
    plan_id: string;
    plan: string;
    plan_version: number;
    version: number;
    starts_at: string;
    ends_at: string;
    created_at: string;
    selected: boolean;
    state: string;
};
