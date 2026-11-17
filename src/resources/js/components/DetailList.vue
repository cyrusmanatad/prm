<script setup lang="ts">
export type DetailItem = { key: string; label: string; value?: string | number | null };

withDefaults(defineProps<{ items: DetailItem[]; missing?: string }>(), { missing: 'Not recorded' });
</script>

<template>
    <dl class="wd-detail-list">
        <template v-for="item in items" :key="item.key">
            <dt>{{ item.label }}</dt>
            <dd>
                <slot :name="item.key" :item="item">{{
                    item.value === null || item.value === undefined || item.value === '' ? missing : item.value
                }}</slot>
            </dd>
        </template>
    </dl>
</template>
