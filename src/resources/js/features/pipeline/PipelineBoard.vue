<script setup lang="ts">
import { useWorkspaceExperience } from '../../composables/useWorkspaceExperience';
const { professional, path, clientLabel } = useWorkspaceExperience();
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Search } from '@lucide/vue';
import FilterChips, { type FilterChip } from '../../components/FilterChips.vue';
import PipelineColumn from './PipelineColumn.vue';
import { pipelineStages } from './stages';
import { money } from './money';
import type { Entry, PipelineWorkspace } from './types';

const props = defineProps<{
    entries: Entry[];
    counts: PipelineWorkspace['counts'];
    /** Per-stage counts ignoring only the stage filter; older callers fall back to `counts`. */
    stageCounts?: PipelineWorkspace['counts'];
    totals: PipelineWorkspace['totals'];
    filters: PipelineWorkspace['filters'];
    nextCursor: string | null;
    loading?: boolean;
    error?: string;
    movable?: boolean;
    moving?: string | null;
}>();
const emit = defineEmits<{
    open: [id: string];
    load: [params: Record<string, string>];
    reset: [];
    move: [lead: Entry, stage: string];
}>();
const dragging = ref<Entry | null>(null);
const canDrag = computed(() => !!props.movable && !props.loading && !props.moving);
watch(canDrag, () => {
    if (!canDrag.value) dragging.value = null;
});
function move(lead: Entry, stage: string): void {
    if (!canDrag.value || dragging.value?.id !== lead.id || lead.stage === stage) return;
    dragging.value = null;
    emit('move', lead, stage);
}
function cancelDrag(event: KeyboardEvent): void {
    if (event.key === 'Escape') dragging.value = null;
}

