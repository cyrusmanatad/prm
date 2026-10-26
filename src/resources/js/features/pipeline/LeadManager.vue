<script setup lang="ts">
import { confirmAction } from '../../composables/useConfirmation';
import { containDialogFocus } from '../../composables/useDialogFocus';
import { Link, router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { Button } from '../../components/ui/button';
import FormField from '../../components/FormField.vue';
import SelectField from '../../components/SelectField.vue';
import DialogClose from '../../components/DialogClose.vue';
import OnboardingEmailFields from '../../components/OnboardingEmailFields.vue';
import OnboardingSubmissionNotice from '../../components/OnboardingSubmissionNotice.vue';
import EntryFields from './EntryFields.vue';
import PipelineBoard from './PipelineBoard.vue';
import { fromMinor, money, toMinor } from './money';
import { pipelineStages } from './stages';
import type { EntryDetail, LeadForm, PipelineWorkspace } from './types';
import { useNotify } from '../../composables/useNotify';
import { useValidationFocus } from '../../composables/useValidationFocus';

const props = defineProps<
    PipelineWorkspace & { workspace: { id: string; name: string }; errors?: Record<string, string>; status?: string }
>();
const root = ref<HTMLElement>();
const dialog = ref<HTMLDialogElement>();
const creating = ref(false);
const active = computed(() => !!props.selected || creating.value);
function openCreate(): void {
    if (props.canWrite) creating.value = true;
}
defineExpose({ openCreate });
onMounted(() => {
    if (location.hash === '#create' || new URLSearchParams(location.search).get('create') === '1') openCreate();
});
watch(
    [() => props.selected?.id, creating],
    async () => {
        await nextTick();
        if (active.value) {
            if (!dialog.value?.open) dialog.value?.showModal();
            dialog.value
                ?.querySelector<HTMLElement>(
                    props.selected ? (props.canWrite ? '#edit-brand' : 'button') : '#create-brand',
                )
                ?.focus();
        } else {
            dialog.value?.close();
        }
    },
    { immediate: true },
);
function close(): void {
    if (busy.value) return;
    if (props.selected) {
        const params = new URLSearchParams(location.search);
        params.delete('entry');
        params.delete('create');
        router.get('/partner/leads', Object.fromEntries(params), { preserveScroll: true, preserveState: true });
    } else {
        creating.value = false;
        dialog.value?.close();
    }
}
const busy = ref(false);
const sendOnboardingEmail = ref(false);
const onboardingRecipient = ref('');
const notifySubmission = ref(false);
watch(
    () => props.selected?.id,
    () => {
        sendOnboardingEmail.value = false;
        onboardingRecipient.value = '';
        notifySubmission.value = false;
    },
);
const formAction = ref('');
const loadedVersion = ref(0);
const formErrors = ref<Record<string, string>>({});
const errors = computed(() => ({ ...(props.errors ?? {}), ...formErrors.value }));
const notify = useNotify();
function versionMessage(value: unknown): string {
    if (typeof value === 'string') return value.trim();
    if (Array.isArray(value) && typeof value[0] === 'string') return value[0].trim();
    return '';
}
const stopVersionToast = router.on('error', (event) => {
    const message = versionMessage(event.detail.errors.version);
    if (message) notify.error(message, { persist: true, toastId: 'lead-version' });
});
const stopVersionDismiss = router.on('success', () => {
    notify.dismiss('lead-version');
});
onUnmounted(() => {
    stopVersionToast();
    stopVersionDismiss();
    notify.dismiss('lead-version');
});
useValidationFocus(
    root,
    () => errors.value,
    () => !busy.value,
);
const blank = (): LeadForm => ({
    brand_name: '',
    contact_name: '',
    contact_email: '',
    notes: '',
    currency: 'PHP',
    amount: '0',
    next_action_due: '',
});
const createForm = ref(blank());
const editForm = ref(blank());
const stage = ref('lead');
const drawerTab = ref<'details' | 'activity'>('details');
const activityKind = ref('note');
const activityBody = ref('');
const activityOptions = [
    { value: 'note', label: 'Note' },
    { value: 'call', label: 'Call' },
    { value: 'email', label: 'Email' },
    { value: 'chat', label: 'Chat' },
];
const currencyCodes = [
    'PHP',
    'USD',
    'JPY',
    'EUR',
    'GBP',
    'SGD',
    'AUD',
    'CAD',
    'HKD',
    'CNY',
    'KRW',
    'THB',
    'MYR',
    'IDR',
    'VND',
    'INR',
    'AED',
];
const currencyNames = new Intl.DisplayNames(['en'], { type: 'currency' });
const stageDots = Object.fromEntries(pipelineStages.map((stage) => [stage.value, stage.dot]));
const activityLabels: Record<string, string> = {
    note: 'Note',
    call: 'Call',
    email: 'Email',
    chat: 'Chat',
    created: 'Created',
    updated: 'Updated',
    stage: 'Stage',
    snoozed: 'Snoozed',
};
const stamp = (value: string | null): string => (value ? new Date(value).toLocaleString() : 'No next action');
const localInput = (value: string | null): string => {
    if (!value) return '';
    const date = new Date(value);
    return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
};
watch(
    [() => props.workspace.id, () => props.selected?.id],
    () => {
        const row = props.selected;
        if (row) creating.value = false;
        editForm.value = row
            ? {
                  brand_name: row.brand_name,
                  contact_name: row.contact_name ?? '',
                  contact_email: row.contact_email ?? '',
                  notes: row.notes,
                  amount: fromMinor(row.value_minor, row.currency),
                  currency: row.currency,
                  next_action_due: localInput(row.next_action_due),
              }
            : blank();
        loadedVersion.value = row?.version ?? 0;
        stage.value = row?.stage ?? 'lead';
        drawerTab.value = 'details';
        activityBody.value = '';
        formErrors.value = {};
    },
    { immediate: true },
);
watch(
    () => props.workspace.id,
    () => {
        createForm.value = blank();
    },
);
// Keep unsaved edits after stale-version errors. A successful save refreshes the form.
function refreshEdit(): void {
    const row = props.selected;
    if (row)
        editForm.value = {
            brand_name: row.brand_name,
            contact_name: row.contact_name ?? '',
            contact_email: row.contact_email ?? '',
            notes: row.notes,
            amount: fromMinor(row.value_minor, row.currency),
            currency: row.currency,
            next_action_due: localInput(row.next_action_due),
        };
}
function visit(
    method: 'post' | 'patch' | 'delete',
    path: string,
    data: Record<string, string | number | null>,
    done?: () => void,
): void {
    if (busy.value || errors.value.version) return;
    formErrors.value = {};
    let succeeded = false;
    router.visit(path, {
        method,
        data: { ...data, pipeline_filters: props.filters },
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            busy.value = true;
        },
        onSuccess: () => {
            if (formAction.value === 'create' || formAction.value === 'update') {
                loadedVersion.value = props.selected?.version ?? 0;
                refreshEdit();
            } else if (typeof data.version === 'number') {
                // Keep the draft's acknowledged version if another edit won the redirect race.
                loadedVersion.value = data.version + 1;
            }
            succeeded = true;
        },
        onFinish: () => {
            busy.value = false;
            if (succeeded) done?.();
        },
    });
}
function details(form: LeadForm): Record<string, string | null> | null {
    const amount = toMinor(form.amount, form.currency);
    if (amount === null) {
        formErrors.value = {
            value_minor: 'Enter a valid value for this currency and a three-letter uppercase currency code.',
        };
        return null;
    }
    return {
        brand_name: form.brand_name,
        contact_name: form.contact_name,
        contact_email: form.contact_email,
        notes: form.notes,
        value_minor: amount,
        currency: form.currency,
        next_action_due: form.next_action_due ? new Date(form.next_action_due).toISOString() : null,
    };
}
function create(): void {
    formAction.value = 'create';
    const data = details(createForm.value);
    if (data)
        visit('post', '/pipeline', data, () => {
            createForm.value = blank();
            creating.value = false;
        });
}
function sameLead(form: LeadForm, row: EntryDetail): boolean {
    return (
        form.brand_name === row.brand_name &&
        form.contact_name === (row.contact_name ?? '') &&
        form.contact_email === (row.contact_email ?? '') &&
        form.notes === row.notes &&
        form.currency === row.currency &&
        form.amount === fromMinor(row.value_minor, row.currency) &&
        form.next_action_due === localInput(row.next_action_due)
    );
}
function update(): void {
    const selected = props.selected;
    if (!selected) return;
    formAction.value = 'update';
    const data = details(editForm.value);
    if (!data) return;
    // Stage is not part of PATCH /pipeline/{id}. Save it with the existing stage command,
    // after a detail save so the command uses the new version.
    const nextStage = stage.value;
    const stageChanged = nextStage !== selected.stage;
    const saveStage = (): void => {
        if (stageChanged) command('stage', { stage: nextStage });
    };
    if (stageChanged && sameLead(editForm.value, selected)) {
        command('stage', { stage: nextStage });
        return;
    }
    visit('patch', `/pipeline/${selected.id}`, { ...data, version: loadedVersion.value }, saveStage);
}
function snoozeBy(days: number): void {
    const date = new Date();
    date.setDate(date.getDate() + days);
    date.setHours(9, 0, 0, 0);
    editForm.value.next_action_due = localInput(date.toISOString());
}
function activityLabel(kind: string): string {
    return activityLabels[kind] ?? kind;
}
function activityWhen(value: string): string {
    return new Date(value).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}
