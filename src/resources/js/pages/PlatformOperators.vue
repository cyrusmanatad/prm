<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Ban, Pencil, RotateCcw, Search } from '@lucide/vue';
import AdminShell from '../layouts/AdminShell.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import FilterChips, { type FilterChip } from '../components/FilterChips.vue';
import RowActionMenu, { type RowAction } from '../components/RowActionMenu.vue';
import StatusBadge from '../components/StatusBadge.vue';
import UnavailableNotice from '../components/UnavailableNotice.vue';
import OperatorAccessDialog, { type Operator } from '../features/admin/OperatorAccessDialog.vue';
import { capabilityNames as labels } from '../features/admin/format';

type Candidate = { id: number; name: string; email: string; verified: boolean };
const props = defineProps<{ operators: Operator[]; capabilities: string[]; candidate: Candidate | null }>();
const dialog = ref<InstanceType<typeof OperatorAccessDialog>>();

/** The list arrives whole, so the views, search and page size apply in the browser. */
const views: Record<string, (row: Operator) => boolean> = {
    '': () => true,
    active: (row) => !row.revoked_at,
    unenrolled: (row) => !row.revoked_at && !row.mfa_ready,
    revoked: (row) => !!row.revoked_at,
};
// All first, as before: revoked grants stay visible (listed last) right after a revocation.
const view = ref('');
const search = ref('');
const shown = ref(50);
const searched = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return needle
        ? props.operators.filter((row) => `${row.name} ${row.email}`.toLowerCase().includes(needle))
        : props.operators;
});
const chips = computed<FilterChip[]>(() => [
    { value: '', label: 'All', count: searched.value.length },
    { value: 'active', label: 'Active', count: searched.value.filter(views.active!).length },
    { value: 'unenrolled', label: 'No authenticator', count: searched.value.filter(views.unenrolled!).length },
    { value: 'revoked', label: 'Revoked', count: searched.value.filter(views.revoked!).length },
]);
/** Your own row, then the operator just changed (or reached from an account page), stay at the top. */
const pinned = ref<number | null>(props.candidate?.id ?? null);
const rank = (row: Operator) => (row.self ? 0 : row.user_id === pinned.value ? 1 : 2);
const matching = computed(() =>
    searched.value.filter(views[view.value] ?? views['']!).sort((a, b) => rank(a) - rank(b)),
);
const rows = computed(() => matching.value.slice(0, shown.value));
watch([view, search], () => (shown.value = 50));

const existing = computed(() => props.operators.find((row) => row.user_id === props.candidate?.id));
// Arriving from an account page: open the grant for that identity when it can receive one.
onMounted(() => {
    if (props.candidate?.verified && (!existing.value || existing.value.revoked_at))
        dialog.value?.open('grant', props.candidate, existing.value);
});
const target = (row: Operator) => ({ id: row.user_id, name: row.name, email: row.email });
function actions(row: Operator): RowAction[] {
    if (row.revoked_at)
        return [
            {
                label: 'Grant again',
                icon: RotateCcw,
                select: (trigger) => dialog.value?.open('grant', target(row), row, trigger),
            },
        ];
    return [
        {
            label: 'Change capabilities',
            icon: Pencil,
            select: (trigger) => dialog.value?.open('change', target(row), row, trigger),
        },
        {
            label: 'Revoke platform access',
            icon: Ban,
            danger: true,
            separated: true,
            select: (trigger) => dialog.value?.open('revoke', target(row), row, trigger),
        },
    ];
}
const status = (row: Operator) =>
    row.suspended
        ? { label: 'Account suspended', tone: 'danger' as const }
        : row.revoked_at
          ? { label: 'Revoked', tone: 'neutral' as const }
          : { label: 'Active', tone: 'success' as const };
const columns = [
    { key: 'name', label: 'Operator' },
    { key: 'status', label: 'Status' },
    { key: 'permissions', label: 'Capabilities' },
    { key: 'mfa', label: 'Authenticator' },
    { key: 'actions', label: 'Actions' },
];
</script>

