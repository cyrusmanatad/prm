<script setup lang="ts">
import { Check } from '@lucide/vue';
import { ruleLines } from './format';
import type { IncentiveTerms } from './types';

defineProps<{
    terms: Pick<IncentiveTerms, 'rule_stage' | 'leads_per_award' | 'starts_on' | 'ends_on'> & {
        campaign_title?: string | null;
    };
    stageLabel: (stage: string) => string;
}>();
</script>

<template>
    <ul class="ic-rule" aria-label="What qualifies">
        <li v-for="line in ruleLines(terms, stageLabel)" :key="line">
            <Check :size="14" aria-hidden="true" /><span>{{ line }}</span>
        </li>
    </ul>
</template>
