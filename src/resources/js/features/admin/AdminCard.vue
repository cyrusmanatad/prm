<script setup lang="ts">
import { useId } from 'vue';

/**
 * A compact administration card: a titled section whose header carries an optional count, badge (`badge` slot) and a
 * short note (`note` prop or slot). Put `FactList`, tables, `adm-action-row`s and `rec-fine` text directly inside;
 * wrap forms and running text in `adm-panel-body`.
 */
defineProps<{ title: string; note?: string; count?: number }>();
const headingId = useId();
</script>

<template>
    <section class="rec-card" :aria-labelledby="headingId">
        <header class="rec-card-head">
            <h2 :id="headingId">{{ title }}</h2>
            <span v-if="count !== undefined" class="rec-count">{{ count.toLocaleString('en-GB') }}</span>
            <slot name="badge" />
            <p v-if="note || $slots.note" class="rec-note">
                <slot name="note">{{ note }}</slot>
            </p>
        </header>
        <slot />
    </section>
</template>
