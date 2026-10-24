<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { money } from './money';
import type { Entry, PipelineWorkspace } from './types';

const props = defineProps<{
    entries: Entry[];
    counts: PipelineWorkspace['counts'];
    totals: PipelineWorkspace['totals'];
    filters: PipelineWorkspace['filters'];
    nextCursor: string | null;
}>();
const emit = defineEmits<{
    open: [id: string];
    load: [params: Record<string, string>];
    reset: [];
}>();

const stages = [
    { value: 'lead', label: 'Lead', dot: '#8FA3B8' },
    { value: 'pitched', label: 'Pitched', dot: '#C9A24B' },
    { value: 'negotiating', label: 'Negotiating', dot: '#D9803B' },
    { value: 'onboarded', label: 'Onboarded', dot: '#5F9C84' },
    { value: 'active', label: 'Active', dot: '#8A1148' },
] as const;
type BoardStage = (typeof stages)[number]['value'];
type TabId = 'all' | 'repeat' | 'lost';

const boardStages: readonly string[] = stages.map((stage) => stage.value);
// repeat-client and lost are stored as stages. The five columns are the open
// stages, so those records stay on the Active column inside their segment.
function columnFor(stage: string): BoardStage | null {
    if (stage === 'repeat-client' || stage === 'lost') return 'active';
    return boardStages.includes(stage) ? (stage as BoardStage) : null;
}
function isDue(value: string | null): boolean {
    if (!value) return false;
    const time = Date.parse(value);
    return !Number.isNaN(time) && time <= Date.now();
}

const tab = ref<TabId>(
    props.filters.stage === 'lost' ? 'lost' : props.filters.stage === 'repeat-client' ? 'repeat' : 'all',
);
const query = ref(props.filters.q);
const stageFilter = ref<BoardStage | 'all'>(
    boardStages.includes(props.filters.stage) ? (props.filters.stage as BoardStage) : 'all',
);
const dueOnly = ref(props.filters.due);
const stageOpen = ref(false);
const stageQuery = ref('');
const stageActive = ref(0);
const stageRoot = ref<HTMLElement>();
const stageTrigger = ref<HTMLButtonElement>();
const stageSearch = ref<HTMLInputElement>();
const filtersActive = computed(() => query.value !== '' || stageFilter.value !== 'all' || dueOnly.value);
const triggerLabel = computed(() => {
    const stage = stages.find((item) => item.value === stageFilter.value);
    const name = stage?.label ?? 'All stages';
    return dueOnly.value ? `${name} · Due` : name;
});
const filteredStages = computed(() => {
    const needle = stageQuery.value.trim().toLowerCase();
    if (!needle) return stages;
    return stages.filter((stage) => stage.label.toLowerCase().includes(needle));
});
const activeStageId = computed(() => {
    const stage = filteredStages.value[stageActive.value];
    return stage ? `stage-option-${stage.value}` : undefined;
});

const tabCounts = computed(() => {
    let all = 0;
    let repeat = 0;
    let lost = 0;
    for (const row of props.counts) {
        const count = Number(row.count);
        if (row.stage === 'lost') lost += count;
        else all += count;
        if (row.stage === 'repeat-client') repeat += count;
    }
    return { all, repeat, lost };
});
const tabs = computed(() => [
    { id: 'all' as const, label: 'All active', count: tabCounts.value.all },
    { id: 'repeat' as const, label: 'Repeat clients', count: tabCounts.value.repeat },
    { id: 'lost' as const, label: 'Lost', count: tabCounts.value.lost },
]);

const headerAmounts = computed(() =>
    props.totals.length ? props.totals.map((row) => money(row.amount_minor, row.currency)) : ['0.00'],
);
const headerCount = computed(
    () => `${props.totals.reduce((sum, row) => sum + Number(row.count), 0)} leads · excludes Lost`,
);

const visible = computed(() => {
    const q = query.value.trim().toLowerCase();
    return props.entries.filter((entry) => {
        const segment = entry.stage === 'lost' ? 'lost' : entry.stage === 'repeat-client' ? 'repeat' : 'pipeline';
        const inTab =
            tab.value === 'repeat'
                ? segment === 'repeat'
                : tab.value === 'lost'
                  ? segment === 'lost'
                  : segment !== 'lost';
        return (
            inTab &&
            (!q || entry.brand_name.toLowerCase().includes(q)) &&
            (!dueOnly.value || isDue(entry.next_action_due))
        );
    });
});
const visibleCurrencies = computed(() => [...new Set(visible.value.map((entry) => entry.currency))].sort());

