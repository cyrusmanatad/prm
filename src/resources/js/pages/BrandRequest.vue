<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import FoundationShell from '../layouts/FoundationShell.vue';
import ContentSection from '../components/ContentSection.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { Button } from '../components/ui/button';
import { useValidationFocus } from '../composables/useValidationFocus';
import BrandRequestFields from '../features/brand-requests/BrandRequestFields.vue';
import RequestHistory from '../features/brand-requests/RequestHistory.vue';
import SubmissionDetails from '../features/brand-requests/SubmissionDetails.vue';
import { brandStatuses, type BrandRequest } from '../features/brand-requests/types';

const props = defineProps<{ request: BrandRequest }>();
const page = usePage<{ account?: { name: string; email: string } | null }>();
const account = computed(() => page.props.account);
const state = computed(() => brandStatuses[props.request.status]);
const feedback = computed(
    () => props.request.history?.find((entry) => entry.feedback && entry.event !== 'approved')?.feedback ?? null,
);
const root = ref<HTMLElement>();
const form = useForm({
    brand_name: props.request.brand_name ?? '',
    website: props.request.website ?? '',
    applicant_role: props.request.applicant_role ?? '',
    intended_use: props.request.intended_use ?? '',
    expected_version: props.request.version,
});
// After a reload the form targets the version now shown; what was typed is kept.
watch(
    () => props.request.version,
    (version) => (form.expected_version = version),
);
useValidationFocus(
    root,
    () => form.errors,
    () => !form.processing,
);
function resubmit() {
    if (!form.processing) form.patch(`/brand/requests/${props.request.id}`, { preserveScroll: true });
}
const opening = ref(false);
// Opening is the applicant's own choice; approval never switches their active workspace for them.
function openWorkspace() {
    if (opening.value || !props.request.workspace) return;
    router.post(
        '/workspace/select',
        { org_id: props.request.workspace.id },
        {
            onStart: () => (opening.value = true),
            onSuccess: () => router.visit('/brand'),
            onFinish: () => (opening.value = false),
        },
    );
}
</script>

<template>
    <Head :title="request.brand_name ?? 'Brand workspace request'" />
    <FoundationShell
        :account-name="account?.name"
        :account-email="account?.email"
        portal="brand"
        @logout="router.post('/logout')"
    >
        <div class="wd-workspace-content wd-finance-sections">
            <header class="wd-page-heading">
                <span class="wd-eyebrow">Brand workspace request</span>
                <h1>{{ request.brand_name }}</h1>
                <p><StatusBadge :label="state.label" :tone="state.tone" /></p>
                <p class="wd-description">{{ state.meaning }}</p>
            </header>
            <ContentSection v-if="request.status === 'approved' && request.workspace" title="Your Brand workspace">
                <p v-if="request.workspace.member">
                    {{ request.workspace.name }} is ready. It also appears in your workspace list.
                </p>
                <p v-else>You no longer have access to {{ request.workspace.name }}.</p>
                <Button
                    v-if="request.workspace.member"
                    type="button"
                    class="wd-button wd-button-primary"
                    :disabled="opening"
                    @click="openWorkspace"
                    >{{ opening ? 'Opening…' : 'Open Brand workspace' }}</Button
                >
            </ContentSection>
            <ContentSection v-if="feedback && request.status !== 'approved'" title="Feedback from the reviewer">
                <p class="wd-pipeline-note">{{ feedback }}</p>
                <Link v-if="request.status === 'declined'" href="/brand/requests" class="wd-button wd-button-secondary"
                    >Submit a new request</Link
                >
            </ContentSection>
            <ContentSection
                v-if="request.status === 'needs_information'"
                title="Update your request"
                description="Resubmitting returns the request to review. Your earlier submission stays in the history."
            >
                <form ref="root" :aria-busy="form.processing" @submit.prevent="resubmit">
                    <p v-if="form.errors.expected_version" class="wd-banner" role="alert">
                        {{ form.errors.expected_version }}
                        <Link :href="`/brand/requests/${request.id}`" class="wd-link" preserve-state preserve-scroll
                            >Load the latest version</Link
                        >
                    </p>
                    <fieldset class="wd-fields" :disabled="form.processing">
                        <legend class="wd-sr-only">Update Brand workspace request</legend>
                        <BrandRequestFields :form="form" :errors="form.errors" prefix="revise" />
                        <Button type="submit" class="wd-button wd-button-primary">{{
                            form.processing ? 'Resubmitting…' : 'Resubmit for review'
                        }}</Button>
                    </fieldset>
                </form>
            </ContentSection>
            <ContentSection v-else title="Submitted information">
                <SubmissionDetails :fields="request" />
            </ContentSection>
            <ContentSection v-if="request.history?.length" title="History">
                <RequestHistory :history="request.history" />
            </ContentSection>
            <Link href="/brand/requests" class="wd-link inline-flex min-h-11 items-center"
                >All Brand workspace requests</Link
            >
        </div>
    </FoundationShell>
</template>
