import { computed, inject, ref, type Ref } from 'vue';

export function useWorkspaceExperience() {
    const experience = inject<Ref<string>>('workspaceExperience', ref('partner'));
    const professional = computed(() => experience.value === 'professional');
    const path = (destination: string): string => {
        if (!professional.value) return destination;
        return destination
            .replace('/partner/media-kit', '/professional/profile')
            .replace('/partner/', '/professional/');
    };
    const clientLabel = computed(() => (professional.value ? 'Client name (individual or business)' : 'Brand name'));
    return { professional, path, clientLabel };
}
