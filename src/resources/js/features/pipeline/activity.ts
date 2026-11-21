// Presentation of a lead's activity history: conversations people logged, and changes the app recorded.

export type ActivityItem = { id: string; kind: string; body: string; occurred_at: string };
export type ActivityGroup = 'conversation' | 'change';

/** Kinds a person logs from the composer; everything else is recorded automatically. */
export const conversationKinds = ['note', 'call', 'email', 'chat', 'reply'] as const;

export const activityLabels: Record<string, string> = {
    note: 'Note',
    call: 'Call',
    email: 'Email',
    chat: 'Chat',
    reply: 'Reply received',
    created: 'Created',
    updated: 'Updated',
    stage: 'Stage',
    snoozed: 'Next action',
};

export function activityGroup(kind: string): ActivityGroup {
    return (conversationKinds as readonly string[]).includes(kind) ? 'conversation' : 'change';
}

const nextActionFormat: Intl.DateTimeFormatOptions = {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
};

/**
 * Plain-language text for an automatic change. The server stores fixed sentences such as
 * "Stage changed from pitched to negotiating." and "Next action moved to 2026-11-28T01:00:00+00:00.";
 * stage values become their names and times become the viewer's local time. Unknown text is shown as stored.
 */
export function changeText(
    item: ActivityItem,
    stageLabel: (value: string) => string,
): { lead: string; value?: string } {
    if (item.kind === 'stage') {
        const match = /^Stage changed from (\S+) to (\S+)\.$/.exec(item.body);
        if (match) return { lead: 'Stage', value: `${stageLabel(match[1]!)} → ${stageLabel(match[2]!)}` };
    }
    if (item.kind === 'snoozed') {
        const match = /^Next action moved to (.+)\.$/.exec(item.body);
        const date = match ? new Date(match[1]!) : null;
        if (date && !Number.isNaN(date.getTime()))
            return { lead: 'Next action set to', value: date.toLocaleString('en-US', nextActionFormat) };
    }
    if (item.kind === 'created') return { lead: 'Lead captured' };
    if (item.kind === 'updated') return { lead: 'Details updated' };
    return { lead: item.body || (activityLabels[item.kind] ?? item.kind) };
}

const startOfDay = (date: Date): number => new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();

/** "Today", "Yesterday", or a short date (with the year when it is not this year). */
export function dayLabel(value: string, now = new Date()): string {
    const date = new Date(value);
    const days = Math.round((startOfDay(now) - startOfDay(date)) / 86_400_000);
    if (days === 0) return 'Today';
    if (days === 1) return 'Yesterday';
    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        ...(date.getFullYear() === now.getFullYear() ? {} : { year: 'numeric' }),
    });
}

export const timeLabel = (value: string): string =>
    new Date(value).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });

/** Consecutive items grouped by local day, keeping the server's newest-first order. */
export function byDay<T extends ActivityItem>(items: T[], now = new Date()): { day: string; items: T[] }[] {
    const groups: { day: string; items: T[] }[] = [];
    for (const item of items) {
        const day = dayLabel(item.occurred_at, now);
        const last = groups.at(-1);
        if (last?.day === day) last.items.push(item);
        else groups.push({ day, items: [item] });
    }
    return groups;
}
