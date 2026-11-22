<script setup lang="ts">
import { ref } from 'vue';
import AdminShell from '../layouts/AdminShell.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import Pagination from '../components/Pagination.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { dateOnly, dateTime } from '../features/admin/format';
import PrivacyRequestDrawer from '../features/privacy/PrivacyRequestDrawer.vue';
import { privacyStatus, type ReviewRequest } from '../features/privacy/review';

type Summary = { awaiting: number; reviewed: number; cancelled: number; unassigned: number };
defineProps<{ listing: { total: number; summary?: Summary; rows: ReviewRequest[] }; page: number }>();
const selected = ref<ReviewRequest | null>(null);
let trigger: HTMLElement | null = null;
function open(request: ReviewRequest, event: Event): void {
    trigger = event.currentTarget as HTMLElement;
    selected.value = request;
}
function close(): void {
    selected.value = null;
    trigger?.focus();
}
const columns = [
    { key: 'subject', label: 'Closure' },
    { key: 'status', label: 'Status' },
    { key: 'requested_by', label: 'Requested by' },
    { key: 'handler', label: 'Handler' },
    { key: 'not_before', label: 'Earliest erasure' },
];
</script>

<template>
    <AdminShell
        title="Privacy requests"
        description="Assign and review closure requests. Private manifests and tenant content stay hidden, and nothing here erases records."
        compact
    >
        <ul v-if="listing.summary" class="rec-stats" aria-label="Privacy request summary">
            <li :class="{ 'is-alert': listing.summary.awaiting }">
                <span>Awaiting review</span><strong>{{ listing.summary.awaiting }}</strong
                ><small>{{ listing.summary.unassigned }} unassigned</small>
            </li>
            <li>
                <span>Review recorded</span><strong>{{ listing.summary.reviewed }}</strong
                ><small>Still in cooling-off</small>
            </li>
            <li>
                <span>Cancelled</span><strong>{{ listing.summary.cancelled }}</strong
                ><small>History kept</small>
            </li>
            <li>
                <span>All requests</span><strong>{{ listing.total }}</strong
                ><small>Account and workspace</small>
            </li>
        </ul>
        <section class="rec-card" aria-labelledby="privacy-heading">
            <header class="rec-card-head">
                <h2 id="privacy-heading">Closure requests</h2>
                <span class="rec-count">{{ listing.total.toLocaleString('en-GB') }}</span>
                <p class="rec-note">Open requests first, oldest first · 20 per page</p>
            </header>
            <EmptyState
                v-if="!listing.rows.length"
                title="No privacy requests"
                description="Account and workspace closure requests will appear here."
            />
            <DataTable
                v-else
                class="rec-table"
                caption="Privacy requests"
                :columns="columns"
                :rows="listing.rows"
                :row-key="(row) => row.id"
            >
                <template #cell-subject="{ row }"
                    ><button
                        type="button"
                        class="rec-row-title"
                        :aria-label="`${row.subject_label} — request ${row.id.slice(0, 8)}`"
                        @click="open(row, $event)"
                    >
                        {{ row.subject_label }}</button
                    ><span class="rec-row-sub"
                        >{{ row.scope === 'account' ? 'Account' : 'Workspace' }} closure · requested
                        {{ dateOnly(row.requested_at) }}</span
                    ></template
                >
                <template #cell-status="{ row }"
                    ><StatusBadge :label="privacyStatus(row).label" :tone="privacyStatus(row).tone"
                /></template>
                <template #cell-requested_by="{ row }"
                    >{{ row.requested_by
                    }}<span class="rec-row-sub adm-phone-hidden">{{ row.requester_email }}</span></template
                >
                <template #cell-handler="{ row }"
                    ><template v-if="row.handler">{{ row.handler }}</template
                    ><span v-else class="rec-fine">Unassigned</span></template
                >
                <template #cell-not_before="{ row }"
                    ><time :datetime="row.not_before" :title="dateTime(row.not_before)">{{
                        dateOnly(row.not_before)
                    }}</time></template
                >
            </DataTable>
            <div v-if="listing.rows.length || page > 1" class="rec-card-foot">
                <Pagination
                    label="Privacy request pages"
                    :page="page"
                    :per-page="20"
                    :count="listing.rows.length"
                    :total="listing.total"
                    noun="privacy requests"
                    numbered
                    :href-for="(next) => `/admin/privacy-requests?page=${next}`"
                />
            </div>
        </section>
        <PrivacyRequestDrawer :request="selected" @close="close" />
    </AdminShell>
</template>
