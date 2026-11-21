<script setup lang="ts">
import { computed } from 'vue';
import { pipelineStages } from '../pipeline/stages';

const props = defineProps<{ stage: string; label: string }>();
// The same coloured dots as the partner pipeline, so a stage reads the same on both sides.
const dot = computed(() => pipelineStages.find((item) => item.value === props.stage)?.dot ?? 'var(--wd-muted)');
</script>

<template>
    <span class="wd-stage-chip" v-bind="{ 'data-stage': stage }"
        ><span class="wd-stage-chip-dot" :style="{ background: dot }" aria-hidden="true"></span>{{ label }}</span
    >
</template>

<style scoped>
.wd-stage-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 2px 9px;
    border-radius: 999px;
    background: var(--wd-divider);
    color: var(--wd-ink);
    font-size: 12px;
    font-weight: 700;
    line-height: 18px;
    white-space: nowrap;
}
.wd-stage-chip-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
}
</style>
