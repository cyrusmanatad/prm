<script setup lang="ts">
import AdminShell from '../layouts/AdminShell.vue';
import CopyReference from '../components/CopyReference.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import AdminCard from '../features/admin/AdminCard.vue';
import AssignmentTable from '../features/admin/AssignmentTable.vue';
import BackLink from '../features/admin/BackLink.vue';
import FactList from '../features/admin/FactList.vue';
import { recordResourceLabel } from '../features/entitlements/resources';
import { dateOnly, dateTime } from '../features/admin/format';
import { quotaResources, type AssignmentRow } from '../features/admin/types';

const props = defineProps<{
    plan: { id: string; name: string; version: number; quotas: Record<string, number>; created_at: string };
    assignments: { total: number; rows: AssignmentRow[] };
}>();
// A resource missing from an assigned plan has a limit of zero, not "unlimited".
const limits = quotaResources.map((resource) => ({ resource, limit: props.plan.quotas[resource] ?? 0 }));
const columns = [
    { key: 'resource', label: 'Resource' },
    { key: 'limit', label: 'Create limit', numeric: true },
];
</script>

<template>
    <AdminShell :title="`${plan.name} v${plan.version}`" compact>
        <template #back><BackLink href="/admin/plans" label="All plans" /></template>
        <template #description
            ><span class="adm-meta"
                ><span>Immutable plan version · Created {{ dateOnly(plan.created_at) }} · Plan ID</span
                ><CopyReference :value="plan.id" label="plan ID" /></span
        ></template>
        <AdminCard
            title="Plan version"
            note="Limits can't be edited; record a new assignment to create the next version"
        >
            <FactList
                :items="[
                    { key: 'name', label: 'Name', value: plan.name },
                    { key: 'version', label: 'Version', value: plan.version },
                    { key: 'created', label: 'Created', value: dateTime(plan.created_at) },
                    { key: 'assigned', label: 'Assignments', value: assignments.total },
                ]"
            />
        </AdminCard>
        <AdminCard
            title="Create limits"
            :count="limits.length"
            note="Per workspace · reaching a limit blocks new records and never deletes existing ones"
        >
            <DataTable
                class="rec-table adm-no-status"
                caption="Create limits"
                :columns="columns"
                :rows="limits"
                :row-key="(row) => row.resource"
            >
                <template #cell-resource="{ row }"
                    ><span class="rec-row-name">{{ recordResourceLabel(row.resource) }}</span></template
                >
            </DataTable>
        </AdminCard>
        <AdminCard title="Assignments using this version" :count="assignments.total">
            <EmptyState v-if="!assignments.rows.length" title="No workspace has been assigned this version" />
            <AssignmentTable
                v-else
                compact
                caption="Assignments using this version"
                :rows="assignments.rows"
                link-workspace
            />
            <p v-if="assignments.total > assignments.rows.length" class="rec-fine">
                Showing the latest {{ assignments.rows.length }} of {{ assignments.total }} assignments.
            </p>
        </AdminCard>
    </AdminShell>
</template>
