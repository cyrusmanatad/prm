export type CampaignStatus = 'draft' | 'shared' | 'ended';
export type CampaignFilter = 'all' | CampaignStatus;

export type CampaignRow = {
    id: string;
    title: string;
    /** Calendar date (YYYY-MM-DD) or null before one is set. */
    due_date: string | null;
    partnership_id: string | null;
    created_at: string;
    shared_at: string | null;
    brand_name: string | null;
    creator_name: string | null;
    status: CampaignStatus;
    /** Active registrations linked to this brief that the reader may see (D43). */
    leads: number;
    /** The latest incentive on this brief that the reader may see (D44). */
    incentive: { id: string; title: string; state: 'draft' | 'shared' | 'ended' } | null;
};
export type CampaignDetail = CampaignRow & { brief: string; version: number };
export type CampaignListing = { data: CampaignRow[]; current_page: number; per_page: number; total: number };
export type CampaignCounts = Record<CampaignFilter, number> & { leads?: number; awards?: number };
/** A registration linked to a brief (D43), as the reader may see it. */
export type CampaignLead = {
    id: string;
    display_name: string;
    stage: string;
    registered_at: string;
    linked_at: string;
    confirmed: boolean;
};
export type CampaignPartner = { id: string; name: string };
