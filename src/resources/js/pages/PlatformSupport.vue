<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import FilterChips, { type FilterChip } from '../components/FilterChips.vue';
import FormField from '../components/FormField.vue';
import Pagination from '../components/Pagination.vue';
import SelectField from '../components/SelectField.vue';
import StatusBadge from '../components/StatusBadge.vue';
import UnavailableNotice from '../components/UnavailableNotice.vue';
import { Button } from '../components/ui/button';
import { useListFilters } from '../composables/useListFilters';
import { useValidationFocus } from '../composables/useValidationFocus';
import AppliedFilters from '../features/admin/AppliedFilters.vue';
import { dateOnly, dateTime, shortDateTime, supportAreas, supportStates } from '../features/admin/format';
import type { SupportGrant } from '../features/admin/support';

type Filters = { state: string; workspace: string };
const props = defineProps<{
    grants: { total: number; rows: SupportGrant[]; facets?: Record<string, number> };
    filters: Filters;
    page: number;
    areas: string[];
    target: { id: string; name: string; suspended: boolean } | null;
}>();
const { pending, clear, href } = useListFilters<Filters>('/admin/support-access', () => props.filters);
const applied = computed(() => [
    ...(props.filters.state
        ? [{ label: 'State', value: supportStates[props.filters.state]?.label ?? props.filters.state }]
        : []),
    ...(props.filters.workspace
        ? [{ label: 'Workspace', value: props.grants.rows[0]?.workspace ?? props.filters.workspace }]
        : []),
]);
/** Awaiting the owner and active first, because those are the ones that need attention. */
const chips = computed<FilterChip[]>(() => {
    const f = props.grants.facets;
    if (!f) return [];
    const order = ['requested', 'approved', 'declined', 'revoked', 'cancelled', 'expired'];
    return [
        { value: '', label: 'All', count: f.all ?? 0 },
        ...order.map((value) => ({ value, label: supportStates[value]?.label ?? value, count: f[value] ?? 0 })),
    ];
});
const columns = [
    { key: 'workspace', label: 'Workspace' },
    { key: 'status', label: 'Status' },
    { key: 'operator', label: 'Operator' },
    { key: 'resources', label: 'Scope' },
    { key: 'expires_at', label: 'Expires' },
];
const root = ref<HTMLElement>();
const form = useForm({ workspace: props.target?.id ?? '', purpose: '', hours: '4', resources: [] as string[] });
useValidationFocus(
    root,
    () => form.errors,
    () => !form.processing,
);
// Not destructive and not yet access: the workspace owner still has to approve, so no confirmation dialog.
function request() {
    if (!form.processing)
        form.transform((data) => ({ ...data, hours: Number(data.hours) })).post('/admin/support-access', {
            preserveScroll: true,
        });
}
</script>