const query = ref(props.filters.q);
const stageFilter = ref(props.filters.stage);
const dueOnly = ref(props.filters.due);
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let submittedQuery = query.value;
watch(
    () => props.filters,
    (filters) => {
        // A response for the previous search must not erase text typed while it ran.
        if (!searchTimer && query.value === submittedQuery) {
            query.value = filters.q;
            submittedQuery = filters.q;
        }
        stageFilter.value = filters.stage;
        dueOnly.value = filters.due;
    },
    { deep: true },
);
function cancelSearchOnHistory(): void {
    clearTimeout(searchTimer);
    searchTimer = undefined;
    // Let the next history props replace even an unsubmitted search draft.
    submittedQuery = query.value;
}
onMounted(() => {
    window.addEventListener('popstate', cancelSearchOnHistory);
    window.addEventListener('keydown', cancelDrag);
});
onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    window.removeEventListener('popstate', cancelSearchOnHistory);
    window.removeEventListener('keydown', cancelDrag);
});
function params(): Record<string, string> {
    return {
        ...(query.value.trim() ? { q: query.value.trim() } : {}),
        ...(stageFilter.value ? { stage: stageFilter.value } : {}),
        ...(dueOnly.value ? { due: '1' } : {}),
    };
}
function apply(): void {
    clearTimeout(searchTimer);
    searchTimer = undefined;
    submittedQuery = query.value;
    emit('load', params());
}
function search(): void {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(apply, 300);
}
function chooseSegment(stage: string): void {
    stageFilter.value = stage;
    apply();
}
function clearFilters(): void {
    clearTimeout(searchTimer);
    searchTimer = undefined;
    submittedQuery = '';
    query.value = '';
    stageFilter.value = '';
    dueOnly.value = false;
    emit('reset');
}
function nextPage(): void {
    clearTimeout(searchTimer);
    searchTimer = undefined;
    if (
        query.value.trim() !== props.filters.q ||
        stageFilter.value !== props.filters.stage ||
        dueOnly.value !== props.filters.due
    ) {
        apply();
        return;
    }
    if (props.nextCursor) emit('load', { ...params(), cursor: props.nextCursor });
}
/** One stage filter: chips count each stage under the search and due filters. */
const stageChips = computed<FilterChip[]>(() => {
    const source = props.stageCounts ?? props.counts;
    const count = (stage: string) => Number(source.find((item) => item.stage === stage)?.count ?? 0);
    return [
        { value: '', label: 'All', count: source.reduce((sum, item) => sum + Number(item.count), 0) },
        ...pipelineStages.map((stage) => ({ value: stage.value, label: stage.label, count: count(stage.value) })),
    ];
});
const busy = computed(() => !!props.loading || !!props.moving);
const matchingCount = computed(() => props.counts.reduce((sum, item) => sum + Number(item.count), 0));
const filtered = computed(() => !!(props.filters.q || props.filters.stage || props.filters.due));
const columns = computed(() =>
    pipelineStages
        .map((stage) => ({
            stage,
            count: Number(props.counts.find((item) => item.stage === stage.value)?.count ?? 0),
            entries: props.entries.filter((entry) => entry.stage === stage.value),
        }))
        .filter((column) => !props.filters.stage || column.stage.value === props.filters.stage),
);
const mainColumns = computed(() =>
    columns.value.filter((column) => !['repeat-client', 'lost'].includes(column.stage.value)),
);
const extraColumns = computed(() =>
    columns.value.filter(
        (column) =>
            ['repeat-client', 'lost'].includes(column.stage.value) &&
            (column.count > 0 || props.filters.stage === column.stage.value),
    ),
);
const headerAmounts = computed(() => props.totals.map((row) => money(row.amount_minor, row.currency)));
</script>
<template>
    <div class="pipeline-root">
        <section class="pipeline-card" aria-labelledby="my-pipeline-title" :aria-busy="loading">
            <div class="pipeline-header">
                <div class="pipeline-intro">
                    <div class="pipeline-title-row">
                        <h2 id="my-pipeline-title">My pipeline</h2>
                        <span v-if="!professional" class="automation-pill">Automation unavailable</span>
                    </div>
                    <p id="pipeline-drag-hint" class="pipeline-subtitle">
                        {{
                            movable
                                ? 'Drag a lead to another column to change its stage, or open it to choose a stage, edit or start onboarding.'
                                : 'Open a lead to edit it, move its stage or start onboarding.'
                        }}
                    </p>
                </div>
                <div class="pipeline-value">
                    <span class="pipeline-kicker">Matching pipeline value</span>
                    <span v-for="amount in headerAmounts" :key="amount" class="pipeline-total">{{ amount }}</span>
                    <span v-if="!headerAmounts.length" class="pipeline-count">No non-lost value</span>
                    <span class="pipeline-count"
                        >{{ matchingCount }} matching {{ matchingCount === 1 ? 'lead' : 'leads' }} · all pages ·
                        excludes Lost</span
                    >
                </div>
            </div>
            <FilterChips
                label="Filter by stage"
                :chips="stageChips"
                :current="stageFilter"
                :class="{ 'is-busy': busy }"
                @select="(stage) => !busy && chooseSegment(stage)"
            />
            <form class="pipeline-toolbar" aria-label="Filter pipeline" @submit.prevent="apply">
                <div class="pipeline-search">
                    <label for="pipeline-search" class="wd-sr-only">{{
                        professional ? 'Search clients' : 'Search brands'
                    }}</label>
                    <Search :size="15" aria-hidden="true" />
                    <input
                        id="pipeline-search"
                        v-model="query"
                        class="wd-table-input"
                        :disabled="busy"
                        type="search"
                        maxlength="160"
                        :placeholder="professional ? 'Search clients' : 'Search brands'"
                        @input="search"
                        @keydown.enter.prevent="apply"
                    />
                </div>
                <label class="pipeline-due"
                    ><input v-model="dueOnly" :disabled="busy" type="checkbox" @change="apply" />Due next actions</label
                >
                <button v-if="filtered" type="button" class="wd-link" :disabled="busy" @click="clearFilters">
                    Clear filters
                </button>
            </form>
            <p v-if="moving" role="status" class="wd-hint">Moving lead… Counts and totals update after saving.</p>
            <p v-if="loading" role="status" class="wd-hint">Loading leads…</p>
            <p v-if="error" role="alert" class="wd-field-error">{{ error }}</p>
            <template v-if="matchingCount > 0">
                <div
                    v-if="mainColumns.length"
                    class="pipeline-scroll"
                    tabindex="0"
                    role="region"
                    aria-label="Five-stage pipeline"
                >
                    <div class="pipeline-grid">
                        <PipelineColumn
                            v-for="column in mainColumns"
                            :key="column.stage.value"
                            v-bind="column"
                            :filtered="filtered"
                            :movable="canDrag"
                            :dragging="dragging"
                            :moving="moving"
                            @drag="dragging = $event"
                            @move="move"
                            @open="emit('open', $event)"
                        />
                    </div>
                </div>
                <div
                    v-if="extraColumns.length"
                    class="pipeline-extra"
                    role="group"
                    aria-label="Repeat Client and Lost stages"
                >
                    <PipelineColumn
                        v-for="column in extraColumns"
                        :key="column.stage.value"
                        v-bind="column"
                        :filtered="filtered"
                        :movable="canDrag"
                        :dragging="dragging"
                        :moving="moving"
                        @drag="dragging = $event"
                        @move="move"
                        @open="emit('open', $event)"
                    />
                </div>
            </template>
            <div v-else class="pipeline-empty" role="status">
                <h3>No leads match these filters</h3>
                <p>
                    {{
                        filtered
                            ? professional
                                ? 'Try a different client name, stage or due filter.'
                                : 'Try a different brand name, stage or due filter.'
                            : 'Capture a lead to start your private pipeline.'
                    }}
                </p>
            </div>
            <div class="pipeline-foot">
                <p class="wd-hint">
                    {{ entries.length }} leads on this page. Counts cover all matching pages; column values cover this
                    page.
                </p>
                <button v-if="nextCursor" class="wd-table-button" type="button" :disabled="busy" @click="nextPage">
                    Next 25 leads
                </button>
                <button
                    v-if="matchingCount > entries.length && !nextCursor"
                    class="wd-table-button"
                    type="button"
                    :disabled="busy"
                    @click="apply"
                >
                    First page
                </button>
            </div>
        </section>
    </div>
