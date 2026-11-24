import { computed, inject, ref, type Ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import type { PortalExperience } from '../features/portals/portalNavigation';

/** Presentation-only description of the selected workspace, shared by the server. Routes authorize themselves. */
export interface ActiveWorkspace {
    kind: string;
    experience: string;
    name?: string;
    role?: string;
}

/**
 * The portal experience of the selected workspace, chosen from the authorized active workspace rather than the URL.
 * Falls back to the experience the workspace route shares when the description is unavailable.
 */
export function usePortalContext() {
    const page = usePage<{ activeWorkspace?: ActiveWorkspace | null }>();
    const shared = inject<Ref<string>>('workspaceExperience', ref('partner'));
    const workspace = computed(() => page.props?.activeWorkspace ?? null);
    const experience = computed<PortalExperience | null>(() => {
        const active = workspace.value;
        if (active?.kind === 'brand') return 'brand';
        if (active?.kind === 'creator') return active.experience === 'professional' ? 'professional' : 'partner';
        return shared.value === 'professional' ? 'professional' : null;
    });
    const owner = computed(() => workspace.value?.role === 'owner');
    const workspaceName = computed(() => workspace.value?.name ?? '');
    return { workspace, experience, owner, workspaceName };
}
