<script setup lang="ts">
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import ContentSection from '../components/ContentSection.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import FormField from '../components/FormField.vue';
import SelectField from '../components/SelectField.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { Button } from '../components/ui/button';
import BackLink from '../features/admin/BackLink.vue';
import { useValidationFocus } from '../composables/useValidationFocus';
import { dateTime, titleCase } from '../features/admin/format';

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
const root = ref<HTMLElement>();
const busy = ref(false);
const form = useForm({
    expected_version: props.batch.version,
    type: 'workspace',
    reference: '',
    evidence: '',
    remove: false,
});
useValidationFocus(
    root,
    () => form.errors,
    () => !form.processing,
);
function mark() {
    if (form.processing) return;
    form.expected_version = props.batch.version;
    form.post(`${base}/marks`, { preserveScroll: true, onSuccess: () => form.reset('reference', 'evidence') });
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
const markColumns = [
    { key: 'name', label: 'Record' },
    { key: 'type', label: 'Type' },
    { key: 'evidence', label: 'Synthetic evidence' },
    { key: 'marked_at', label: 'Marked' },
    { key: 'actions', label: 'Actions' },
];
const runColumns = [
    { key: 'created_at', label: 'Dry run' },
    { key: 'eligible', label: 'Eligible', numeric: true },
    { key: 'blocked', label: 'Blocked', numeric: true },
    { key: 'protected', label: 'Protected', numeric: true },
    { key: 'state', label: 'State' },
];
</script>

<template>
    <AdminShell :title="batch.name" :description="batch.reason" eyebrow="Fixture batch">
        <template #back><BackLink href="/admin/cleanup" label="All fixture batches" /></template>
        <template #actions><StatusBadge label="Preview — no data deleted" tone="warning" /></template>
        <p v-if="$page.props.errors?.expected_version" class="wd-banner" role="alert">
            {{ $page.props.errors.expected_version }} <Link :href="base" class="wd-link">Reload this batch</Link>
        </p>
        <ContentSection
            title="Marked records"
            description="Only records listed here are ever considered. A workspace is blocked unless all its members are marked too."
        >
            <EmptyState
                v-if="!marks.length"
                title="Nothing is marked yet"
                description="Add an account or workspace below using its ID from the Directory."
            />
            <DataTable
                v-else
                caption="Marked records"
                :columns="markColumns"
                :rows="marks"
                :row-key="(row) => `${row.type}-${row.reference}`"
            >
                <template #cell-name="{ row }"
                    ><Link :href="`/admin/${row.type === 'account' ? 'accounts' : 'workspaces'}/${row.reference}`">{{
                        row.name
                    }}</Link
                    ><span class="wd-data-secondary">{{ row.email ?? row.reference }}</span></template
                >
                <template #cell-type="{ row }">{{ titleCase(row.type) }}</template>
                <template #cell-marked_at="{ row }"
                    >{{ dateTime(row.marked_at) }}<span class="wd-data-secondary">{{ row.marked_by }}</span></template
                >
                <template #cell-actions="{ row }"
                    ><Button
                        type="button"
                        class="wd-button wd-button-secondary"
                        :disabled="busy"
                        :ariaLabel="`Remove ${row.name} from this batch`"
                        @click="unmark(row)"
                        >Remove</Button
                    ></template
                >
            </DataTable>
        </ContentSection>
        <ContentSection title="Mark a record as synthetic">
            <form ref="root" @submit.prevent="mark">
                <fieldset class="wd-fields" :disabled="form.processing">
                    <legend class="wd-sr-only">Mark a record</legend>
                    <SelectField
                        id="mark-type"
                        v-model="form.type"
                        label="Record type"
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
                        hint="Copy it from the record's page in the Directory."
                    />
                    <FormField
                        id="mark-evidence"
                        v-model="form.evidence"
                        label="Evidence that it is synthetic"
                        :maxlength="240"
                        :error="form.errors.evidence"
                        hint="For example the test run that created it. A matching name or email is not evidence."
                    />
                    <Button type="submit" class="wd-button wd-button-secondary">Mark record</Button>
                </fieldset>
            </form>
        </ContentSection>
        <ContentSection
            title="Dry runs"
            description="Each dry run is an exact, unchangeable inventory of this batch at that moment. Changing the marks makes earlier runs out of date."
        >
            <Button type="button" class="wd-button wd-button-primary" :disabled="busy || !marks.length" @click="dryRun"
                >Run a dry run</Button
            >
            <p v-if="!marks.length" class="wd-hint">Mark at least one record before running.</p>
            <DataTable
                v-if="runs.length"
                caption="Dry runs"
                :columns="runColumns"
                :rows="runs"
                :row-key="(row) => row.id"
            >
                <template #cell-created_at="{ row }"
                    ><Link :href="`/admin/cleanup/runs/${row.id}`">{{ dateTime(row.created_at) }}</Link
                    ><span class="wd-data-secondary">{{ row.created_by }}</span></template
                >
                <template #cell-state="{ row }"
                    ><StatusBadge
                        :label="row.stale ? 'Out of date' : 'Current'"
                        :tone="row.stale ? 'warning' : 'success'"
                /></template>
            </DataTable>
        </ContentSection>
    </AdminShell>
</template>
