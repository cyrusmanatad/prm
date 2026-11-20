export type ExportReceipt = {
    id: string;
    scope: 'account' | 'workspace';
    org_id: string | null;
    state: 'building' | 'ready' | 'failed' | 'expired';
    size: number | null;
    created_at: string;
    expires_at: string;
};
