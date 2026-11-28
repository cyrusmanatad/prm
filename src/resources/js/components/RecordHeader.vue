<script setup lang="ts">
/**
 * The header card of a record page: an optional leading avatar, eyebrow, the page's one heading, a meta line of
 * status and dates (`meta` slot, as `<div><dt/><dd/></div>` pairs) and the record's actions. Anything below the
 * identity, such as notices or figures, goes in the default slot.
 */
defineProps<{ eyebrow: string; title: string; headingId: string }>();
</script>

<template>
    <section class="wd-card wd-record" :aria-labelledby="headingId">
        <div class="wd-record-identity" :class="{ 'has-leading': $slots.leading }">
            <div v-if="$slots.leading" class="wd-record-leading"><slot name="leading" /></div>
            <p class="wd-record-eyebrow">{{ eyebrow }}</p>
            <h1 :id="headingId">{{ title }}</h1>
            <dl v-if="$slots.meta" class="wd-record-meta"><slot name="meta" /></dl>
            <div v-if="$slots.actions" class="wd-record-actions"><slot name="actions" /></div>
        </div>
        <slot />
    </section>
</template>
