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
};
export type CampaignDetail = CampaignRow & { brief: string; version: number };
export type CampaignListing = { data: CampaignRow[]; current_page: number; per_page: number; total: number };
export type CampaignCounts = Record<CampaignFilter, number>;
export type CampaignPartner = { id: string; name: string };
