<script setup lang="ts">
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import ContentSection from '../components/ContentSection.vue';
import DataTable from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import FormField from '../components/FormField.vue';
import UnavailableNotice from '../components/UnavailableNotice.vue';
import { Button } from '../components/ui/button';
import { useValidationFocus } from '../composables/useValidationFocus';
import { dateTime } from '../features/admin/format';

type Batch = {
    id: string;
    name: string;
    reason: string;
    created_at: string;
    created_by: string;
    marks: number;
    runs: number;
};
defineProps<{ batches: Batch[] }>();
const root = ref<HTMLElement>();
const form = useForm({ name: '', reason: '' });
useValidationFocus(
    root,
    () => form.errors,
    () => !form.processing,
);
const columns = [
    { key: 'name', label: 'Batch' },
    { key: 'marks', label: 'Marked records', numeric: true },
    { key: 'runs', label: 'Dry runs', numeric: true },
    { key: 'created_at', label: 'Created' },
];
</script>

<template>
    <AdminShell
        title="Data cleanup"
        description="Identify synthetic test records and see exactly what removing them would involve. This console produces dry runs only."
        eyebrow="Governance"
    >
        <ContentSection title="What this does">
            <UnavailableNotice
                title="Preview only — no data can be deleted here"
                reason="A dry run lists what a cleanup would touch and what blocks it. Executing a cleanup is a separately reviewed step that this console does not offer."
            />
            <ul>
                <li>
                    Records are marked one at a time with stated evidence. Nothing is selected by name or email pattern.
                </li>
                <li>Workspaces holding finance records are blocked. Audit history is always kept.</li>
                <li>Accounts that hold a platform grant are always protected.</li>
            </ul>
        </ContentSection>
        <ContentSection title="Fixture batches">
            <EmptyState
                v-if="!batches.length"
                title="No fixture batches yet"
                description="Create a batch below, then mark the accounts and workspaces that belong to it."
            />
            <DataTable v-else caption="Fixture batches" :columns="columns" :rows="batches" :row-key="(row) => row.id">
                <template #cell-name="{ row }"
                    ><Link :href="`/admin/cleanup/${row.id}`">{{ row.name }}</Link
                    ><span class="wd-data-secondary">{{ row.reason }}</span></template
                >
                <template #cell-created_at="{ row }"
                    >{{ dateTime(row.created_at) }}<span class="wd-data-secondary">{{ row.created_by }}</span></template
                >
            </DataTable>
        </ContentSection>
        <ContentSection
            title="Create a fixture batch"
            description="A batch is a named group of records you have evidence are synthetic, for example the data from one test run."
        >
            <form ref="root" @submit.prevent="form.post('/admin/cleanup')">
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
                    <Button type="submit" class="wd-button wd-button-primary">Create batch</Button>
                </fieldset>
            </form>
        </ContentSection>
    </AdminShell>
</template>
