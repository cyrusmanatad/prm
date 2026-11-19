<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Info, Search } from '@lucide/vue';
import ContentSection from '../components/ContentSection.vue';
import EmptyState from '../components/EmptyState.vue';
import FilterChips, { type FilterChip } from '../components/FilterChips.vue';
import Pagination from '../components/Pagination.vue';
import SelectField from '../components/SelectField.vue';
import { useListFilters } from '../composables/useListFilters';
import { query } from '../features/admin/format';
import RegistrationTable from '../features/brand-leads/RegistrationTable.vue';
import BrandChrome from '../features/portals/BrandChrome.vue';
import type {
    BrandWorkspace,
    PageMeta,
    RegistrationFacets,
    RegistrationFilters,
    RegistrationRow,
    StageOption,
} from '../features/portals/types';

const props = defineProps<{
    account: { name: string; email: string };
    workspace: BrandWorkspace;
    registrations: RegistrationRow[];
    pagination: PageMeta;
    filters: RegistrationFilters;
    partners: { id: string; creator_name: string }[];
    stages: StageOption[];
    facets: RegistrationFacets;
}>();
const path = '/brand/lead-registrations';
const { draft, pending, apply, href } = useListFilters(path, () => props.filters);

const stageChips = computed<FilterChip[]>(() => [
    { value: '', label: 'All', count: props.facets.stageTotal },
    ...props.stages.map((stage) => ({ ...stage, count: props.facets.stages[stage.value] ?? 0 })),
]);
const partnerOptions = computed(() => [
    { value: '', label: 'All partners', count: props.facets.partnerTotal },
    ...props.partners.map((row) => ({
        value: row.id,
        label: row.creator_name,
        count: props.facets.partners[row.id] ?? 0,
    })),
]);
const windowOptions = [
    { value: '', label: 'Any time' },
    { value: '30d', label: 'Last 30 days' },
];
// Phones show rows as cards without column headers, so the sort order gets its own control there.
const sortOptions = [
    { value: '', label: 'Newest first' },
    { value: 'registered_asc', label: 'Oldest first' },
    { value: 'lead_asc', label: 'Lead name A–Z' },
    { value: 'lead_desc', label: 'Lead name Z–A' },
];
const pageSizes = ['10', '25', '50'];
const perPage = computed(() => props.filters.per_page || String(props.pagination.perPage));
const filtered = computed(
    () => !!(props.filters.q || props.filters.partner || props.filters.stage || props.filters.window),
);

// The search box filters as the user types. It keeps its own text while focused so a response
// to an earlier keystroke never overwrites what has been typed since.
const search = ref(props.filters.q);
const searchFocused = ref(false);
let typing: ReturnType<typeof setTimeout> | undefined;
watch(search, (value) => {
    clearTimeout(typing);
    if (value.trim() === props.filters.q) return;
    typing = setTimeout(() => apply({ q: value.trim() }), 350);
});
watch(
    () => props.filters.q,
    (value) => {
        if (!searchFocused.value || !search.value.trim()) search.value = value;
    },
);
onBeforeUnmount(() => clearTimeout(typing));
function submitSearch() {
    clearTimeout(typing);
    if (search.value.trim() !== props.filters.q) apply({ q: search.value.trim() });
}
function clearFilters() {
    clearTimeout(typing);
    search.value = '';
    apply({ q: '', partner: '', stage: '', window: '' });
}

const detailHref = (row: RegistrationRow) =>
    `${path}/${row.id}${query({ ...props.filters, page: props.pagination.page })}`;
</script>

