<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import ContentSection from '../components/ContentSection.vue';
import FormField from '../components/FormField.vue';
import SelectField from '../components/SelectField.vue';
import ActionGroup from '../components/ActionGroup.vue';
import { Button } from '../components/ui/button';

import ResolutionForm from '../features/platform-notifications/ResolutionForm.vue';
import { resolutionOptions as resolutions, type DeliveryNotice } from '../features/platform-notifications/types';

const props = defineProps<{
    account: { name: string; email: string };
    notices: DeliveryNotice[];
    workspace: string;
    state: string;
    page: number;
    canResolve: boolean;
    canDirectory: boolean;
}>();
const workspace = ref(props.workspace);
const state = ref(props.state);
const selected = ref<DeliveryNotice | null>(null);
const resolving = ref(false);
const states = {
    pending: 'Queued; delivery pending',
    sending: 'Send reserved; outcome pending',
    sent: 'Accepted by mail transport; receipt unconfirmed',
    captured: 'Captured locally; no external email sent',
    cancelled: 'Cancelled before sending',
    uncertain: 'Uncertain; automatic resend blocked',
};
const failures: Record<string, string> = {
    transport_unavailable: 'Mail transport was unavailable',
    workspace_budget: 'Workspace delivery budget blocked this attempt',
    global_budget: 'Application delivery budget blocked this attempt',
    queue_unavailable: 'Queue publication was unavailable',
};
function choose(notice: DeliveryNotice) {
    selected.value = notice;
}
function load(page = 1) {
    router.get(
        '/admin/notifications',
        { workspace: workspace.value || undefined, state: state.value || undefined, page },
        { preserveState: false },
    );
}
</script>
<template>
    <Head title="Delivery diagnostics" />
    <FoundationShell :account-name="account.name" :account-email="account.email" @logout="router.post('/logout')">
        <div class="wd-workspace-content wd-finance wd-finance-sections">
            <header class="wd-page-heading">
                <span class="wd-eyebrow">Platform administration</span>
                <h1>Delivery diagnostics</h1>
                <p class="wd-description">
                    Review delivery state and record provider evidence without opening private messages.
                </p>
            </header>
            <Link v-if="canDirectory" href="/admin" class="wd-link inline-flex min-h-11 items-center"
                >Accounts and workspaces</Link
            >
            <ContentSection title="Find deliveries">
                <form @submit.prevent="load()">
                    <div class="wd-fields">
                        <FormField
                            id="delivery-workspace"
                            v-model="workspace"
                            label="Workspace ID (optional)"
                            :required="false"
                        /><SelectField
                            id="delivery-state"
                            v-model="state"
                            label="Delivery state"
                            :options="[
                                { value: '', label: 'All states' },
                                ...Object.keys(states).map((key) => ({
                                    value: key,
                                    label: states[key as keyof typeof states],
                                })),
                            ]"
                        /><Button class="wd-button wd-button-secondary" type="submit">Filter deliveries</Button>
                    </div>
                </form>
            </ContentSection>
            <ContentSection
                title="Delivery history"
                description="Latest 25 matching records per page. Transport acceptance does not confirm mailbox receipt."
            >
                <p v-if="!notices.length" class="wd-empty">No deliveries match these filters.</p>
                <article v-for="notice in notices" :key="notice.id" class="wd-section-stack">
                    <h3 class="break-all">{{ notice.id }}</h3>
                    <p>{{ states[notice.state as keyof typeof states] }}</p>
                    <p class="wd-hint break-all">
                        Workspace {{ notice.org_id }} · {{ notice.kind }} ·
                        {{ new Date(notice.created_at).toLocaleString() }}
                    </p>
                    <p v-if="notice.failure_code">
                        Last deferral: {{ failures[notice.failure_code] }}. Eligible again
                        {{ new Date(notice.available_at).toLocaleString() }}.
                    </p>
                    <p v-if="notice.resolution">
                        Recorded resolution: {{ resolutions.find((row) => row.value === notice.resolution)?.label }}. No
                        resend authorized.
                    </p>
                    <Button
                        v-if="canResolve && notice.state === 'uncertain' && !notice.resolution"
                        type="button"
                        class="wd-button wd-button-secondary"
                        :disabled="resolving"
                        :ariaLabel="`Review resolution ${notice.id}`"
                        @click="choose(notice)"
                        >Review resolution</Button
                    >
                </article>
                <ActionGroup label="Delivery history pages"
                    ><Button v-if="page > 1" type="button" class="wd-button wd-button-secondary" @click="load(page - 1)"
                        >Previous</Button
                    ><Button
                        v-if="notices.length === 25"
                        type="button"
                        class="wd-button wd-button-secondary"
                        @click="load(page + 1)"
                        >Next</Button
                    ></ActionGroup
                >
            </ContentSection>
            <ResolutionForm
                v-if="selected && canResolve"
                :key="selected.id"
                :notice="selected"
                :filters="{ workspace: props.workspace, state: props.state, page: props.page }"
                @busy="resolving = $event"
                @close="selected = null"
            />
        </div>
    </FoundationShell>
</template>
