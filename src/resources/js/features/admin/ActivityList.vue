<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import DataTable from '../../components/DataTable.vue';
import EmptyState from '../../components/EmptyState.vue';
import UnavailableNotice from '../../components/UnavailableNotice.vue';
import { dateTime } from './format';

export type ActivityRow = {
    source: string;
    id: string;
    occurred_at: string;
    event: string;
    reason: string;
    actor: string;
};
defineProps<{ caption: string; activity: ActivityRow[] | null }>();
const columns = [
    { key: 'event', label: 'Action' },
    { key: 'actor', label: 'Actor' },
    { key: 'reason', label: 'Reason' },
    { key: 'occurred_at', label: 'Occurred' },
];
</script>

<template>
    <UnavailableNotice
        v-if="activity === null"
        title="Activity is outside your capabilities"
        reason="Reviewing administration activity needs the audit review capability."
    />
    <EmptyState
        v-else-if="!activity.length"
        title="No administration activity recorded"
        description="Administrative actions that target this record appear here. Activity inside the workspace itself is not shown."
    />
    <DataTable
        v-else
        :caption="caption"
        :columns="columns"
        :rows="activity"
        :row-key="(row) => `${row.source}-${row.id}`"
    >
        <template #cell-event="{ row }"
            ><Link :href="`/admin/audit/${row.source}/${row.id}`">{{ row.event }}</Link></template
        >
        <template #cell-occurred_at="{ row }">{{ dateTime(row.occurred_at) }}</template>
    </DataTable>
</template>
