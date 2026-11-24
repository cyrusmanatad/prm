<script setup lang="ts">
import { useWorkspaceExperience } from '../composables/useWorkspaceExperience';
import { confirmAction } from '../composables/useConfirmation';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronRight, Copy, Download, Plus, RefreshCw, Search, X } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import FormField from '../components/FormField.vue';
import OnboardingEmailFields from '../components/OnboardingEmailFields.vue';
import OnboardingSubmissionNotice from '../components/OnboardingSubmissionNotice.vue';
import FilterChips, { type FilterChip } from '../components/FilterChips.vue';
import Pagination from '../components/Pagination.vue';
import EmptyState from '../components/EmptyState.vue';
import StatusBadge from '../components/StatusBadge.vue';
import Tabs from '../components/Tabs.vue';
import DialogClose from '../components/DialogClose.vue';
import { containDialogFocus } from '../composables/useDialogFocus';
import { emailStates } from '../features/notifications/emailStates';
import {
    ago,
    expiry,
    fileSize,
    requestStatuses,
    shortDate,
    statusFilters,
    type RequestStatus,
} from '../features/onboarding/requests';
import { Button } from '../components/ui/button';

type Row = {
    id: string;
    label: string;
    status: RequestStatus;
    version: number;
    pipeline_entry_id: string;
    brand_name: string;
    created_at: string;
    expires_at: string;
    submitted_at: string | null;
};
type Detail = {
    id: string;
    label: string;
    status: RequestStatus;
    version: number;
    business_name: string;
    contact_name: string;
    business_email: string;
    website: string;
    brief: string;
    correction_note: string;
    pipeline_entry_id?: string;
    brand_name?: string;
    created_at?: string;
    expires_at?: string;
    submitted_at?: string | null;
    documents: { id: string; name: string; size?: number; scan_state: string; downloadable?: boolean }[];
};
type Filter = (typeof statusFilters)[number];
const props = defineProps<{
    account: { name: string; email: string };
    workspace: { id: string; name: string };
    canWrite: boolean;
    requests: { items: Row[]; page: number; perPage: number; total: number; counts: Record<Filter, number> };
    filters: { status: Filter; q: string };
    leads?: { id: string; brand_name: string }[];
    selected: Detail | null;
    emails: { id: string; kind: string; state: string; created_at: string }[];
    submissions: {
        id: string;
        revision: number;
        snapshot: {
            business_name?: string;
            contact_name?: string;
            business_email?: string;
            website?: string;
            brief?: string;
            documents?: { id: string }[];
        };
        consent_at: string;
    }[];
    issuedLink: string | null;
    status?: string;
    errors?: Record<string, string>;
}>();
const { professional, path } = useWorkspaceExperience();
const base = computed(() => path('/partner/onboarding'));
const busy = ref(false);
const blocked = computed(() => busy.value || !!props.errors?.version);

// List: status chips, search and pages stay in the address so Back/Forward and links restore them.
function listHref(change: { status?: string; q?: string; page?: number; request?: string | null } = {}): string {
    const state = {
        status: change.status ?? props.filters.status,
        q: change.q ?? props.filters.q,
        page: change.page ?? (change.status !== undefined || change.q !== undefined ? 1 : props.requests.page),
        request: change.request === undefined ? (props.selected?.id ?? null) : change.request,
    };
    const search = new URLSearchParams();
    if (state.request) search.set('request', state.request);
    search.set('status', state.status);
    if (state.q) search.set('q', state.q);
    if (state.page > 1) search.set('page', String(state.page));
    return `${base.value}?${search}`;
}
const chips = computed<FilterChip[]>(() =>
    statusFilters.map((value) => ({
        value,
        label: value === 'all' ? 'All' : requestStatuses[value].label,
        count: props.requests.counts[value],
    })),
);
const listTitle = computed(() =>
    props.filters.status === 'all' ? 'All requests' : requestStatuses[props.filters.status].label,
);
const q = ref(props.filters.q);
watch(
    () => props.filters.q,
    (value) => {
        if (document.activeElement?.id !== 'request-search') q.value = value;
    },
);
let typing: ReturnType<typeof setTimeout> | undefined;
watch(q, (value) => {
    clearTimeout(typing);
    if (value.trim() === props.filters.q) return;
    typing = setTimeout(
        () => router.get(listHref({ q: value.trim() }), {}, { preserveState: true, preserveScroll: true }),
        350,
    );
});
onBeforeUnmount(() => clearTimeout(typing));
const emptyList = computed(() => {
    if (props.filters.q) return { title: 'No requests match', description: 'Try another search or status.' };
    if (!props.requests.counts.all)
        return {
            title: 'No onboarding requests yet',
            description: 'Start one for a lead to collect the client details you need.',
        };
    return { title: `Nothing in ${listTitle.value.toLowerCase()}`, description: 'Choose another status.' };
});
function rowWhen(row: Row): string {
    if (row.submitted_at && row.status !== 'draft') return `submitted ${ago(row.submitted_at)}`;
    return `invited ${ago(row.created_at)}`;
}

