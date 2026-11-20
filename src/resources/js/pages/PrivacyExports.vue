<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import FoundationShell from '../layouts/FoundationShell.vue';
import ContentSection from '../components/ContentSection.vue';
import FormField from '../components/FormField.vue';
import SelectField from '../components/SelectField.vue';
import ActionGroup from '../components/ActionGroup.vue';
import { Button } from '../components/ui/button';
import { useValidationFocus } from '../composables/useValidationFocus';
import type { AccountSummary } from '../types/foundation';

import type { ExportReceipt } from '../types/privacy';
import ExportHistory from '../features/privacy/ExportHistory.vue';

const props = defineProps<{
    workspaces: { id: string; name: string }[];
    exports: ExportReceipt[];
    enrolled: boolean;
    verified: boolean;
}>();
const account = computed(() => usePage<{ account?: AccountSummary | null }>().props.account);
const root = ref<HTMLElement>();
const form = useForm({ scope: 'account', org_id: '', password: '', code: '' });
const downloading = ref<string | null>(null);
const downloadError = ref('');
const busy = computed(() => form.processing || downloading.value !== null);
const options = computed(() => props.workspaces.map((org) => ({ value: org.id, label: org.name })));
const errors = computed(() => form.errors as Record<string, string>);
useValidationFocus(
    root,
    () => errors.value,
    () => !form.processing,
);

function submit(verifyOnly = false): void {
    if (busy.value) return;
    form.post(verifyOnly ? '/account/exports/verify' : '/account/exports', {
        preserveScroll: true,
        onSuccess: () => form.defaults({ ...form.data(), password: '', code: '' }),
        onFinish: () => {
            form.password = '';
            form.code = '';
        },
    });
}
async function download(receipt: ExportReceipt): Promise<void> {
    if (busy.value) return;
    downloading.value = receipt.id;
    downloadError.value = '';
    try {
        const response = await fetch(`/account/exports/${receipt.id}/download`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok || !response.headers.get('Content-Type')?.includes('application/zip')) {
            downloadError.value =
                response.status === 409
                    ? 'Included data or access changed, or the archive is unavailable. Create a fresh export.'
                    : response.status === 410
                      ? 'This export is no longer available. Create a new export.'
                      : response.status === 422
                        ? 'Verify your identity again before downloading. If the export is too large, contact the privacy team.'
                        : 'This download is unavailable. Check your access and verify your identity again.';
            return;
        }
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = url;
        link.download = `weavdash-export-${receipt.id}.zip`;
        link.click();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch {
        downloadError.value = 'The download could not finish. Check your connection and try again.';
    } finally {
        downloading.value = null;
    }
}
</script>

<template>
    <Head title="Privacy exports" />
    <FoundationShell :account-name="account?.name" :account-email="account?.email" @logout="router.post('/logout')">
        <div ref="root" class="wd-workspace-content space-y-6">
            <header class="wd-page-heading">
                <span class="wd-eyebrow">Your data</span>
                <h1>Privacy exports</h1>
                <p class="wd-description">
                    Download a private copy of your data. Exporting keeps your saved records intact.
                </p>
                <Link href="/workspaces" class="wd-link">Back to workspaces</Link>
            </header>
            <ContentSection
                title="Create an export"
                description="Your archive contains JSON, CSV and eligible scanned documents. It is available for 24 hours."
            >
                <p class="wd-description">
                    Account exports include your profile and personal automation data within your current access.
                    Workspace exports include business records and finance, and require workspace ownership and an
                    authenticator. Other members’ private drafts and security credentials are excluded.
                </p>
                <form :aria-busy="form.processing" @submit.prevent="submit()">
                    <fieldset class="wd-fields" :disabled="busy">
                        <legend class="wd-sr-only">Export scope and identity verification</legend>
                        <SelectField
                            id="privacy_scope"
                            v-model="form.scope"
                            label="Export scope"
                            :searchable="false"
                            :options="[
                                { value: 'account', label: 'My account' },
                                { value: 'workspace', label: 'A workspace I own' },
                            ]"
                            :error="form.errors.scope"
                        />
                        <SelectField
                            v-if="form.scope === 'workspace'"
                            id="privacy_workspace"
                            v-model="form.org_id"
                            label="Workspace"
                            :options="options"
                            :error="form.errors.org_id"
                            required
                        />
                        <p v-if="form.scope === 'workspace' && !workspaces.length" class="wd-description">
                            You do not currently own an available workspace.
                        </p>
                        <p v-if="form.scope === 'workspace' && !enrolled" class="wd-description">
                            <Link href="/account/authenticator" class="wd-link">Set up your authenticator</Link> before
                            exporting a workspace.
                        </p>
                        <FormField
                            id="privacy_password"
                            v-model="form.password"
                            label="Current password"
                            type="password"
                            autocomplete="current-password"
                            :error="form.errors.password"
                            required
                        />
                        <FormField
                            v-if="enrolled"
                            id="privacy_code"
                            v-model="form.code"
                            label="Authenticator code"
                            autocomplete="one-time-code"
                            inputmode="numeric"
                            :error="form.errors.code"
                            required
                        />
                        <p v-if="errors.export || errors.verification" class="wd-field-error" role="alert">
                            {{ errors.export || errors.verification }}
                        </p>
                        <ActionGroup>
                            <Button
                                type="submit"
                                class="wd-button wd-button-primary"
                                :disabled="form.scope === 'workspace' && (!enrolled || !form.org_id)"
                                >{{ form.processing ? 'Preparing…' : 'Create private export' }}</Button
                            >
                            <Button type="button" class="wd-button wd-button-secondary" @click="submit(true)"
                                >Verify for downloads</Button
                            >
                        </ActionGroup>
                    </fieldset>
                </form>
                <p class="wd-description" role="status">
                    {{
                        verified
                            ? 'Identity verified for this session. Downloads recheck your access.'
                            : 'Enter your password and, if enrolled, a fresh authenticator code. Verification lasts 15 minutes.'
                    }}
                </p>
            </ContentSection>
            <ExportHistory
                :exports="exports"
                :workspaces="workspaces"
                :verified="verified"
                :busy="busy"
                :downloading="downloading"
                :download-error="downloadError"
                @download="download"
                @refresh="router.reload({ only: ['exports', 'verified', 'workspaces'] })"
            />
        </div>
    </FoundationShell>
</template>
