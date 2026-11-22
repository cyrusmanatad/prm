<script setup lang="ts">
export type Fact = { key: string; label: string; value?: string | number | null; wide?: boolean };

/**
 * Compact label and value pairs for administration detail cards: two columns on wide screens, one on phones.
 * A slot named after the fact's key replaces its value; a missing value shows `missing`.
 */
withDefaults(defineProps<{ items: Fact[]; missing?: string }>(), { missing: 'Not recorded' });
</script>

<template>
    <dl class="adm-facts">
        <div v-for="item in items" :key="item.key" :class="{ 'is-wide': item.wide }">
            <dt>{{ item.label }}</dt>
            <dd>
                <slot :name="item.key" :item="item">{{
                    item.value === null || item.value === undefined || item.value === '' ? missing : item.value
                }}</slot>
            </dd>
        </div>
    </dl>
</template>
