<script setup lang="ts">
import { computed } from 'vue';
import { pipelineStages } from '../pipeline/stages';

/** How far a set of registered leads has progressed: a proportional bar and a legend with counts. */
const props = defineProps<{ stages: string[]; label: (stage: string) => string }>();
const counts = computed(() =>
    pipelineStages
        .map((stage) => ({ ...stage, count: props.stages.filter((value) => value === stage.value).length }))
        .filter((stage) => stage.count > 0),
);
const description = computed(
    () => `Stage progress: ${counts.value.map((stage) => `${stage.count} ${props.label(stage.value)}`).join(', ')}`,
);
</script>

<template>
    <div v-if="stages.length" class="wd-stage-summary">
        <div class="wd-stage-bar" role="img" :aria-label="description">
            <span
                v-for="stage in counts"
                :key="stage.value"
                :style="{ width: `${(stage.count / stages.length) * 100}%`, background: stage.dot }"
            ></span>
        </div>
        <ul class="wd-stage-legend" aria-hidden="true">
            <li v-for="stage in counts" :key="stage.value">
                <span class="wd-stage-legend-dot" :style="{ background: stage.dot }"></span>{{ label(stage.value) }}
                <strong>{{ stage.count }}</strong>
            </li>
        </ul>
    </div>
</template>
