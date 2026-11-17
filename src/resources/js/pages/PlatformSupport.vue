<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import ContentSection from '../components/ContentSection.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import FilterBar from '../components/FilterBar.vue';
import FormField from '../components/FormField.vue';
import Pagination from '../components/Pagination.vue';
import SelectField from '../components/SelectField.vue';
import StatusBadge from '../components/StatusBadge.vue';
import UnavailableNotice from '../components/UnavailableNotice.vue';
import { Button } from '../components/ui/button';
import { useListFilters } from '../composables/useListFilters';
import { useValidationFocus } from '../composables/useValidationFocus';
import { dateTime, supportAreas, supportStates } from '../features/admin/format';
import type { SupportGrant } from '../features/admin/support';

type Filters = { state: string; workspace: string };
const props = defineProps<{
    grants: { total: number; rows: SupportGrant[] };
    filters: Filters;
    page: number;
    areas: string[];
    target: { id: string; name: string; suspended: boolean } | null;
}>();
const { draft, pending, submit, clear, href } = useListFilters<Filters>('/admin/support-access', () => props.filters);
const applied = computed(() => [
    ...(props.filters.state
        ? [{ label: 'State', value: supportStates[props.filters.state]?.label ?? props.filters.state }]
        : []),
    ...(props.filters.workspace
        ? [{ label: 'Workspace', value: props.grants.rows[0]?.workspace ?? props.filters.workspace }]
        : []),
]);
const columns = [
    { key: 'workspace', label: 'Workspace' },
    { key: 'operator', label: 'Operator' },
    { key: 'resources', label: 'Scope' },
    { key: 'state', label: 'State' },
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
        eyebrow="Governance"
    >
        <ContentSection v-if="target" :title="`Request access to ${target.name}`">
            <UnavailableNotice
                v-if="target.suspended"
                title="This workspace is suspended"
                reason="Support access cannot be requested while a workspace is suspended, because its owners cannot open it to approve."
            />
            <form v-else ref="root" @submit.prevent="request">
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
        </ContentSection>
        <ContentSection title="Find requests and grants">
            <FilterBar
                label="Filter support access"
                :applied="applied"
                :pending="pending"
                @submit="submit"
                @clear="clear"
            >
                <SelectField
                    id="support-state"
                    v-model="draft.state"
                    label="State"
                    :options="[
                        { value: '', label: 'Any' },
                        ...Object.entries(supportStates).map(([value, entry]) => ({ value, label: entry.label })),
                    ]"
                />
            </FilterBar>
        </ContentSection>
        <ContentSection title="Requests and grants">
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
                caption="Support requests and grants"
                :columns="columns"
                :rows="grants.rows"
                :row-key="(row) => row.id"
            >
                <template #cell-workspace="{ row }"
                    ><Link :href="`/admin/support-access/${row.id}`">{{ row.workspace }}</Link
                    ><span class="wd-data-secondary">Requested {{ dateTime(row.requested_at) }}</span></template
                >
                <template #cell-resources="{ row }">{{
                    row.resources.map((area) => supportAreas[area] ?? area).join(', ')
                }}</template>
                <template #cell-state="{ row }"
                    ><StatusBadge
                        :label="supportStates[row.state]?.label ?? row.state"
                        :tone="supportStates[row.state]?.tone ?? 'neutral'"
                /></template>
                <template #cell-expires_at="{ row }">{{
                    row.expires_at ? dateTime(row.expires_at) : `${row.hours} h after approval`
                }}</template>
            </DataTable>
            <Pagination
                label="Support access pages"
                :page="page"
                :per-page="25"
                :count="grants.rows.length"
                :total="grants.total"
                noun="requests"
                :href-for="href"
            />
        </ContentSection>
    </AdminShell>
</template>
