import type { BadgeTone } from '../../components/StatusBadge.vue';
import type { CampaignStatus } from './types';

/** A calendar date such as a due date, shown the same everywhere regardless of the browser's time zone. */
export function calendarDate(value: string | null | undefined, missing = 'Not set'): string {
    if (!value) return missing;
    const date = new Date(`${value.slice(0, 10)}T00:00:00Z`);
    if (Number.isNaN(date.getTime())) return missing;
    return new Intl.DateTimeFormat('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(date);
}

/** Whole days from the browser's today to a calendar date; negative once it has passed. */
export function daysUntil(value: string, today = new Date()): number {
    const target = Date.UTC(Number(value.slice(0, 4)), Number(value.slice(5, 7)) - 1, Number(value.slice(8, 10)));
    const now = Date.UTC(today.getFullYear(), today.getMonth(), today.getDate());
    return Math.round((target - now) / 86_400_000);
}

export function dueHint(value: string | null): string {
    if (!value) return '';
    const days = daysUntil(value);
    if (days < 0) return 'Past due';
    if (days === 0) return 'Due today';
    return days === 1 ? 'In 1 day' : `In ${days} days`;
}

export const campaignStatuses: Record<CampaignStatus, { label: string; tone: BadgeTone }> = {
    draft: { label: 'Private draft', tone: 'neutral' },
    shared: { label: 'Shared', tone: 'success' },
    ended: { label: 'Relationship ended', tone: 'warning' },
};
