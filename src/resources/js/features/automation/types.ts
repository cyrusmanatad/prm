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
};
