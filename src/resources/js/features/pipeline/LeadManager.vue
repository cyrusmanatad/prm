<script setup lang="ts">
import { useWorkspaceExperience } from '../../composables/useWorkspaceExperience';
const { professional, path, clientLabel } = useWorkspaceExperience();
import { confirmAction } from '../../composables/useConfirmation';
import { containDialogFocus } from '../../composables/useDialogFocus';
import { Link, router } from '@inertiajs/vue3';
import { computed, inject, nextTick, onMounted, onUnmounted, ref, watch, type Component, type Ref } from 'vue';
import {
    ArrowRightLeft,
    ChevronRight,
    Circle,
    Clock,
    Mail,
    MessageCircle,
    NotebookPen,
    Pencil,
    Phone,
    Plus,
    Reply,
} from '@lucide/vue';
import FilterChips, { type FilterChip } from '../../components/FilterChips.vue';
import { activityGroup, activityLabels, byDay, changeText, timeLabel, type ActivityGroup } from './activity';
import { Button } from '../../components/ui/button';
import FormField from '../../components/FormField.vue';
import SelectField from '../../components/SelectField.vue';
import DialogClose from '../../components/DialogClose.vue';
import OnboardingEmailFields from '../../components/OnboardingEmailFields.vue';
import OnboardingSubmissionNotice from '../../components/OnboardingSubmissionNotice.vue';
import EntryFields from './EntryFields.vue';
import { localInput } from './leadFields';
import StatusBadge, { type BadgeTone } from '../../components/StatusBadge.vue';
import { ago, requestStatuses, type RequestStatus } from '../onboarding/requests';
import PipelineBoard from './PipelineBoard.vue';
import { fromMinor, money, toMinor } from './money';
import { pipelineStages } from './stages';
import type { Entry, EntryDetail, LeadForm, PipelineWorkspace } from './types';
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
        router.get(path('/partner/leads'), Object.fromEntries(params), { preserveScroll: true, preserveState: true });
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
    amount: '',
    next_action_due: '',
});
const createForm = ref(blank());
// Optional: the connected Brand a new lead is for. Empty keeps the lead private.
const registerWith = ref('');
const brandChoices = computed(() =>
    props.registration?.canRegister
        ? props.registration.brands
              .filter((brand) => !brand.paused)
              .map((brand) => ({ value: brand.id, label: brand.brand_name }))
        : [],
);
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
    { value: 'reply', label: 'Reply received' },
];
// The composer opens from a one-line box so the history stays in view; it closes after recording or on Cancel.
const composerOpen = ref(false);
const composerTrigger = ref<HTMLButtonElement>();
async function openComposer(): Promise<void> {
    composerOpen.value = true;
    await nextTick();
    document.querySelector<HTMLInputElement>('#lead-activity .activity-kind input:checked')?.focus();
}
async function closeComposer(): Promise<void> {
    if (busy.value) return;
    composerOpen.value = false;
    activityBody.value = '';
    activityKind.value = 'note';
    await nextTick();
    composerTrigger.value?.focus();
}
function onComposerKey(event: KeyboardEvent): void {
    // Escape closes the composer first, not the whole drawer.
    if (event.key !== 'Escape' || !composerOpen.value) return;
    event.preventDefault();
    event.stopPropagation();
    void closeComposer();
}
const historyFilter = ref<'all' | ActivityGroup>('all');
const historyChips = computed<FilterChip[]>(() => {
    const items = props.selected?.activities ?? [];
    const conversations = items.filter((item) => activityGroup(item.kind) === 'conversation').length;
    return [
        { value: 'all', label: 'All', count: items.length },
        { value: 'conversation', label: 'Conversations', count: conversations },
        { value: 'change', label: 'Changes', count: items.length - conversations },
    ];
});
const historyDays = computed(() =>
    byDay(
        (props.selected?.activities ?? []).filter(
            (item) => historyFilter.value === 'all' || activityGroup(item.kind) === historyFilter.value,
        ),
    ),
);
const stageName = (value: string): string => props.stages.find((item) => item.value === value)?.label ?? value;
function recordActivity(event: Event): void {
    if (busy.value || errors.value.version) return;
    const kind = activityKind.value;
    const body = activityBody.value;
    const save = () =>
        command('activities', { kind, body }, () => {
            activityBody.value = '';
            if (kind === 'reply') editForm.value.next_action_due = '';
            composerOpen.value = false;
            activityKind.value = 'note';
        });
    if (kind === 'reply') {
        confirmAction(
            'Record reply',
            `Record a reply for “${props.selected?.brand_name}”? This clears its next-action date and permanently cancels existing follow-up schedules and drafts for everyone in this workspace. Other reminders stay active. Previously downloaded messages cannot be recalled.`,
            save,
            event,
            { tone: 'danger' },
        );
    } else save();
}
const stageDots = Object.fromEntries(pipelineStages.map((stage) => [stage.value, stage.dot]));
const stamp = (value: string | null): string => (value ? new Date(value).toLocaleString() : 'No next action');
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
        composerOpen.value = false;
        historyFilter.value = 'all';
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
    // The value is optional; an empty amount is stored as zero.
    const amount = toMinor(form.amount.trim() === '' ? '0' : form.amount, form.currency);
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
    // The form skips browser validation bubbles; the name is checked here and every field again on the server.
    if (!createForm.value.brand_name.trim()) {
        formErrors.value = { brand_name: professional.value ? 'Enter the client name.' : 'Enter the Brand name.' };
        return;
    }
    const data = details(createForm.value);
    if (data)
        visit('post', '/pipeline', registerWith.value ? { ...data, register_with: registerWith.value } : data, () => {
            createForm.value = blank();
            registerWith.value = '';
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
// Unsaved edits: the footer says so and offers Discard; Save stays off until something changed.
const dirty = computed(
    () =>
        !!props.selected &&
        props.canWrite &&
        (!sameLead(editForm.value, props.selected) || stage.value !== props.selected.stage),
);
function discard(): void {
    if (busy.value || !props.selected) return;
    refreshEdit();
    stage.value = props.selected.stage;
    formErrors.value = {};
}
function deleteLead(event: Event): void {
    const selected = props.selected;
    if (!selected) return;
    confirmAction(
        'Delete permanently',
        `Permanently delete ${selected.brand_name}, its private notes and activity history? This can't be undone.`,
        () => {
            formAction.value = 'delete';
            visit('delete', `/pipeline/${selected.id}`, { version: loadedVersion.value });
        },
        event,
        { tone: 'danger' },
    );
}
const updatedAgo = computed(() => (props.selected?.updated_at ? ago(props.selected.updated_at) : ''));
const onboardingState = computed(() => {
    const request = props.selected?.onboarding;
    if (!request) return { label: 'Not started', tone: 'neutral' as BadgeTone, detail: 'No onboarding request yet' };
    const known = requestStatuses[request.status as RequestStatus];
    return {
        label: known?.label ?? request.status,
        tone: known?.tone ?? ('neutral' as BadgeTone),
        detail: `Started ${ago(request.created_at)}`,
    };
});
const sharingState = computed(() => {
    const shared = props.selected?.sharing ?? [];
    if (!shared.length) return { label: 'Private', tone: 'neutral' as BadgeTone, detail: 'Not shared with any Brand' };
    const paused = shared.every((item) => item.paused);
    const first = shared[0]!;
    return {
        label: paused ? 'Paused' : 'Shared',
        tone: (paused ? 'warning' : 'success') as BadgeTone,
        detail:
            shared.length === 1
                ? `Shared with ${first.brand_name} since ${shortDate(first.registered_at)}${first.paused ? ' · paused' : ''}`
                : `Shared with ${shared.length} Brands`,
    };
});
const shortDate = (value: string): string =>
    new Date(value).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });

// Starting onboarding opens a small dialog with the existing email choices.
const onboardingDialog = ref<HTMLDialogElement>();
const onboardingOpen = ref(false);
let onboardingTrigger: HTMLElement | null = null;
async function openOnboarding(event: Event): Promise<void> {
    if (busy.value) return;
    onboardingTrigger = event.currentTarget as HTMLElement;
    onboardingOpen.value = true;
    await nextTick();
    onboardingDialog.value?.showModal();
    onboardingDialog.value?.querySelector<HTMLInputElement>('input[type="checkbox"]')?.focus();
}
function onOnboardingClosed(): void {
    onboardingOpen.value = false;
    onboardingTrigger?.focus();
}
const activityIcons: Record<string, Component> = {
    note: NotebookPen,
    call: Phone,
    email: Mail,
    chat: MessageCircle,
    reply: Reply,
    created: Plus,
    stage: ArrowRightLeft,
    snoozed: Clock,
    updated: Pencil,
};
function activityIcon(kind: string): Component {
    return activityIcons[kind] ?? Circle;
}
function activityLabel(kind: string): string {
    return activityLabels[kind] ?? kind;
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
const detailErrors = computed((): Record<string, string> => (formAction.value === 'update' ? errors.value : {}));
function command(action: string, data: Record<string, string | null>, done?: () => void): void {
    formAction.value = action;
    if (props.selected)
        visit('post', `/pipeline/${props.selected.id}/${action}`, { ...data, version: loadedVersion.value }, done);
}
function openLead(id: string): void {
    if (pendingMove.value) return;
    const params = new URLSearchParams(location.search);
    params.delete('create');
    params.set('entry', id);
    router.get(path('/partner/leads'), Object.fromEntries(params), { preserveScroll: true });
}
// Only the card is optimistic; matching counts and exact totals stay server-owned.
const subscription = inject<Ref<{ state: string } | undefined>>('professionalSubscription', ref(undefined));
const workspaceActive = computed(() => !professional.value || subscription.value?.state === 'active');
const pendingMove = ref<{ id: string; stage: string } | null>(null);
const moveError = ref('');
const boardEntries = computed(() =>
    props.entries.map((row) => (row.id === pendingMove.value?.id ? { ...row, stage: pendingMove.value.stage } : row)),
);
function moveLead(lead: Entry, stage: string): void {
    if (
        !props.canWrite ||
        !workspaceActive.value ||
        busy.value ||
        pipelineLoading.value ||
        pendingMove.value ||
        active.value ||
        lead.stage === stage
    )
        return;
    if (!pipelineStages.some((item) => item.value === stage)) return;
    pendingMove.value = { id: lead.id, stage };
    busy.value = true;
    moveError.value = '';
    let stale = false;
    let succeeded = false;
    router.post(
        `/pipeline/${lead.id}/stage`,
        {
            stage,
            version: lead.version,
            board: true,
            pipeline_filters: props.filters,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                succeeded = true;
            },
            onError: (errors) => {
                stale = !!errors.version;
                moveError.value =
                    Object.values(errors).join(' ') || 'The stage could not be changed. Please try again.';
                if (stale)
                    moveError.value += ' The latest filtered board has been requested. Review it before moving again.';
            },
            onNetworkError: () => {
                moveError.value = 'The stage could not be confirmed. Reload the board before trying again.';
                return false;
            },
            onHttpException: () => {
                moveError.value =
                    'The stage could not be changed. Reload the board to check your access and the latest lead.';
                return false;
            },
            onFinish: () => {
                pendingMove.value = null;
                busy.value = false;
                if (!succeeded && !moveError.value)
                    moveError.value = 'The stage change was interrupted. Reload the board before trying again.';
                if (stale)
                    loadPage(
                        Object.fromEntries(
                            Object.entries(props.filters)
                                .filter(([, value]) => !!value)
                                .map(([key, value]) => [key, value === true ? '1' : String(value)]),
                        ),
                    );
            },
        },
    );
}
const pipelineLoading = ref(false);
let listingVisit = 0;
function loadPage(params: Record<string, string>): void {
    if (pendingMove.value) return;
    const currentVisit = ++listingVisit;
    router.get(path('/partner/leads'), params, {
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
    if (props.selected) router.get(path('/partner/leads'), { entry: props.selected.id }, { preserveState: false });
}
</script>

<template>
    <div ref="root" class="wd-pipeline">
        <div v-if="!active && errors.version && !moveError" class="wd-banner">
            <button type="button" class="wd-link" @click="reloadSelected">Reload latest lead</button>
            <p class="wd-hint">Copy any unsaved changes before reloading.</p>
        </div>
        <p v-if="!canWrite" class="wd-hint">Your viewer role provides read-only access to this pipeline.</p>
        <p v-if="moveError" role="alert" class="wd-field-error">{{ moveError }}</p>
        <PipelineBoard
            :entries="boardEntries"
            :movable="canWrite && workspaceActive && !busy && !active"
            :moving="pendingMove?.id ?? null"
            :counts="counts"
            :stage-counts="stageCounts"
            :totals="totals"
            :filters="filters"
            :next-cursor="nextCursor"
            :loading="pipelineLoading"
            :error="errors.q || errors.stage || errors.due || errors.cursor"
            @move="moveLead"
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
                                <span v-if="!canWrite" class="drawer-view-only">View only</span>
                            </div>
                        </div>
                        <div class="drawer-head-actions">
                            <button
                                v-if="canWrite && !selected.onboarding"
                                type="button"
                                class="wd-table-button drawer-delete-button"
                                aria-label="Delete lead"
                                :disabled="busy || !!errors.version"
                                @click="deleteLead"
                            >
                                Delete
                            </button>
                            <DialogClose label="Close lead" :disabled="busy" @click="close" />
                        </div>
                    </div>
                    <div class="drawer-subrow">
                        <Link class="contacts-pill" :href="path(`/partner/contacts?entry=${selected.id}`)"
                            >Contacts &amp; reminders<ChevronRight :size="14" aria-hidden="true"
                        /></Link>
                        <span v-if="updatedAgo" class="drawer-updated">Updated {{ updatedAgo }}</span>
                    </div>
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
                            Activity<span class="drawer-tab-count" aria-hidden="true">{{
                                selected.activities.length
                            }}</span>
                        </button>
                    </div>
                </header>
                <div class="drawer-body">
                    <div v-if="errors.version" class="wd-banner">
                        <button type="button" class="wd-link" @click="reloadSelected">Reload latest lead</button>
                        <p class="wd-hint">Copy any unsaved changes before reloading.</p>
                    </div>
                    <p v-if="formAction === 'delete' && errors.entry" class="wd-field-error" role="alert">
                        {{ errors.entry }}
                    </p>
                    <div
                        v-if="drawerTab === 'details'"
                        id="lead-details"
                        role="tabpanel"
                        aria-labelledby="lead-tab-details"
                        class="drawer-stack"
                    >
                        <form v-if="canWrite" id="lead-edit-form" novalidate :aria-busy="busy" @submit.prevent="update">
                            <fieldset class="drawer-fields" :disabled="busy || !!errors.version">
                                <legend class="wd-sr-only">Lead details</legend>
                                <EntryFields v-model="editForm" prefix="edit" :errors="detailErrors">
                                    <template #stage>
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
                                    </template>
                                </EntryFields>
                            </fieldset>
                        </form>
                        <dl v-else class="drawer-summary">
                            <dt>Contact</dt>
                            <dd>
                                <template v-if="selected.contact_name || selected.contact_email">
                                    {{ selected.contact_name }}
                                    <span v-if="selected.contact_email" class="drawer-muted">{{
                                        selected.contact_email
                                    }}</span>
                                </template>
                                <span v-else class="drawer-muted">None</span>
                            </dd>
                            <dt>Next action</dt>
                            <dd :class="{ 'drawer-muted': !selected.next_action_due }">
                                {{ selected.next_action_due ? stamp(selected.next_action_due) : 'None' }}
                            </dd>
                            <dt>Private notes</dt>
                            <dd :class="{ 'drawer-muted': !selected.notes }">{{ selected.notes || 'None' }}</dd>
                        </dl>
                        <section class="drawer-status" aria-label="Onboarding and Brand sharing">
                            <div class="drawer-status-row">
                                <span class="drawer-status-copy"
                                    ><strong>Onboarding</strong><small>{{ onboardingState.detail }}</small></span
                                >
                                <StatusBadge :label="onboardingState.label" :tone="onboardingState.tone" />
                                <Link
                                    v-if="selected.onboarding"
                                    class="wd-table-button"
                                    :href="`${path('/partner/onboarding')}?request=${selected.onboarding.id}`"
                                    >Open</Link
                                >
                                <button
                                    v-else-if="canWrite"
                                    type="button"
                                    class="wd-table-button"
                                    :disabled="busy || !!errors.version"
                                    @click="openOnboarding"
                                >
                                    Start onboarding…
                                </button>
                            </div>
                            <p v-if="canWrite && selected.onboarding" class="drawer-status-note">
                                Leads with onboarding history are kept. Revoke access from Onboarding requests.
                            </p>
                            <div class="drawer-status-row">
                                <span class="drawer-status-copy"
                                    ><strong>Brand sharing</strong><small>{{ sharingState.detail }}</small></span
                                >
                                <StatusBadge :label="sharingState.label" :tone="sharingState.tone" />
                                <Link
                                    class="wd-table-button"
                                    :href="`${path('/partner/brand-sharing')}?lead=${selected.id}`"
                                    >{{ selected.sharing?.length ? 'Manage sharing' : 'Share with a Brand' }}</Link
                                >
                            </div>
                            <p
                                v-for="item in (selected.sharing ?? []).filter((row) => row.campaign)"
                                :key="item.campaign!.id"
                                class="drawer-status-note"
                            >
                                Source campaign for {{ item.brand_name }}:
                                <Link class="wd-link" :href="`/partner/campaigns/${item.campaign!.id}`">{{
                                    item.campaign!.title
                                }}</Link
                                >. Change it in Brand sharing.
                            </p>
                        </section>
                    </div>
                    <div
                        v-else
                        id="lead-activity"
                        role="tabpanel"
                        aria-labelledby="lead-tab-activity"
                        class="drawer-stack"
                    >
                        <template v-if="canWrite">
                            <button
                                v-show="!composerOpen"
                                ref="composerTrigger"
                                type="button"
                                class="composer-open"
                                aria-controls="activity-composer"
                                :aria-expanded="composerOpen"
                                :disabled="!!errors.version"
                                @click="openComposer"
                            >
                                <NotebookPen :size="15" aria-hidden="true" />
                                <span class="composer-open-text">Log a call, email, chat or note…</span>
                                <span class="composer-open-action">Log activity</span>
                            </button>
                            <form
                                v-show="composerOpen"
                                id="activity-composer"
                                class="activity-composer"
                                :aria-busy="busy"
                                @submit.prevent="recordActivity($event)"
                                @keydown="onComposerKey"
                            >
                                <fieldset class="activity-fields" :disabled="busy || !!errors.version">
                                    <legend class="wd-sr-only">Log an activity</legend>
                                    <fieldset class="activity-kinds" role="radiogroup">
                                        <legend>Type</legend>
                                        <label
                                            v-for="option in activityOptions"
                                            :key="option.value"
                                            class="activity-kind"
                                        >
                                            <input
                                                v-model="activityKind"
                                                type="radio"
                                                name="activity-kind"
                                                :value="option.value"
                                            />
                                            <span>{{ option.label }}</span>
                                        </label>
                                    </fieldset>
                                    <div class="wd-field">
                                        <label for="activity-body">Details</label>
                                        <textarea
                                            id="activity-body"
                                            v-model="activityBody"
                                            class="drawer-activity-notes"
                                            maxlength="5000"
                                            rows="3"
                                            placeholder="What happened?"
                                            :aria-invalid="
                                                formAction === 'activities' && errors.body ? true : undefined
                                            "
                                            :aria-describedby="
                                                [
                                                    'activity-body-hint',
                                                    formAction === 'activities' && errors.body
                                                        ? 'activity-body-error'
                                                        : '',
                                                ]
                                                    .filter(Boolean)
                                                    .join(' ')
                                            "
                                        />
                                        <p
                                            v-if="formAction === 'activities' && errors.kind"
                                            class="wd-field-error"
                                            role="alert"
                                        >
                                            {{ errors.kind }}
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
                                    <div class="activity-submit">
                                        <p id="activity-body-hint" class="wd-hint">
                                            {{
                                                activityKind === 'reply'
                                                    ? 'Cancels existing follow-ups and clears the next-action date after confirmation. Other reminders stay active.'
                                                    : 'Records a past conversation or private note. No message is sent.'
                                            }}
                                        </p>
                                        <div class="activity-actions">
                                            <Button
                                                type="button"
                                                class="wd-button wd-button-secondary"
                                                @click="closeComposer"
                                                >Cancel</Button
                                            >
                                            <Button class="wd-button wd-button-primary" type="submit"
                                                >Record activity</Button
                                            >
                                        </div>
                                    </div>
                                </fieldset>
                            </form>
                        </template>
                        <section class="history" aria-labelledby="activity-history-title">
                            <div class="history-head">
                                <h3 id="activity-history-title" class="drawer-heading">Activity history</h3>
                                <FilterChips
                                    label="Show activity"
                                    :chips="historyChips"
                                    :current="historyFilter"
                                    compact
                                    @select="historyFilter = $event as 'all' | ActivityGroup"
                                />
                            </div>
                            <p v-if="!historyDays.length" class="wd-hint history-empty">
                                {{
                                    historyFilter === 'conversation'
                                        ? 'No conversations logged yet.'
                                        : 'No automatic changes yet.'
                                }}
                            </p>
                            <div v-for="group in historyDays" :key="group.day" class="history-day">
                                <h4 class="history-day-label">{{ group.day }}</h4>
                                <ol class="timeline">
                                    <li
                                        v-for="item in group.items"
                                        :key="item.id"
                                        :class="activityGroup(item.kind) === 'change' ? 'is-change' : 'is-conversation'"
                                    >
                                        <span class="timeline-icon" aria-hidden="true"
                                            ><component :is="activityIcon(item.kind)" :size="14"
                                        /></span>
                                        <div v-if="activityGroup(item.kind) === 'conversation'" class="timeline-card">
                                            <p class="timeline-meta">
                                                <strong>{{ activityLabel(item.kind) }}</strong>
                                                <time :datetime="item.occurred_at">{{
                                                    timeLabel(item.occurred_at)
                                                }}</time>
                                            </p>
                                            <p class="timeline-title">{{ item.body || activityLabel(item.kind) }}</p>
                                        </div>
                                        <p v-else class="timeline-change">
                                            {{ changeText(item, stageName).lead }}
                                            <strong v-if="changeText(item, stageName).value">{{
                                                changeText(item, stageName).value
                                            }}</strong>
                                            <span class="timeline-time"
                                                >·
                                                <time :datetime="item.occurred_at">{{
                                                    timeLabel(item.occurred_at)
                                                }}</time></span
                                            >
                                        </p>
                                    </li>
                                </ol>
                            </div>
                            <p class="history-end">
                                {{
                                    selected.activities.length >= 50
                                        ? 'Showing the latest 50 events.'
                                        : "Start of this lead's history"
                                }}
                            </p>
                        </section>
                    </div>
                </div>
                <footer v-if="drawerTab === 'details' && canWrite" class="drawer-footer">
                    <p v-if="dirty" class="drawer-dirty" role="status">
                        <span aria-hidden="true"></span>Unsaved changes
                    </p>
                    <p v-else class="drawer-clean">No unsaved changes</p>
                    <Button
                        v-if="dirty"
                        type="button"
                        class="wd-button wd-button-secondary"
                        :disabled="busy"
                        @click="discard"
                        >Discard</Button
                    >
                    <Button
                        type="submit"
                        form="lead-edit-form"
                        class="wd-button wd-button-primary"
                        :disabled="busy || !!errors.version || !dirty"
                        >{{ busy ? 'Saving…' : 'Save lead' }}</Button
                    >
                </footer>
            </template>
            <template v-else>
                <header class="create-header">
                    <div>
                        <h2 id="lead-dialog-title">Add lead</h2>
                        <p class="create-sub">
                            Private to your workspace · starts in
                            <span class="stage-chip"
                                ><span
                                    class="stage-dot"
                                    :style="{ background: stageDots.lead }"
                                    aria-hidden="true"
                                ></span
                                >Lead</span
                            >
                        </p>
                    </div>
                    <DialogClose label="Close add lead" :disabled="busy" @click="close" />
                </header>
                <form
                    v-if="creating && canWrite"
                    class="create-form"
                    novalidate
                    :aria-busy="busy"
                    @submit.prevent="create"
                >
                    <div class="create-body">
                        <div v-if="errors.version" class="wd-banner">
                            <button type="button" class="wd-link" @click="reloadSelected">Reload latest lead</button>
                            <p class="wd-hint">Copy any unsaved changes before reloading.</p>
                        </div>
                        <fieldset class="create-fields" :disabled="busy || !!errors.version">
                            <legend class="wd-sr-only">New lead</legend>
                            <EntryFields
                                v-model="createForm"
                                prefix="create"
                                :errors="formAction === 'create' ? errors : {}"
                            />
                            <div v-if="brandChoices.length" class="create-share">
                                <SelectField
                                    id="create-register-with"
                                    v-model="registerWith"
                                    label="Share with a Brand (optional)"
                                    :options="[{ value: '', label: 'No Brand — keep private' }, ...brandChoices]"
                                    :searchable="brandChoices.length > 8"
                                    hint="The Brand sees the lead's name and stage. Notes, value and contact stay private. Change this later in Brand sharing."
                                    :error="formAction === 'create' ? errors.register_with : undefined"
                                />
                            </div>
                        </fieldset>
                    </div>
                    <footer class="create-footer">
                        <p class="create-required">
                            Only the {{ professional ? 'client name' : 'Brand name' }} is required.
                        </p>
                        <Button type="button" class="wd-button wd-button-secondary" :disabled="busy" @click="close"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            class="wd-button wd-button-primary"
                            :disabled="busy || !!errors.version"
                            >{{ busy ? 'Saving…' : 'Add lead' }}</Button
                        >
                    </footer>
                </form>
            </template>
        </dialog>
        <dialog
            ref="onboardingDialog"
            class="wd-dialog onboarding-dialog"
            aria-labelledby="onboarding-dialog-title"
            @close="onOnboardingClosed"
            @keydown="containDialogFocus($event, onboardingDialog)"
        >
            <div class="wd-dialog-header">
                <h2 id="onboarding-dialog-title">Start onboarding</h2>
                <DialogClose label="Close start onboarding" :disabled="busy" @click="onboardingDialog?.close()" />
            </div>
            <form v-if="onboardingOpen && selected && canWrite" :aria-busy="busy" @submit.prevent="startOnboarding">
                <fieldset class="onboarding-fields" :disabled="busy || !!errors.version">
                    <legend class="wd-sr-only">Onboarding invitation for {{ selected.brand_name }}</legend>
                    <p class="wd-hint">
                        Creates a private onboarding form for {{ selected.brand_name }} and opens it in Onboarding
                        requests, where you can copy the link.
                    </p>
                    <OnboardingEmailFields
                        prefix="start-onboarding"
                        v-model:send-email="sendOnboardingEmail"
                        v-model:recipient="onboardingRecipient"
                        :errors="errors"
                    />
                    <OnboardingSubmissionNotice
                        prefix="start-onboarding"
                        v-model="notifySubmission"
                        :error="errors.notify_submission"
                    />
                    <div class="wd-dialog-footer">
                        <Button type="button" class="wd-button wd-button-secondary" @click="onboardingDialog?.close()"
                            >Cancel</Button
                        >
                        <Button type="submit" class="wd-button wd-button-primary">{{
                            busy ? 'Starting…' : 'Start onboarding'
                        }}</Button>
                    </div>
                </fieldset>
            </form>
        </dialog>
    </div>
</template>
<style scoped>
.lead-dialog {
    width: min(640px, calc(100dvw - 32px));
    max-width: calc(100dvw - 32px);
}
/* Add lead: the header and footer stay in view while the fields scroll. */
.lead-dialog:not(.lead-drawer)[open] {
    display: flex;
    flex-direction: column;
    width: min(600px, calc(100dvw - 32px));
    padding: 0;
    overflow: hidden;
}
.create-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 20px 14px;
    border-bottom: 1px solid var(--wd-divider);
}
.create-header h2 {
    margin: 0;
    font-size: 20px;
    line-height: 1.2;
}
.create-sub {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    margin: 4px 0 0;
    color: var(--wd-muted);
    font-size: 12.5px;
}
.create-form {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-height: 0;
}
.create-body {
    flex: 1;
    min-height: 0;
    padding: 16px 20px;
    overflow-y: auto;
    overscroll-behavior: contain;
}
.create-fields {
    display: flex;
    flex-direction: column;
    gap: 14px;
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
}
.create-share {
    padding: 12px;
    border: 1px solid var(--wd-line);
    border-radius: 12px;
    background: var(--wd-paper);
}
.create-footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    padding: 12px 20px;
    border-top: 1px solid var(--wd-divider);
    background: var(--wd-paper);
}
.create-required {
    margin: 0 auto 0 0;
    color: var(--wd-muted);
    font-size: 12.5px;
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
.lead-drawer :deep(.wd-field > label),
.lead-drawer :deep(.entry-label > label) {
    font-size: 14px;
    font-weight: 600;
    color: var(--wd-ink);
}
.lead-drawer :deep(.wd-hint) {
    font-size: 13px;
}
.lead-drawer :deep(.wd-select-panel) {
    max-height: none;
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
    gap: 10px;
    padding: 16px 20px 0;
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
    gap: 6px;
}
.drawer-title {
    margin: 0;
    font-size: 20px;
    line-height: 26px;
    letter-spacing: -0.01em;
    overflow-wrap: anywhere;
}
.drawer-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 8px;
    color: var(--wd-muted);
    font-size: 13px;
}
.drawer-value {
    color: var(--wd-ink);
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}
.drawer-view-only {
    padding: 1px 8px;
    border-radius: 999px;
    background: var(--wd-divider);
    font-size: 12px;
    font-weight: 700;
}
.drawer-head-actions {
    display: flex;
    flex: none;
    align-items: center;
    gap: 6px;
    --wd-table-control: 32px;
}
.drawer-subrow {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 6px 10px;
}
.drawer-updated {
    color: var(--wd-muted);
    font-size: 12.5px;
}
.contacts-pill {
    display: inline-flex;
    align-self: flex-start;
    align-items: center;
    gap: 4px;
    min-height: 28px;
    padding: 0 10px;
    border: 1px solid var(--wd-control-line);
    border-radius: 999px;
    background: var(--wd-surface);
    color: var(--wd-ink);
    font-size: 12.5px;
    font-weight: 700;
    text-decoration: none;
}
.contacts-pill:hover {
    background: var(--wd-paper);
    color: var(--wd-brand);
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
    margin: -6px -8px 0 0;
}
.drawer-tabs {
    display: flex;
    gap: 18px;
    margin: 0 -20px;
    padding: 0 20px;
    border-bottom: 1px solid var(--wd-divider);
}
.drawer-tabs [role='tab'] {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 38px;
    padding: 0;
    border: 0;
    background: transparent;
    color: var(--wd-muted);
    font-size: 13px;
    font-weight: 700;
}
.drawer-tabs [role='tab'][aria-selected='true'] {
    box-shadow: inset 0 -2px 0 var(--wd-brand);
    color: var(--wd-brand-hover);
}
.drawer-tabs [role='tab']:focus-visible {
    outline: 2px solid var(--wd-focus);
    outline-offset: 2px;
}
.drawer-tab-count {
    padding: 0 6px;
    border-radius: 999px;
    background: var(--wd-divider);
    color: var(--wd-muted);
    font-size: 11px;
}
.drawer-body {
    flex: 1 1 auto;
    min-height: 0;
    padding: 0 20px 20px;
    overflow-y: auto;
    overscroll-behavior: contain;
}
.drawer-stack,
.drawer-fields,
.activity-fields {
    display: flex;
    flex-direction: column;
    gap: 16px;
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
}
.drawer-stack {
    padding-top: 16px;
}
.activity-fields {
    gap: 12px;
}
.drawer-summary {
    display: grid;
    grid-template-columns: 110px minmax(0, 1fr);
    gap: 10px 12px;
    margin: 0;
    font-size: 14px;
}
.drawer-summary dt {
    color: var(--wd-muted);
    font-weight: 600;
}
.drawer-summary dd {
    margin: 0;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}
