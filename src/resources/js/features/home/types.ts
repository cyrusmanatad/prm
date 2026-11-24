import type { PortalExperience } from '../portals/portalNavigation';

/** An amount in minor units of one currency. Amounts in different currencies are never added together. */
export interface Money {
    currency: string;
    amount_minor: string;
}

/** One stored stage with the number of records in it; creator stages also carry per-currency totals. */
export interface HomeStage {
    value: string;
    label: string;
    count: number;
    totals: Money[];
}

export interface CreatorHome {
    clock: string;
    tasks: {
        reminders: { id: string; title: string; due_at: string; lead: string | null }[];
        reminderTotal: number;
        onboarding: { id: string; label: string; lead: string | null; submitted_at: string | null }[];
        onboardingTotal: number;
        /** Leads whose next action is due now or earlier. */
        leadsDue: number;
    };
    metrics: {
        openLeads: number;
        openValue: Money[];
        remindersDue: number;
        onboardingReview: number;
        /** Active Brand partnerships; null on Professional Home. */
        partnerships: number | null;
    };
    stages: HomeStage[];
    recent: {
        id: string;
        name: string;
        stage: string;
        currency: string;
        value_minor: string;
        next_action_due: string | null;
        updated_at: string | null;
    }[];
    activity: { id: string; kind: string; entry_id: string; name: string; body: string; occurred_at: string | null }[];
    profilePublished: boolean;
}

export interface BrandHome {
    clock: string;
    tasks: {
        /** Null for roles that cannot read invitations. */
        pendingInvitations: number | null;
        updatedWeek: number;
        registeredRecent: number;
    };
    metrics: {
        activePartners: number;
        leadsRegistered: number;
        registeredRecent: number;
        partnersWithLeads: number;
    };
    stages: HomeStage[];
    recent: {
        id: string;
        name: string;
        stage: string;
        partner_name: string;
        registered_at: string;
        updated_at: string;
    }[];
    activity: {
        id: string;
        display_name: string;
        stage: string;
        partner_name: string;
        registered_at: string;
        shared_updated_at: string;
        event: 'registered' | 'updated';
    }[];
    canManage: boolean;
}

export type HomeProps =
    { experience: 'brand'; home: BrandHome } | { experience: Exclude<PortalExperience, 'brand'>; home: CreatorHome };
