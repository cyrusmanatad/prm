<script setup lang="ts">
import '../../css/records.css';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import { CalendarPlus, Info, Plus } from '@lucide/vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import Tabs, { type Tab } from '../components/Tabs.vue';
import { confirmAction } from '../composables/useConfirmation';
import { useWorkspaceExperience } from '../composables/useWorkspaceExperience';
import AutomationPreferences, { type Preferences } from '../features/automation/AutomationPreferences.vue';
import DraftDrawer from '../features/automation/DraftDrawer.vue';
import DraftTable from '../features/automation/DraftTable.vue';
import FollowUpDialog from '../features/automation/FollowUpDialog.vue';
import NoticeHistory, { type Notice } from '../features/automation/NoticeHistory.vue';
import SavedSchedules from '../features/automation/SavedSchedules.vue';
import ScheduleDialog, { type ReminderOption } from '../features/automation/ScheduleDialog.vue';
import type { DraftAction, DraftFilter, FollowUpDraft, LeadOption } from '../features/automation/follow-up-types';
import type { Schedule } from '../features/automation/types';

const props = defineProps<{
    account: { name: string; email: string };
    workspace: { id: string; name: string };
    preferences: Preferences;
    schedules: Schedule[];
    reminders: ReminderOption[];
    drafts: FollowUpDraft[];
    leads: LeadOption[];
    notices: Notice[];
    timezones: string[];
    canWrite: boolean;
}>();
const { professional } = useWorkspaceExperience();
const page = usePage();

/* Tab and open draft live in the address, so reload, Back and a saved change return to the same view. */
type TabId = 'approvals' | 'schedules' | 'history' | 'preferences';
const tabIds: TabId[] = ['approvals', 'schedules', 'history', 'preferences'];
const query = (url: string) => new URLSearchParams(url.split('?')[1] ?? '');
const base = computed(() => page.url.split('?')[0]!);
const fromUrl = (url: string) => {
    const tab = query(url).get('tab') as TabId | null;
    return { tab: tab && tabIds.includes(tab) ? tab : 'approvals', draft: query(url).get('draft') };
};
const tab = ref<string>(fromUrl(page.url).tab);
const draftId = ref<string | null>(fromUrl(page.url).draft);
watch(
    () => page.url,
    (url) => {
        const next = fromUrl(url);
        tab.value = next.tab;
        if (next.draft !== draftId.value) {
            draftId.value = next.draft;
            editOnOpen.value = false;
        }
    },
);
watch([tab, draftId], () => {
    const url = new URL(window.location.href);
    if (tab.value === 'approvals') url.searchParams.delete('tab');
    else url.searchParams.set('tab', tab.value);
    if (draftId.value) url.searchParams.set('draft', draftId.value);
    else url.searchParams.delete('draft');
    history.replaceState(history.state, '', url.pathname + url.search + url.hash);
});

const needsReview = computed(() => props.drafts.filter((draft) => draft.state === 'draft').length);
const activeSchedules = computed(() => props.schedules.filter((schedule) => schedule.enabled).length);
const tabs = computed<Tab[]>(() => [
    {
        id: 'approvals',
        label: 'Approvals',
        count: props.drafts.length,
        badge: needsReview.value ? `${needsReview.value} to review` : undefined,
    },
    { id: 'schedules', label: 'Schedules', count: activeSchedules.value },
    { id: 'history', label: 'Email history', count: props.notices.length },
    { id: 'preferences', label: 'Preferences' },
]);
const draftFilter = ref<DraftFilter>(needsReview.value ? 'draft' : '');

/* Drafts: the drawer, and Approve / Send / Discard with Cancel-first confirmation. */
const selected = computed(() => props.drafts.find((draft) => draft.id === draftId.value) ?? null);
const editOnOpen = ref(false);
let drawerTrigger: HTMLElement | null = null;
function openDraft(draft: FollowUpDraft, edit = false): void {
    drawerTrigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    editOnOpen.value = edit;
    draftId.value = draft.id;
}
async function closeDraft(): Promise<void> {
    draftId.value = null;
    editOnOpen.value = false;
    await nextTick();
    (drawerTrigger?.isConnected ? drawerTrigger : document.getElementById('drafts-heading'))?.focus();
    drawerTrigger = null;
}
const action = useForm({ version: 1 });
function act(draft: FollowUpDraft, kind: DraftAction, source: Event | HTMLElement): void {
    if (action.processing) return;
    confirmAction(
        kind === 'approve' ? 'Approve follow-up' : kind === 'send' ? 'Send approved message' : 'Discard follow-up',
        kind === 'approve'
            ? `Approve “${draft.subject}” to ${draft.recipient || 'the recipient you enter'} for manual sending? No email will be sent by WeavDash.`
            : kind === 'send'
              ? `Send the approved version ${draft.version} of “${draft.subject}” to ${draft.recipient}? This sends the message through WeavDash. Replies go to your verified account. A reserved send cannot be recalled.`
              : `Discard “${draft.subject}”? It will no longer be available for approval or manual handoff.`,
        () => {
            action.version = draft.version;
            action.clearErrors();
            action.post(`/automation/drafts/${draft.id}/${kind}`, { preserveScroll: true, preserveState: true });
        },
        source,
        { tone: kind === 'discard' ? 'danger' : 'default' },
    );
}

