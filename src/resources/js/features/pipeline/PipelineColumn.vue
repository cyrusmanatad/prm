<script setup lang="ts">
import { computed, ref } from 'vue';
import { money } from './money';
import type { Entry } from './types';

const props = defineProps<{
    stage: { value: string; label: string; dot: string };
    entries: Entry[];
    count: number;
    filtered: boolean;
    // Board moves are enabled only for writers; the reference fixture stays static.
    movable?: boolean;
    dragging?: Entry | null;
    moving?: string | null;
}>();
const emit = defineEmits<{ open: [id: string]; drag: [lead: Entry | null]; move: [lead: Entry, stage: string] }>();
const over = ref(false);
const accepts = computed(() => !!props.movable && !!props.dragging && props.dragging.stage !== props.stage.value);
function start(event: DragEvent, lead: Entry): void {
    if (!props.movable || !event.dataTransfer) return;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', lead.id);
    emit('drag', lead);
}
function enter(event: DragEvent): void {
    if (!accepts.value) return;
    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
    over.value = true;
}
function leave(event: DragEvent): void {
    if (!(event.currentTarget as HTMLElement).contains(event.relatedTarget as Node | null)) over.value = false;
}
function drop(event: DragEvent): void {
    over.value = false;
    if (!accepts.value || !props.dragging) return;
    event.preventDefault();
    emit('move', props.dragging, props.stage.value);
}
const amounts = computed(() => {
    const sums = new Map<string, bigint>();
    for (const row of props.entries) sums.set(row.currency, (sums.get(row.currency) ?? 0n) + BigInt(row.value_minor));
    return [...sums.entries()]
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([currency, minor]) => money(minor.toString(), currency));
});
const empty = computed(() =>
    props.count > 0 ? `${props.count} leads on other pages` : props.filtered ? 'No matching leads' : 'No leads yet',
);
function due(row: Entry): boolean {
    return !!row.next_action_due && Date.parse(row.next_action_due) <= Date.now();
}
</script>
<template>
    <section
        class="pipeline-column"
        :class="{ 'column-accepts': accepts, 'column-over': over && accepts }"
        :aria-label="stage.label"
        @dragenter="enter"
        @dragover="enter"
        @dragleave="leave"
        @drop="drop"
    >
        <header class="column-header">
            <div class="column-title">
                <span class="stage-dot" aria-hidden="true" :style="{ background: stage.dot }"></span>
                <h3>{{ stage.label }}</h3>
                <span v-if="count > 0" class="column-count" :aria-label="`${count} matching leads, all pages`">{{
                    count
                }}</span>
            </div>
            <p v-if="amounts.length" class="column-total">Page value: {{ amounts.join(' · ') }}</p>
        </header>
        <div class="column-cards">
            <button
                v-for="lead in entries"
                :key="lead.id"
                type="button"
                class="lead-card"
                :class="{ 'lead-dragging': dragging?.id === lead.id, 'lead-moving': moving === lead.id }"
                :draggable="movable"
                :aria-describedby="movable ? 'pipeline-drag-hint' : undefined"
                @click="emit('open', lead.id)"
                @dragstart="start($event, lead)"
                @dragend="emit('drag', null)"
            >
                <span class="wd-sr-only">Open </span>
                <span class="lead-brand">{{ lead.brand_name }}</span>
                <span class="lead-amount">{{ money(lead.value_minor, lead.currency) }}</span>
                <span v-if="stage.value === 'repeat-client' || stage.value === 'lost' || due(lead)" class="lead-tags">
                    <span v-if="stage.value === 'repeat-client'" class="tag tag-repeat">Repeat client</span>
                    <span v-if="stage.value === 'lost'" class="tag tag-lost">Lost</span>
                    <span v-if="due(lead)" class="tag tag-due">Action due</span>
                </span>
            </button>
            <p v-if="!entries.length" class="column-empty">{{ empty }}</p>
        </div>
    </section>
</template>
<style scoped>
.pipeline-column {
    display: flex;
    flex-direction: column;
    gap: 12px;
    min-width: 0;
    padding: 14px;
    border: 1px solid var(--wd-line);
    border-radius: 16px;
    background: var(--wd-paper);
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
    flex: none;
    width: 8px;
    height: 8px;
    border-radius: 50%;
}
.column-title h3 {
    flex: 1;
    margin: 0;
    color: var(--wd-ink);
    font-size: 14px;
    font-weight: 700;
}
.column-count {
    min-width: 22px;
    padding: 1px 7px;
    border: 1px solid var(--wd-line);
    border-radius: 999px;
    background: var(--wd-surface);
    color: var(--wd-body);
    font-size: 12px;
    font-weight: 600;
    text-align: center;
}
.column-total {
    overflow-wrap: anywhere;
    margin: 0;
    color: var(--wd-muted);
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
    width: 100%;
    padding: 14px;
    border: 1px solid var(--wd-line);
    border-radius: 12px;
    background: var(--wd-surface);
    color: var(--wd-ink);
    text-align: left;
}
.lead-card:hover {
    border-color: var(--wd-brand);
}
.lead-card[draggable='true'] {
    cursor: grab;
}
.lead-dragging {
    opacity: 0.45;
}
.lead-moving {
    opacity: 0.7;
}
.column-accepts {
    border-style: dashed;
    border-color: var(--wd-control-line);
}
.column-over {
    border-color: var(--wd-brand);
    background: var(--wd-surface);
}
.lead-card:focus-visible {
    outline: 3px solid var(--wd-focus);
    outline-offset: 3px;
}
.lead-brand {
    font-size: 15px;
    font-weight: 600;
    line-height: 1.3;
    overflow-wrap: anywhere;
}
.lead-amount {
    color: var(--wd-muted);
    font-size: 13px;
    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
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
    background: var(--color-blush, var(--wd-paper));
    color: var(--wd-brand-hover);
}
.tag-lost {
    background: var(--wd-divider);
    color: var(--wd-body);
}
.tag-due {
    background: var(--wd-warning-bg);
    color: var(--wd-warning);
}
.column-empty {
    margin: 0;
    padding: 20px 12px;
    border: 1px dashed var(--wd-control-line);
    border-radius: 12px;
    color: var(--wd-muted);
    font-size: 13px;
    text-align: center;
}
</style>