// Narrow screens show the list or the details.
const panel = ref<'list' | 'detail'>(props.selected ? 'detail' : 'list');
watch(
    () => props.selected?.id,
    (id) => {
        if (id) panel.value = 'detail';
    },
);

// Detail.
const tab = ref('submission');
const tabs = computed(() => [
    { id: 'submission', label: 'Submission' },
    { id: 'history', label: 'History', count: props.submissions.length },
    ...(props.canWrite ? [{ id: 'emails', label: 'Emails', count: props.emails.length }] : []),
]);
const note = ref('');
const sendOutcome = ref(false);
const outcomeRecipient = ref('');
const replacing = ref(false);
const sendEmail = ref(false);
const recipient = ref('');
const notifySubmission = ref(false);
const reviewForm = ref<HTMLFormElement>();
const replacementForm = ref<HTMLFormElement>();
const emailKinds: Record<string, string> = {
    'onboarding.invitation': 'Invitation',
    'onboarding.submitted': 'Submission alert',
    'onboarding.corrected': 'Correction request',
    'onboarding.accepted': 'Acceptance',
};
watch(
    () => props.selected?.id,
    () => {
        sendEmail.value = false;
        recipient.value = '';
        notifySubmission.value = false;
        sendOutcome.value = false;
        outcomeRecipient.value = '';
        note.value = '';
        replacing.value = false;
        tab.value = 'submission';
    },
);
const selectedExpiry = computed(() =>
    props.selected?.expires_at ? expiry(props.selected.status, props.selected.expires_at) : null,
);
function openReplacement(): void {
    replacing.value = true;
    void nextTick(() => document.getElementById('replacement-send-email')?.focus());
}
function replace(event: Event): void {
    if (!props.selected || busy.value || !replacementForm.value?.reportValidity()) return;
    confirmAction(
        'Create replacement invitation',
        `Replace the invitation for ${props.selected.label}? The previous invitation and its sessions will stop working.${sendEmail.value ? ` A new invitation email will be requested for ${recipient.value}.` : ''}`,
        () => review('renew'),
        event,
        { tone: 'danger' },
    );
}
function review(action: string): void {
    if (!props.selected || busy.value || props.errors?.version) return;
    if (['correct', 'accept'].includes(action) && !reviewForm.value?.reportValidity()) return;
    router.post(
        `/onboarding-requests/${props.selected.id}/${action}`,
        {
            version: props.selected.version,
            note: note.value,
            ...(action === 'renew'
                ? {
                      send_email: sendEmail.value,
                      recipient_email: recipient.value,
                      notify_submission: notifySubmission.value,
                  }
                : {}),
            ...(['correct', 'accept'].includes(action)
                ? { send_email: sendOutcome.value, recipient_email: outcomeRecipient.value }
                : {}),
        },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess: () => {
                if (action === 'renew') {
                    sendEmail.value = false;
                    recipient.value = '';
                    notifySubmission.value = false;
                    replacing.value = false;
                }
                sendOutcome.value = false;
                outcomeRecipient.value = '';
                note.value = '';
            },
        },
    );
}

// Copy the one-time invitation link.
const copied = ref('');
async function copyLink(): Promise<void> {
    if (!props.issuedLink) return;
    try {
        await navigator.clipboard.writeText(props.issuedLink);
        copied.value = 'Invitation link copied';
    } catch {
        copied.value = 'Could not copy. Select the link and copy it instead.';
    }
}
watch(
    () => props.issuedLink,
    () => (copied.value = ''),
);