/* Schedules: one dialog for new schedules and weekly edits; withdrawal is confirmed. */
const scheduleDialog = ref<InstanceType<typeof ScheduleDialog>>();
const followUpDialog = ref<InstanceType<typeof FollowUpDialog>>();
const revoke = useForm({ version: 1 });
function withdraw(schedule: Schedule, event: Event): void {
    if (revoke.processing) return;
    confirmAction(
        'Withdraw schedule consent',
        `Disable ${schedule.name} and cancel its pending scheduled work?`,
        () => {
            revoke.version = schedule.version;
            revoke.delete(`/automation/schedules/${schedule.id}`, { preserveScroll: true, preserveState: true });
        },
        event,
    );
}
async function showPreferences(): Promise<void> {
    tab.value = 'preferences';
    await nextTick();
    document.getElementById('auto-tab-preferences')?.focus();
}
const trigger = (event: Event) => event.currentTarget as HTMLElement;
</script>

<template>
    <Head :title="professional ? 'Automation and approvals' : 'Automation preferences'" />
    <FoundationShell :account-name="account.name" :account-email="account.email" @logout="router.post('/logout')">
        <div class="rec-page">
            <header class="rec-header">
                <div>
                    <h1>{{ professional ? 'Automation and approvals' : 'Automation preferences' }}</h1>
                    <p>Review private follow-up drafts and choose which scheduled notices you receive.</p>
                </div>
                <div v-if="canWrite" class="rec-header-actions">
                    <button type="button" class="wd-table-button" @click="scheduleDialog?.open(trigger($event))">
                        <CalendarPlus :size="16" aria-hidden="true" />New schedule
                    </button>
                    <button
                        type="button"
                        class="wd-table-button rec-primary"
                        @click="followUpDialog?.open(trigger($event))"
                    >
                        <Plus :size="16" aria-hidden="true" />Write follow-up
                    </button>
                </div>
            </header>
            <p class="rec-info">
                <Info :size="16" aria-hidden="true" /><span
                    >Reminder and weekly review emails go only to your verified account. Follow-up schedules prepare
                    private drafts for your review; approval does not send email.</span
                >
            </p>
            <p v-if="!canWrite" class="rec-info">
                <Info :size="16" aria-hidden="true" /><span
                    >Viewer access: you can see your history, but scheduling and drafts need an editing role.</span
                >
            </p>
            <Tabs v-model="tab" label="Automation" id-prefix="auto" :tabs="tabs" />
            <div
                v-show="tab === 'approvals'"
                id="auto-panel-approvals"
                role="tabpanel"
                aria-labelledby="auto-tab-approvals"
                class="rec-panel"
            >
                <div v-if="Object.keys(action.errors).length && !selected" class="wd-banner rec-banner" role="alert">
                    <p v-for="(error, key) in action.errors" :key="key">{{ error }}</p>
                    <a class="wd-link" :href="base">Reload drafts</a>
                </div>
                <DraftTable
                    v-model:filter="draftFilter"
                    :drafts="drafts"
                    :can-write="canWrite"
                    :busy="action.processing"
                    @open="openDraft"
                    @act="act"
                />
            </div>
            <div
                v-show="tab === 'schedules'"
                id="auto-panel-schedules"
                role="tabpanel"
                aria-labelledby="auto-tab-schedules"
                class="rec-panel"
            >
                <SavedSchedules
                    :schedules="schedules"
                    :can-write="canWrite"
                    :busy="revoke.processing"
                    :error="revoke.errors.version"
                    :reload="`${base}?tab=schedules`"
                    @edit="(schedule, source) => scheduleDialog?.open(source, schedule)"
                    @withdraw="withdraw"
                />
            </div>
            <div
                v-show="tab === 'history'"
                id="auto-panel-history"
                role="tabpanel"
                aria-labelledby="auto-tab-history"
                class="rec-panel"
            >
                <NoticeHistory :notices="notices" />
            </div>
            <div
                v-show="tab === 'preferences'"
                id="auto-panel-preferences"
                role="tabpanel"
                aria-labelledby="auto-tab-preferences"
                class="rec-panel"
            >
                <AutomationPreferences
                    :key="workspace.id"
                    :preferences="preferences"
                    :timezones="timezones"
                    :can-write="canWrite"
                    :reload="`${base}?tab=preferences`"
                />
            </div>
            <DraftDrawer
                v-if="selected"
                :draft="selected"
                :can-write="canWrite"
                :busy="action.processing"
                :errors="action.errors"
                :reload="`${base}?draft=${selected.id}`"
                :edit-on-open="editOnOpen"
                @close="closeDraft"
                @act="act"
            />
            <template v-if="canWrite">
                <ScheduleDialog
                    ref="scheduleDialog"
                    :preferences="preferences"
                    :schedules="schedules"
                    :reminders="reminders"
                    :leads="leads"
                    @preferences="showPreferences"
                />
                <FollowUpDialog ref="followUpDialog" :leads="leads" />
            </template>
        </div>
    </FoundationShell>
</template>
