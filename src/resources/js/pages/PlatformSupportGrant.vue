<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import CopyReference from '../components/CopyReference.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import FormField from '../components/FormField.vue';
import Pagination from '../components/Pagination.vue';
import StatusBadge from '../components/StatusBadge.vue';
import TabNav from '../components/TabNav.vue';
import { Button } from '../components/ui/button';
import AdminCard from '../features/admin/AdminCard.vue';
import BackLink from '../features/admin/BackLink.vue';
import FactList from '../features/admin/FactList.vue';
import FormDialog from '../features/admin/FormDialog.vue';
import { confirmAction } from '../composables/useConfirmation';
import { dateOnly, dateTime, supportAreas, supportStates, titleCase } from '../features/admin/format';
import { viewsFor, type SupportGrant } from '../features/admin/support';

type Row = Record<string, string | number | null>;
const props = defineProps<{
    grant: SupportGrant & { mine: boolean };
    area: string | null;
    records: { rows: Row[]; total: number } | null;
    page: number;
}>();
const base = computed(() => `/admin/support-access/${props.grant.id}`);
const active = computed(() => props.grant.state === 'approved');
const open = computed(() => ['requested', 'approved'].includes(props.grant.state));
const tabs = computed(() =>
    viewsFor(props.grant.resources).map((area) => ({
        href: `${base.value}?area=${area}`,
        label: supportAreas[area] ?? area,
        current: props.area === area,
    })),
);
const dates = new Set(['created_at', 'next_action_due', 'due_at', 'completed_at', 'expires_at']);
const columns = computed(() =>
    Object.keys(props.records?.rows[0] ?? {})
        .filter((key) => key !== 'id' && key !== 'currency')
        .map((key) => ({ key, label: titleCase(key.replace(/_minor$|_at$/, '')), numeric: key.endsWith('_minor') })),
);
function show(row: Row, key: string): string {
    const value = row[key];
    if (value === null || value === '') return '—';
    if (key.endsWith('_minor')) return `${(Number(value) / 100).toFixed(2)} ${row.currency ?? ''}`.trim();
    return dates.has(key) ? dateTime(String(value)) : String(value);
}
// Ending or withdrawing opens a dialog for the reason, then the shared Cancel-first confirmation.
const dialog = ref<InstanceType<typeof FormDialog>>();
const form = useForm({ expected_version: props.grant.version, reason: '' });
function begin(event: Event) {
    form.clearErrors();
    form.reason = '';
    form.expected_version = props.grant.version;
    dialog.value?.open(event.currentTarget as HTMLElement);
}
function end(event: SubmitEvent) {
    if (form.processing) return;
    confirmAction(
        active.value ? 'End support access' : 'Withdraw support request',
        active.value
            ? `End support access to ${props.grant.workspace} now? ${props.grant.operator} can no longer view its records. A new request needs a new approval from a workspace owner.`
            : `Withdraw the request for ${props.grant.workspace}? The workspace owners will no longer be able to approve it.`,
        () => form.post(`${base.value}/end`, { preserveScroll: true, onSuccess: () => dialog.value?.close() }),
        event,
        { tone: 'danger' },
    );
}
</script>

