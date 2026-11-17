export type SupportGrant = {
    id: string;
    org_id: string;
    workspace: string;
    operator_id: number;
    operator: string;
    purpose: string;
    resources: string[];
    hours: number;
    state: string;
    requested_at: string;
    decided_at: string | null;
    decided_by: string | null;
    expires_at: string | null;
    ended_at: string | null;
    ended_by: string | null;
    end_reason: string | null;
    version: number;
};
/** Record views a grant covers; a "finance" grant covers both finance views. */
export function viewsFor(resources: string[]): string[] {
    return resources.flatMap((area) => (area === 'finance' ? ['invoice_drafts', 'manual_payments'] : [area]));
}
