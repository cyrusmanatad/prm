export type LeadOption = { id: string; brand_name: string };
export type FollowUpDraft = {
    id: string;
    pipeline_entry_id: string;
    recipient: string;
    subject: string;
    body: string;
    version: number;
    state: 'draft' | 'approved' | 'discarded' | 'cancelled';
    outreach_state?: string | null;
    schedule_run_id: string | null;
};