// New request dialog.
const newDialog = ref<HTMLDialogElement>();
const newLead = ref('');
const newLabel = ref('');
const newSend = ref(false);
const newRecipient = ref('');
const newNotify = ref(false);
const newForm = ref<HTMLFormElement>();
// The dialog's fields exist only while it is open, so they never duplicate the page's own fields.
const newOpen = ref(false);
watch(newLead, (id, previous) => {
    const name = props.leads?.find((row) => row.id === id)?.brand_name;
    const previousName = props.leads?.find((row) => row.id === previous)?.brand_name;
    if (name && (!newLabel.value || newLabel.value === `${previousName} onboarding`))
        newLabel.value = `${name} onboarding`;
});
function openNew(): void {
    newOpen.value = true;
    newDialog.value?.showModal();
    void nextTick(() => document.getElementById('new-request-lead')?.focus());
}
function closeNew(): void {
    newDialog.value?.close();
}
function createRequest(): void {
    if (busy.value || !newForm.value?.reportValidity()) return;
    router.post(
        '/onboarding-requests',
        {
            pipeline_entry_id: newLead.value,
            label: newLabel.value,
            send_email: newSend.value,
            recipient_email: newRecipient.value,
            notify_submission: newNotify.value,
        },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess: () => {
                closeNew();
                newLead.value = '';
                newLabel.value = '';
                newSend.value = false;
                newRecipient.value = '';
                newNotify.value = false;
            },
        },
    );
}
const newErrors = computed(() =>
    props.errors && (props.errors.pipeline_entry_id || props.errors.label) ? props.errors : undefined,
);
watch(
    newErrors,
    (value) => {
        if (value && newDialog.value && !newDialog.value.open) {
            newOpen.value = true;
            newDialog.value.showModal();
        }
    },
    { flush: 'post' },
);
</script>
<template>
    <Head title="Onboarding requests" />
    <FoundationShell :account-name="account.name" :account-email="account.email" @logout="router.post('/logout')">
        <div class="wd-onboarding-admin onb">
            <header class="onb-header">
                <div>
                    <nav aria-label="Breadcrumb">
                        <ol class="onb-crumbs">
                            <li>
                                <Link :href="path('/partner/leads')">Leads</Link
                                ><ChevronRight :size="14" aria-hidden="true" />
                            </li>
                            <li aria-current="page">Onboarding requests</li>
                        </ol>
                    </nav>
                    <div class="onb-title-row">
                        <h1>Onboarding requests</h1>
                        <span class="onb-count">{{ requests.counts.all }}</span>
                    </div>
                </div>
                <button
                    v-if="canWrite && leads?.length"
                    type="button"
                    class="wd-table-button onb-primary"
                    :disabled="blocked"
                    @click="openNew"
                >
                    <Plus :size="16" aria-hidden="true" />New request
                </button>
            </header>
            <div v-if="errors?.version" role="alert" class="wd-banner">
                <p>{{ errors.version }}</p>
                <button type="button" class="wd-link" @click="router.get(listHref(), {}, { preserveState: false })">
                    Reload latest request
                </button>
                <p class="wd-hint">Copy unsaved changes before reloading.</p>
            </div>
            <div v-if="issuedLink" class="onb-issued">
                <label for="issued-invite">Private invitation link (copy now)</label>
                <div class="onb-issued-row">
                    <input id="issued-invite" class="wd-table-input" :value="issuedLink" readonly />
                    <button type="button" class="wd-table-button" @click="copyLink">
                        <Copy :size="14" aria-hidden="true" />Copy
                    </button>
                </div>
                <p class="wd-hint" role="status">
                    {{
                        copied ||
                        'Shown only once. Expires in seven days. Opening it once creates a 24-hour session in that browser. Revocation ends access immediately.'
                    }}
                </p>
            </div>
            <FilterChips
                label="Filter requests by status"
                :chips="chips"
                :current="filters.status"
                :href-for="(value) => listHref({ status: value })"
                compact
            />
            <div class="onb-switch" role="group" aria-label="Show panel">
                <button type="button" :aria-pressed="panel === 'list'" @click="panel = 'list'">
                    Requests <span class="onb-count">{{ requests.total }}</span>
                </button>
                <button
                    type="button"
                    :aria-pressed="panel === 'detail'"
                    :disabled="!selected"
                    @click="panel = 'detail'"
                >
                    Details
                </button>
            </div>
            <div class="onb-grid">
                <section
                    class="onb-card onb-list"
                    :class="{ 'is-hidden-small': panel !== 'list' }"
                    aria-labelledby="request-list"
                >
                    <header class="onb-card-head">
                        <h2 id="request-list">{{ listTitle }}</h2>
                        <span class="wd-hint">Newest first</span>
                    </header>
                    <form class="onb-search" role="search" aria-label="Search requests" @submit.prevent>
                        <label for="request-search" class="wd-sr-only">Search requests or brands</label>
                        <Search :size="15" aria-hidden="true" />
                        <input
                            id="request-search"
                            v-model="q"
                            class="wd-table-input"
                            type="search"
                            maxlength="160"
                            placeholder="Search requests or brands"
                            autocomplete="off"
                        />
                    </form>
                    <ul v-if="requests.items.length" class="onb-items">
                        <li
                            v-for="row in requests.items"
                            :key="row.id"
                            :class="{ 'is-selected': selected?.id === row.id }"
                        >
                            <Link
                                class="onb-item-name"
                                :href="listHref({ request: row.id })"
                                preserve-scroll
                                v-bind="{
                                    'aria-current': selected?.id === row.id ? 'page' : undefined,
                                    'aria-describedby': `request-meta-${row.id}`,
                                }"
                                >{{ row.label }}</Link
                            >
                            <StatusBadge
                                class="onb-item-status"
                                :label="requestStatuses[row.status].label"
                                :tone="requestStatuses[row.status].tone"
                            />
                            <span :id="`request-meta-${row.id}`" class="onb-item-meta">
                                <span>{{ row.brand_name }} · {{ rowWhen(row) }}</span>
                                <span v-if="expiry(row.status, row.expires_at)" class="onb-item-expiry">{{
                                    expiry(row.status, row.expires_at)
                                }}</span>
                            </span>
                        </li>
                    </ul>
                    <EmptyState
                        v-else
                        class="onb-empty"
                        :title="emptyList.title"
                        :description="emptyList.description"
                    />
                    <Pagination
                        v-if="requests.total > requests.perPage"
                        class="onb-pagination"
                        label="Request pages"
                        noun="requests"
                        numbered
                        :page="requests.page"
                        :per-page="requests.perPage"
                        :count="requests.items.length"
                        :total="requests.total"
                        :href-for="(page) => listHref({ page })"
                    />
                </section>
                <section
                    v-if="selected"
                    class="onb-card onb-detail"
                    :class="{ 'is-hidden-small': panel !== 'detail' }"
                    aria-labelledby="selected-request"
                >
                    <header class="onb-detail-head">
                        <div class="onb-detail-title">
                            <h2 id="selected-request">{{ selected.label }}</h2>
                            <StatusBadge
                                :label="requestStatuses[selected.status].label"
                                :tone="requestStatuses[selected.status].tone"
                            />
                        </div>
                        <p class="onb-detail-meta">
                            <span v-if="selected.brand_name && selected.pipeline_entry_id"
                                >Lead:
                                <Link :href="path(`/partner/leads?entry=${selected.pipeline_entry_id}`)">{{
                                    selected.brand_name
                                }}</Link></span
                            >
                            <span v-if="selected.created_at">Invited {{ shortDate(selected.created_at) }}</span>
                            <span v-if="selected.submitted_at"
                                >Submitted {{ ago(selected.submitted_at) }} · version
                                {{ submissions[0]?.revision ?? 1 }}</span
                            >
                            <span v-if="selectedExpiry && selected.expires_at"
                                >{{ selectedExpiry }} ({{ shortDate(selected.expires_at) }})</span
                            >
                        </p>
                    </header>
                    <p v-if="$page.props.unscannedUploadsAllowed" class="wd-banner onb-pad">
                        Staging uploads are not scanned for malware. Download only test files you trust.
                    </p>
                    <Tabs v-model="tab" class="onb-tabs" label="Request details" id-prefix="request" :tabs="tabs" />
                    <div
                        v-show="tab === 'submission'"
                        id="request-panel-submission"
                        role="tabpanel"
                        aria-labelledby="request-tab-submission"
                        tabindex="0"
                        class="onb-panel"
                    >
                        <p v-if="selected.correction_note && selected.status === 'correction'" class="onb-note">
                            <strong>Correction requested:</strong> {{ selected.correction_note }}
                        </p>
                        <dl class="onb-facts">
                            <dt>Client</dt>
                            <dd>{{ selected.business_name || 'Not provided' }}</dd>
                            <dt>Contact</dt>
                            <dd>
                                {{
                                    [selected.contact_name, selected.business_email].filter(Boolean).join(' · ') ||
                                    'Not provided'
                                }}
                            </dd>
                            <dt>Website</dt>
                            <dd>{{ selected.website || 'Not provided' }}</dd>
                            <dt>Brief</dt>
                            <dd class="onb-brief">{{ selected.brief || 'Not provided' }}</dd>
                        </dl>
                        <h3 class="onb-subhead">Reference files · {{ selected.documents.length }}</h3>
                        <p v-if="!selected.documents.length" class="wd-hint">No files shared.</p>
                        <ul v-else class="onb-files">
                            <li v-for="doc in selected.documents" :key="doc.id">
                                <span class="onb-file-name"
                                    ><strong>{{ doc.name }}</strong
                                    ><small v-if="doc.size">{{ fileSize(doc.size) }}</small></span
                                >
                                <StatusBadge
                                    :label="
                                        doc.scan_state === 'unscanned'
                                            ? 'Unscanned (staging only)'
                                            : doc.scan_state === 'clean'
                                              ? 'Clean'
                                              : doc.scan_state === 'pending'
                                                ? 'Scan pending'
                                                : doc.scan_state.replaceAll('_', ' ')
                                    "
                                    :tone="
                                        doc.scan_state === 'clean'
                                            ? 'success'
                                            : doc.scan_state === 'pending' || doc.scan_state === 'unscanned'
                                              ? 'warning'
                                              : 'danger'
                                    "
                                />
                                <a
                                    v-if="doc.downloadable ?? doc.scan_state === 'clean'"
                                    class="wd-table-button"
                                    :href="`/onboarding-requests/${selected.id}/documents/${doc.id}`"
                                    :aria-label="`Download ${doc.name}`"
                                    ><Download :size="14" aria-hidden="true" />Download</a
                                >
                                <button
                                    v-if="canWrite && doc.scan_state === 'pending'"
                                    type="button"
                                    class="wd-table-button"
                                    :aria-label="`Retry scan ${doc.name}`"
                                    @click="router.post(`/onboarding-requests/${selected.id}/documents/${doc.id}`)"
                                >
                                    <RefreshCw :size="14" aria-hidden="true" />Retry scan
                                </button>
                            </li>
                        </ul>
                    </div>
                    <div
                        v-show="tab === 'history'"
                        id="request-panel-history"
                        role="tabpanel"
                        aria-labelledby="request-tab-history"
                        tabindex="0"
                        class="onb-panel"
                    >
                        <h3 class="onb-subhead">Submission history</h3>
                        <p v-if="!submissions.length" class="wd-hint">Nothing has been submitted yet.</p>
                        <details v-for="submission in submissions" :key="submission.id" class="onb-version">
                            <summary>
                                Submission version {{ submission.revision }}
                                <span class="wd-hint">· {{ ago(submission.consent_at) }}</span>
                            </summary>
                            <p class="wd-hint">
                                Consent recorded: {{ new Date(submission.consent_at).toLocaleString() }}
                            </p>
                            <dl class="onb-facts">
                                <dt>Client</dt>
                                <dd>{{ submission.snapshot.business_name }}</dd>
                                <dt>Contact</dt>
                                <dd>{{ submission.snapshot.contact_name }}</dd>
                                <dt>Client email</dt>
                                <dd>{{ submission.snapshot.business_email }}</dd>
                                <dt>Website</dt>
                                <dd>{{ submission.snapshot.website || 'Not provided' }}</dd>
                                <dt>Collaboration brief</dt>
                                <dd class="onb-brief">{{ submission.snapshot.brief }}</dd>
                            </dl>
                            <p class="wd-hint">
                                {{ submission.snapshot.documents?.length ?? 0 }} reference files shared with this
                                submission.
                            </p>
                        </details>
                    </div>
                    <div
                        v-if="canWrite"
                        v-show="tab === 'emails'"
                        id="request-panel-emails"
                        role="tabpanel"
                        aria-labelledby="request-tab-emails"
                        tabindex="0"
                        class="onb-panel"
                    >
                        <section aria-labelledby="email-history-title">
                            <h3 id="email-history-title" class="onb-subhead">Onboarding email history</h3>
                            <p class="wd-hint">
                                Refresh this page to check delivery progress. Newer edits or outcomes, replacement and
                                revocation cancel obsolete pending notices; mail already sending cannot be recalled.
                            </p>
                            <p v-if="!emails.length" class="wd-hint">No email requested or queued for this request.</p>
                            <ul v-else class="onb-emails">
                                <li v-for="email in emails" :key="email.id">
                                    <strong>{{ emailKinds[email.kind] ?? 'Onboarding notice' }}</strong>
                                    <span class="wd-hint">{{ new Date(email.created_at).toLocaleString() }}</span>
                                    <span>{{ emailStates[email.state] }}</span>
                                </li>
                            </ul>
                        </section>
                    </div>
                    <form
                        v-if="canWrite && selected.status === 'submitted'"
                        ref="reviewForm"
                        class="onb-review"
                        :aria-busy="busy"
                        aria-labelledby="review-title"
                        @submit.prevent
                    >
                        <h3 id="review-title">Review this submission</h3>
                        <div class="wd-field">
                            <label for="correction-note">Correction request</label>
                            <textarea
                                id="correction-note"
                                v-model="note"
                                rows="3"
                                maxlength="2000"
                                placeholder="Tell the client what to change"
                                aria-describedby="correction-note-hint"
                                :aria-invalid="errors?.note ? 'true' : undefined"
                            />
                            <p id="correction-note-hint" class="wd-hint">Required only to request a correction.</p>
                            <p v-if="errors?.note" class="wd-field-error" role="alert">{{ errors.note }}</p>
                        </div>
                        <OnboardingEmailFields
                            v-model:send-email="sendOutcome"
                            v-model:recipient="outcomeRecipient"
                            prefix="review-outcome"
                            purpose="outcome"
                            :errors="errors"
                        />
                        <div class="onb-review-actions">
                            <p class="wd-hint">Accepting closes the request for edits.</p>
                            <Button
                                type="button"
                                class="wd-button wd-button-secondary"
                                :disabled="blocked || !note.trim()"
                                @click="review('correct')"
                                >Request correction</Button
                            ><Button
                                type="button"
                                class="wd-button wd-button-primary"
                                :disabled="blocked"
                                @click="review('accept')"
                                >Accept submission</Button
                            >
                        </div>
                    </form>
                    <footer v-if="canWrite && selected.status !== 'revoked'" class="onb-access">
                        <div class="onb-access-row">
                            <p class="wd-hint">Invitation access · the link opens once; sessions last 24 hours.</p>
                            <span class="onb-access-actions">
                                <button
                                    v-if="!replacing"
                                    type="button"
                                    class="wd-table-button"
                                    aria-expanded="false"
                                    aria-controls="replacement-form"
                                    :disabled="blocked"
                                    @click="openReplacement"
                                >
                                    Replace invitation…
                                </button>
                                <Button
                                    type="button"
                                    class="wd-button wd-button-danger-outline onb-revoke"
                                    :disabled="blocked"
                                    @click="
                                        confirmAction(
                                            'Revoke invitation access',
                                            `Revoke access to ${selected.label}? The invitation and existing onboarding sessions will stop working immediately.`,
                                            () => review('revoke'),
                                            $event,
                                            { tone: 'danger' },
                                        )
                                    "
                                    >Revoke invitation access</Button
                                >
                            </span>
                        </div>
                        <form
                            v-if="replacing"
                            id="replacement-form"
                            ref="replacementForm"
                            class="onb-replace"
                            :aria-busy="busy"
                            @submit.prevent="replace"
                        >
                            <fieldset class="wd-fields" :disabled="blocked">
                                <legend class="onb-subhead">Replacement invitation</legend>
                                <OnboardingEmailFields
                                    v-model:send-email="sendEmail"
                                    v-model:recipient="recipient"
                                    prefix="replacement"
                                    :errors="errors"
                                />
                                <OnboardingSubmissionNotice
                                    v-model="notifySubmission"
                                    prefix="replacement"
                                    :error="errors?.notify_submission"
                                />
                                <div class="onb-replace-actions">
                                    <Button
                                        type="button"
                                        class="wd-button wd-button-secondary"
                                        @click="replacing = false"
                                        >Cancel</Button
                                    ><Button type="submit" class="wd-button wd-button-primary"
                                        >Create replacement invitation</Button
                                    >
                                </div>
                            </fieldset>
                        </form>
                    </footer>
                </section>
                <section
                    v-else
                    class="onb-card onb-detail onb-detail-empty"
                    :class="{ 'is-hidden-small': panel !== 'detail' }"
                    aria-label="Request details"
                >
                    <EmptyState
                        title="Choose a request"
                        :description="
                            professional
                                ? 'Select a request to review the client details, files and history.'
                                : 'Select a request to review the brand details, files and history.'
                        "
                    />
                </section>
            </div>
            <dialog
                v-if="canWrite"
                ref="newDialog"
                class="wd-dialog onb-dialog"
                aria-labelledby="new-request-title"
                @keydown="containDialogFocus($event, newDialog)"
                @close="newOpen = false"
            >
                <div class="wd-dialog-header">
                    <h2 id="new-request-title">New onboarding request</h2>
                    <DialogClose label="Close new request" :disabled="busy" @click="closeNew" />
                </div>
                <form v-if="newOpen" ref="newForm" class="wd-fields" :aria-busy="busy" @submit.prevent="createRequest">
                    <div class="wd-field">
                        <label for="new-request-lead">Lead</label>
                        <select
                            id="new-request-lead"
                            v-model="newLead"
                            required
                            :aria-invalid="errors?.pipeline_entry_id ? 'true' : undefined"
                        >
                            <option value="" disabled>Choose a lead</option>
                            <option v-for="lead in leads" :key="lead.id" :value="lead.id">{{ lead.brand_name }}</option>
                        </select>
                        <p v-if="errors?.pipeline_entry_id" class="wd-field-error" role="alert">
                            {{ errors.pipeline_entry_id }}
                        </p>
                    </div>
                    <FormField
                        id="new-request-label"
                        v-model="newLabel"
                        label="Request label"
                        :maxlength="160"
                        :error="errors?.label"
                    />
                    <OnboardingEmailFields
                        v-model:send-email="newSend"
                        v-model:recipient="newRecipient"
                        prefix="new-request"
                        :errors="errors"
                    />
                    <OnboardingSubmissionNotice v-model="newNotify" prefix="new-request" />
                    <p class="wd-hint">You get a private link to copy. It expires in seven days.</p>
                    <div class="wd-dialog-footer">
                        <Button type="button" class="wd-button wd-button-secondary" :disabled="busy" @click="closeNew"
                            >Cancel</Button
                        ><Button type="submit" class="wd-button wd-button-primary" :disabled="busy"
                            >Create request</Button
                        >
                    </div>
                </form>
            </dialog>
        </div>
    </FoundationShell>
