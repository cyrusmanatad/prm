<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Search, SlidersHorizontal } from '@lucide/vue';
import FilterChips, { type FilterChip } from '../../components/FilterChips.vue';
import SelectField from '../../components/SelectField.vue';
import type { RegistrationFilters, StageOption } from '../portals/types';

/** The filters this toolbar edits. The Partner record has no partner choice: its partner is the record itself. */
export type RegistrationFilterValues = Pick<RegistrationFilters, 'q' | 'stage' | 'window' | 'sort'> & {
    partner?: string;
    campaign?: string;
};

const props = withDefaults(
    defineProps<{
        filters: RegistrationFilterValues;
        stages: StageOption[];
        /** Counts behind each stage choice; each ignores only the stage filter. */
        stageCounts: Record<string, number>;
        stageTotal: number;
        /** Address of the list filtered to a stage, for the stage chips. */
        stageHref: (stage: string) => string;
        /** Partner choices with counts, including "All partners"; omitted where the list belongs to one partner. */
        partners?: { value: string; label: string; count: number }[];
        /** Source campaign choices (D43), including "Any campaign"; omitted when the Brand has shared no brief. */
        campaigns?: { value: string; label: string }[];
        pending?: boolean;
        label: string;
        /** Prefix for control IDs, so labels stay linked when two lists could share a page. */
        idPrefix?: string;
    }>(),
    { pending: false, idPrefix: 'registration', partners: undefined, campaigns: undefined },
);
const emit = defineEmits<{ apply: [change: Partial<RegistrationFilterValues>] }>();

const draft = reactive({ ...props.filters });
watch(
    () => props.filters,
    (value) => Object.assign(draft, value),
    { deep: true },
);
function apply(change: Partial<RegistrationFilterValues>): void {
    Object.assign(draft, change);
    emit('apply', change);
}

const stageChips = computed<FilterChip[]>(() => [
    { value: '', label: 'All', count: props.stageTotal },
    ...props.stages.map((stage) => ({ ...stage, count: props.stageCounts[stage.value] ?? 0 })),
]);
// Phones show the stage as one select and the other filters behind a Filters button.
const stageOptions = computed(() =>
    stageChips.value.map((chip) => ({ ...chip, label: `${chip.value ? chip.label : 'All stages'} (${chip.count})` })),
);
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
const filtered = computed(
    () =>
        !!(
            props.filters.q ||
            props.filters.partner ||
            props.filters.campaign ||
            props.filters.stage ||
            props.filters.window
        ),
);
const filtersOpen = ref(false);
const activeFilters = computed(
    () =>
        [props.filters.partner, props.filters.campaign, props.filters.window, props.filters.sort].filter(Boolean)
            .length,
);

// The search box filters as the user types. It keeps its own text while focused so a response
// to an earlier keystroke never overwrites what has been typed since.
const search = ref(props.filters.q);
const searchFocused = ref(false);
let typing: ReturnType<typeof setTimeout> | undefined;
watch(search, (value) => {
    clearTimeout(typing);
    // Compare with the latest requested search, not the last response: Clear filters empties the box after
    // requesting q='', and a second identical visit would cancel whatever the user opens next.
    if (value.trim() === draft.q) return;
    typing = setTimeout(() => apply({ q: value.trim() }), 350);
});
watch(
    () => props.filters.q,
    (value) => {
        if (!searchFocused.value || !search.value.trim()) search.value = value;
    },
);
onBeforeUnmount(() => clearTimeout(typing));
function submitSearch(): void {
    clearTimeout(typing);
    if (search.value.trim() !== draft.q) apply({ q: search.value.trim() });
}
function clearFilters(): void {
    clearTimeout(typing);
    search.value = '';
    apply({
        q: '',
        stage: '',
        window: '',
        ...(props.partners ? { partner: '' } : {}),
        ...(props.campaigns ? { campaign: '' } : {}),
    });
}
</script>

