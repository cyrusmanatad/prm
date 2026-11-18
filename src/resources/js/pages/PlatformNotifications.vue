<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import ContentSection from '../components/ContentSection.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import FilterBar from '../components/FilterBar.vue';
import FormField from '../components/FormField.vue';
import Pagination from '../components/Pagination.vue';
import SelectField from '../components/SelectField.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { useListFilters } from '../composables/useListFilters';
import { resolutionOptions as resolutions, type DeliveryRow } from '../features/platform-notifications/types';
import { dateTime, notificationStates as states } from '../features/admin/format';

type Filters = { q: string; workspace: string; state: string };
const props = defineProps<{
    notices: DeliveryRow[];
    total: number;
    workspace: string;
    q: string;
    state: string;
    page: number;
    canResolve: boolean;
}>();
const filters = computed<Filters>(() => ({ q: props.q, workspace: props.workspace, state: props.state }));
const { draft, pending, submit, clear, href } = useListFilters<Filters>('/admin/notifications', () => filters.value);
const applied = computed(() => [
    ...(props.q ? [{ label: 'Search', value: props.q }] : []),
    ...(props.workspace ? [{ label: 'Workspace', value: props.notices[0]?.workspace ?? props.workspace }] : []),
    ...(props.state ? [{ label: 'State', value: states[props.state]?.label ?? props.state }] : []),
]);
const columns = [
    { key: 'kind', label: 'Notification' },
    { key: 'workspace', label: 'Workspace or account' },
    { key: 'state', label: 'State' },
    { key: 'created_at', label: 'Created' },
    { key: 'next', label: 'Next step' },
];
function next(notice: DeliveryRow): string {
    if (notice.resolution)
        return `Resolved: ${resolutions.find((option) => option.value === notice.resolution)?.label ?? notice.resolution}`;
    if (notice.state === 'uncertain') return props.canResolve ? 'Review delivery' : 'Awaiting review';
    return 'None';
}
</script>

<template>
    <AdminShell
        title="Notifications"
        description="Delivery state for notification intents. Message bodies, recipients and links are never shown, and nothing is resent from here."
        eyebrow="Operations"
    >
        <ContentSection title="Find deliveries">
            <FilterBar
                label="Filter deliveries"
                :applied="applied"
                :pending="pending"
                submit-label="Filter deliveries"
                @submit="submit"
                @clear="clear"
            >
                <FormField
                    id="delivery-search"
                    v-model="draft.q"
                    label="Workspace or account name, workspace ID or notification ID"
                    :required="false"
                    :maxlength="120"
                />
                <SelectField
                    id="delivery-state"
                    v-model="draft.state"
                    label="Delivery state"
                    :options="[
                        { value: '', label: 'All states' },
                        ...Object.entries(states).map(([value, entry]) => ({ value, label: entry.label })),
                    ]"
                />
            </FilterBar>
        </ContentSection>
        <ContentSection
            title="Delivery history"
            description="Newest first. Accepted by transport does not confirm that a recipient received the message."
        >
            <EmptyState
                v-if="!notices.length && applied.length"
                title="No deliveries match these filters"
                description="Change or clear the filters to see other deliveries."
            />
            <EmptyState
                v-else-if="!notices.length"
                title="No deliveries recorded"
                description="Notification intents appear here once a workspace or a review decision queues an email."
            />
            <DataTable v-else caption="Delivery history" :columns="columns" :rows="notices" :row-key="(row) => row.id">
                <template #cell-kind="{ row }"
                    ><Link
                        :href="`/admin/notifications/${row.id}`"
                        v-bind="{ 'aria-label': `${row.kind}, notification ${row.id}` }"
                        >{{ row.kind }}</Link
                    ><span class="wd-data-secondary">{{ row.id.slice(0, 8) }}…</span></template
                >
                <template #cell-workspace="{ row }">{{ row.workspace ?? `Account: ${row.account}` }}</template>
                <template #cell-state="{ row }">
                    <StatusBadge
                        :label="states[row.state]?.label ?? row.state"
                        :tone="states[row.state]?.tone ?? 'neutral'"
                    />
                </template>
                <template #cell-created_at="{ row }">{{ dateTime(row.created_at) }}</template>
                <template #cell-next="{ row }">{{ next(row) }}</template>
            </DataTable>
            <Pagination
                label="Delivery history pages"
                :page="page"
                :per-page="25"
                :count="notices.length"
                :total="total"
                noun="deliveries"
                :href-for="href"
            />
        </ContentSection>
    </AdminShell>
</template>