<template>
    <Head title="Lead registrations" />
    <BrandChrome
        current="registrations"
        :workspace-name="workspace.name"
        :account-name="account.name"
        :account-email="account.email"
    >
        <main id="main-content" class="blv-page mx-auto flex max-w-[1408px] flex-col gap-6 px-6 pt-7 pb-12">
            <div>
                <h1 class="font-display text-[32px] font-bold">Lead registrations</h1>
                <p class="mt-1.5 max-w-[760px] text-[15px] text-ink-soft">
                    Leads your partners have chosen to register with {{ workspace.name }}, with each lead's current
                    stage. Partners' other prospects, private notes and estimates are never shown here.
                </p>
            </div>
            <ContentSection title="Registered leads" heading-id="registered-leads-heading" class="wd-registrations">
                <template #header>
                    <header class="wd-registrations-header">
                        <div class="wd-registrations-title">
                            <h2 id="registered-leads-heading">Registered leads</h2>
                            <span class="wd-registrations-total">
                                {{ facets.stageTotal }} {{ facets.stageTotal === 1 ? 'registration' : 'registrations' }}
                            </span>
                        </div>
                        <p class="wd-registrations-note">
                            <Info :size="18" aria-hidden="true" />Counts are registrations, not sales, revenue or
                            estimates.
                        </p>
                    </header>
                </template>
                <FilterChips
                    label="Filter by stage"
                    :chips="stageChips"
                    :current="filters.stage"
                    :href-for="(stage) => href(1, { stage })"
                />
                <form
                    class="wd-registrations-filters"
                    role="search"
                    aria-label="Filter lead registrations"
                    @submit.prevent="submitSearch"
                >
                    <div class="wd-field">
                        <label for="registration-search">Lead name</label>
                        <div class="wd-search-input">
                            <Search :size="18" aria-hidden="true" />
                            <input
                                id="registration-search"
                                v-model="search"
                                name="q"
                                type="search"
                                maxlength="160"
                                placeholder="Search leads"
                                autocomplete="off"
                                @focus="searchFocused = true"
                                @blur="searchFocused = false"
                            />
                        </div>
                    </div>
                    <SelectField
                        id="registration-partner"
                        v-model="draft.partner"
                        label="Partner"
                        :options="partnerOptions"
                        search-placeholder="Search partners"
                        search-label="Search partners"
                        empty-text="No matching partners."
                        show-mark
                        show-search-icon
                        @change="(partner) => apply({ partner })"
                    />
                    <SelectField
                        id="registration-window"
                        v-model="draft.window"
                        label="Registered"
                        :options="windowOptions"
                        :searchable="false"
                        @change="(window) => apply({ window })"
                    />
                    <SelectField
                        id="registration-sort"
                        v-model="draft.sort"
                        class="wd-registrations-sort"
                        label="Sort by"
                        :options="sortOptions"
                        :searchable="false"
                        @change="(sort) => apply({ sort })"
                    />
                </form>
                <div v-if="pending || filtered" class="wd-registrations-status">
                    <p role="status">{{ pending ? 'Updating results…' : 'Filters applied' }}</p>
                    <button v-if="filtered" type="button" class="wd-link" :disabled="pending" @click="clearFilters">
                        Clear filters
                    </button>
                </div>
                <RegistrationTable
                    v-if="registrations.length"
                    :class="{ 'is-pending': pending }"
                    v-bind="{ 'aria-busy': pending ? 'true' : undefined }"
                    caption="Lead registrations"
                    :rows="registrations"
                    :stages="stages"
                    :detail-href="detailHref"
                    :sort="filters.sort"
                    :sort-href="(sort) => href(1, { sort })"
                />
                <EmptyState
                    v-else
                    :title="filtered ? 'No registrations match these filters' : 'No leads registered yet'"
                    :description="
                        filtered
                            ? 'Clear the filters to see every lead your partners have registered with you.'
                            : 'A lead appears here after a partner registers it with you.'
                    "
                />
                <Pagination
                    class="mt-6"
                    label="Lead registrations pages"
                    noun="registrations"
                    numbered
                    :page="pagination.page"
                    :per-page="pagination.perPage"
                    :count="registrations.length"
                    :total="pagination.total"
                    :href-for="href"
                >
                    <div class="wd-page-size">
                        <label for="registration-per-page">Rows per page</label>
                        <select
                            id="registration-per-page"
                            :value="perPage"
                            @change="apply({ per_page: ($event.target as HTMLSelectElement).value })"
                        >
                            <option v-for="size in pageSizes" :key="size" :value="size">{{ size }}</option>
                        </select>
                    </div>
                </Pagination>
            </ContentSection>
        </main>
    </BrandChrome>
</template>