function formatAmounts(amounts: Map<string, bigint>): string {
    if (amounts.size === 0) {
        if (!visibleCurrencies.value.length) return '0.00';
        return visibleCurrencies.value.map((currency) => money('0', currency)).join(' · ');
    }
    return [...amounts.entries()]
        .sort(([left], [right]) => left.localeCompare(right))
        .map(([currency, minor]) => money(minor.toString(), currency))
        .join(' · ');
}

const columns = computed(() =>
    stages
        .filter((stage) => stageFilter.value === 'all' || stageFilter.value === stage.value)
        .map((stage) => {
            const items = visible.value.filter((entry) => columnFor(entry.stage) === stage.value);
            const amounts = new Map<string, bigint>();
            for (const item of items) {
                amounts.set(item.currency, (amounts.get(item.currency) ?? 0n) + BigInt(item.value_minor));
            }
            return {
                ...stage,
                count: items.length,
                totalText: formatAmounts(amounts),
                empty: items.length === 0,
                items: items.map((item) => {
                    const repeat = item.stage === 'repeat-client';
                    const due = isDue(item.next_action_due);
                    return {
                        id: item.id,
                        brand: item.brand_name,
                        amountText: money(item.value_minor, item.currency),
                        repeat,
                        due,
                        hasTags: repeat || due,
                    };
                }),
            };
        }),
);
const hasResults = computed(() => visible.value.length > 0);
const emptyCopy = computed(() => {
    if (!filtersActive.value && tab.value === 'lost') {
        return { title: 'No lost leads', body: 'Leads you mark as lost will appear here.' };
    }
    if (!filtersActive.value && tab.value === 'repeat') {
        return { title: 'No repeat clients yet', body: 'Clients you work with again will appear here.' };
    }
    return { title: 'No leads match these filters', body: 'Try a different brand name, stage or segment.' };
});

function clearFilters(): void {
    query.value = '';
    stageFilter.value = 'all';
    dueOnly.value = false;
    closeStage(true);
    if (props.filters.q || props.filters.stage || props.filters.due) emit('reset');
}
function highlightStage(): void {
    const index = filteredStages.value.findIndex((stage) => stage.value === stageFilter.value);
    stageActive.value = index >= 0 ? index : 0;
}
function closeStage(restoreFocus = false): void {
    if (!stageOpen.value) return;
    stageOpen.value = false;
    stageQuery.value = '';
    if (restoreFocus) nextTick(() => stageTrigger.value?.focus());
}
async function openStage(): Promise<void> {
    if (stageOpen.value) return;
    stageOpen.value = true;
    highlightStage();
    await nextTick();
    stageSearch.value?.focus();
}
function toggleStage(): void {
    if (stageOpen.value) closeStage(true);
    else void openStage();
}
function chooseStage(value: BoardStage | 'all'): void {
    stageFilter.value = value;
    closeStage(true);
}
function moveStage(delta: number): void {
    const count = filteredStages.value.length;
    if (!count) return;
    stageActive.value = (stageActive.value + delta + count) % count;
    document.getElementById(`stage-option-${filteredStages.value[stageActive.value]?.value ?? ''}`)?.scrollIntoView({
        block: 'nearest',
    });
}
function onStageTriggerKey(event: KeyboardEvent): void {
    if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
        event.preventDefault();
        void openStage();
    }
}
function onStagePanelKey(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        moveStage(1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        moveStage(-1);
    } else if (event.key === 'Home') {
        event.preventDefault();
        stageActive.value = 0;
    } else if (event.key === 'End') {
        event.preventDefault();
        stageActive.value = Math.max(filteredStages.value.length - 1, 0);
    } else if (event.key === 'Enter' && event.target === stageSearch.value) {
        event.preventDefault();
        const stage = filteredStages.value[stageActive.value];
        if (stage) chooseStage(stage.value);
    } else if (event.key === 'Escape') {
        event.preventDefault();
        closeStage(true);
    }
}
function onStagePointer(event: PointerEvent): void {
    if (!stageOpen.value || stageRoot.value?.contains(event.target as Node)) return;
    closeStage();
}
watch(stageQuery, highlightStage);
watch(stageOpen, (isOpen) => {
    if (isOpen) document.addEventListener('pointerdown', onStagePointer);
    else document.removeEventListener('pointerdown', onStagePointer);
});
onBeforeUnmount(() => document.removeEventListener('pointerdown', onStagePointer));
function nextPage(): void {
    const params: Record<string, string> = {};
    if (props.filters.q) params.q = props.filters.q;
    if (props.filters.stage) params.stage = props.filters.stage;
    if (props.filters.due) params.due = '1';
    if (props.nextCursor) params.cursor = props.nextCursor;
    emit('load', params);
}
</script>

