<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Info, Search, SlidersHorizontal } from '@lucide/vue';
import EmptyState from '../components/EmptyState.vue';
import FilterChips, { type FilterChip } from '../components/FilterChips.vue';
import Pagination from '../components/Pagination.vue';
import SelectField from '../components/SelectField.vue';
import { useListFilters } from '../composables/useListFilters';
import { query } from '../features/admin/format';
import RegistrationDrawer from '../features/brand-leads/RegistrationDrawer.vue';
import RegistrationTable from '../features/brand-leads/RegistrationTable.vue';
import BrandChrome from '../features/portals/BrandChrome.vue';
import type {
    BrandWorkspace,
    PageMeta,
    RegistrationFacets,
    RegistrationDetail,
    RegistrationFilters,
    RegistrationRow,
    RegistrationSummary,
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
    summary?: RegistrationSummary;
    selected?: RegistrationDetail | null;
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

// A registration opens in a drawer over the list; the address keeps the list state and the open registration.
const detailHref = (row: RegistrationRow) =>
    `${path}${query({ ...props.filters, page: props.pagination.page, registration: row.id })}`;
function closeDrawer(): void {
    router.get(href(props.pagination.page), {}, { preserveScroll: true, preserveState: true });
}
// Phones show the stage as one select and the other filters behind a Filters button.
const stageOptions = computed(() =>
    stageChips.value.map((chip) => ({ ...chip, label: `${chip.value ? chip.label : 'All stages'} (${chip.count})` })),
);
const filtersOpen = ref(false);
const activeFilters = computed(
    () => [props.filters.partner, props.filters.window, props.filters.sort].filter(Boolean).length,
);
</script>

<template>
    <Head title="Lead registrations" />
    <BrandChrome :workspace-name="workspace.name" :account-name="account.name" :account-email="account.email">
        <main id="main-content" class="blv-page rg mx-auto flex max-w-[1280px] flex-col gap-3.5 px-6 pt-6 pb-12">
            <header>
                <h1>Lead registrations</h1>
                <p class="rg-sub">
                    Leads your partners chose to share with {{ workspace.name }}. Their private notes, estimates and
                    other prospects are never shown.
                </p>
            </header>
            <ul v-if="summary" class="rg-stats" aria-label="Registration summary">
                <li>
                    <span>Registrations</span><strong>{{ summary.total }}</strong>
                </li>
                <li>
                    <span>Last 30 days</span><strong>{{ summary.recent }}</strong>
                </li>
                <li>
                    <span>Partners registering</span><strong>{{ summary.partners }}</strong>
                </li>
                <li>
                    <span>Updated this week</span><strong>{{ summary.updatedWeek }}</strong>
                </li>
            </ul>
            <section class="rg-card" aria-labelledby="registered-leads-heading">
                <header class="rg-card-head">
                    <h2 id="registered-leads-heading">Registered leads</h2>
                    <span class="rg-count">{{ facets.stageTotal }}</span>
                    <p class="rg-note">
                        <Info :size="14" aria-hidden="true" />Counts are registrations, not sales, revenue or estimates.
                    </p>
                </header>
                <div class="rg-chips">
                    <FilterChips
                        label="Filter by stage"
                        compact
                        :chips="stageChips"
                        :current="filters.stage"
                        :href-for="(stage) => href(1, { stage })"
                    />
                </div>
                <form
                    class="rg-toolbar"
                    role="search"
                    aria-label="Filter lead registrations"
                    @submit.prevent="submitSearch"
                >
                    <div class="rg-search">
                        <label for="registration-search" class="wd-sr-only">Lead name</label>
                        <Search :size="16" aria-hidden="true" />
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
                    <SelectField
                        id="registration-stage"
                        v-model="draft.stage"
                        class="rg-stage-select"
                        label="Stage"
                        :options="stageOptions"
                        :searchable="false"
                        @change="(stage) => apply({ stage })"
                    />
                    <button
                        type="button"
                        class="wd-table-button rg-filters-toggle"
                        aria-controls="registration-filters"
                        :aria-expanded="filtersOpen"
                        @click="filtersOpen = !filtersOpen"
                    >
                        <SlidersHorizontal :size="15" aria-hidden="true" />Filters<span
                            v-if="activeFilters"
                            class="rg-count"
                            >{{ activeFilters }}</span
                        >
                    </button>
                    <div id="registration-filters" class="rg-filters" :class="{ 'is-open': filtersOpen }">
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
                            class="rg-sort"
                            label="Sort by"
                            :options="sortOptions"
                            :searchable="false"
                            @change="(sort) => apply({ sort })"
                        />
                    </div>
                </form>
                <div v-if="pending || filtered" class="rg-status">
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
                    class="rg-empty"
                    :title="filtered ? 'No registrations match these filters' : 'No leads registered yet'"
                    :description="
                        filtered
                            ? 'Clear the filters to see every lead your partners have registered with you.'
                            : 'A lead appears here after a partner registers it with you.'
                    "
                />
                <Pagination
                    class="rg-pager"
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
            </section>
        </main>
        <RegistrationDrawer v-if="selected" :registration="selected" :stages="stages" @close="closeDrawer" />
    </BrandChrome>
</template>

<style scoped>
.rg {
    color: var(--wd-ink);
    font-size: 14px;
}
.rg h1 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 28px;
    font-weight: 700;
    letter-spacing: -0.01em;
    line-height: 1.15;
}
.rg-sub {
    margin: 4px 0 0;
    color: var(--wd-muted);
    font-size: 13px;
}
.rg-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.rg-stats li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 10px 14px;
    border: 1px solid var(--wd-line);
    border-radius: 10px;
    background: var(--wd-surface);
}
.rg-stats span {
    color: var(--wd-muted);
    font-size: 12px;
    font-weight: 600;
}
.rg-stats strong {
    font-family: var(--wd-font-display);
    font-size: 20px;
    line-height: 1;
}
.rg-card {
    display: flex;
    flex-direction: column;
    min-width: 0;
    border: 1px solid var(--wd-line);
    border-radius: 12px;
    background: var(--wd-surface);
    overflow: clip;
}
.rg-card-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    padding: 12px 14px 8px;
}
.rg-card-head h2 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 16px;
    font-weight: 600;
}
.rg-count {
    padding: 1px 7px;
    border-radius: 999px;
    background: var(--wd-divider);
    color: var(--wd-muted);
    font-size: 11.5px;
    font-weight: 700;
}
.rg-note {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0 0 0 auto;
    color: var(--wd-muted);
    font-size: 12px;
}
.rg-chips {
    padding: 0 14px 8px;
}
.rg-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 8px;
    padding: 0 14px 10px;
}
.rg-search {
    display: flex;
    flex: 1 1 220px;
    align-items: center;
    gap: 6px;
    padding: 0 10px;
    border: 1px solid var(--wd-control-line);
    border-radius: 8px;
    background: var(--wd-surface);
    color: var(--wd-muted);
}
.rg-search:focus-within {
    outline: 3px solid var(--wd-focus);
    outline-offset: 1px;
}
/* The input itself is the touch target: 44px on phones and touch screens, compact with a fine pointer. */
.rg-search input {
    flex: 1;
    min-width: 0;
    min-height: var(--wd-table-control);
    padding: 0;
    border: 0;
    background: transparent;
    outline: none;
}
.rg-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
/* Inline filters keep their labels for assistive technology; the selected value says what each one does. */
.rg-toolbar :deep(.wd-select > label) {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
}
.rg-filters > *,
.rg-stage-select {
    position: relative;
    min-width: 170px;
}
.rg-sort,
.rg-stage-select,
.rg-filters-toggle {
    display: none !important;
}
/* Dropdowns in the toolbar match the search box: compact with a fine pointer, 44px on phones. */
.rg-toolbar :deep(.wd-select-trigger) {
    min-height: var(--wd-table-control);
    padding-block: 0;
    font-size: 13px;
}
.rg-status {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0 14px 10px;
    color: var(--wd-muted);
    font-size: 12.5px;
}
.rg-status p {
    margin: 0;
}
.rg-empty {
    margin: 0 14px 14px;
}
.rg-pager {
    padding: 8px 14px;
    border-top: 1px solid var(--wd-divider);
}
.rg :deep(.wd-registration-table) {
    border-top: 1px solid var(--wd-divider);
}
@media (min-width: 600px) and (pointer: fine) {
    .rg-toolbar,
    .rg-card :deep(.wd-filter-chips) {
        --wd-table-control: 32px;
    }
}
@media (max-width: 899px) {
    .rg-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 599px) {
    .rg h1 {
        font-size: 24px;
    }
    .rg-note {
        margin-left: 0;
        flex-basis: 100%;
    }
    .rg-stats {
        gap: 6px;
    }
    .rg-stats li {
        padding: 8px 12px;
    }
    .rg-chips {
        display: none;
    }
    .rg-search {
        flex-basis: 100%;
    }
    .rg-stage-select,
    .rg-filters-toggle {
        display: flex !important;
        flex: 1 1 0;
    }
    .rg-filters {
        display: none;
        flex-basis: 100%;
        flex-direction: column;
    }
    .rg-filters.is-open {
        display: flex;
    }
    .rg-filters > * {
        min-width: 0;
    }
    .rg-sort {
        display: block !important;
    }
    .rg-filters :deep(.wd-select > label) {
        position: static;
        width: auto;
        height: auto;
        overflow: visible;
        clip: auto;
    }
}
</style>
