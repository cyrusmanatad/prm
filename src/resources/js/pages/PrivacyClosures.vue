<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import FoundationShell from '../layouts/FoundationShell.vue';
import ContentSection from '../components/ContentSection.vue';
import SelectField from '../components/SelectField.vue';
import { Button } from '../components/ui/button';
import ClosureForm from '../features/privacy/ClosureForm.vue';
import ClosureCancellation from '../features/privacy/ClosureCancellation.vue';
import type { ClosurePreview, ClosureRequest } from '../types/privacy';
import type { AccountSummary } from '../types/foundation';
const props = defineProps<{
    preview: ClosurePreview | null;
    requests: ClosureRequest[];
    workspaces: { id: string; name: string }[];
    enrolled: boolean;
    accountClosing: boolean;
    selectedScope: string;
    selectedOrg: string | null;
}>();
const account = usePage<{ account?: AccountSummary | null }>().props.account;
const scope = ref(props.selectedScope);
const org = ref(props.selectedOrg ?? '');
const reviewing = ref(false);
watch(
    () => [props.selectedScope, props.selectedOrg] as const,
    ([selectedScope, selectedOrg]) => {
        scope.value = selectedScope;
        org.value = selectedOrg ?? '';
    },
);
function review(): void {
    if (reviewing.value) return;
    router.get(
        '/account/closure',
        { scope: scope.value, ...(scope.value === 'workspace' ? { org_id: org.value } : {}) },
        {
            preserveScroll: true,
            onStart: () => {
                reviewing.value = true;
            },
            onFinish: () => {
                reviewing.value = false;
            },
        },
    );
}
</script>
<template>
    <Head title="Account and workspace closure" />
    <FoundationShell :account-name="account?.name" :account-email="account?.email" @logout="router.post('/logout')">
        <div class="wd-workspace-content space-y-6">
            <header class="wd-page-heading">
                <span class="wd-eyebrow">Privacy requests</span>
                <h1>Account and workspace closure</h1>
                <p class="wd-description">
                    Review the impact before closing access. Records are retained for privacy review, with at least 30
                    days before erasure can begin.
                </p>
                <Link v-if="!accountClosing" href="/workspaces" class="wd-link">Back to workspaces</Link>
            </header>
            <ContentSection v-if="!accountClosing" title="Choose what to close">
                <form :aria-busy="reviewing" @submit.prevent="review">
                    <fieldset class="wd-fields" :disabled="reviewing">
                        <legend class="wd-sr-only">Choose closure scope</legend>
                        <SelectField
                            id="closure_scope"
                            v-model="scope"
                            label="Closure scope"
                            :searchable="false"
                            :options="[
                                { value: 'account', label: 'My account' },
                                { value: 'workspace', label: 'A workspace I own' },
                            ]"
                        />
                        <SelectField
                            v-if="scope === 'workspace'"
                            id="closure_workspace"
                            v-model="org"
                            label="Workspace"
                            :options="workspaces.map((workspace) => ({ value: workspace.id, label: workspace.name }))"
                            required
                        />
                        <Button
                            type="submit"
                            class="wd-button wd-button-secondary"
                            :disabled="scope === 'workspace' && !org"
                            >{{ reviewing ? 'Reviewing…' : 'Review closure' }}</Button
                        >
                    </fieldset>
                </form>
                <p class="wd-description">
                    <Link href="/account/exports" class="wd-link">Download an export</Link> before closing access.
                </p>
            </ContentSection>
            <ClosureForm
                v-if="preview && !accountClosing"
                :key="`${preview.scope}:${preview.org_id ?? ''}`"
                :preview="preview"
                :enrolled="enrolled"
            />
            <p v-if="accountClosing" class="wd-description" role="status">
                Your account is restricted to closure and security pages. You can cancel below while erasure has not
                begun.
            </p>
            <h2 v-if="requests.length" class="text-xl font-bold">Your closure requests</h2>
            <ClosureCancellation
                v-for="request in requests"
                :key="request.id"
                :request="request"
                :enrolled="enrolled"
                :account-closing="accountClosing"
            />
        </div>
    </FoundationShell>
</template>