<template>
    <AdminShell
        title="Platform access"
        description="Who can open administration, and with which capabilities. A workspace role never grants platform access, and platform access never opens private workspace records."
        compact
    >
        <section v-if="candidate && !candidate.verified" class="rec-card adm-panel" aria-labelledby="grant-heading">
            <header class="rec-card-head"><h2 id="grant-heading">Grant platform access</h2></header>
            <UnavailableNotice
                title="This account cannot receive platform access yet"
                :reason="`${candidate.name} (${candidate.email}) has not verified their email address. Only a verified identity can hold a grant.`"
            />
        </section>
        <section class="rec-card" aria-labelledby="operators-heading">
            <header class="rec-card-head">
                <h2 id="operators-heading">Operators</h2>
                <span class="rec-count">{{ operators.length.toLocaleString('en-GB') }}</span>
                <p class="rec-note">To grant access to someone new, open their account under Accounts</p>
            </header>
            <div class="rec-chips">
                <FilterChips label="Show operators" compact :chips="chips" :current="view" @select="view = $event" />
            </div>
            <div class="adm-toolbar" role="search" aria-label="Filter operators">
                <div class="adm-search">
                    <label for="operator-search" class="wd-sr-only">Operator name or email</label>
                    <Search :size="16" aria-hidden="true" />
                    <input
                        id="operator-search"
                        v-model="search"
                        type="search"
                        maxlength="120"
                        placeholder="Search name or email"
                        autocomplete="off"
                    />
                </div>
            </div>
            <EmptyState v-if="!operators.length" title="No platform grants exist" />
            <EmptyState
                v-else-if="!matching.length"
                title="No operators match"
                description="Change the search or choose another view."
            />
            <DataTable
                v-else
                class="rec-table"
                caption="Operators"
                :columns="columns"
                :rows="rows"
                :row-key="(row) => row.user_id"
            >
                <template #cell-name="{ row }"
                    ><Link class="rec-row-title" :href="`/admin/accounts/${row.user_id}?tab=security`">{{
                        row.name
                    }}</Link
                    ><span class="rec-row-sub">{{ row.self ? `${row.email} · This is you` : row.email }}</span
                    ><span v-if="row.self" class="adm-row-note"
                        >You cannot change your own grant{{
                            row.last_owner ? '. You are the last usable platform owner' : ''
                        }}.</span
                    ></template
                >
                <template #cell-status="{ row }"
                    ><StatusBadge :label="status(row).label" :tone="status(row).tone"
                /></template>
                <template #cell-permissions="{ row }"
                    ><span class="rec-ellipsis" :title="labels(row.permissions)">{{
                        labels(row.permissions)
                    }}</span></template
                >
                <template #cell-mfa="{ row }"
                    ><StatusBadge
                        :label="row.mfa_ready ? 'Ready' : 'Not enrolled'"
                        :tone="row.mfa_ready ? 'success' : 'warning'"
                /></template>
                <template #cell-actions="{ row }"
                    ><RowActionMenu v-if="!row.self" :label="`Actions for ${row.name}`" :actions="actions(row)"
                /></template>
            </DataTable>
            <div v-if="matching.length" class="rec-card-foot adm-more">
                <p role="status">
                    Showing {{ rows.length.toLocaleString('en-GB') }} of
                    {{ matching.length.toLocaleString('en-GB') }} operators
                </p>
                <button v-if="rows.length < matching.length" type="button" class="wd-table-button" @click="shown += 50">
                    Show {{ Math.min(50, matching.length - rows.length) }} more
                </button>
            </div>
            <p class="rec-fine adm-panel-body">
                The first platform owner, and recovery of a lost authenticator, are handled by the offline procedure.
            </p>
        </section>
        <OperatorAccessDialog
            ref="dialog"
            :capabilities="capabilities"
            :operators="operators"
            @saved="pinned = $event"
        />
    </AdminShell>
</template>
