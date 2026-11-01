import type { PipelineWorkspace } from '../pipeline/types';
export interface Relationship {
    id: string;
    brand_name: string;
    creator_name: string;
    status: string;
    accepted_at: string;
}
export interface JoinLink {
    code: string;
    url: string;
    created_at: string;
}
export interface Invitation {
    id: string;
    status: string;
    expires_at: string;
    email_state: string | null;
}
export interface PortalData {
    account: { name: string; email: string };
    workspace: { id: string; name: string; kind: 'creator' | 'brand' };
    relationships: Relationship[];
    canManage: boolean;
    joinLink?: JoinLink | null;
    invitations?: Invitation[];
    pipeline: PipelineWorkspace | null;
    errors?: Record<string, string>;
    status?: string;
}
