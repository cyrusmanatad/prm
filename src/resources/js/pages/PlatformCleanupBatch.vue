<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, Undo2 } from '@lucide/vue';
import AdminShell from '../layouts/AdminShell.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import FilterChips, { type FilterChip } from '../components/FilterChips.vue';
import FormField from '../components/FormField.vue';
import RowActionMenu from '../components/RowActionMenu.vue';
import SelectField from '../components/SelectField.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { Button } from '../components/ui/button';
import BackLink from '../features/admin/BackLink.vue';
import FormDialog from '../features/admin/FormDialog.vue';
import { dateOnly, dateTime, recentTime, titleCase } from '../features/admin/format';

type Mark = {
    type: string;
    reference: string;
    name: string;
    email: string | null;
    evidence: string;
    marked_at: string;
    marked_by: string;
};
type Run = {
    id: string;
    created_at: string;
    eligible: number;
    blocked: number;
    protected: number;
    stale: boolean;
    created_by: string;
};
const props = defineProps<{
    batch: { id: string; name: string; reason: string; version: number; created_at: string; created_by: string };
    marks: Mark[];
    runs: Run[];
}>();
const base = `/admin/cleanup/${props.batch.id}`;
const page = usePage<{ errors?: Record<string, string> }>();
// A changed batch, or a rule that refused the dry run.
const errors = computed(() => page.props.errors ?? {});
const busy = ref(false);
const dialog = ref<InstanceType<typeof FormDialog>>();
const form = useForm({
    expected_version: props.batch.version,
    type: 'workspace',
    reference: '',
    evidence: '',
    remove: false,
});
function openMark() {
    form.clearErrors();
    form.reset('reference', 'evidence');
    dialog.value?.open();
}
function mark() {
    if (form.processing) return;
    form.expected_version = props.batch.version;
    form.post(`${base}/marks`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            form.reset('reference', 'evidence');
            dialog.value?.close();
        },
    });
}
// Unmarking deletes nothing and is undone by marking again, so it needs no confirmation.
function unmark(row: Mark) {
    if (busy.value) return;
    router.post(
        `${base}/marks`,
        { expected_version: props.batch.version, type: row.type, reference: row.reference, remove: true },
        { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) },
    );
}
function dryRun() {
    if (busy.value) return;
    router.post(
        `${base}/runs`,
        { expected_version: props.batch.version },
        { onStart: () => (busy.value = true), onFinish: () => (busy.value = false) },
    );
}

const view = ref('');
const chips = computed<FilterChip[]>(() => [
    { value: '', label: 'All', count: props.marks.length },
    { value: 'workspace', label: 'Workspaces', count: props.marks.filter((row) => row.type === 'workspace').length },
    { value: 'account', label: 'Accounts', count: props.marks.filter((row) => row.type === 'account').length },
]);
const shown = computed(() => (view.value ? props.marks.filter((row) => row.type === view.value) : props.marks));
const markColumns = [
    { key: 'name', label: 'Record' },
    { key: 'type', label: 'Type' },
    { key: 'evidence', label: 'Synthetic evidence' },
    { key: 'marked_at', label: 'Marked' },
    { key: 'actions', label: 'Actions' },
];
const runColumns = [
    { key: 'created_at', label: 'Dry run' },
    { key: 'state', label: 'Status' },
    { key: 'eligible', label: 'Eligible', numeric: true },
    { key: 'blocked', label: 'Blocked', numeric: true },
    { key: 'protected', label: 'Protected', numeric: true },
];
</script>

