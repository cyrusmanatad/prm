<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import FoundationShell from '../layouts/FoundationShell.vue';
import ContentSection from '../components/ContentSection.vue';
import EmptyState from '../components/EmptyState.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { Button } from '../components/ui/button';
import { useValidationFocus } from '../composables/useValidationFocus';
import { dateTime } from '../features/admin/format';
import BrandRequestFields from '../features/brand-requests/BrandRequestFields.vue';
import { brandStatuses, emptyFields, type BrandRequest } from '../features/brand-requests/types';

const props = defineProps<{ requests: BrandRequest[]; open: boolean; registering: boolean }>();
const page = usePage<{ account?: { name: string; email: string } | null }>();
const account = computed(() => page.props.account);
const current = computed(() => props.requests.find((row) => ['pending', 'needs_information'].includes(row.status)));
const root = ref<HTMLElement>();
const form = useForm(emptyFields());
useValidationFocus(
    root,
    () => form.errors,
    () => !form.processing,
);
// A routine submission: it creates nothing but the request, so it needs no confirmation dialog.
function submit() {
    if (!form.processing) form.post('/brand/requests', { preserveScroll: true });
}
</script>

<template>
    <Head title="Brand workspace requests" />
    <FoundationShell
        :account-name="account?.name"
        :account-email="account?.email"
        portal="brand"
        @logout="router.post('/logout')"
    >
        <div class="wd-workspace-content wd-finance-sections">
            <header class="wd-page-heading">
                <span class="wd-eyebrow">Brand workspace</span>
                <h1>Request a Brand workspace</h1>
                <p class="wd-description">
                    WeavDash reviews every Brand workspace before it is created. Tell us about the business; a reviewer
                    will approve the request, ask for more information or decline it.
                </p>
            </header>
            <p v-if="registering && !open" class="wd-warning" role="status">
                Your account is ready. One step remains: submit the request below so your Brand workspace can be
                reviewed. Nothing is created until it is approved.
            </p>
            <ContentSection v-if="current" :title="current.brand_name ?? 'Your request'">
                <p>
                    <StatusBadge
                        :label="brandStatuses[current.status].label"
                        :tone="brandStatuses[current.status].tone"
                    />
                </p>
                <p>{{ brandStatuses[current.status].meaning }}</p>
                <p class="wd-hint">
                    You can have one request open at a time. You can request another Brand workspace after this one is
                    decided.
                </p>
                <Link :href="`/brand/requests/${current.id}`" class="wd-button wd-button-primary">{{
                    current.status === 'needs_information' ? 'Update request' : 'View request'
                }}</Link>
            </ContentSection>
            <ContentSection
                v-else
                title="About the business"
                description="All four fields are required. No documents are needed."
            >
                <form ref="root" :aria-busy="form.processing" @submit.prevent="submit">
                    <fieldset class="wd-fields" :disabled="form.processing">
                        <legend class="wd-sr-only">Brand workspace request</legend>
                        <BrandRequestFields :form="form" :errors="form.errors" />
                        <p class="wd-hint">
                            By submitting, you agree to receive email updates about this request at
                            {{ account?.email }}. Approval lets you operate a Brand workspace. It is not a
                            verified-business badge and does not include a paid subscription.
                        </p>
                        <Button type="submit" class="wd-button wd-button-primary">{{
                            form.processing ? 'Submitting…' : 'Submit for review'
                        }}</Button>
                    </fieldset>
                </form>
            </ContentSection>
            <ContentSection title="Your requests">
                <EmptyState
                    v-if="!requests.length"
                    title="No Brand workspace requests yet"
                    description="After you submit a request, its review status appears here."
                />
                <article v-for="request in requests" :key="request.id" class="wd-record">
                    <h3>
                        <Link :href="`/brand/requests/${request.id}`" class="wd-link">{{ request.brand_name }}</Link>
                    </h3>
                    <p>
                        <StatusBadge
                            :label="brandStatuses[request.status].label"
                            :tone="brandStatuses[request.status].tone"
                        />
                    </p>
                    <p class="wd-hint">
                        Submitted {{ dateTime(request.submitted_at)
                        }}<template v-if="request.decided_at"> · Decided {{ dateTime(request.decided_at) }}</template>
                    </p>
                </article>
            </ContentSection>
            <Link href="/workspaces" class="wd-link inline-flex min-h-11 items-center">Back to workspaces</Link>
        </div>
    </FoundationShell>
</template>