.drawer-muted {
    display: block;
    color: var(--wd-muted);
}
.drawer-status {
    border: 1px solid var(--wd-line);
    border-radius: 12px;
    overflow: hidden;
}
.drawer-status-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 10px;
    padding: 10px 12px;
    --wd-table-control: 30px;
}
.drawer-status-row + .drawer-status-row,
.drawer-status-note + .drawer-status-row {
    border-top: 1px solid var(--wd-divider);
}
.drawer-status-copy {
    display: flex;
    flex: 1 1 160px;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
}
.drawer-status-copy strong {
    font-size: 13.5px;
}
.drawer-status-copy small {
    color: var(--wd-muted);
    font-size: 12px;
}
.drawer-status-note {
    margin: -6px 0 0;
    padding: 0 12px 10px;
    color: var(--wd-muted);
    font-size: 12px;
}
.activity-composer {
    padding: 12px;
    border: 1px solid var(--wd-line);
    border-radius: 12px;
    background: var(--wd-paper);
}
.activity-kinds {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin: 0;
    padding: 0;
    border: 0;
}
.activity-kinds legend {
    margin-bottom: 6px;
    padding: 0;
    font-size: 13px;
    font-weight: 700;
}
.activity-kind {
    position: relative;
    display: inline-flex;
    align-items: center;
    min-height: 30px;
    padding: 0 11px;
    border: 1px solid var(--wd-control-line);
    border-radius: 999px;
    background: var(--wd-surface);
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
}
/* The native radio covers its chip, so pointer, keyboard and assistive technology all use the real input. */
.activity-kind input {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    margin: 0;
    opacity: 0;
    cursor: pointer;
}
.activity-kind:has(input:checked) {
    border-color: var(--wd-brand);
    background: var(--wd-brand);
    color: #fff;
}
.activity-kind:has(input:focus-visible) {
    outline: 3px solid var(--wd-focus);
    outline-offset: 2px;
}
.composer-open {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    min-height: 40px;
    padding: 0 12px;
    border: 1px solid var(--wd-control-line);
    border-radius: 10px;
    background: var(--wd-surface);
    color: var(--wd-muted);
    font: inherit;
    font-size: 13.5px;
    text-align: left;
    cursor: text;
}
.composer-open:hover:not(:disabled) {
    border-color: var(--wd-brand);
}
.composer-open:focus-visible {
    outline: 3px solid var(--wd-focus);
    outline-offset: 2px;
}
.composer-open-text {
    flex: 1;
    min-width: 0;
}
.composer-open-action {
    color: var(--wd-brand);
    font-size: 12.5px;
    font-weight: 700;
}
.activity-actions {
    display: flex;
    flex: none;
    gap: 8px;
}
.activity-submit {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.activity-submit .wd-hint {
    margin: 0;
}
.timeline-icon {
    display: grid;
    flex: none;
    place-items: center;
    width: 26px;
    height: 26px;
    border-radius: 8px;
    background: var(--wd-divider);
    color: var(--wd-brand-hover);
}
.onboarding-dialog[open] {
    display: flex;
    flex-direction: column;
    gap: 12px;
    width: min(500px, calc(100dvw - 32px));
}
.onboarding-dialog .wd-dialog-header {
    margin-bottom: 0;
}
.drawer-heading {
    margin: 0;
    font-family: var(--wd-font-body);
}
.drawer-heading {
    color: var(--wd-ink);
    font-size: 16px;
    font-weight: 700;
}
.lead-drawer textarea {
    padding: 12px 14px;
    line-height: 22px;
    resize: vertical;
}
.drawer-activity-notes {
    height: 76px;
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
.activity-submit :deep(.wd-button) {
    flex: none;
}
.drawer-delete-button {
    color: var(--wd-danger);
}
.lead-drawer .drawer-delete-button:hover:not(:disabled) {
    background: var(--wd-danger-bg);
    color: var(--wd-danger);
}
.history {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.history-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px 12px;
}
.history-day {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.history-day-label {
    margin: 4px 0 0;
    color: var(--wd-muted);
    font-family: var(--wd-font-body);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
}
.history-empty {
    margin: 0;
}
.history-end {
    margin: 4px 0 0;
    color: var(--wd-muted);
    font-size: 12px;
    text-align: center;
}
.drawer-stack > .history:first-child {
    padding-top: 0;
    border-top: 0;
}
.timeline {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 0;
    padding: 0;
    list-style: none;
}
/* A thin rail joins the entries of one day. */
.timeline::before {
    position: absolute;
    top: 8px;
    bottom: 8px;
    left: 12px;
    width: 1px;
    background: var(--wd-line);
    content: '';
}
.timeline li {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}
.timeline li.is-change .timeline-icon {
    width: 26px;
    height: 22px;
    border-radius: 999px;
    background: var(--wd-divider);
    color: var(--wd-muted);
}
.timeline li.is-conversation .timeline-icon {
    background: var(--color-blush, #f6e3ec);
}
.timeline-card {
    flex: 1;
    min-width: 0;
    padding: 8px 10px;
    border: 1px solid var(--wd-line);
    border-radius: 10px;
    background: var(--wd-surface);
}
.timeline-meta {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    margin: 0;
    color: var(--wd-muted);
    font-size: 12px;
}
.timeline-meta strong {
    color: var(--wd-ink);
}
.timeline-title {
    margin: 3px 0 0;
    font-size: 13.5px;
    line-height: 1.45;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}
.timeline-change {
    flex: 1;
    min-width: 0;
    margin: 0;
    padding-top: 2px;
    color: var(--wd-body);
    font-size: 13px;
    overflow-wrap: anywhere;
}
.timeline-change strong {
    color: var(--wd-ink);
}
.timeline-time {
    margin-left: 4px;
    color: var(--wd-muted);
    white-space: nowrap;
}
.drawer-footer {
    display: flex;
    flex: none;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    padding: 12px 20px;
    border-top: 1px solid var(--wd-divider);
    background: var(--wd-paper);
}
.drawer-dirty,
.drawer-clean {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0 auto 0 0;
    font-size: 12.5px;
}
.drawer-dirty {
    color: var(--wd-warning);
    font-weight: 700;
}
.drawer-dirty span {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--wd-warning);
}
.drawer-clean {
    color: var(--wd-muted);
}
/* A mobile layout viewport can widen after focus/scroll inside a native dialog.
   Anchor to the device viewport so pointer coordinates still reach its controls. */
@media (max-width: 640px) {
    .lead-dialog {
        inset: 16px auto auto 16px;
        margin: 0;
    }
    /* Add lead opens as a full-screen sheet on phones, still anchored to the device viewport. */
    .lead-dialog:not(.lead-drawer)[open] {
        inset: 0 auto auto 0;
        width: 100dvw;
        max-width: 100dvw;
        height: 100dvh;
        max-height: 100dvh;
        border: 0;
        border-radius: 0;
    }
    .create-required {
        flex-basis: 100%;
    }
    .create-footer :deep(.wd-button) {
        flex: 1;
        min-height: 44px;
    }
    .drawer-dirty,
    .drawer-clean {
        flex-basis: 100%;
    }
    .drawer-footer :deep(.wd-button) {
        flex: 1;
        min-height: 44px;
    }
    .drawer-head-actions,
    .drawer-status-row {
        --wd-table-control: 44px;
    }
    .contacts-pill,
    .activity-kind {
        min-height: 44px;
    }
    .drawer-tabs [role='tab'] {
        min-height: 44px;
    }
    .activity-submit {
        flex-direction: column;
        align-items: stretch;
    }
    .activity-actions :deep(.wd-button) {
        flex: 1;
        min-height: 44px;
    }
    .composer-open {
        min-height: 44px;
    }
    .composer-open-text {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .drawer-summary {
        grid-template-columns: minmax(0, 1fr);
        gap: 2px;
    }
    .drawer-summary dd {
        margin-bottom: 8px;
    }
    .lead-dialog.lead-drawer {
        inset: 0 auto 0 0;
    }
}
</style>