</template>
<style scoped>
.pipeline-root {
    min-width: 0;
    max-width: 100%;
}
.pipeline-card {
    display: flex;
    flex-direction: column;
    gap: 14px;
    width: 100%;
    min-width: 0;
    padding: 20px;
    border: 1px solid var(--wd-line);
    border-radius: 16px;
    background: var(--wd-surface);
    color: var(--wd-ink);
}
.pipeline-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px 32px;
}
.pipeline-intro {
    display: flex;
    flex: 1 1 260px;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
}
.pipeline-title-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
}
.pipeline-title-row h2 {
    margin: 0;
    font-size: 20px;
    line-height: 1.2;
}
.automation-pill {
    padding: 4px 10px;
    border-radius: 999px;
    background: var(--wd-warning-bg);
    color: var(--wd-warning);
    font-size: 12px;
    font-weight: 600;
}
.pipeline-subtitle {
    margin: 0;
    color: var(--wd-muted);
    font-size: 13px;
}
.pipeline-value {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 1px;
    text-align: right;
}
.pipeline-kicker {
    color: var(--wd-muted);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.pipeline-total {
    font-family: var(--wd-font-display);
    font-size: 20px;
    font-weight: 700;
    line-height: 1.15;
    overflow-wrap: anywhere;
    font-variant-numeric: tabular-nums;
}
.pipeline-count {
    color: var(--wd-muted);
    font-size: 12px;
}
.pipeline-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px 12px;
}
.pipeline-search {
    position: relative;
    flex: 0 1 300px;
    min-width: 0;
}
.pipeline-search svg {
    position: absolute;
    top: 50%;
    left: 11px;
    color: var(--wd-muted);
    transform: translateY(-50%);
    pointer-events: none;
}
.pipeline-search input {
    width: 100%;
    padding-left: 34px;
}
.pipeline-due {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: var(--wd-table-control);
    padding: 0 12px;
    border: 1px solid var(--wd-control-line);
    border-radius: 10px;
    color: var(--wd-ink);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}
.pipeline-due input {
    width: 16px;
    height: 16px;
    accent-color: var(--wd-brand);
}
.pipeline-due:focus-within {
    outline: 3px solid var(--wd-focus);
    outline-offset: 2px;
}
.wd-filter-chips.is-busy {
    opacity: 0.6;
    pointer-events: none;
}
.pipeline-foot {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px 16px;
}
.pipeline-scroll {
    min-width: 0;
    margin: 0 -4px;
    padding: 4px;
    overflow-x: auto;
    contain: inline-size layout paint;
}
.pipeline-grid {
    display: grid;
    grid-auto-columns: minmax(150px, 1fr);
    grid-auto-flow: column;
    gap: 10px;
}
.pipeline-extra {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(212px, 100%), 1fr));
    gap: 10px;
}
.pipeline-empty {
    padding: 32px 16px;
    border: 1px dashed var(--wd-control-line);
    border-radius: 12px;
    background: var(--wd-paper);
    text-align: center;
}
.pipeline-empty h3 {
    margin: 0 0 8px;
    font-size: 16px;
}
.pipeline-empty p {
    margin: 0;
    color: var(--wd-muted);
    font-size: 14px;
}
.pipeline-card :deep(.wd-hint) {
    margin: 0;
}
@media (max-width: 599px) {
    /* Once the header wraps, the value block reads from the left like the rest of the card. */
    .pipeline-value {
        align-items: flex-start;
        text-align: left;
    }
}
@media (max-width: 480px) {
    .pipeline-card {
        padding: 16px;
    }
}
</style>
