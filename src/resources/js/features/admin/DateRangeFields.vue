<script setup lang="ts">
import FieldGroup from '../../components/FieldGroup.vue';

/** A pair of calendar-day filters. The server treats the end date as inclusive and both as UTC days. */
withDefaults(defineProps<{ prefix: string; fromLabel?: string; toLabel?: string }>(), {
    fromLabel: 'From date',
    toLabel: 'To date (inclusive)',
});
const from = defineModel<string>('from', { default: '' });
const to = defineModel<string>('to', { default: '' });
</script>

<template>
    <FieldGroup :id="`${prefix}-from`" :label="fromLabel" :error="undefined">
        <template #default="{ id }"><input :id="id" v-model="from" type="date" /></template>
    </FieldGroup>
    <FieldGroup :id="`${prefix}-to`" :label="toLabel" :error="undefined">
        <template #default="{ id }"><input :id="id" v-model="to" type="date" /></template>
    </FieldGroup>
</template>
