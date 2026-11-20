export type SnoozePreset = { label: string; detail: string; at: Date };

const time = (date: Date) => date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
const atNine = (date: Date, days: number) => {
    const next = new Date(date);
    next.setDate(next.getDate() + days);
    next.setHours(9, 0, 0, 0);
    return next;
};

/** Quick snooze times in the viewer's local time. Every preset is in the future. */
export function snoozePresets(now = new Date()): SnoozePreset[] {
    const later = new Date(now);
    later.setMinutes(0, 0, 0);
    later.setHours(later.getHours() + 3);
    // Days until the next Monday, a full week ahead when today is Monday.
    const monday = atNine(now, (8 - now.getDay()) % 7 || 7);
    return [
        { label: 'In 3 hours', detail: time(later), at: later },
        { label: 'Tomorrow', detail: time(atNine(now, 1)), at: atNine(now, 1) },
        {
            label: 'Next week',
            detail: monday.toLocaleDateString(undefined, { weekday: 'short' }) + ' ' + time(monday),
            at: monday,
        },
    ];
}

export type ReminderTone = 'overdue' | 'today' | 'upcoming' | 'completed';

export function reminderTone(row: { due_at: string; completed_at: string | null }, now = new Date()): ReminderTone {
    if (row.completed_at) return 'completed';
    const due = new Date(row.due_at);
    if (due.getTime() < now.getTime()) return 'overdue';
    return due.toDateString() === now.toDateString() ? 'today' : 'upcoming';
}

/** Short due label; today's reminders show only the time and other years show the year. */
export function dueLabel(value: string, now = new Date()): string {
    const date = new Date(value);
    if (date.toDateString() === now.toDateString()) return time(date);
    const day = date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        ...(date.getFullYear() === now.getFullYear() ? {} : { year: 'numeric' }),
    });
    return `${day} · ${time(date)}`;
}
