<script setup lang="ts">
import { computed } from 'vue';
import { Banknote, Package, Percent } from '@lucide/vue';
import { rewardKind, rewardValue } from './format';
import type { IncentiveTerms } from './types';

const props = defineProps<{
    terms: Pick<IncentiveTerms, 'reward_type' | 'amount_minor' | 'currency' | 'reward_text'>;
}>();
const icon = computed(() => ({ cash: Banknote, non_cash: Package, percent: Percent })[props.terms.reward_type]);
</script>

<template>
    <div class="ic-reward">
        <span class="ic-reward-icon" aria-hidden="true"><component :is="icon" :size="22" /></span>
        <div>
            <p class="ic-reward-value" :class="{ 'is-text': terms.reward_type !== 'cash' }">{{ rewardValue(terms) }}</p>
            <p class="ic-reward-kind">{{ rewardKind(terms) }}</p>
        </div>
    </div>
</template>
