type Tone = 'neutral' | 'success' | 'warning' | 'danger';

/** The newest dry run of a fixture batch, as `app_cleanup_batches()` reports it. */
export type LatestRun = {
    id: string;
    created_at: string;
    eligible: number;
    blocked: number;
    protected: number;
    stale: boolean;
};
export type BatchRunState = 'none' | 'stale' | 'blocked' | 'clear';

/** A batch's status in the list: no dry run yet, marks changed since the latest one, or what the latest one found. */
export function batchRunState(run: LatestRun | null | undefined): { key: BatchRunState; label: string; tone: Tone } {
    if (!run) return { key: 'none', label: 'No dry run', tone: 'neutral' };
    if (run.stale) return { key: 'stale', label: 'Out of date', tone: 'warning' };
    if (run.blocked) return { key: 'blocked', label: `${run.blocked} blocked`, tone: 'danger' };
    return { key: 'clear', label: 'Nothing blocked', tone: 'success' };
}
