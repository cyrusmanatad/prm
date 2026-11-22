<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import AdminShell from '../layouts/AdminShell.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import FilterChips, { type FilterChip } from '../components/FilterChips.vue';
import FormField from '../components/FormField.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { Button } from '../components/ui/button';
import FormDialog from '../features/admin/FormDialog.vue';
import { batchRunState, type BatchRunState, type LatestRun } from '../features/admin/cleanup';
import { dateTime, recentTime } from '../features/admin/format';

type Batch = {
    id: string;
    name: string;
    reason: string;
    created_at: string;
    created_by: string;
    marks: number;
    runs: number;
    latest_run?: LatestRun | null;
};
const props = defineProps<{ batches: Batch[] }>();
const views: { value: '' | BatchRunState; label: string }[] = [
    { value: '', label: 'All' },
    { value: 'blocked', label: 'Blocked' },
    { value: 'stale', label: 'Out of date' },
    { value: 'none', label: 'No dry run' },
    { value: 'clear', label: 'Nothing blocked' },
];
// The Overview links here with ?view=blocked for batches whose current dry run has blocked records.
const requested = new URLSearchParams(usePage().url.split('?')[1] ?? '').get('view') ?? '';
const view = ref(views.some((option) => option.value === requested) ? requested : '');
const state = (row: Batch) => batchRunState(row.latest_run);
const chips = computed<FilterChip[]>(() =>
    views.map(({ value, label }) => ({
        value,
        label,
        count: value ? props.batches.filter((row) => state(row).key === value).length : props.batches.length,
    })),
);
const rows = computed(() =>
    view.value ? props.batches.filter((row) => state(row).key === view.value) : props.batches,
);
const columns = [
    { key: 'name', label: 'Batch' },
    { key: 'state', label: 'Status' },
    { key: 'marks', label: 'Marked records', numeric: true },
    { key: 'runs', label: 'Dry runs', numeric: true },
    { key: 'created_at', label: 'Created' },
];

const dialog = ref<InstanceType<typeof FormDialog>>();
const form = useForm({ name: '', reason: '' });
function create() {
    if (form.processing) return;
    // A new batch opens its own page; on an error the dialog stays open with the message beside the field.
    form.post('/admin/cleanup', { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <AdminShell
        title="Data cleanup"
        description="Identify synthetic test records and see exactly what removing them would involve. Preview only: nothing can be deleted here."
        compact
    >
        <template #actions>
            <Button type="button" class="wd-button wd-button-primary" @click="dialog?.open()"
                ><Plus :size="16" aria-hidden="true" />New fixture batch</Button
            >
        </template>
        <section class="rec-card" aria-labelledby="batches-heading">
            <header class="rec-card-head">
                <h2 id="batches-heading">Fixture batches</h2>
                <span class="rec-count">{{ batches.length.toLocaleString('en-GB') }}</span>
                <p class="rec-note">Newest first · status is the latest dry run</p>
            </header>
            <div v-if="batches.length" class="rec-chips">
                <FilterChips label="Show batches" compact :chips="chips" :current="view" @select="view = $event" />
            </div>
            <EmptyState
                v-if="!batches.length"
                title="No fixture batches yet"
                description="Create a batch, then mark the accounts and workspaces that belong to it."
            />
            <EmptyState
                v-else-if="!rows.length"
                title="No batches match"
                description="Choose another view to see other batches."
            />
            <DataTable
                v-else
                class="rec-table"
                caption="Fixture batches"
                :columns="columns"
                :rows="rows"
                :row-key="(row) => row.id"
            >
                <template #cell-name="{ row }"
                    ><Link class="rec-row-title" :href="`/admin/cleanup/${row.id}`">{{ row.name }}</Link
                    ><span class="rec-row-sub" :title="row.reason">{{ row.reason }}</span></template
                >
                <template #cell-state="{ row }"
                    ><StatusBadge :label="state(row).label" :tone="state(row).tone"
                /></template>
                <template #cell-created_at="{ row }"
                    ><time :datetime="row.created_at" :title="dateTime(row.created_at)">{{
                        recentTime(row.created_at)
                    }}</time
                    ><span class="rec-row-sub">{{ row.created_by }}</span></template
                >
            </DataTable>
            <p v-if="batches.length >= 100" class="rec-fine">Showing the newest 100 batches.</p>
            <p class="rec-fine">
                Records are marked one at a time with stated evidence, never by name or email pattern. Workspaces
                holding finance records are blocked, accounts with a platform grant are protected, and audit history is
                always kept. Executing a cleanup is a separately reviewed step that this console does not offer.
            </p>
        </section>
        <FormDialog
            id="new-batch"
            ref="dialog"
            title="New fixture batch"
            description="A named group of records you have evidence are synthetic, for example the data from one test run."
            submit-label="Create batch"
            :processing="form.processing"
            :errors="form.errors"
            @submit="create"
        >
            <fieldset class="wd-fields" :disabled="form.processing">
                <legend class="wd-sr-only">New fixture batch</legend>
                <FormField
                    id="batch-name"
                    v-model="form.name"
                    label="Batch name"
                    :maxlength="120"
                    :error="form.errors.name"
                />
                <FormField
                    id="batch-reason"
                    v-model="form.reason"
                    label="Purpose"
                    :maxlength="240"
                    :error="form.errors.reason"
                    hint="Recorded in the administration audit."
                />
            </fieldset>
        </FormDialog>
    </AdminShell>
</template>
