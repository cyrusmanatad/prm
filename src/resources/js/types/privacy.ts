export type ExportReceipt = {
    id: string;
    scope: 'account' | 'workspace';
    org_id: string | null;
    state: 'building' | 'ready' | 'failed' | 'expired' | 'revoked';
    size: number | null;
    created_at: string;
    expires_at: string;
};

export type ClosurePreview = {
    scope: 'account' | 'workspace';
    org_id: string | null;
    label: string;
    hash: string;
    counts: Record<string, number>;
    blockers: string[];
};

export type ClosureRequest = {
    id: string;
    scope: 'account' | 'workspace';
    org_id?: string | null;
    subject_label: string;
    state: 'cooling_off' | 'cancelled';
    version: number;
    reason: string;
    requested_at: string;
    not_before: string;
    reviewed_at: string | null;
    cancelled_at?: string | null;
};
