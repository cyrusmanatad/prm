<script setup lang="ts">
import { computed } from 'vue';
import { Circle, CircleCheck, CircleX, TriangleAlert } from '@lucide/vue';

export type BadgeTone = 'neutral' | 'success' | 'warning' | 'danger';

const props = withDefaults(defineProps<{ label: string; tone?: BadgeTone }>(), { tone: 'neutral' });
const icon = computed(
    () => ({ neutral: Circle, success: CircleCheck, warning: TriangleAlert, danger: CircleX })[props.tone],
);
</script>

<template>
    <span class="wd-badge" :class="`wd-badge-${tone}`"
        ><component :is="icon" :size="14" aria-hidden="true" focusable="false" />{{ label }}</span
    >
</template>
