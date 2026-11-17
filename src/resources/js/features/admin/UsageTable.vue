<script setup lang="ts">
import DataTable from '../../components/DataTable.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { recordResourceLabel } from '../entitlements/resources';
import type { Entitlement, Usage } from './types';

defineProps<{ entitlement: Entitlement }>();
const columns = [
    { key: 'resource', label: 'Resource' },
    { key: 'used', label: 'Retained records', numeric: true },
    { key: 'limit', label: 'Create limit', numeric: true },
    { key: 'remaining', label: 'Remaining creates', numeric: true },
    { key: 'state', label: 'State' },
];
function status(row: Usage): { label: string; tone: 'neutral' | 'success' | 'warning' | 'danger' } {
    if (row.limit === null) return { label: 'No plan configured', tone: 'neutral' };
    if (row.used > row.limit) return { label: `Over limit by ${row.used - row.limit}`, tone: 'danger' };
    if (row.used === row.limit) return { label: 'Limit reached', tone: 'warning' };
    return { label: 'Within limit', tone: 'success' };
}
</script>

<template>
    <DataTable caption="Usage" :columns="columns" :rows="entitlement.usage" :row-key="(row) => row.resource">
        <template #cell-resource="{ row }">{{ recordResourceLabel(row.resource) }}</template>
        <template #cell-limit="{ row }">{{ row.limit ?? 'Not configured' }}</template>
        <template #cell-remaining="{ row }">{{
            row.limit === null ? 'Not configured' : Math.max(row.limit - row.used, 0)
        }}</template>
        <template #cell-state="{ row }"><StatusBadge :label="status(row).label" :tone="status(row).tone" /></template>
    </DataTable>
</template>
