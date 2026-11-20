import type { BadgeTone } from '../../components/StatusBadge.vue';

export type RequestStatus = 'draft' | 'submitted' | 'correction' | 'accepted' | 'revoked';

/** Plain-language names for stored request statuses; `draft` means the recipient has not submitted yet. */
export const requestStatuses: Record<RequestStatus, { label: string; tone: BadgeTone }> = {
    submitted: { label: 'Awaiting review', tone: 'warning' },
    draft: { label: 'Invited', tone: 'neutral' },
    correction: { label: 'Correction requested', tone: 'neutral' },
    accepted: { label: 'Accepted', tone: 'success' },
    revoked: { label: 'Revoked', tone: 'danger' },
};
export const statusFilters = ['submitted', 'draft', 'correction', 'accepted', 'revoked', 'all'] as const;

const day = 86_400_000;
const dateLabel = (date: Date, now: Date) =>
    date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        ...(date.getFullYear() === now.getFullYear() ? {} : { year: 'numeric' }),
    });

/** "2 h ago", "yesterday", or a short date. */
export function ago(value: string, now = new Date()): string {
    const date = new Date(value);
    const minutes = Math.round((now.getTime() - date.getTime()) / 60_000);
    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes} min ago`;
    if (minutes < 24 * 60) return `${Math.floor(minutes / 60)} h ago`;
    if (minutes < 48 * 60) return 'yesterday';
    return dateLabel(date, now);
}

/** Time left on an open invitation, or null once the request is closed. */
export function expiry(status: RequestStatus, value: string, now = new Date()): string | null {
    if (status === 'accepted' || status === 'revoked') return null;
    const days = Math.ceil((new Date(value).getTime() - now.getTime()) / day);
    if (days <= 0) return 'Expired';
    return days === 1 ? 'Expires tomorrow' : `Expires in ${days} days`;
}

export const shortDate = (value: string, now = new Date()) => dateLabel(new Date(value), now);

export function fileSize(bytes?: number | null): string {
    if (!bytes) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
