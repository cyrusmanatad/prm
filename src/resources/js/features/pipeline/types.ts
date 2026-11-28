export interface Entry {
    id: string;
    brand_name: string;
    contact_name: string | null;
    contact_email: string | null;
    stage: string;
    value_minor: string;
    currency: string;
    next_action_due: string | null;
    version: number;
}
export interface EntryDetail extends Entry {
    notes: string;
    activities: { id: string; kind: string; body: string; occurred_at: string }[];
    /** Absent from older payloads. */
    updated_at?: string;
    /** The latest onboarding request for this lead. */
    onboarding?: { id: string; status: string; created_at: string } | null;
    /** Brands this lead is currently shared with. */
    sharing?: {
        brand_name: string;
        paused: boolean;
        registered_at: string;
        campaign?: { id: string; title: string } | null;
    }[];
}
export interface LeadForm {
    brand_name: string;
    contact_name: string;
    contact_email: string;
    notes: string;
    amount: string;
    currency: string;
    next_action_due: string;
}

export interface PipelineWorkspace {
    entries: Entry[];
    stages: { value: string; label: string }[];
    counts: { stage: string; count: number }[];
    /** Counts per stage under every filter except the stage filter, for the stage chips. Absent for older callers. */
    stageCounts?: { stage: string; count: number }[];
    totals: { currency: string; amount_minor: string; count: number }[];
    nextCursor: string | null;
    selected: EntryDetail | null;
    canWrite: boolean;
    filters: { q: string; stage: string; due: boolean };
    /** Connected Brands a new lead can optionally be registered with. Absent for older callers. */
    registration?: { canRegister: boolean; brands: { id: string; brand_name: string; paused: boolean }[] };
    /** Incentive rewards (D44); absent for legacy JSON clients. */
    rewards?: { earned: { currency: string; minor: string }[]; nonCash: number; awaiting: number };
}