function currencyHint(code: string): string {
    try {
        return currencyNames.of(code) ?? '';
    } catch {
        return '';
    }
}
function onTabKey(event: KeyboardEvent): void {
    const order = ['details', 'activity'] as const;
    const index = order.indexOf(drawerTab.value);
    let next = index;
    if (event.key === 'ArrowRight') next = (index + 1) % order.length;
    else if (event.key === 'ArrowLeft') next = (index - 1 + order.length) % order.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = order.length - 1;
    else return;
    event.preventDefault();
    drawerTab.value = order[next];
    void nextTick(() => document.getElementById(next === 0 ? 'lead-tab-details' : 'lead-tab-activity')?.focus());
}
const stageLabel = computed(() => props.stages.find((item) => item.value === stage.value)?.label ?? stage.value);
const stageDot = computed(() => stageDots[stage.value] ?? 'var(--wd-muted)');
const currencyOptions = computed(() => {
    const codes = [...currencyCodes];
    if (/^[A-Z]{3}$/.test(editForm.value.currency) && !codes.includes(editForm.value.currency))
        codes.push(editForm.value.currency);
    return codes.map((code) => ({ value: code, label: code, hint: currencyHint(code) }));
});
const detailErrors = computed((): Record<string, string> => (formAction.value === 'update' ? errors.value : {}));
function command(action: string, data: Record<string, string | null>, done?: () => void): void {
    formAction.value = action;
    if (props.selected)
        visit('post', `/pipeline/${props.selected.id}/${action}`, { ...data, version: loadedVersion.value }, done);
}
function openLead(id: string): void {
    const params = new URLSearchParams(location.search);
    params.delete('create');
    params.set('entry', id);
    router.get('/partner/leads', Object.fromEntries(params), { preserveScroll: true });
}
const pipelineLoading = ref(false);
let listingVisit = 0;
function loadPage(params: Record<string, string>): void {
    const currentVisit = ++listingVisit;
    router.get('/partner/leads', params, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            pipelineLoading.value = true;
        },
        onFinish: () => {
            if (currentVisit === listingVisit) pipelineLoading.value = false;
        },
    });
}
function resetPipeline(): void {
    loadPage({});
}
function startOnboarding(): void {
    if (busy.value || errors.value.version || !props.selected) return;
    router.post(
        '/onboarding-requests',
        {
            pipeline_entry_id: props.selected.id,
            label: `Onboarding for ${props.selected.brand_name}`.slice(0, 160),
            send_email: sendOnboardingEmail.value,
            recipient_email: onboardingRecipient.value,
            notify_submission: notifySubmission.value,
        },
        {
            onStart: () => {
                busy.value = true;
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}

function reloadSelected(): void {
    if (props.selected) router.get('/partner/leads', { entry: props.selected.id }, { preserveState: false });
}
</script>

<template>
    <div ref="root" class="wd-pipeline">
        <div v-if="!active && errors.version" class="wd-banner">
            <button type="button" class="wd-link" @click="reloadSelected">Reload latest lead</button>
            <p class="wd-hint">Copy any unsaved changes before reloading.</p>
        </div>
        <p v-if="!canWrite" class="wd-hint">Your viewer role provides read-only access to this pipeline.</p>
        <PipelineBoard
            :entries="entries"
            :counts="counts"
            :totals="totals"
            :filters="filters"
            :next-cursor="nextCursor"
            :loading="pipelineLoading"
            :error="errors.q || errors.stage || errors.due || errors.cursor"
            @open="openLead"
            @load="loadPage"
            @reset="resetPipeline"
        />
        <dialog
            ref="dialog"
            :class="['wd-dialog', 'lead-dialog', { 'lead-drawer': selected }]"
            aria-labelledby="lead-dialog-title"
            @cancel.prevent="close"
            @keydown="containDialogFocus($event, dialog)"
        >
            <template v-if="selected">
                <header class="drawer-header">
                    <div class="drawer-heading-row">
                        <div class="drawer-identity">
                            <h2 id="lead-dialog-title" class="drawer-title">{{ selected.brand_name }}</h2>
                            <div class="drawer-meta">
                                <span class="stage-chip">
                                    <span class="stage-dot" :style="{ background: stageDot }" aria-hidden="true"></span>
                                    {{ stageLabel }}
                                </span>
                                <span class="drawer-value">{{ money(selected.value_minor, selected.currency) }}</span>
                            </div>
                        </div>
                        <DialogClose label="Close lead" :disabled="busy" @click="close" />
                    </div>
                    <Link class="contacts-link" :href="`/partner/contacts?entry=${selected.id}`">
                        <span class="contacts-icon" aria-hidden="true">
                            <svg
                                width="18"
                                height="18"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <circle cx="12" cy="8" r="3.5" />
                                <path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6" />
                            </svg>
                        </span>
                        <span class="contacts-copy">
                            <span class="contacts-title">Contacts &amp; reminders</span>
                            <span class="contacts-description">Manage people and due dates for this lead</span>
                        </span>
                        <svg
                            class="contacts-chevron"
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <path d="M9 6l6 6-6 6" />
                        </svg>
                    </Link>
                    <div class="drawer-tabs" role="tablist" aria-label="Lead sections" @keydown="onTabKey">
                        <button
                            id="lead-tab-details"
                            type="button"
                            role="tab"
                            :aria-selected="drawerTab === 'details'"
                            :tabindex="drawerTab === 'details' ? 0 : -1"
                            aria-controls="lead-details"
                            @click="drawerTab = 'details'"
                        >
                            Details
                        </button>
                        <button
                            id="lead-tab-activity"
                            type="button"
                            role="tab"
                            :aria-selected="drawerTab === 'activity'"
                            :tabindex="drawerTab === 'activity' ? 0 : -1"
                            aria-controls="lead-activity"
                            @click="drawerTab = 'activity'"
                        >
                            Activity
                        </button>
                    </div>
                </header>
                <div class="drawer-body">
                    <div v-if="errors.version" class="wd-banner">
                        <button type="button" class="wd-link" @click="reloadSelected">Reload latest lead</button>
                        <p class="wd-hint">Copy any unsaved changes before reloading.</p>
                    </div>
                    <div
                        v-if="drawerTab === 'details'"
                        id="lead-details"
                        role="tabpanel"
                        aria-labelledby="lead-tab-details"
                        class="drawer-stack"
                    >
                        <form v-if="canWrite" id="lead-edit-form" :aria-busy="busy" @submit.prevent="update">
                            <fieldset class="drawer-fields" :disabled="busy || !!errors.version">
                                <legend class="wd-sr-only">Lead details</legend>
                                <section class="drawer-section">
                                    <h3 class="drawer-kicker">Lead</h3>
                                    <FormField
                                        id="edit-brand"
                                        v-model="editForm.brand_name"
                                        label="Brand name"
                                        :maxlength="160"
                                        :error="detailErrors.brand_name"
                                    />
                                    <SelectField
                                        id="lead-stage"
                                        v-model="stage"
                                        label="Stage"
                                        :options="stages"
                                        empty-text="No matches"
                                        search-placeholder="Search stages"
                                        search-label="Search stages"
                                        show-mark
                                        show-search-icon
                                        :error="formAction === 'stage' ? errors.stage : undefined"
                                    />
                                    <div class="wd-field">
                                        <label for="edit-amount">Estimated value</label>
                                        <div class="value-row">
                                            <SelectField
                                                id="edit-currency"
                                                v-model="editForm.currency"
                                                class="value-currency"
                                                label="Currency"
                                                :options="currencyOptions"
                                                empty-text="No matches"
                                                search-placeholder="Search code or name"
                                                search-label="Search currencies"
                                                show-mark
                                                show-search-icon
                                                :error="detailErrors.currency"
                                            />
                                            <input
                                                id="edit-amount"
                                                v-model="editForm.amount"
                                                class="value-amount"
                                                inputmode="decimal"
                                                maxlength="24"
                                                :aria-invalid="detailErrors.value_minor ? true : undefined"
                                                :aria-describedby="
                                                    [
                                                        'edit-value-hint',
                                                        detailErrors.value_minor ? 'edit-amount-error' : '',
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' ') || undefined
                                                "
                                            />
                                        </div>
                                        <p id="edit-value-hint" class="wd-hint">
                                            Totals are kept separate for each currency.
                                        </p>
                                        <p
                                            v-if="detailErrors.value_minor"
                                            id="edit-amount-error"
                                            class="wd-field-error"
                                            role="alert"
                                        >
                                            {{ detailErrors.value_minor }}
                                        </p>
                                    </div>
                                </section>
                                <section class="drawer-section">
                                    <h3 class="drawer-kicker">Primary contact</h3>
                                    <div class="contact-grid">
                                        <FormField
                                            id="edit-contact"
                                            v-model="editForm.contact_name"
                                            :required="false"
                                            label="Name"
                                            :maxlength="120"
                                            :error="detailErrors.contact_name"
                                        />
                                        <FormField
                                            id="edit-email"
                                            v-model="editForm.contact_email"
                                            :required="false"
                                            label="Email"
                                            type="email"
                                            :maxlength="255"
                                            :error="detailErrors.contact_email"
                                        />
                                    </div>
                                </section>
                                <section class="drawer-section">
                                    <h3 class="drawer-kicker">Follow-up</h3>
                                    <FormField
                                        id="edit-due"
                                        v-model="editForm.next_action_due"
                                        :required="false"
                                        label="Next action"
                                        type="datetime-local"
                                        hint="Shown in your local time."
                                        :error="detailErrors.next_action_due"
                                    >
                                        <div class="snooze-row">
                                            <span>Snooze:</span>
                                            <button type="button" class="snooze-chip" @click="snoozeBy(1)">
                                                Tomorrow
                                            </button>
                                            <button type="button" class="snooze-chip" @click="snoozeBy(3)">
                                                In 3 days
                                            </button>
                                            <button type="button" class="snooze-chip" @click="snoozeBy(7)">
                                                Next week
                                            </button>
                                        </div>
                                    </FormField>
                                    <div class="wd-field">
                                        <label for="edit-notes">Private notes</label>
                                        <textarea
                                            id="edit-notes"
                                            v-model="editForm.notes"
                                            class="drawer-notes"
                                            rows="3"
                                            maxlength="5000"
                                            placeholder="Only you can see these"
                                            :aria-invalid="detailErrors.notes ? true : undefined"
                                            :aria-describedby="detailErrors.notes ? 'edit-notes-error' : undefined"
                                        />
                                        <p
                                            v-if="detailErrors.notes"
                                            id="edit-notes-error"
                                            class="wd-field-error"
                                            role="alert"
                                        >
                                            {{ detailErrors.notes }}
                                        </p>
                                    </div>
                                </section>
                            </fieldset>
                        </form>
                        <div v-else class="drawer-fields">
                            <section class="drawer-section">
                                <h3 class="drawer-kicker">Lead</h3>
                                <p>{{ selected.brand_name }}</p>
                                <p>{{ stageLabel }}</p>
                                <p class="drawer-value">{{ money(selected.value_minor, selected.currency) }}</p>
                            </section>
                            <section class="drawer-section">
                                <h3 class="drawer-kicker">Primary contact</h3>
                                <p>{{ selected.contact_name }} {{ selected.contact_email }}</p>
                            </section>
                            <section class="drawer-section">
                                <h3 class="drawer-kicker">Follow-up</h3>
                                <p>{{ stamp(selected.next_action_due) }}</p>
                                <p class="wd-pipeline-note">{{ selected.notes || 'No private notes yet.' }}</p>
                            </section>
                        </div>
                        <form
                            v-if="canWrite"
                            class="onboarding-card"
                            :aria-busy="busy"
                            @submit.prevent="startOnboarding"
                        >
                            <fieldset class="onboarding-fields" :disabled="busy || !!errors.version">
                                <legend class="wd-sr-only">Onboarding invitation</legend>
                                <div class="onboarding-copy">
                                    <h3>Onboarding invitation</h3>
                                    <p>Start onboarding when this lead is ready to join.</p>
                                </div>
                                <OnboardingEmailFields
                                    prefix="start-onboarding"
                                    v-model:send-email="sendOnboardingEmail"
                                    v-model:recipient="onboardingRecipient"
                                    :errors="errors"
                                />
                                <Button type="submit" class="wd-button wd-button-secondary drawer-outline-brand"
                                    >Start onboarding</Button
                                >
                                <OnboardingSubmissionNotice
                                    prefix="start-onboarding"
                                    v-model="notifySubmission"
                                    :error="errors.notify_submission"
                                />
                            </fieldset>
                        </form>
                        <details v-if="canWrite" class="drawer-delete">
                            <summary>
                                <span>Delete lead</span>
                                <svg
                                    width="18"
                                    height="18"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    aria-hidden="true"
                                >
                                    <path d="M6 9l6 6 6-6" />
                                </svg>
                            </summary>
                            <div class="drawer-delete-body">
                                <p>
                                    This removes {{ selected.brand_name }} and its activity history. This can't be
                                    undone.
                                </p>
                                <Button
                                    type="button"
                                    class="wd-button wd-button-secondary drawer-delete-button"
                                    :disabled="busy || !!errors.version"
                                    @click="
                                        confirmAction(
                                            'Delete permanently',
                                            `Permanently delete ${selected.brand_name}, its private notes and activity history?`,
                                            () =>
                                                visit('delete', `/pipeline/${selected!.id}`, {
                                                    version: loadedVersion,
                                                }),
                                            $event,
                                            { tone: 'danger' },
                                        )
                                    "
                                    >Delete this lead</Button
                                >
                            </div>
                        </details>
                    </div>
                    <div
                        v-else
                        id="lead-activity"
                        role="tabpanel"
                        aria-labelledby="lead-tab-activity"
                        class="drawer-stack"
                    >
                        <form
                            v-if="canWrite"
                            :aria-busy="busy"
                            @submit.prevent="
                                command('activities', { kind: activityKind, body: activityBody }, () => {
                                    activityBody = '';
                                })
                            "
                        >
                            <fieldset class="activity-fields" :disabled="busy || !!errors.version">
                                <legend class="wd-sr-only">Log an activity</legend>
                                <h3 class="drawer-heading">Log an activity</h3>
                                <SelectField
                                    id="activity-kind"
                                    v-model="activityKind"
                                    label="Type"
                                    :options="activityOptions"
                                    empty-text="No matches"
                                    search-placeholder="Search activity types"
                                    search-label="Search activity types"
                                    show-mark
                                    show-search-icon
                                    :error="formAction === 'activities' ? errors.kind : undefined"
                                />
                                <div class="wd-field">
                                    <label for="activity-body">Details</label>
                                    <textarea
                                        id="activity-body"
                                        v-model="activityBody"
                                        class="drawer-activity-notes"
                                        maxlength="5000"
                                        rows="4"
                                        placeholder="What happened?"
                                        :aria-invalid="formAction === 'activities' && errors.body ? true : undefined"
                                        :aria-describedby="
                                            [
                                                'activity-body-hint',
                                                formAction === 'activities' && errors.body ? 'activity-body-error' : '',
                                            ]
                                                .filter(Boolean)
                                                .join(' ')
                                        "
                                    />
                                    <p id="activity-body-hint" class="wd-hint">
                                        Records a past conversation or private note. No message is sent.
                                    </p>
                                    <p
                                        v-if="formAction === 'activities' && errors.body"
                                        id="activity-body-error"
                                        class="wd-field-error"
                                        role="alert"
                                    >
                                        {{ errors.body }}
                                    </p>
                                </div>
                                <Button class="wd-button wd-button-primary" type="submit">Record activity</Button>
                            </fieldset>
                        </form>
                        <section class="history" aria-labelledby="activity-history-title">
                            <div class="history-head">
                                <h3 id="activity-history-title" class="drawer-heading">Activity history</h3>
                                <p class="wd-hint">Most recent 50 events</p>
                            </div>
                            <ol class="timeline">
                                <li v-for="item in selected.activities" :key="item.id">
                                    <span class="timeline-rail" aria-hidden="true"
                                        ><span class="timeline-dot"></span
                                    ></span>
                                    <span class="timeline-copy">
                                        <span class="timeline-title">{{ item.body || activityLabel(item.kind) }}</span>
                                        <span class="timeline-meta"
                                            >{{ activityLabel(item.kind) }} ·
                                            <time :datetime="item.occurred_at">{{
                                                activityWhen(item.occurred_at)
                                            }}</time></span
                                        >
                                    </span>
                                </li>
                            </ol>
                        </section>
                    </div>
                </div>
                <footer v-if="drawerTab === 'details' && canWrite" class="drawer-footer">
                    <Button type="button" class="wd-button wd-button-secondary" :disabled="busy" @click="close"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        form="lead-edit-form"
                        class="wd-button wd-button-primary"
                        :disabled="busy || !!errors.version"
                        >{{ busy ? 'Saving…' : 'Save lead' }}</Button
                    >
                </footer>
            </template>
            <template v-else>
                <div class="wd-dialog-header">
                    <h2 id="lead-dialog-title">Add lead</h2>
                    <DialogClose label="Close add lead" :disabled="busy" @click="close" />
                </div>
                <div v-if="errors.version" class="wd-banner">
                    <button type="button" class="wd-link" @click="reloadSelected">Reload latest lead</button>
                    <p class="wd-hint">Copy any unsaved changes before reloading.</p>
                </div>
                <form v-if="creating && canWrite" :aria-busy="busy" @submit.prevent="create">
                    <fieldset class="wd-fields" :disabled="busy || !!errors.version">
                        <legend class="wd-sr-only">New lead</legend>
                        <EntryFields
                            v-model="createForm"
                            prefix="create"
                            :errors="formAction === 'create' ? errors : {}"
                        />
                        <div class="wd-dialog-footer">
                            <Button type="button" class="wd-button wd-button-secondary" @click="close">Cancel</Button>
                            <Button type="submit" class="wd-button wd-button-primary">{{
                                busy ? 'Saving…' : 'Capture lead'
                            }}</Button>
                        </div>
                    </fieldset>
                </form>
            </template>
        </dialog>
    </div>
</template>
<style scoped>
.lead-dialog {
    width: min(640px, calc(100dvw - 32px));
    max-width: calc(100dvw - 32px);
}
.lead-dialog.lead-drawer {
    inset: 0 0 0 auto;
    display: flex;
    flex-direction: column;
    width: min(480px, 100dvw);
    max-width: 100dvw;
    height: 100dvh;
    max-height: 100dvh;
    margin: 0;
    padding: 0;
    overflow: clip;
    border-width: 0 0 0 1px;
    border-radius: 0;
}
.lead-drawer :focus-visible {
    outline: 2px solid var(--wd-brand);
    outline-offset: 2px;
    box-shadow: none;
}
.lead-drawer :deep(.wd-field > label) {
    font-size: 14px;
    font-weight: 600;
    color: var(--wd-ink);
}
.lead-drawer :deep(.wd-hint) {
    font-size: 13px;
}
.lead-drawer :deep(.wd-select-panel) {
    max-height: none;
    border-radius: 12px;
    box-shadow: 0 8px 24px rgb(31 26 29 / 16%);
}
.lead-drawer :deep(.wd-select-options) {
    max-height: 200px;
}
.lead-drawer :deep(.wd-select-option:hover),
.lead-drawer :deep(.wd-select-option.is-active) {
    background: var(--wd-divider);
}
.lead-drawer :deep(.wd-select-option[aria-selected='true']) {
    background: var(--color-blush, var(--wd-paper));
    font-weight: 600;
}
.drawer-header {
    display: flex;
    flex: none;
    flex-direction: column;
    gap: 16px;
    padding: 24px 24px 16px;
}
.drawer-heading-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
}
.drawer-identity {
    display: flex;
    min-width: 0;
    flex-direction: column;
    gap: 8px;
}
.drawer-title {
    margin: 0;
    font-size: 24px;
    line-height: 30px;
    letter-spacing: -0.01em;
}
.drawer-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--wd-muted);
    font-size: 14px;
}
.drawer-value {
    font-variant-numeric: tabular-nums;
}
.stage-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 10px;
    border-radius: 999px;
    background: var(--wd-divider);
    color: var(--wd-ink);
    font-size: 13px;
    font-weight: 600;
}
.stage-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}
.drawer-heading-row :deep(.wd-dialog-close) {
    margin: -8px -10px 0 0;
}
.contacts-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border: 1px solid var(--color-lilac, var(--wd-line));
    border-radius: 12px;
    background: var(--color-blush, var(--wd-paper));
    color: var(--wd-ink);
    text-decoration: none;
}
.contacts-icon {
    display: inline-flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border: 1px solid var(--color-lilac, var(--wd-line));
    border-radius: 8px;
    background: var(--wd-surface);
    color: var(--wd-brand);
}
.contacts-copy {
    display: flex;
    min-width: 0;
    flex: 1;
    flex-direction: column;
}
.contacts-title {
    font-size: 15px;
    font-weight: 600;
}
.contacts-description {
    color: var(--wd-muted);
    font-size: 13px;
}
.contacts-chevron {
    flex: none;
    color: var(--wd-muted);
}
.drawer-tabs {
    display: flex;
    gap: 4px;
    padding: 4px;
    border-radius: 12px;
    background: var(--wd-divider);
}
.drawer-tabs [role='tab'] {
    flex: 1;
    height: 36px;
    border: 0;
    border-radius: 9px;
    background: transparent;
    color: var(--wd-muted);
    font-size: 14px;
    font-weight: 600;
}
.drawer-tabs [role='tab'][aria-selected='true'] {
    background: var(--wd-surface);
    color: var(--wd-ink);
    box-shadow: 0 1px 2px rgb(31 26 29 / 14%);
}
.drawer-body {
    flex: 1 1 auto;
    min-height: 0;
    padding: 8px 24px 24px;
    overflow-y: auto;
    border-top: 1px solid var(--wd-divider);
}
.drawer-stack,
.drawer-fields,
.activity-fields {
    display: flex;
    flex-direction: column;
    gap: 28px;
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
}
.drawer-stack {
    padding-top: 16px;
}
.activity-fields {
    gap: 16px;
}
.drawer-section {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.drawer-kicker,
.drawer-heading {
    margin: 0;
    font-family: var(--wd-font-body);
}
.drawer-kicker {
    color: var(--wd-muted);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.drawer-heading {
    color: var(--wd-ink);
    font-size: 16px;
    font-weight: 700;
}
.contact-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.value-row {
    display: flex;
    gap: 8px;
    align-items: flex-start;
}
.value-currency {
    position: relative;
    flex: none;
    width: 104px;
}
.value-currency :deep(> label) {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: 0;
    padding: 0;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
.value-currency :deep(.wd-select-panel) {
    right: auto;
    width: min(320px, calc(100vw - 48px));
}
.value-amount {
    flex: 1;
    min-width: 0;
    font-variant-numeric: tabular-nums;
    text-align: right;
}
.lead-drawer textarea {
    padding: 12px 14px;
    line-height: 22px;
    resize: vertical;
}
.drawer-notes {
    height: 88px;
}
.drawer-activity-notes {
    height: 104px;
}
.snooze-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-top: 4px;
    color: var(--wd-muted);
    font-size: 13px;
}
.snooze-chip {
    height: 32px;
    padding: 0 12px;
    border: 1px solid var(--wd-control-line);
    border-radius: 999px;
    background: var(--wd-surface);
    color: var(--wd-ink);
    font-size: 13px;
    font-weight: 600;
}
.onboarding-card {
    display: flex;
    flex-direction: column;
    padding: 16px;
    border: 1px solid var(--color-lilac, var(--wd-line));
    border-radius: 12px;
    background: var(--color-blush, var(--wd-paper));
}
.onboarding-fields {
    display: flex;
    flex-direction: column;
    gap: 14px;
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
}
.onboarding-copy h3 {
    margin: 0;
    font-family: var(--wd-font-body);
    font-size: 16px;
    font-weight: 700;
}
.onboarding-copy p {
    margin: 0;
    color: var(--wd-muted);
    font-size: 13px;
}
.onboarding-card :deep(.wd-fields > label) {
    align-items: center;
    min-height: 44px;
    font-size: 15px;
    font-weight: 400;
}
.drawer-footer :deep(.wd-button-secondary) {
    border-color: var(--wd-control-line);
    background: var(--wd-surface);
    color: var(--wd-ink);
}
.drawer-footer :deep(.wd-button-primary),
.activity-fields :deep(.wd-button-primary) {
    border-color: transparent;
    background: var(--wd-brand);
    color: #fff;
}
.activity-fields :deep(.wd-button),
.onboarding-fields :deep(.wd-button) {
    align-self: flex-start;
}
.drawer-outline-brand {
    border-color: var(--wd-brand);
    background: var(--wd-surface);
    color: var(--wd-brand);
}
.drawer-delete {
    border-top: 1px solid var(--wd-divider);
    padding-top: 8px;
}
.drawer-delete summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 44px;
    color: var(--wd-danger);
    font-size: 15px;
    font-weight: 600;
    list-style: none;
    cursor: pointer;
}
.drawer-delete summary::-webkit-details-marker {
    display: none;
}
.drawer-delete[open] .drawer-delete-body {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 4px 0 8px;
}
.drawer-delete-body p {
    margin: 0;
    color: var(--wd-muted);
    font-size: 13px;
    line-height: 19px;
}
.drawer-delete-button {
    align-self: flex-start;
    border-color: var(--wd-danger);
    background: var(--wd-surface);
    color: var(--wd-danger);
}
.history {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding-top: 24px;
    border-top: 1px solid var(--wd-divider);
}
.history-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
}
.history-head .wd-hint {
    margin: 0;
}
.drawer-stack > .history:first-child {
    padding-top: 0;
    border-top: 0;
}
.timeline {
    display: flex;
    flex-direction: column;
    gap: 16px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.timeline li {
    display: flex;
    gap: 14px;
}
.timeline-rail {
    display: flex;
    flex: none;
    flex-direction: column;
    align-items: center;
    padding-top: 6px;
}
.timeline-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--wd-brand);
}
.timeline-copy {
    display: flex;
    min-width: 0;
    flex-direction: column;
    gap: 2px;
}
.timeline-title {
    font-size: 15px;
    font-weight: 600;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}
.timeline-meta {
    color: var(--wd-muted);
    font-size: 13px;
}
.drawer-footer {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    padding: 16px 24px;
    border-top: 1px solid var(--wd-divider);
    background: var(--wd-surface);
}
/* A mobile layout viewport can widen after focus/scroll inside a native dialog.
   Anchor to the device viewport so pointer coordinates still reach its controls. */
@media (max-width: 640px) {
    .lead-dialog {
        inset: 16px auto auto 16px;
        margin: 0;
    }
    .lead-dialog.lead-drawer {
        inset: 0 auto 0 0;
    }
}
</style>
