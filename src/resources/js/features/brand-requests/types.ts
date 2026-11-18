import type { BadgeTone } from '../../components/StatusBadge.vue';

export type BrandFields = { brand_name: string; website: string; applicant_role: string; intended_use: string };
export type BrandStatus = 'pending' | 'needs_information' | 'approved' | 'declined';
export type BrandEvent =
    'submitted' | 'resubmitted' | 'information_requested' | 'approved' | 'declined' | 'rollout_approved';
export type BrandHistoryEntry = {
    version: number;
    event: BrandEvent;
    occurred_at: string;
    /** Reviewer feedback, as shown to the applicant. */
    feedback?: string | null;
    /** Administration only. */
    reason?: string | null;
    actor?: string | null;
    operator?: string | null;
    submission: BrandFields | null;
};
/** An applicant's own request. Reviewer identities are never part of it. */
export type BrandRequest = Partial<BrandFields> & {
    id: string;
    status: BrandStatus;
    version: number;
    submitted_at: string;
    updated_at: string;
    decided_at: string | null;
    workspace: { id: string; name: string; member: boolean } | null;
    history?: BrandHistoryEntry[];
};

export const fieldLabels: Record<keyof BrandFields, string> = {
    brand_name: 'Brand name',
    website: 'Website or public business profile',
    applicant_role: 'Role at the business',
    intended_use: 'Intended use',
};

/** Applicant wording. `meaning` says what happens next. */
export const brandStatuses: Record<BrandStatus, { label: string; tone: BadgeTone; meaning: string }> = {
    pending: {
        label: 'In review',
        tone: 'neutral',
        meaning:
            'WeavDash is reviewing this request. You cannot change it while it is in review. We will email you when there is a decision.',
    },
    needs_information: {
        label: 'More information needed',
        tone: 'warning',
        meaning: 'The reviewer asked for more information. Update the request below and resubmit it for review.',
    },
    approved: {
        label: 'Approved',
        tone: 'success',
        meaning: 'This request was approved. The Brand workspace exists and you are its owner.',
    },
    declined: {
        label: 'Not approved',
        tone: 'danger',
        meaning: 'This request was not approved and is closed. You can submit a new request.',
    },
};

/** Reviewer wording for the same states. */
export const reviewStatuses: Record<BrandStatus, { label: string; tone: BadgeTone }> = {
    pending: { label: 'Awaiting decision', tone: 'warning' },
    needs_information: { label: 'Waiting for applicant', tone: 'neutral' },
    approved: { label: 'Approved', tone: 'success' },
    declined: { label: 'Declined', tone: 'neutral' },
};

export const brandSources: Record<string, string> = { request: 'Request', rollout: 'Rollout approval' };

export const brandEvents: Record<BrandEvent, string> = {
    submitted: 'Request submitted',
    resubmitted: 'Request resubmitted',
    information_requested: 'More information requested',
    approved: 'Request approved',
    declined: 'Request declined',
    rollout_approved: 'Approved in the rollout',
};

export const emptyFields = (): BrandFields => ({ brand_name: '', website: '', applicant_role: '', intended_use: '' });