<template>
    <AdminShell
        title="Support access"
        description="Time-limited, read-only access to a workspace's records for support. A workspace owner must approve every request; platform access alone never opens private records."
        compact
    >
        <section v-if="target" class="rec-card adm-panel" aria-labelledby="support-request-heading">
            <header class="rec-card-head">
                <h2 id="support-request-heading">Request access to {{ target.name }}</h2>
                <p class="rec-note">The workspace owners decide</p>
            </header>
            <UnavailableNotice
                v-if="target.suspended"
                title="This workspace is suspended"
                reason="Support access cannot be requested while a workspace is suspended, because its owners cannot open it to approve."
            />
            <form v-else ref="root" class="adm-panel-body" @submit.prevent="request">
                <p v-if="form.errors.workspace" class="wd-banner" role="alert">{{ form.errors.workspace }}</p>
                <fieldset class="wd-fields" :disabled="form.processing">
                    <legend class="wd-sr-only">Support access request</legend>
                    <FormField
                        id="support-purpose"
                        v-model="form.purpose"
                        label="Purpose"
                        :maxlength="240"
                        :error="form.errors.purpose"
                        hint="Shown to the workspace owners who decide. Say what you need to look at and why."
                    />
                    <fieldset class="adm-capabilities">
                        <legend>Record areas</legend>
                        <label v-for="area in areas" :key="area" class="wd-checkbox"
                            ><input v-model="form.resources" type="checkbox" :value="area" />{{
                                supportAreas[area] ?? area
                            }}</label
                        >
                        <p v-if="form.errors.resources" class="wd-field-error" role="alert">
                            {{ form.errors.resources }}
                        </p>
                    </fieldset>
                    <SelectField
                        id="support-hours"
                        v-model="form.hours"
                        label="Duration after approval"
                        :options="
                            ['1', '4', '8', '24'].map((value) => ({
                                value,
                                label: value === '1' ? '1 hour' : `${value} hours`,
                            }))
                        "
                        :error="form.errors.hours"
                    />
                    <p class="wd-hint">
                        View only: no edits, downloads or exports. Notes, template text, onboarding answers, documents
                        and payment evidence are never shown. Every view is recorded for the workspace and in the
                        administration audit.
                    </p>
                    <Button type="submit" class="wd-button wd-button-primary">Send request to workspace owners</Button>
                </fieldset>
            </form>
        </section>
        <section class="rec-card" aria-labelledby="support-list-heading">
            <header class="rec-card-head">
                <h2 id="support-list-heading">Requests and grants</h2>
                <span class="rec-count">{{ grants.total.toLocaleString('en-GB') }}</span>
                <p class="rec-note">Newest first · to request access, open a workspace</p>
            </header>
            <div v-if="chips.length" class="rec-chips">
                <FilterChips
                    label="Support access state"
                    compact
                    :chips="chips"
                    :current="filters.state"
                    :href-for="(state) => href(1, { state })"
                />
            </div>
            <AppliedFilters :applied="applied" :pending="pending" @clear="clear" />
            <EmptyState
                v-if="!grants.rows.length && applied.length"
                title="No requests match these filters"
                description="Change or clear the filters to see other requests."
            />
            <EmptyState
                v-else-if="!grants.rows.length"
                title="No support access has been requested"
                description="To request access, open a workspace under Workspaces and choose Request support access."
                ><Link href="/admin/workspaces" class="wd-link inline-flex min-h-11 items-center"
                    >Go to workspaces</Link
                ></EmptyState
            >
            <DataTable
                v-else
                class="rec-table"
                :class="{ 'is-pending': pending }"
                caption="Support requests and grants"
                :columns="columns"
                :rows="grants.rows"
                :row-key="(row) => row.id"
            >
                <template #cell-workspace="{ row }"
                    ><Link class="rec-row-title" :href="`/admin/support-access/${row.id}`">{{ row.workspace }}</Link
                    ><span class="rec-row-sub">Requested {{ dateOnly(row.requested_at) }}</span></template
                >
                <template #cell-status="{ row }"
                    ><StatusBadge
                        :label="supportStates[row.state]?.label ?? row.state"
                        :tone="supportStates[row.state]?.tone ?? 'neutral'"
                /></template>
                <template #cell-resources="{ row }"
                    ><span class="rec-ellipsis">{{
                        row.resources.map((area) => supportAreas[area] ?? area).join(', ')
                    }}</span></template
                >
                <template #cell-expires_at="{ row }"
                    ><time
                        v-if="row.expires_at"
                        class="adm-nowrap"
                        :datetime="row.expires_at"
                        :title="dateTime(row.expires_at)"
                        >{{ shortDateTime(row.expires_at) }}</time
                    ><template v-else>{{ row.hours }} h after approval</template></template
                >
            </DataTable>
            <div v-if="grants.rows.length || page > 1" class="rec-card-foot">
                <Pagination
                    label="Support access pages"
                    :page="page"
                    :per-page="25"
                    :count="grants.rows.length"
                    :total="grants.total"
                    noun="requests"
                    numbered
                    :href-for="href"
                />
            </div>
        </section>
    </AdminShell>
</template>
