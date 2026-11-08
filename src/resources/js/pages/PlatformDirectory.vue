<script setup lang="ts">
import { recordResourceLabel } from '../features/entitlements/resources';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import ContentSection from '../components/ContentSection.vue';
import FormField from '../components/FormField.vue';
import SelectField from '../components/SelectField.vue';
import { Button } from '../components/ui/button';
import { confirmAction } from '../composables/useConfirmation';
const props = defineProps<{
    account: { name: string; email: string };
    workspaces: {
        id: string;
        name: string;
        kind: string;
        experience: string;
        members: number;
        assignment_version: number | null;
    }[];
    accounts: { id: number; name: string; email: string; email_verified_at: string | null }[];
    q: string;
    page: number;
    canAssign: boolean;
}>();
const query = ref(props.q);
watch(
    () => props.q,
    (value) => {
        query.value = value;
    },
);
const resources = [
    'pipeline_entries',
    'brand_contacts',
    'reminders',
    'onboarding_requests',
    'outreach_templates',
    'invoice_drafts',
    'manual_payments',
];
const form = useForm({
    workspace: '',
    expected_version: 0,
    name: '',
    starts_at: '',
    ends_at: '',
    reason: '',
    quotas: Object.fromEntries(resources.map((key) => [key, ''])),
});
function select(id: string) {
    form.workspace = id;
    form.expected_version = props.workspaces.find((row) => row.id === id)?.assignment_version ?? 0;
}
function assign(event: SubmitEvent) {
    if (form.processing) return;
    const workspace = props.workspaces.find((row) => row.id === form.workspace);
    if (!workspace) return;
    confirmAction(
        'Record subscription assignment',
        `Replace the current assignment for ${workspace.name} with ${form.name} from ${form.starts_at} to ${form.ends_at}? This may pause workspace access. Existing professional schedules, pending notices and approvals will be cancelled.`,
        () => form.post('/admin/assignments', { onSuccess: () => form.reset() }),
        event,
    );
}
</script>
<template>
    <Head title="Platform directory" />
    <FoundationShell :account-name="account.name" :account-email="account.email" @logout="router.post('/logout')"
        ><div class="wd-workspace-content wd-finance wd-finance-sections">
            <header class="wd-page-heading">
                <span class="wd-eyebrow">Platform administration</span>
                <h1>Accounts and workspaces</h1>
                <p class="wd-description">Workspace metadata and manual subscriptions.</p>
            </header>
            <ContentSection title="Directory"
                ><form @submit.prevent="router.get('/admin', { q: query })">
                    <FormField id="directory-search" v-model="query" label="Search names" :maxlength="120" /><Button
                        class="wd-button wd-button-secondary"
                        type="submit"
                        >Search</Button
                    >
                </form>
                <ul class="wd-workspace-list">
                    <li v-for="workspace in workspaces" :key="workspace.id">
                        <strong>{{ workspace.name }}</strong>
                        <p>
                            {{ workspace.kind }} · {{ workspace.experience }} · {{ workspace.members }} members ·
                            assignment {{ workspace.assignment_version ?? 0 }}
                        </p>
                    </li>
                </ul>
                <p v-if="!workspaces.length">No workspaces match this search.</p>
                <ul>
                    <li v-for="account in accounts" :key="account.id">
                        {{ account.name }} · {{ account.email }} ·
                        {{ account.email_verified_at ? 'Verified' : 'Unverified' }}
                    </li>
                </ul>
                <nav aria-label="Directory pages">
                    <Link
                        v-if="page > 1"
                        class="wd-link inline-flex min-h-11 items-center"
                        :href="`/admin?q=${encodeURIComponent(q)}&page=${page - 1}`"
                        >Previous</Link
                    ><Link
                        v-if="workspaces.length === 25 || accounts.length === 25"
                        class="wd-link inline-flex min-h-11 items-center"
                        :href="`/admin?q=${encodeURIComponent(q)}&page=${page + 1}`"
                        >Next</Link
                    >
                </nav>
            </ContentSection>
            <ContentSection
                v-if="canAssign"
                title="Assign or renew a subscription"
                description="Supply every quota explicitly. This records an immutable plan version and assignment; no payment is collected."
            >
                <form @submit.prevent="assign">
                    <fieldset class="wd-fields" :disabled="form.processing">
                        <legend class="wd-sr-only">Subscription assignment</legend>
                        <SelectField
                            id="assign-workspace"
                            :model-value="form.workspace"
                            label="Workspace"
                            :options="[
                                { value: '', label: 'Choose a workspace' },
                                ...workspaces.map((row) => ({ value: row.id, label: row.name })),
                            ]"
                            @update:model-value="select"
                        />
                        <FormField
                            id="assign-name"
                            v-model="form.name"
                            label="Plan name"
                            :error="form.errors.name"
                            :maxlength="120"
                        />
                        <FormField
                            id="assign-start"
                            v-model="form.starts_at"
                            label="Starts at (include timezone, e.g. 2026-11-07T09:00:00+08:00)"
                            :error="form.errors.starts_at"
                        />
                        <FormField
                            id="assign-end"
                            v-model="form.ends_at"
                            label="Ends at (include timezone)"
                            :error="form.errors.ends_at"
                        />
                        <FormField
                            v-for="resource in resources"
                            :id="`quota-${resource}`"
                            :key="resource"
                            v-model="form.quotas[resource]"
                            :label="`${recordResourceLabel(resource)} quota`"
                            :error="form.errors[`quotas.${resource}` as keyof typeof form.errors]"
                        />
                        <FormField
                            id="assign-reason"
                            v-model="form.reason"
                            label="Reason"
                            :maxlength="240"
                            :error="form.errors.reason"
                        />
                        <p v-for="(error, key) in form.errors" :key="key" class="wd-field-error" role="alert">
                            {{ error }}
                        </p>
                        <Link v-if="form.errors.expected_version" href="/admin" class="wd-link">Reload directory</Link>
                        <Button type="submit" class="wd-button wd-button-primary">Record assignment</Button>
                    </fieldset>
                </form>
            </ContentSection>
            <Link href="/admin/security" class="wd-link inline-flex min-h-11 items-center"
                >Verify administrator access</Link
            >
        </div></FoundationShell
    >
</template>
