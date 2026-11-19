<script setup lang="ts">
import { computed } from 'vue';
import { Check, Circle, Contrast, Diamond, RefreshCw, UserCheck, X } from '@lucide/vue';

const props = defineProps<{ stage: string; label: string }>();
const icons: Record<string, typeof Circle> = {
    lead: Circle,
    pitched: Contrast,
    negotiating: Diamond,
    onboarded: UserCheck,
    active: Check,
    'repeat-client': RefreshCw,
    lost: X,
};
const icon = computed(() => icons[props.stage] ?? Circle);
</script>

<template>
    <span class="wd-badge wd-stage-badge" v-bind="{ 'data-stage': stage }"
        ><component :is="icon" :size="14" aria-hidden="true" focusable="false" />{{ label }}</span
    >
</template>
