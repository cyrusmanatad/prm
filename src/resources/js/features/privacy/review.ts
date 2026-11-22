import type { ClosureRequest } from '../../types/privacy';

/** A closure request in the platform owner's review queue: metadata and counts only, never private content. */
export type ReviewRequest = ClosureRequest & {
    requested_by: string;
    requester_email: string;
    handler: string | null;
    assigned_to: number | null;
    counts: Record<string, number>;
};

export function privacyStatus(request: ReviewRequest): { label: string; tone: 'neutral' | 'success' | 'warning' } {
    if (request.state === 'cancelled') return { label: 'Cancelled', tone: 'neutral' };
    if (request.reviewed_at) return { label: 'Review recorded', tone: 'success' };
    return { label: 'Awaiting review', tone: 'warning' };
}
