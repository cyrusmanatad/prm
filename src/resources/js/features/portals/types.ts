import type { PipelineWorkspace } from '../pipeline/types';
export interface Relationship { id: string; brand_name: string; creator_name: string; status: string; accepted_at: string }
export interface PortalData {
    account: { name: string; email: string };
    workspace: { id: string; name: string; kind: 'creator' | 'brand' };
    relationships: Relationship[];
    canManage: boolean;
    pipeline: PipelineWorkspace | null;
    errors?: Record<string, string>;
    status?: string;
}