<template>
    <div class="pipeline-root">
        <section class="pipeline-card" aria-labelledby="my-pipeline-title">
            <div class="pipeline-header">
                <div class="pipeline-intro">
                    <div class="pipeline-title-row">
                        <h2 id="my-pipeline-title">My pipeline</h2>
                        <span class="automation-pill">
                            <span class="automation-dot" aria-hidden="true"></span>
                            Automation unavailable
                        </span>
                    </div>
                    <p class="pipeline-subtitle">Open a lead to edit it, move its stage or start onboarding.</p>
                </div>
                <div class="pipeline-value">
                    <span class="pipeline-kicker">Pipeline value</span>
                    <span v-for="amount in headerAmounts" :key="amount" class="pipeline-total">{{ amount }}</span>
                    <span class="pipeline-count">{{ headerCount }}</span>
                </div>
            </div>

            <div class="pipeline-toolbar">
                <div class="segment" role="group" aria-label="Lead segment">
                    <button
                        v-for="item in tabs"
                        :key="item.id"
                        type="button"
                        :class="tab === item.id ? 'seg-on' : 'seg-off'"
                        :aria-pressed="tab === item.id"
                        @click="tab = item.id"
                    >
                        {{ item.label }}
                        <span :class="tab === item.id ? 'seg-badge-on' : 'seg-badge-off'">{{ item.count }}</span>
                    </button>
                </div>

                <div class="pipeline-tools">
                    <div class="pipeline-search">
                        <svg
                            aria-hidden="true"
                            width="16"
                            height="16"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="#5E555B"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <circle cx="11" cy="11" r="7"></circle>
                            <path d="M21 21l-4.3-4.3"></path>
                        </svg>
                        <input
                            v-model="query"
                            type="search"
                            aria-label="Search brands"
                            placeholder="Search brands"
                            maxlength="160"
                        />
                    </div>

                    <div ref="stageRoot" class="pipeline-stage">
                        <button
                            ref="stageTrigger"
                            type="button"
                            class="stage-trigger"
                            role="combobox"
                            aria-label="Stage filter"
                            aria-controls="stage-filter-list"
                            :aria-expanded="stageOpen"
                            :aria-activedescendant="stageOpen ? activeStageId : undefined"
                            aria-autocomplete="list"
                            @click="toggleStage"
                            @keydown="onStageTriggerKey"
                        >
                            <span>{{ triggerLabel }}</span>
                            <svg
                                aria-hidden="true"
                                width="16"
                                height="16"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="#5E555B"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <path d="M6 9l6 6 6-6"></path>
                            </svg>
                        </button>
                        <div v-if="stageOpen" class="stage-panel" @keydown="onStagePanelKey">
                            <div class="stage-panel-head">
                                <button
                                    type="button"
                                    class="stage-all"
                                    :aria-pressed="stageFilter === 'all'"
                                    @click="chooseStage('all')"
                                >
                                    All stages
                                </button>
                                <button type="button" class="stage-clear" @click="clearFilters">Clear filters</button>
                            </div>
                            <div class="stage-search">
                                <svg
                                    aria-hidden="true"
                                    width="16"
                                    height="16"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="#5E555B"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                >
                                    <circle cx="11" cy="11" r="7"></circle>
                                    <path d="M21 21l-4.3-4.3"></path>
                                </svg>
                                <input
                                    ref="stageSearch"
                                    v-model="stageQuery"
                                    type="search"
                                    aria-label="Search stages"
                                    aria-controls="stage-filter-list"
                                    autocomplete="off"
                                    placeholder="Search"
                                />
                            </div>
                            <ul id="stage-filter-list" class="stage-options" role="listbox" aria-label="Stages">
                                <li v-if="!filteredStages.length" class="stage-empty" role="status">
                                    No matching options.
                                </li>
                                <li v-for="(stage, index) in filteredStages" :key="stage.value" role="presentation">
                                    <button
                                        :id="`stage-option-${stage.value}`"
                                        type="button"
                                        class="stage-option"
                                        role="option"
                                        :aria-selected="stageFilter === stage.value"
                                        :class="{ 'is-active': index === stageActive }"
                                        @mouseenter="stageActive = index"
                                        @click="chooseStage(stage.value)"
                                    >
                                        <span
                                            class="stage-check"
                                            :class="{ 'is-on': stageFilter === stage.value }"
                                            aria-hidden="true"
                                        >
                                            <svg
                                                v-if="stageFilter === stage.value"
                                                width="12"
                                                height="12"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="#fff"
                                                stroke-width="3"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                            >
                                                <path d="M5 12l5 5 9-10"></path>
                                            </svg>
                                        </span>
                                        <span class="stage-dot" :style="{ background: stage.dot }"></span>
                                        {{ stage.label }}
                                    </button>
                                </li>
                            </ul>
                            <hr class="stage-divider" />
                            <button
                                type="button"
                                class="stage-option due-row"
                                :aria-pressed="dueOnly"
                                @click="dueOnly = !dueOnly"
                            >
                                <span class="stage-check" :class="{ 'is-on': dueOnly }" aria-hidden="true">
                                    <svg
                                        v-if="dueOnly"
                                        width="12"
                                        height="12"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="#fff"
                                        stroke-width="3"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    >
                                        <path d="M5 12l5 5 9-10"></path>
                                    </svg>
                                </span>
                                Due next actions
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="hasResults" class="pipeline-scroll" tabindex="0" role="region" aria-label="Five-stage pipeline">
                <div class="pipeline-grid">
                    <section
                        v-for="column in columns"
                        :key="column.value"
                        class="pipeline-column"
                        :aria-label="column.label"
                    >
                        <header class="column-header">
                            <div class="column-title">
                                <span class="stage-dot" aria-hidden="true" :style="{ background: column.dot }"></span>
                                <h3>{{ column.label }}</h3>
                                <span class="column-count">{{ column.count }}</span>
                            </div>
                            <span class="column-total">{{ column.totalText }}</span>
                        </header>
                        <div class="column-cards">
                            <button
                                v-for="lead in column.items"
                                :key="lead.id"
                                type="button"
                                class="lead-card"
                                :aria-label="`Open ${lead.brand}`"
                                @click="emit('open', lead.id)"
                            >
                                <span class="lead-brand">{{ lead.brand }}</span>
                                <span class="lead-amount">{{ lead.amountText }}</span>
                                <span v-if="lead.hasTags" class="lead-tags">
                                    <span v-if="lead.repeat" class="tag tag-repeat">Repeat client</span>
                                    <span v-if="lead.due" class="tag tag-due">Action due</span>
                                </span>
                            </button>
                            <div v-if="column.empty" class="column-empty">No leads</div>
                        </div>
                    </section>
                </div>
            </div>

            <div v-else class="pipeline-empty" role="status">
                <h3>{{ emptyCopy.title }}</h3>
                <p>{{ emptyCopy.body }}</p>
                <button v-if="filtersActive" type="button" class="empty-clear" @click="clearFilters">
                    Clear filters
                </button>
            </div>
        </section>
        <p v-if="nextCursor" class="wd-hint">Showing 25 leads. Pipeline value includes every non-lost lead.</p>
        <button v-if="nextCursor" class="wd-link" type="button" @click="nextPage">Next 25 leads</button>
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
    box-sizing: border-box;
    width: 100%;
    min-width: 0;
    max-width: 100%;
    padding: 28px;
    border: 1px solid #e6dccb;
    border-radius: 20px;
    background: #fff;
    color: #1f1a1d;
}
.pipeline-card button,
.pipeline-card input,
.pipeline-card select {
    font-family: inherit;
}
.pipeline-card button {
    cursor: pointer;
}
.pipeline-card button:focus-visible,
.pipeline-card input:focus-visible,
.pipeline-card select:focus-visible {
    outline: 2px solid #8a1148;
    outline-offset: 2px;
}
.pipeline-card h2,
.pipeline-card h3,
.pipeline-card p {
    margin: 0;
    color: inherit;
}
.pipeline-card h2,
.pipeline-total {
    font-family: var(--wd-font-display);
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
    flex: 1 1 320px;
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
    font-size: 24px;
    font-weight: 700;
    line-height: 1.2;
    letter-spacing: -0.01em;
}
.automation-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    background: #f3ecdf;
    color: #5e555b;
    font-size: 12px;
    font-weight: 600;
}
.automation-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #b7791f;
}
.pipeline-subtitle {
    font-size: 14px;
    line-height: 1.5;
    color: #5e555b;
}
.pipeline-value {
    display: flex;
    flex-direction: column;
    gap: 2px;
    align-items: flex-end;
    text-align: right;
}
.pipeline-kicker {
    color: #5e555b;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.pipeline-total {
    font-size: 30px;
    font-weight: 700;
    line-height: 1.15;
    letter-spacing: -0.02em;
    font-variant-numeric: tabular-nums;
}
.pipeline-count {
    color: #5e555b;
    font-size: 13px;
}
.pipeline-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px 16px;
}
.segment {
    display: inline-flex;
    gap: 2px;
    padding: 4px;
    border-radius: 12px;
    background: #f3ecdf;
}
.seg-on,
.seg-off {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 36px;
    padding: 0 14px;
    border: 0;
    border-radius: 9px;
    font-size: 14px;
    font-weight: 600;
}
.seg-on {
    background: #fff;
    color: #1f1a1d;
    box-shadow: 0 1px 3px rgba(31, 26, 29, 0.12);
}
.seg-off {
    background: transparent;
    color: #5e555b;
}
.seg-off:hover {
    color: #1f1a1d;
}
.seg-badge-on,
.seg-badge-off {
    min-width: 20px;
    padding: 1px 6px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    text-align: center;
}
.seg-badge-on {
    background: #8a1148;
    color: #fff;
}
.seg-badge-off {
    background: #e6dccb;
    color: #3f373c;
}
.pipeline-tools {
    display: flex;
    flex: 1 1 460px;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    margin-left: auto;
}
.pipeline-search {
    position: relative;
    flex: 1 1 220px;
    min-width: 180px;
    max-width: 320px;
}
.pipeline-search svg {
    position: absolute;
    top: 14px;
    left: 14px;
    pointer-events: none;
}
.pipeline-search input,
.stage-trigger,
.stage-search input {
    box-sizing: border-box;
    height: 44px;
    border: 1px solid #d9cdb8;
    border-radius: 12px;
    background: #fff;
    color: #1f1a1d;
    font-size: 14px;
}
.pipeline-search input {
    width: 100%;
    padding: 0 14px 0 40px;
}
.pipeline-search input::placeholder {
    color: #5e555b;
    opacity: 1;
}
.pipeline-stage {
    position: relative;
    flex: 0 0 auto;
}
.stage-trigger {
    display: inline-flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-width: 160px;
    padding: 0 14px;
    text-align: left;
}
.stage-panel {
    position: absolute;
    z-index: 30;
    top: calc(100% + 6px);
    right: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 260px;
    max-width: calc(100vw - 48px);
    padding: 10px;
    border: 1px solid #d9cdb8;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 8px 24px rgba(31, 26, 29, 0.12);
}
.stage-panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.stage-all,
.stage-clear {
    min-height: 44px;
    padding: 0 4px;
    border: 0;
    background: transparent;
    font-size: 13px;
    font-weight: 600;
}
.stage-all {
    color: #1f1a1d;
}
.stage-all[aria-pressed='true'] {
    color: #8a1148;
}
.stage-clear {
    color: #8a1148;
}
.stage-clear:hover,
.stage-all:hover,
.stage-option:hover {
    background: #f3ecdf;
}
.stage-search {
    position: relative;
}
.stage-search svg {
    position: absolute;
    top: 14px;
    left: 12px;
    pointer-events: none;
}
.stage-search input {
    width: 100%;
    padding: 0 12px 0 36px;
}
.stage-search input::placeholder {
    color: #5e555b;
    opacity: 1;
}
.stage-options {
    max-height: 220px;
    margin: 0;
    padding: 0;
    overflow-y: auto;
    list-style: none;
}
.stage-option {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    min-height: 44px;
    padding: 8px 10px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: #1f1a1d;
    font-size: 14px;
    font-weight: 600;
    text-align: left;
}
.stage-option[aria-selected='true'],
.stage-option.is-active,
.due-row[aria-pressed='true'] {
    background: #f3ecdf;
}
.stage-check {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    border: 1.5px solid #d9cdb8;
    border-radius: 4px;
    background: #fff;
}
.stage-check.is-on {
    border-color: #8a1148;
    background: #8a1148;
}
.stage-option .stage-dot {
    width: 8px;
    height: 8px;
}
.stage-empty {
    margin: 0;
    padding: 12px 10px;
    color: #5e555b;
    font-size: 13px;
}
.stage-divider {
    height: 1px;
    margin: 2px 0;
    border: 0;
    background: #e6dccb;
}
.empty-clear {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    height: 44px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
}
.pipeline-scroll {
    min-width: 0;
    margin: 0 -4px;
    padding: 0 4px 4px;
    overflow-x: auto;
}
.pipeline-grid {
    display: grid;
    grid-auto-columns: minmax(212px, 1fr);
    grid-auto-flow: column;
    gap: 14px;
}
.pipeline-column {
    display: flex;
    flex-direction: column;
    gap: 12px;
    min-width: 0;
    padding: 14px;
    border: 1px solid #f0e7d6;
    border-radius: 16px;
    background: #faf5ec;
}
.column-header {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.column-title {
    display: flex;
    align-items: center;
    gap: 8px;
}
.stage-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}
.column-title h3 {
    flex: 1 1 auto;
    font-size: 14px;
    font-weight: 700;
}
.column-count {
    min-width: 22px;
    padding: 1px 7px;
    border: 1px solid #e6dccb;
    border-radius: 999px;
    background: #fff;
    color: #3f373c;
    font-size: 12px;
    font-weight: 600;
    text-align: center;
}
.column-total {
    padding-left: 16px;
    color: #5e555b;
    font-size: 12px;
    font-variant-numeric: tabular-nums;
}
.column-cards {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.lead-card {
    display: flex;
    flex-direction: column;
    gap: 6px;
    box-sizing: border-box;
    width: 100%;
    padding: 14px;
    border: 1px solid #e6dccb;
    border-radius: 12px;
    background: #fff;
    color: #1f1a1d;
    text-align: left;
}
.lead-card:hover {
    border-color: #8a1148;
    box-shadow: 0 2px 8px rgba(138, 17, 72, 0.12);
}
.lead-brand {
    font-size: 15px;
    font-weight: 600;
    line-height: 1.3;
}
.lead-amount {
    color: #5e555b;
    font-size: 13px;
    font-variant-numeric: tabular-nums;
}
.lead-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 4px;
}
.tag {
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
}
.tag-repeat {
    background: #f6e3ec;
    color: #6e0c3a;
}
.tag-due {
    background: #fbebd0;
    color: #6b4200;
}
.column-empty {
    padding: 20px 12px;
    border: 1px dashed #d9cdb8;
    border-radius: 12px;
    color: #5e555b;
    font-size: 13px;
    text-align: center;
}
.pipeline-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 48px 16px;
    border: 1px dashed #d9cdb8;
    border-radius: 16px;
    background: #faf5ec;
    text-align: center;
}
.pipeline-empty h3 {
    font-size: 16px;
    font-weight: 700;
}
.pipeline-empty p {
    max-width: 360px;
    color: #5e555b;
    font-size: 14px;
    line-height: 1.5;
}
.empty-clear {
    margin-top: 8px;
    padding: 0 20px;
    border: 0;
    background: #8a1148;
    color: #fff;
}
.pipeline-root > .wd-hint {
    margin: 12px 0 0;
}
</style>
