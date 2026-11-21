export type LeadOption = { id: string; brand_name: string };
export type FollowUpDraft = {
    id: string;
    pipeline_entry_id: string;
    lead_name?: string | null;
    recipient: string;
    subject: string;
    body: string;
    version: number;
    state: 'draft' | 'approved' | 'discarded' | 'cancelled';
    outreach_state?: string | null;
    schedule_run_id: string | null;
    created_at?: string;
    updated_at?: string;
};
export type DraftAction = 'approve' | 'discard' | 'send';
export type DraftFilter = 'draft' | 'approved' | 'closed' | '';

/** The status line shown in the drawer, as the inbox has always worded it. */
export function draftStatus(draft: FollowUpDraft): string {
    return draft.state === 'approved'
        ? 'Approved for manual sending'
        : draft.state === 'discarded'
          ? 'Discarded'
          : draft.state === 'cancelled'
            ? 'Cancelled after a reply'
            : 'Needs review';
}
export function draftTone(draft: FollowUpDraft): 'warning' | 'success' | 'neutral' {
    return draft.state === 'draft' ? 'warning' : draft.state === 'approved' ? 'success' : 'neutral';
}
export const draftShortStatus = (draft: FollowUpDraft): string =>
    draft.state === 'approved' ? 'Approved' : draft.state === 'cancelled' ? 'Cancelled' : draftStatus(draft);
export const canSend = (draft: FollowUpDraft): boolean => draft.state === 'approved' && !draft.outreach_state;
export const isOpen = (draft: FollowUpDraft): boolean => draft.state === 'draft' || draft.state === 'approved';
export const handoffHref = (draft: FollowUpDraft): string =>
    `/automation/drafts/${draft.id}/handoff?version=${draft.version}`;
