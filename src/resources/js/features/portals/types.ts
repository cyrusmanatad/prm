import type { PipelineWorkspace } from '../pipeline/types';
export interface Relationship {
    id: string;
    brand_name: string;
    creator_name: string;
    status: string;
    accepted_at: string;
    /** The other party's workspace is suspended. No reason is disclosed. */
    paused?: boolean;
}
export interface JoinLink {
    id: string;
    code: string;
    url: string;
}
export interface PartnerActivity {
    at: string;
    kind: 'signed_in' | 'lead_registered' | 'lead_updated';
}
/** A roster row. Figures are null while the partnership is paused: nothing about it is disclosed then. */
export interface PartnerRow extends Relationship {
    leads_total: number | null;
    leads_30d: number | null;
    last_active: PartnerActivity | null;
}
export interface PageMeta {
    page: number;
    perPage: number;
    total: number;
}
/** Full-result figures. They never depend on the roster page or its search. */
export interface BrandMetrics {
    activePartners: number;
    leadsRegistered: number;
}
export interface SharedProfile {
    contact_name: string | null;
    contact_email: string | null;
    website_url: string | null;
    description: string;
}
export interface PartnerRecord extends PartnerRow {
    profile: SharedProfile | null;
}
export interface StageOption {
    value: string;
    label: string;
}
/** The fields a Brand may read about a registered lead. */
export interface RegistrationRow {
    id: string;
    display_name: string;
    stage: string;
    registered_at: string;
    shared_updated_at: string;
    partnership_id: string;
    partner_name: string;
}
export interface RegistrationDetail extends RegistrationRow {
    brand_name: string;
    summary: string;
    share_contact: boolean;
    contact_name: string | null;
    contact_email: string | null;
}
export type RegistrationFilters = {
    partner: string;
    stage: string;
    q: string;
    window: string;
    /** '', 'registered_asc', 'lead_asc' or 'lead_desc'; empty is newest registration first. */
    sort: string;
    /** Rows per page; empty is the server default. */
    per_page: string;
};
/** Registration counts behind the stage and partner choices; each ignores only its own filter. */
export interface RegistrationFacets {
    stages: Record<string, number>;
    stageTotal: number;
    partners: Record<string, number>;
    partnerTotal: number;
}
export interface BrandWorkspace {
    id: string;
    name: string;
    kind: 'brand';
}
/** A Partner's own registration, in any state. */
export interface OwnRegistration {
    id: string;
    display_name: string;
    summary: string;
    share_contact: boolean;
    stage: string;
    state: 'active' | 'withdrawn';
    withdrawn_reason: 'partner' | 'source_deleted' | 'partnership_ended' | null;
    version: number;
    registered_at: string;
    entry_id: string | null;
    brand_name: string | null;
    paused: boolean;
}
export interface PortalData {
    account: { name: string; email: string };
    workspace: { id: string; name: string; kind: 'creator' | 'brand' };
    /** Partner portal: every relationship. Brand hub: the current roster page only. */
    relationships: Relationship[];
    partners?: PartnerRow[];
    partnerPagination?: PageMeta;
    partnerFilters?: { q: string };
    metrics?: BrandMetrics;
    canManage: boolean;
    joinLink?: JoinLink | null;
    pipeline: PipelineWorkspace | null;
    errors?: Record<string, string>;
    status?: string;
}
/** A Brand's own partner invitation, shown only to its owners and admins. */
export interface PartnerInvitation {
    id: string;
    recipient_email: string;
    status: 'pending' | 'accepted' | 'revoked' | 'expired';
    created_at: string;
    expires_at: string;
    email_state: string | null;
}
