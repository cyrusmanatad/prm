<script setup lang="ts">
import AdminShell from '../layouts/AdminShell.vue';
import ContentSection from '../components/ContentSection.vue';
import CopyReference from '../components/CopyReference.vue';
import DataTable from '../components/DataTable.vue';
import DetailList from '../components/DetailList.vue';
import EmptyState from '../components/EmptyState.vue';
import AssignmentTable from '../features/admin/AssignmentTable.vue';
import BackLink from '../features/admin/BackLink.vue';
import { recordResourceLabel } from '../features/entitlements/resources';
import { dateTime } from '../features/admin/format';
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
    <AdminShell
        :title="`${plan.name} v${plan.version}`"
        description="An immutable plan version. Its limits cannot be edited; record a new assignment to create the next version."
        eyebrow="Plan version"
    >
        <template #back><BackLink href="/admin/plans" label="All plans" /></template>
        <ContentSection title="Plan version">
            <DetailList
                :items="[
                    { key: 'id', label: 'Plan ID' },
                    { key: 'name', label: 'Name', value: plan.name },
                    { key: 'version', label: 'Version', value: plan.version },
                    { key: 'created', label: 'Created', value: dateTime(plan.created_at) },
                ]"
            >
                <template #id><CopyReference :value="plan.id" label="plan ID" /></template>
            </DetailList>
        </ContentSection>
        <ContentSection
            title="Create limits"
            description="Explicit whole-number limits per workspace. Reaching a limit blocks new records; it never deletes existing ones."
        >
            <DataTable caption="Create limits" :columns="columns" :rows="limits" :row-key="(row) => row.resource">
                <template #cell-resource="{ row }">{{ recordResourceLabel(row.resource) }}</template>
            </DataTable>
        </ContentSection>
        <ContentSection title="Assignments using this version">
            <EmptyState v-if="!assignments.rows.length" title="No workspace has been assigned this version" />
            <AssignmentTable v-else caption="Assignments using this version" :rows="assignments.rows" link-workspace />
            <p v-if="assignments.total > assignments.rows.length" class="wd-hint">
                Showing the latest {{ assignments.rows.length }} of {{ assignments.total }} assignments.
            </p>
        </ContentSection>
    </AdminShell>
</template>