<template>
    <AdminShell :title="batch.name" compact>
        <template #back><BackLink href="/admin/cleanup" label="All fixture batches" /></template>
        <template #description
            >{{ batch.reason }} · created {{ dateOnly(batch.created_at) }} by {{ batch.created_by }}</template
        >
        <template #actions>
            <div class="rec-header-actions">
                <StatusBadge label="Preview — no data deleted" tone="warning" />
                <Button type="button" class="wd-button wd-button-secondary" :disabled="busy" @click="openMark"
                    ><Plus :size="16" aria-hidden="true" />Mark a record</Button
                >
                <Button
                    type="button"
                    class="wd-button wd-button-primary"
                    :disabled="busy || !marks.length"
                    @click="dryRun"
                    >Run a dry run</Button
                >
            </div>
        </template>
        <p v-if="errors.expected_version" class="wd-banner rec-banner" role="alert">
            {{ errors.expected_version }} <Link :href="base" class="wd-link">Reload this batch</Link>
        </p>
        <p v-else-if="errors.reason" class="wd-banner rec-banner" role="alert">
            {{ errors.reason }}
        </p>
        <section class="rec-card" aria-labelledby="marks-heading">
            <header class="rec-card-head">
                <h2 id="marks-heading">Marked records</h2>
                <span class="rec-count">{{ marks.length.toLocaleString('en-GB') }}</span>
                <p class="rec-note">
                    Only records listed here are considered · a workspace is blocked unless all its members are marked
                </p>
            </header>
            <div v-if="marks.length" class="rec-chips">
                <FilterChips
                    label="Show marked records"
                    compact
                    :chips="chips"
                    :current="view"
                    @select="view = $event"
                />
            </div>
            <EmptyState
                v-if="!marks.length"
                title="Nothing is marked yet"
                description="Mark an account or workspace using its ID from its page under Accounts or Workspaces. Then run a dry run."
            />
            <EmptyState v-else-if="!shown.length" title="No records of this type are marked" />
            <DataTable
                v-else
                class="rec-table adm-no-status"
                caption="Marked records"
                :columns="markColumns"
                :rows="shown"
                :row-key="(row) => `${row.type}-${row.reference}`"
            >
                <template #cell-name="{ row }"
                    ><Link
                        class="rec-row-title"
                        :href="`/admin/${row.type === 'account' ? 'accounts' : 'workspaces'}/${row.reference}`"
                        >{{ row.name }}</Link
                    ><span class="rec-row-sub">{{ row.email ?? row.reference }}</span></template
                >
                <template #cell-type="{ row }">{{ titleCase(row.type) }}</template>
                <template #cell-evidence="{ row }"
                    ><span class="rec-ellipsis" :title="row.evidence">{{ row.evidence }}</span></template
                >
                <template #cell-marked_at="{ row }"
                    ><time :datetime="row.marked_at" :title="dateTime(row.marked_at)">{{
                        recentTime(row.marked_at)
                    }}</time
                    ><span class="rec-row-sub">{{ row.marked_by }}</span></template
                >
                <template #cell-actions="{ row }"
                    ><RowActionMenu
                        :label="`Actions for ${row.name}`"
                        :disabled="busy"
                        :actions="[{ label: 'Remove from this batch', icon: Undo2, select: () => unmark(row) }]"
                /></template>
            </DataTable>
            <p v-if="marks.length" class="rec-fine">
                Removing a mark only takes the record out of this batch; nothing is deleted.
            </p>
        </section>
        <section class="rec-card" aria-labelledby="runs-heading">
            <header class="rec-card-head">
                <h2 id="runs-heading">Dry runs</h2>
                <span class="rec-count">{{ runs.length.toLocaleString('en-GB') }}</span>
                <p class="rec-note">
                    Each is an exact inventory at that moment · changing the marks puts earlier runs out of date
                </p>
            </header>
            <EmptyState
                v-if="!runs.length"
                title="No dry run yet"
                :description="
                    marks.length
                        ? 'Run a dry run to see what removing these records would involve. Nothing is deleted.'
                        : 'Mark at least one record before running.'
                "
            />
            <DataTable
                v-else
                class="rec-table"
                caption="Dry runs"
                :columns="runColumns"
                :rows="runs"
                :row-key="(row) => row.id"
            >
                <template #cell-created_at="{ row }"
                    ><Link class="rec-row-title" :href="`/admin/cleanup/runs/${row.id}`"
                        ><time :datetime="row.created_at" :title="dateTime(row.created_at)">{{
                            recentTime(row.created_at)
                        }}</time></Link
                    ><span class="rec-row-sub">{{ row.created_by }}</span></template
                >
                <template #cell-eligible="{ row }"
                    ><span class="adm-phone-only">Eligible </span>{{ row.eligible }}</template
                >
                <template #cell-blocked="{ row }"
                    ><span class="adm-phone-only">· Blocked </span>{{ row.blocked }}</template
                >
                <template #cell-protected="{ row }"
                    ><span class="adm-phone-only">· Protected </span>{{ row.protected }}</template
                >
                <template #cell-state="{ row }"
                    ><StatusBadge
                        :label="row.stale ? 'Out of date' : 'Current'"
                        :tone="row.stale ? 'warning' : 'success'"
                /></template>
            </DataTable>
        </section>
        <FormDialog
            id="mark-record"
            ref="dialog"
            title="Mark a record as synthetic"
            :description="`Adds it to ${batch.name}. Nothing is deleted.`"
            submit-label="Mark record"
            :processing="form.processing"
            :errors="form.errors"
            @submit="mark"
        >
            <p v-if="form.errors.expected_version" class="wd-banner rec-banner" role="alert">
                {{ form.errors.expected_version }} <Link :href="base" class="wd-link">Reload this batch</Link>
            </p>
            <fieldset class="wd-fields" :disabled="form.processing">
                <legend class="wd-sr-only">Mark a record</legend>
                <SelectField
                    id="mark-type"
                    v-model="form.type"
                    label="Record type"
                    :searchable="false"
                    :options="[
                        { value: 'workspace', label: 'Workspace' },
                        { value: 'account', label: 'Account' },
                    ]"
                />
                <FormField
                    id="mark-reference"
                    v-model="form.reference"
                    :label="form.type === 'account' ? 'Account ID' : 'Workspace ID'"
                    :maxlength="36"
                    :error="form.errors.reference"
                    hint="Copy it from the record's page under Accounts or Workspaces."
                />
                <FormField
                    id="mark-evidence"
                    v-model="form.evidence"
                    label="Evidence that it is synthetic"
                    :maxlength="240"
                    :error="form.errors.evidence"
                    hint="For example the test run that created it. A matching name or email is not evidence."
                />
            </fieldset>
        </FormDialog>
    </AdminShell>
</template>
