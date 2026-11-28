export type RewardType = 'cash' | 'non_cash' | 'percent';
export type IncentiveState = 'draft' | 'shared' | 'ended';
export type AwardState = 'pending' | 'approved' | 'fulfilled' | 'rejected' | 'cancelled';

/** The terms both sides see. Money is in minor units as a string, like finance amounts. */
export type IncentiveTerms = {
    title: string;
    terms: string;
    reward_type: RewardType;
    amount_minor: string | null;
    currency: string | null;
    reward_text: string;
    rule_stage: string;
    leads_per_award: number;
    starts_on: string | null;
    ends_on: string | null;
};

export type Offer = IncentiveTerms & {
    id: string;
    state: IncentiveState;
    version: number;
    campaign_id: string | null;
    campaign_title: string | null;
    cap_per_partner: number | null;
    cap_total: number | null;
    created_at: string;
    shared_at: string | null;
    ended_at: string | null;
    audience: { id: string; name: string | null; granted?: number }[];
    granted: number;
    pending: number;
};

export type BrandIncentive = Offer & { awards: Award[]; campaign_partnership: string | null };

export type Award = {
    id: string;
    incentive_id: string;
    incentive_title: string;
    partnership_id: string;
    partner_name: string | null;
    state: AwardState;
    reward_type: RewardType;
    currency: string | null;
    reward_text: string;
    amount_minor: string | null;
    amount_reason: string | null;
    note: string;
    decision_reason: string | null;
    cancel_reason: 'withdrawn' | 'source_deleted' | 'partnership_ended' | null;
    claimed_at: string;
    decided_at: string | null;
    fulfilled_on: string | null;
    fulfilment_reference: string | null;
    lead_count: number;
    version: number;
};

export type CitedLead = {
    id: string;
    name: string;
    visible: boolean;
    stage: string;
    stage_at_claim: string;
    registered_at: string | null;
    campaign_title: string | null;
    linked_at: string | null;
    confirmed: boolean;
    meets: boolean;
};

export type AwardDetail = Award & {
    offer_amount: string | null;
    rule_stage: string;
    campaign_id: string | null;
    campaign_title: string | null;
    cap_per_partner: number | null;
    cap_total: number | null;
    granted_partner: number;
    granted_total: number;
    leads: CitedLead[];
};

export type Candidate = {
    id: string;
    name: string;
    stage: string;
    campaign_title: string | null;
    eligible: boolean;
    reason: 'used' | 'stage' | 'campaign' | null;
};

export type PartnerOffer = IncentiveTerms & {
    id: string;
    state: IncentiveState;
    brand_name: string;
    partnership_id: string;
    campaign_id: string | null;
    campaign_title: string | null;
    cap_per_partner: number | null;
    granted: number;
    pending: number;
    has_room: boolean;
    open: boolean;
    ready: number;
    candidates: Candidate[];
};

export type PartnerAward = Award & { brand_name: string | null; leads: string[] };

export type MoneyTotal = { currency: string; minor: string };
