<script setup lang="ts">
import { computed } from 'vue';
import { initials, pick } from '../lib/initials';

const props = withDefaults(
    defineProps<{
        name: string;
        /** Partnership ID: the tint follows the partnership, not the name, so renames keep the colour. */
        seed: string;
        size?: 28 | 36 | 48;
        paused?: boolean;
    }>(),
    { size: 36, paused: false },
);
// Decorative (aria-hidden): the partner's name is always shown beside it.
const tones = ['blush', 'mint', 'sand', 'lilac', 'sky', 'clay'] as const;
const tone = computed(() => (props.paused ? 'paused' : tones[pick(props.seed, tones.length)]));
</script>

<template>
    <span class="wd-partner-avatar" :class="[`is-${tone}`, `is-${size}`]" aria-hidden="true">{{ initials(name) }}</span>
</template>
