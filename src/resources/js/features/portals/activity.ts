import type { PartnerActivity, StageOption } from './types';

export const activityKinds: Record<PartnerActivity['kind'], string> = {
    signed_in: 'Signed in',
    lead_registered: 'Lead registered',
    lead_updated: 'Lead updated',
};

/** Short relative time for lists. Pair it with the exact timestamp wherever it is shown. */
export function relativeTime(value: string, now: Date = new Date()): string {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Not yet recorded';
    const seconds = Math.max(0, Math.round((now.getTime() - date.getTime()) / 1000));
    if (seconds < 60) return 'Just now';
    const plural = (count: number, unit: string) => `${count} ${unit}${count === 1 ? '' : 's'} ago`;
    if (seconds < 3600) return plural(Math.floor(seconds / 60), 'minute');
    if (seconds < 86400) return plural(Math.floor(seconds / 3600), 'hour');
    const days = Math.floor(seconds / 86400);
    if (days === 1) return 'Yesterday';
    if (days < 30) return `${days} days ago`;
    return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

export function stageLabel(stages: StageOption[], value: string): string {
    return stages.find((stage) => stage.value === value)?.label ?? value;
}
