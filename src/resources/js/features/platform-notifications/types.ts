export type DeliveryNotice = {
    id: string;
    /** Null for an account notice, which belongs to no workspace. */
    org_id: string | null;
    workspace: string | null;
    kind: string;
    state: string;
    created_at: string;
    started_at: string | null;
    completed_at: string | null;
    available_at: string;
    defer_count: number;
    failure_code: string | null;
    resolution: string | null;
    resolved_at: string | null;
    resolution_version: number;
};
/** A list row. `account` is the account's name for an account notice. */
export type DeliveryRow = DeliveryNotice & { account: string | null };
export type DeliveryDetail = DeliveryNotice & {
    account: { id: number; name: string } | null;
    actor: { id: number; name: string } | null;
    resolver: string | null;
};
export const resolutionOptions = [
    { value: 'transport_accepted', label: 'Provider confirms transport acceptance' },
    { value: 'not_sent', label: 'Provider confirms no send' },
    { value: 'unknown_closed', label: 'Close with outcome still unknown' },
];
export const deferralReasons: Record<string, string> = {
    transport_unavailable: 'Mail transport was unavailable',
    workspace_budget: 'Workspace delivery budget blocked this attempt',
    account_budget: 'Account delivery budget blocked this attempt',
    global_budget: 'Application delivery budget blocked this attempt',
    queue_unavailable: 'Queue publication was unavailable',
};