<template>
    <AdminShell :title="grant.workspace" compact>
        <template #back><BackLink href="/admin/support-access" label="All support access" /></template>
        <template #description
            ><span class="adm-meta"
                ><span
                    >Support access requested by {{ grant.operator }} · {{ dateOnly(grant.requested_at) }} ·
                    Reference</span
                ><CopyReference :value="grant.id" label="support reference" /></span
        ></template>
        <template #actions>
            <StatusBadge
                :label="supportStates[grant.state]?.label ?? grant.state"
                :tone="supportStates[grant.state]?.tone ?? 'neutral'"
            />
        </template>
        <p v-if="active && grant.mine" class="wd-warning rec-banner adm-support-banner" role="status">
            Support session: you are viewing {{ grant.workspace }} as {{ grant.operator }}, read-only, until
            {{ dateTime(grant.expires_at) }}. You are not signed in as a member, and every view is recorded for the
            workspace.
        </p>
        <AdminCard title="Request" note="View only · approved by a workspace owner">
            <FactList
                :items="[
                    { key: 'workspace', label: 'Workspace' },
                    { key: 'operator', label: 'Operator', value: grant.operator },
                    { key: 'duration', label: 'Duration', value: `${grant.hours} hours after approval` },
                    { key: 'requested', label: 'Requested', value: dateTime(grant.requested_at) },
                    {
                        key: 'decided',
                        label: 'Owner decision',
                        value: grant.decided_at
                            ? `${dateTime(grant.decided_at)} by ${grant.decided_by}`
                            : 'Waiting for a workspace owner',
                    },
                    { key: 'expires', label: 'Expires', value: dateTime(grant.expires_at, 'Not approved') },
                    {
                        key: 'scope',
                        label: 'Scope',
                        value: `View only: ${grant.resources.map((area) => supportAreas[area] ?? area).join(', ')}`,
                        wide: true,
                    },
                    { key: 'purpose', label: 'Purpose', value: grant.purpose, wide: true },
                    {
                        key: 'ended',
                        label: 'Ended',
                        value: grant.ended_at
                            ? `${dateTime(grant.ended_at)}${grant.ended_by ? ` by ${grant.ended_by}` : ''}: ${grant.end_reason}`
                            : 'Not ended early',
                        wide: true,
                    },
                ]"
            >
                <template #workspace
                    ><Link :href="`/admin/workspaces/${grant.org_id}`" class="wd-link">{{
                        grant.workspace
                    }}</Link></template
                >
            </FactList>
            <p v-if="grant.state === 'requested'" class="rec-fine">
                Only an owner of this workspace can approve, from Support access requests in their workspace settings.
                Nothing can be viewed until they do.
            </p>
            <div v-if="open" class="adm-action-row">
                <div>
                    <h3>{{ active ? 'End support access' : 'Withdraw request' }}</h3>
                    <p>
                        {{
                            active
                                ? `${grant.operator} can no longer view the records. A new request needs a new owner approval.`
                                : 'The workspace owners can no longer approve it.'
                        }}
                    </p>
                </div>
                <Button type="button" class="wd-button wd-button-danger-outline" @click="begin">{{
                    active ? 'End support access' : 'Withdraw request'
                }}</Button>
            </div>
        </AdminCard>
        <AdminCard
            v-if="active && grant.mine"
            title="Records in scope"
            note="Read-only · notes, template text, answers, documents and payment evidence are not shown · opening an area is recorded"
        >
            <div class="adm-panel-body"><TabNav label="Record areas in this grant" :tabs="tabs" /></div>
            <EmptyState
                v-if="!records"
                title="Choose an area to view"
                description="Only the areas the workspace owner approved are listed."
            />
            <EmptyState
                v-else-if="!records.rows.length"
                :title="`No ${(supportAreas[area!] ?? area!).toLowerCase()} in this workspace`"
            />
            <template v-else>
                <DataTable
                    class="rec-table adm-no-status"
                    :caption="supportAreas[area!] ?? area!"
                    :columns="columns"
                    :rows="records.rows"
                    :row-key="(row) => String(row.id)"
                >
                    <template v-for="column in columns" :key="column.key" #[`cell-${column.key}`]="{ row }">{{
                        show(row, column.key)
                    }}</template>
                </DataTable>
                <div class="rec-card-foot">
                    <Pagination
                        label="Record pages"
                        :page="page"
                        :per-page="25"
                        :count="records.rows.length"
                        :total="records.total"
                        numbered
                        :href-for="(target) => `${base}?area=${area}${target > 1 ? `&page=${target}` : ''}`"
                    />
                </div>
            </template>
        </AdminCard>
        <AdminCard v-else-if="active" title="Records in scope">
            <p class="rec-fine">Only {{ grant.operator }}, who holds this grant, can view the records it covers.</p>
        </AdminCard>
        <FormDialog
            v-if="open"
            id="support-end"
            ref="dialog"
            :title="active ? 'End support access' : 'Withdraw support request'"
            :description="`For ${grant.workspace}.`"
            :submit-label="active ? 'Review ending' : 'Review withdrawal'"
            tone="danger"
            :processing="form.processing"
            :errors="form.errors"
            @submit="end"
        >
            <p v-if="form.errors.expected_version" class="wd-banner rec-banner" role="alert">
                {{ form.errors.expected_version }}
                <Link :href="base" class="wd-link">Reload this request</Link>
            </p>
            <fieldset class="wd-fields" :disabled="form.processing">
                <legend class="wd-sr-only">{{ active ? 'End support access' : 'Withdraw support request' }}</legend>
                <FormField
                    id="support-end-reason"
                    v-model="form.reason"
                    label="Reason"
                    :maxlength="240"
                    :error="form.errors.reason"
                    hint="Recorded in the administration audit."
                />
            </fieldset>
        </FormDialog>
    </AdminShell>
</template>
