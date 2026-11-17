<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import DataTable from '../../components/DataTable.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { dateTime, entitlementStates } from './format';
import type { AssignmentRow } from './types';

defineProps<{ caption: string; rows: AssignmentRow[]; linkWorkspace?: boolean }>();
const columns = [
    { key: 'workspace', label: 'Workspace' },
    { key: 'plan', label: 'Plan' },
    { key: 'version', label: 'Assignment version', numeric: true },
    { key: 'state', label: 'State' },
    { key: 'starts_at', label: 'Starts' },
    { key: 'ends_at', label: 'Ends' },
];
</script>

<template>
    <DataTable :caption="caption" :columns="columns" :rows="rows" :row-key="(row) => row.id">
        <template #cell-workspace="{ row }"
            ><Link v-if="linkWorkspace" :href="`/admin/workspaces/${row.org_id}?tab=entitlements`">{{
                row.workspace
            }}</Link
            ><template v-else>{{ row.workspace }}</template></template
        >
        <template #cell-plan="{ row }"
            ><Link :href="`/admin/plans/${row.plan_id}`">{{ row.plan }} v{{ row.plan_version }}</Link></template
        >
        <template #cell-state="{ row }"
            ><StatusBadge
                :label="entitlementStates[row.state]?.label ?? row.state"
                :tone="entitlementStates[row.state]?.tone ?? 'neutral'"
        /></template>
        <template #cell-starts_at="{ row }">{{ dateTime(row.starts_at) }}</template>
        <template #cell-ends_at="{ row }">{{ dateTime(row.ends_at) }}</template>
    </DataTable>
</template>
