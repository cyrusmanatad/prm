<script setup lang="ts">
import ProfessionalShell from '../../layouts/ProfessionalShell.vue';
import PortalShell from '../../layouts/PortalShell.vue';
import WorkspaceNotice from '../../components/WorkspaceNotice.vue';
import { useWorkspaceExperience } from '../../composables/useWorkspaceExperience';
import { computed, ref, watch } from 'vue';
import { Search } from '@lucide/vue';
import { router } from '@inertiajs/vue3';

const { professional, path } = useWorkspaceExperience();
const props = defineProps<{
    accountName: string;
    accountEmail?: string;
    fixture?: boolean;
    /** The header search filters My leads by `q` from every Partner page; it is not a cross-resource search. */
    searchValue?: string;
}>();
const fixture = computed(() => import.meta.env.DEV && props.fixture === true);
const initials = computed(() =>
    fixture.value
        ? 'BS'
        : props.accountName
              .split(' ')
              .map((part) => part[0])
              .slice(0, 2)
              .join(''),
);
const search = ref(props.searchValue ?? '');
watch(
    () => props.searchValue,
    (value) => {
        search.value = value ?? '';
    },
);
function searchLeads(): void {
    if (!fixture.value) router.get(path('/partner/leads'), { q: search.value });
}
</script>

<template>
    <ProfessionalShell
        v-if="professional"
        :account-name="accountName"
        :account-email="accountEmail"
        :contain-main="false"
        @logout="router.post('/logout')"
        ><slot
    /></ProfessionalShell>
    <PortalShell
        v-else
        experience="partner"
        root-class="portal-ui font-sans leading-[normal]"
        :account-name="accountName"
        :account-email="accountEmail"
        :initials="initials"
        :fixture="fixture"
        :workspace-name="fixture ? 'Bea Santos Studio' : undefined"
        :contain-main="false"
        @logout="router.post('/logout')"
    >
        <template #actions>
            <div role="search" class="wd-portal-search wd-portal-lead-search">
                <label for="portal-lead-search" class="wd-sr-only">Search leads</label>
                <Search :size="16" aria-hidden="true" class="wd-portal-search-icon" />
                <input
                    id="portal-lead-search"
                    v-model="search"
                    type="search"
                    placeholder="Search leads…"
                    class="w-full min-w-0 rounded-lg bg-white py-[9px] pr-3 pl-9 text-[14px] text-ink placeholder:text-muted"
                    @keydown.enter="searchLeads"
                />
            </div>
        </template>
        <template #notice><WorkspaceNotice /></template>
        <slot />
    </PortalShell>
</template>
