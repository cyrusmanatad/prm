<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import DataTable from '../../components/DataTable.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { dateOnly, dateTime, entitlementStates } from './format';
import type { AssignmentRow } from './types';

/** `compact` renders the records-table variant used inside compact list cards; detail pages keep the full table. */
const props = defineProps<{ caption: string; rows: AssignmentRow[]; linkWorkspace?: boolean; compact?: boolean }>();
const columns = computed(() =>
    props.compact
        ? [
              { key: 'workspace', label: 'Workspace' },
              { key: 'status', label: 'Status' },
              { key: 'plan', label: 'Plan' },
              { key: 'starts_at', label: 'Starts' },
              { key: 'ends_at', label: 'Ends' },
          ]
        : [
              { key: 'workspace', label: 'Workspace' },
              { key: 'plan', label: 'Plan' },
              { key: 'version', label: 'Assignment version', numeric: true },
              { key: 'state', label: 'State' },
              { key: 'starts_at', label: 'Starts' },
              { key: 'ends_at', label: 'Ends' },
          ],
);
const badge = (row: AssignmentRow) => ({
    label: entitlementStates[row.state]?.label ?? row.state,
    tone: entitlementStates[row.state]?.tone ?? 'neutral',
});
</script>

<template>
    <DataTable
        :class="{ 'rec-table': compact }"
        :caption="caption"
        :columns="columns"
        :rows="rows"
        :row-key="(row) => row.id"
    >
        <template #cell-workspace="{ row }"
            ><Link
                v-if="linkWorkspace"
                :class="{ 'rec-row-title': compact }"
                :href="`/admin/workspaces/${row.org_id}?tab=entitlements`"
                >{{ row.workspace }}</Link
            ><template v-else>{{ row.workspace }}</template
            ><span v-if="compact" class="rec-row-sub">Assignment v{{ row.version }}</span></template
        >
        <template #cell-plan="{ row }"
            ><Link :href="`/admin/plans/${row.plan_id}`">{{ row.plan }} v{{ row.plan_version }}</Link></template
        >
        <template #cell-status="{ row }"><StatusBadge :label="badge(row).label" :tone="badge(row).tone" /></template>
        <template #cell-state="{ row }"><StatusBadge :label="badge(row).label" :tone="badge(row).tone" /></template>
        <template #cell-starts_at="{ row }"
            ><time v-if="compact" :datetime="row.starts_at" :title="dateTime(row.starts_at)">{{
                dateOnly(row.starts_at)
            }}</time
            ><template v-else>{{ dateTime(row.starts_at) }}</template></template
        >
        <template #cell-ends_at="{ row }"
            ><time v-if="compact" :datetime="row.ends_at" :title="dateTime(row.ends_at)">{{
                dateOnly(row.ends_at)
            }}</time
            ><template v-else>{{ dateTime(row.ends_at) }}</template></template
        >
    </DataTable>
</template>