</template>
<style scoped>
.onb {
    display: flex;
    flex-direction: column;
    gap: 14px;
    width: 100%;
    padding-bottom: 16px;
    color: var(--wd-ink);
    font-size: 14px;
}
.onb-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 12px 24px;
}
.onb-crumbs {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px;
    margin: 0;
    padding: 0;
    list-style: none;
    color: var(--wd-muted);
    font-size: 13px;
}
.onb-crumbs li {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.onb-crumbs a {
    color: var(--wd-brand);
    font-weight: 600;
    text-decoration: none;
}
.onb-crumbs a:hover {
    text-decoration: underline;
}
.onb-title-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 4px;
}
.onb h1 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 28px;
    font-weight: 700;
    letter-spacing: -0.01em;
    line-height: 1.15;
}
.onb-count {
    padding: 1px 8px;
    border-radius: 999px;
    background: var(--wd-divider);
    color: var(--wd-muted);
    font-size: 12px;
    font-weight: 700;
}
.onb-primary {
    border-color: var(--wd-brand);
    background: var(--wd-brand);
    color: #fff;
}
.onb .onb-primary:hover:not(:disabled) {
    border-color: var(--wd-brand-hover);
    background: var(--wd-brand-hover);
    color: #fff;
}
.onb > .wd-banner {
    margin: 0;
}
.onb-issued {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 10px 12px;
    border: 1px solid #cfe7dc;
    border-radius: 10px;
    background: var(--wd-success-bg);
    color: var(--wd-success);
}
.onb-issued label {
    font-size: 13px;
    font-weight: 700;
}
.onb-issued-row {
    display: flex;
    gap: 6px;
}
.onb-issued input {
    flex: 1;
    min-width: 0;
    background: var(--wd-surface);
    font-size: 13px;
}
.onb-issued .wd-hint {
    color: var(--wd-success);
}
.onb-switch {
    display: none;
}
.onb-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.35fr);
    align-items: start;
    gap: 14px;
}
.onb-card {
    display: flex;
    flex-direction: column;
    min-width: 0;
    border: 1px solid var(--wd-line);
    border-radius: 12px;
    background: var(--wd-surface);
    overflow: clip;
}
.onb-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 12px 14px;
}
.onb h2 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 16px;
    font-weight: 600;
}
.onb-search {
    position: relative;
    display: flex;
    align-items: center;
    margin: 0 12px 10px;
}
.onb-search svg {
    position: absolute;
    left: 10px;
    color: var(--wd-muted);
    pointer-events: none;
}
.onb-search input {
    width: 100%;
    padding-left: 32px;
    font-size: 13px;
}
.onb-items {
    margin: 0;
    padding: 0;
    list-style: none;
}
.onb-items li {
    position: relative;
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 2px 10px;
    padding: 10px 14px 10px 11px;
    border-top: 1px solid var(--wd-divider);
    border-left: 3px solid transparent;
}
.onb-items li:hover {
    background: #fcfaf6;
}
.onb-items li.is-selected {
    border-left-color: var(--wd-brand);
    background: #fdf6f9;
}
/* The link names the request; its stretched hit area keeps the whole row clickable. */
.onb-item-name {
    color: var(--wd-ink);
    font-weight: 700;
    text-decoration: none;
    overflow-wrap: anywhere;
}
.onb-item-name::after {
    content: '';
    position: absolute;
    inset: 0;
}
.onb-item-name:focus-visible {
    outline: none;
}
.onb-item-name:focus-visible::after {
    outline: 2px solid var(--wd-focus);
    outline-offset: -2px;
}
.onb-items li.is-selected .onb-item-name {
    color: var(--wd-brand-hover);
}
.onb-item-status {
    justify-self: end;
    pointer-events: none;
}
.onb-item-meta {
    display: flex;
    grid-column: 1 / -1;
    justify-content: space-between;
    gap: 10px;
    min-width: 0;
    pointer-events: none;
}
.onb-item-meta > span {
    overflow: hidden;
    color: var(--wd-muted);
    font-size: 12px;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.onb-item-expiry {
    flex: none;
}
.onb-empty {
    margin: 0 12px 12px;
}
.onb-pagination {
    padding: 8px 14px;
    border-top: 1px solid var(--wd-divider);
}
.onb-detail-head {
    padding: 14px 14px 10px;
}
.onb-detail-title {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 10px;
}
.onb-detail-head h2 {
    font-size: 18px;
    font-weight: 700;
    overflow-wrap: anywhere;
}
.onb-detail-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 2px 12px;
    margin: 6px 0 0;
    color: var(--wd-muted);
    font-size: 12.5px;
}
.onb-detail-meta a {
    color: var(--wd-brand);
    font-weight: 700;
    text-decoration: none;
}
.onb-detail-meta a:hover {
    text-decoration: underline;
}
.onb-pad {
    margin: 0 14px 10px;
}
.onb-tabs {
    padding: 0 14px;
}
.onb-panel {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 12px 14px 14px;
}
.onb-panel:focus-visible {
    outline: 2px solid var(--wd-focus);
    outline-offset: -2px;
}
.onb-note {
    margin: 0;
    padding: 8px 10px;
    border-radius: 8px;
    background: var(--wd-warning-bg);
    color: var(--wd-warning);
    font-size: 13px;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
.onb-facts {
    display: grid;
    grid-template-columns: 120px minmax(0, 1fr);
    gap: 6px 12px;
    margin: 0;
}
.onb-facts dt {
    color: var(--wd-muted);
    font-size: 12.5px;
    font-weight: 600;
}
.onb-facts dd {
    margin: 0;
    overflow-wrap: anywhere;
}
.onb-brief {
    white-space: pre-wrap;
}
.onb-subhead {
    margin: 4px 0 0;
    padding: 0;
    color: var(--wd-muted);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.onb-files {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.onb-files li {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 10px;
    padding: 8px 10px;
    border: 1px solid var(--wd-line);
    border-radius: 8px;
}
.onb-file-name {
    display: flex;
    flex: 1 1 160px;
    flex-direction: column;
    min-width: 0;
    overflow-wrap: anywhere;
}
.onb-file-name small {
    color: var(--wd-muted);
    font-size: 11.5px;
}
.onb-version {
    border-top: 1px solid var(--wd-divider);
}
.onb-version summary {
    padding: 10px 0;
    font-weight: 700;
    cursor: pointer;
}
.onb-version[open] {
    padding-bottom: 10px;
}
.onb-emails {
    margin: 0;
    padding: 0;
    list-style: none;
}
.onb-emails li {
    display: flex;
    flex-wrap: wrap;
    gap: 2px 10px;
    padding: 8px 0;
    border-top: 1px solid var(--wd-divider);
    overflow-wrap: anywhere;
}
.onb-review {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 0 14px 14px;
    padding: 12px;
    border: 1px solid #e9d3a8;
    border-radius: 10px;
    background: #fffaf1;
}
.onb-review h3 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 14px;
    font-weight: 600;
}
.onb-review textarea {
    font: inherit;
    font-size: 14px;
}
.onb-review-actions,
.onb-replace-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
}
.onb-review-actions .wd-hint {
    flex: 1 1 160px;
    margin: 0;
}
.onb-access {
    border-top: 1px solid var(--wd-divider);
    background: var(--wd-paper);
}
.onb-access-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px 12px;
    padding: 10px 14px;
}
.onb-access-row .wd-hint {
    margin: 0;
}
.onb-access-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.onb-access-actions :deep(.wd-button) {
    min-height: var(--wd-table-control);
    padding-block: 0;
    font-size: 13px;
}
.onb-replace {
    padding: 0 14px 14px;
}
.onb-replace fieldset {
    margin: 0;
    padding: 12px;
    border: 1px solid var(--wd-line);
    border-radius: 10px;
    background: var(--wd-surface);
}
.onb-detail-empty {
    padding: 24px 14px;
}
.onb-dialog[open] {
    display: flex;
    flex-direction: column;
    gap: 14px;
    width: min(460px, calc(100vw - 32px));
}
.onb-dialog .wd-dialog-header {
    margin-bottom: 0;
}
@media (min-width: 600px) and (pointer: fine) {
    .onb-header,
    .onb-search,
    .onb-files,
    .onb-access,
    .onb-issued {
        --wd-table-control: 34px;
    }
}
@media (max-width: 1099px) {
    .onb-grid {
        grid-template-columns: minmax(0, 1fr);
    }
    .onb-switch {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 2px;
        padding: 3px;
        border-radius: 9px;
        background: var(--wd-divider);
    }
    .onb-switch button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        min-height: 36px;
        border: 0;
        border-radius: 7px;
        background: transparent;
        color: var(--wd-muted);
        font: inherit;
        font-weight: 700;
    }
    .onb-switch button[aria-pressed='true'] {
        background: var(--wd-surface);
        box-shadow: 0 1px 2px rgb(34 26 38 / 12%);
        color: var(--wd-brand-hover);
    }
    .onb-switch button:disabled {
        opacity: 0.5;
    }
    .onb-card.is-hidden-small {
        display: none;
    }
}
@media (max-width: 599px), (pointer: coarse) {
    .onb-crumbs a {
        display: inline-flex;
        align-items: center;
        min-width: 44px;
        min-height: 44px;
    }
    .onb .onb-switch button {
        min-height: 44px;
    }
}
@media (max-width: 599px) {
    .onb h1 {
        font-size: 24px;
    }
    .onb-facts {
        grid-template-columns: minmax(0, 1fr);
        gap: 0;
    }
    .onb-facts dd {
        margin-bottom: 8px;
    }
    .onb-review-actions :deep(.wd-button) {
        flex: 1;
    }
    .onb-access-actions,
    .onb-access-actions > * {
        flex: 1 1 auto;
    }
}
</style>
