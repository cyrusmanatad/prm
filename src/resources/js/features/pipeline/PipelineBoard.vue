<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import SelectField from '../../components/SelectField.vue';
import ActionGroup from '../../components/ActionGroup.vue';
import { Button } from '../../components/ui/button';
import PipelineColumn from './PipelineColumn.vue';
import { pipelineStages } from './stages';
import { money } from './money';
import type { Entry, PipelineWorkspace } from './types';

const props = defineProps<{
    entries: Entry[];
    counts: PipelineWorkspace['counts'];
    totals: PipelineWorkspace['totals'];
    filters: PipelineWorkspace['filters'];
    nextCursor: string | null;
    loading?: boolean;
    error?: string;
}>();
const emit = defineEmits<{ open: [id: string]; load: [params: Record<string, string>]; reset: [] }>();
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
onMounted(() => window.addEventListener('popstate', cancelSearchOnHistory));
onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    window.removeEventListener('popstate', cancelSearchOnHistory);
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
const stageOptions = [{ value: '', label: 'All stages' }, ...pipelineStages];
const segments = [
    { value: '', label: 'All leads' },
    { value: 'repeat-client', label: 'Repeat clients' },
    { value: 'lost', label: 'Lost' },
];
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
                        <span class="automation-pill">Automation unavailable</span>
                    </div>
                    <p class="pipeline-subtitle">Open a lead to edit it, move its stage or start onboarding.</p>
                </div>
                <div class="pipeline-value">
                    <span class="pipeline-kicker">Matching pipeline value</span>
                    <span v-for="amount in headerAmounts" :key="amount" class="pipeline-total">{{ amount }}</span>
                    <span v-if="!headerAmounts.length" class="pipeline-count">No non-lost value</span>
                    <span class="pipeline-count">All matching pages · excludes Lost</span>
                    <span class="pipeline-count"
                        >{{ matchingCount }} matching {{ matchingCount === 1 ? 'lead' : 'leads' }} · all pages</span
                    >
                </div>
            </div>
            <ActionGroup label="Lead segment">
                <Button
                    v-for="segment in segments"
                    :key="segment.value"
                    type="button"
                    class="wd-button"
                    :class="stageFilter === segment.value ? 'wd-button-primary' : 'wd-button-secondary'"
                    :aria-pressed="stageFilter === segment.value"
                    :disabled="loading"
                    @click="chooseSegment(segment.value)"
                >
                    {{ segment.label }}
                </Button>
            </ActionGroup>
            <form class="pipeline-toolbar" aria-label="Filter pipeline" @submit.prevent="apply">
                <div class="wd-field pipeline-search">
                    <label for="pipeline-search">Search brands</label>
                    <input
                        id="pipeline-search"
                        v-model="query"
                        type="search"
                        maxlength="160"
                        placeholder="Search brands"
                        @input="search"
                    />
                </div>
                <SelectField
                    id="pipeline-stage"
                    v-model="stageFilter"
                    class="pipeline-stage"
                    label="Stage filter"
                    :options="stageOptions"
                    :show-mark="true"
                    @change="apply"
                />
                <label class="pipeline-due"
                    ><input v-model="dueOnly" type="checkbox" @change="apply" />Due next actions</label
                >
                <Button type="submit" class="wd-button wd-button-secondary">Apply filters</Button>
                <Button v-if="filtered" type="button" class="wd-button wd-button-secondary" @click="clearFilters"
                    >Clear filters</Button
                >
            </form>
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
                        @open="emit('open', $event)"
                    />
                </div>
            </template>
            <div v-else class="pipeline-empty" role="status">
                <h3>No leads match these filters</h3>
                <p>
                    {{
                        filtered
                            ? 'Try a different brand name, stage or due filter.'
                            : 'Capture a lead to start your private pipeline.'
                    }}
                </p>
            </div>
            <ActionGroup>
                <p class="wd-hint">
                    {{ entries.length }} leads on this page. Counts cover all matching pages; column values cover this
                    page.
                </p>
                <Button
                    v-if="nextCursor"
                    class="wd-button wd-button-secondary"
                    type="button"
                    :disabled="loading"
                    @click="nextPage"
                    >Next 25 leads</Button
                >
                <Button
                    v-if="matchingCount > entries.length && !nextCursor"
                    class="wd-button wd-button-secondary"
                    type="button"
                    :disabled="loading"
                    @click="apply"
                    >First page</Button
                >
            </ActionGroup>
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
    gap: 24px;
    width: 100%;
    min-width: 0;
    padding: 24px;
    border: 1px solid var(--wd-line);
    border-radius: 16px;
    background: var(--wd-surface);
    color: var(--wd-ink);
}
.pipeline-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 16px 32px;
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
    font-size: 24px;
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
    font-size: 14px;
}
.pipeline-value {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.pipeline-kicker {
    color: var(--wd-muted);
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}
.pipeline-total {
    font-family: var(--wd-font-display);
    font-size: 28px;
    font-weight: 700;
    line-height: 1.15;
    overflow-wrap: anywhere;
    font-variant-numeric: tabular-nums;
}
.pipeline-count {
    color: var(--wd-muted);
    font-size: 13px;
}
.pipeline-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 12px 16px;
}
.pipeline-search {
    flex: 1 1 220px;
    min-width: 0;
}
.pipeline-search input {
    width: 100%;
}
.pipeline-stage {
    flex: 1 1 180px;
    min-width: 0;
}
.pipeline-due {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 44px;
    color: var(--wd-body);
    font-size: 14px;
}
.pipeline-due input {
    width: 18px;
    height: 18px;
    accent-color: var(--wd-brand);
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
    grid-auto-columns: minmax(170px, 1fr);
    grid-auto-flow: column;
    gap: 14px;
}
.pipeline-extra {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(212px, 100%), 1fr));
    gap: 14px;
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
@media (max-width: 480px) {
    .pipeline-card {
        padding: 20px;
    }
}
</style>
