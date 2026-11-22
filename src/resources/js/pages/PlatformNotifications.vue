<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import FilterChips, { type FilterChip } from '../components/FilterChips.vue';
import Pagination from '../components/Pagination.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { useListFilters } from '../composables/useListFilters';
import { resolutionOptions as resolutions, type DeliveryRow } from '../features/platform-notifications/types';
import AppliedFilters from '../features/admin/AppliedFilters.vue';
import ListToolbar from '../features/admin/ListToolbar.vue';
import { dateTime, notificationKindLabel, notificationStates as states, recentTime } from '../features/admin/format';

type Filters = { q: string; workspace: string; state: string };
type Facets = Record<'all' | 'pending' | 'sending' | 'sent' | 'captured' | 'cancelled' | 'uncertain', number>;
const props = defineProps<{
    notices: DeliveryRow[];
    total: number;
    facets?: Facets;
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
/** One chip per delivery state, uncertain first after All because it is the one that needs review. */
const chips = computed<FilterChip[]>(() => {
    const f = props.facets;
    if (!f) return [];
    const order = ['uncertain', 'pending', 'sending', 'sent', 'captured', 'cancelled'] as const;
    return [
        { value: '', label: 'All', count: f.all },
        ...order.map((value) => ({ value, label: states[value]?.label ?? value, count: f[value] })),
    ];
});
const columns = [
    { key: 'kind', label: 'Notification' },
    { key: 'workspace', label: 'Workspace or account' },
    { key: 'status', label: 'Status' },
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
        compact
    >
        <section class="rec-card" aria-labelledby="deliveries-heading">
            <header class="rec-card-head">
                <h2 id="deliveries-heading">Delivery history</h2>
                <span class="rec-count">{{ total.toLocaleString('en-GB') }}</span>
                <p class="rec-note">Newest first · accepted by transport does not prove receipt</p>
            </header>
            <div v-if="chips.length" class="rec-chips">
                <FilterChips
                    label="Delivery state"
                    compact
                    :chips="chips"
                    :current="state"
                    :href-for="(value) => href(1, { state: value })"
                />
            </div>
            <ListToolbar
                id="delivery"
                v-model:q="draft.q"
                label="Filter deliveries"
                search-label="Workspace or account name, workspace ID or notification ID"
                placeholder="Search workspace, account or notification ID"
                :pending="pending"
                @submit="submit"
            />
            <AppliedFilters :applied="applied" :pending="pending" @clear="clear" />
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
            <DataTable
                v-else
                class="rec-table"
                :class="{ 'is-pending': pending }"
                caption="Delivery history"
                :columns="columns"
                :rows="notices"
                :row-key="(row) => row.id"
            >
                <template #cell-kind="{ row }"
                    ><Link
                        class="rec-row-title"
                        :href="`/admin/notifications/${row.id}`"
                        v-bind="{ 'aria-label': `${notificationKindLabel(row.kind)}, notification ${row.id}` }"
                        >{{ notificationKindLabel(row.kind) }}</Link
                    ><span class="rec-row-sub">{{ row.id.slice(0, 8) }}…</span></template
                >
                <template #cell-workspace="{ row }">{{ row.workspace ?? `Account: ${row.account}` }}</template>
                <template #cell-status="{ row }">
                    <StatusBadge
                        :label="states[row.state]?.label ?? row.state"
                        :tone="states[row.state]?.tone ?? 'neutral'"
                    />
                </template>
                <template #cell-created_at="{ row }"
                    ><time :datetime="row.created_at" :title="dateTime(row.created_at)">{{
                        recentTime(row.created_at)
                    }}</time></template
                >
                <template #cell-next="{ row }"
                    ><span :class="{ 'rec-fine adm-phone-hidden': next(row) === 'None' }">{{
                        next(row)
                    }}</span></template
                >
            </DataTable>
            <div v-if="notices.length || page > 1" class="rec-card-foot">
                <Pagination
                    label="Delivery history pages"
                    :page="page"
                    :per-page="25"
                    :count="notices.length"
                    :total="total"
                    noun="deliveries"
                    numbered
                    :href-for="href"
                />
            </div>
        </section>
    </AdminShell>
</template>