<template>
    <div class="rf">
        <div class="rf-chips">
            <FilterChips
                label="Filter by stage"
                compact
                :chips="stageChips"
                :current="filters.stage"
                :href-for="stageHref"
            />
        </div>
        <form class="rf-toolbar" role="search" :aria-label="label" @submit.prevent="submitSearch">
            <div class="rf-search">
                <label :for="`${idPrefix}-search`" class="wd-sr-only">Lead name</label>
                <Search :size="16" aria-hidden="true" />
                <input
                    :id="`${idPrefix}-search`"
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
                :id="`${idPrefix}-stage`"
                v-model="draft.stage"
                class="rf-stage-select"
                label="Stage"
                :options="stageOptions"
                :searchable="false"
                @change="(stage) => apply({ stage })"
            />
            <button
                type="button"
                class="wd-table-button rf-filters-toggle"
                :aria-controls="`${idPrefix}-filters`"
                :aria-expanded="filtersOpen"
                @click="filtersOpen = !filtersOpen"
            >
                <SlidersHorizontal :size="15" aria-hidden="true" />Filters<span v-if="activeFilters" class="rf-count">{{
                    activeFilters
                }}</span>
            </button>
            <div :id="`${idPrefix}-filters`" class="rf-filters" :class="{ 'is-open': filtersOpen }">
                <SelectField
                    v-if="partners"
                    :id="`${idPrefix}-partner`"
                    v-model="draft.partner"
                    label="Partner"
                    :options="partners"
                    search-placeholder="Search partners"
                    search-label="Search partners"
                    empty-text="No matching partners."
                    show-mark
                    show-search-icon
                    @change="(partner) => apply({ partner })"
                />
                <SelectField
                    v-if="campaigns"
                    :id="`${idPrefix}-campaign`"
                    v-model="draft.campaign"
                    label="Campaign"
                    :options="campaigns"
                    :searchable="campaigns.length > 8"
                    search-placeholder="Search campaigns"
                    search-label="Search campaigns"
                    empty-text="No matching campaigns."
                    @change="(campaign) => apply({ campaign })"
                />
                <SelectField
                    :id="`${idPrefix}-window`"
                    v-model="draft.window"
                    label="Registered"
                    :options="windowOptions"
                    :searchable="false"
                    @change="(window) => apply({ window })"
                />
                <SelectField
                    :id="`${idPrefix}-sort`"
                    v-model="draft.sort"
                    class="rf-sort"
                    label="Sort by"
                    :options="sortOptions"
                    :searchable="false"
                    @change="(sort) => apply({ sort })"
                />
            </div>
        </form>
        <div v-if="pending || filtered" class="rf-status">
            <p role="status">{{ pending ? 'Updating results…' : 'Filters applied' }}</p>
            <button v-if="filtered" type="button" class="wd-link" :disabled="pending" @click="clearFilters">
                Clear filters
            </button>
        </div>
    </div>
</template>

<style scoped>
.rf {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.rf-count {
    padding: 1px 7px;
    border-radius: 999px;
    background: var(--wd-divider);
    color: var(--wd-muted);
    font-size: 11.5px;
    font-weight: 700;
}
.rf-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 8px;
}
.rf-search {
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
.rf-search:focus-within {
    outline: 3px solid var(--wd-focus);
    outline-offset: 1px;
}
/* The input itself is the touch target: 44px on phones and touch screens, compact with a fine pointer. */
.rf-search input {
    flex: 1;
    min-width: 0;
    min-height: var(--wd-table-control);
    padding: 0;
    border: 0;
    background: transparent;
    outline: none;
}
.rf-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
/* Inline filters keep their labels for assistive technology; the selected value says what each one does. */
.rf-toolbar :deep(.wd-select > label) {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
}
.rf-filters > *,
.rf-stage-select {
    position: relative;
    min-width: 170px;
}
.rf-sort,
.rf-stage-select,
.rf-filters-toggle {
    display: none !important;
}
/* Dropdowns in the toolbar match the search box: compact with a fine pointer, 44px on phones. */
.rf-toolbar :deep(.wd-select-trigger) {
    min-height: var(--wd-table-control);
    padding-block: 0;
    font-size: 13px;
}
.rf-status {
    display: flex;
    align-items: center;
    gap: 12px;
    color: var(--wd-muted);
    font-size: 12.5px;
}
.rf-status p {
    margin: 0;
}
@media (min-width: 600px) and (pointer: fine) {
    .rf {
        --wd-table-control: 32px;
    }
}
@media (max-width: 599px) {
    .rf-chips {
        display: none;
    }
    .rf-search {
        flex-basis: 100%;
    }
    .rf-stage-select,
    .rf-filters-toggle {
        display: flex !important;
        flex: 1 1 0;
    }
    .rf-filters {
        display: none;
        flex-basis: 100%;
        flex-direction: column;
    }
    .rf-filters.is-open {
        display: flex;
    }
    .rf-filters > * {
        min-width: 0;
    }
    .rf-sort {
        display: block !important;
    }
    .rf-filters :deep(.wd-select > label) {
        position: static;
        width: auto;
        height: auto;
        overflow: visible;
        clip: auto;
    }
}
</style>
