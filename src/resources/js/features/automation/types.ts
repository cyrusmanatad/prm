export type Schedule = {
    id: string;
    name: string;
    kind: string;
    version: number;
    enabled: boolean;
    timezone: string;
    weekday: number | null;
    local_time: string | null;
    next_due_at: string | null;
    /** The open reminder a reminder email is for. */
    subject_label?: string | null;
    /** The lead a follow-up draft schedule is for. */
    subject_lead?: string | null;
};
export type ScheduleKind = 'weekly_digest' | 'reminder' | 'follow_up_draft';
export const scheduleKinds: {
    value: ScheduleKind;
    label: string;
    preference: 'weekly_digest' | 'reminders' | 'follow_up_drafts';
}[] = [
    { value: 'weekly_digest', label: 'Weekly digest', preference: 'weekly_digest' },
    { value: 'reminder', label: 'Reminder email', preference: 'reminders' },
    { value: 'follow_up_draft', label: 'Follow-up draft', preference: 'follow_up_drafts' },
];
export const weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
export function kindLabel(kind: string): string {
    return scheduleKinds.find((item) => item.value === kind)?.label ?? kind.replaceAll('_', ' ');
}
