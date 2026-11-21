<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronRight, CreditCard, Gauge, LifeBuoy, Pencil, Plus, Settings2 } from '@lucide/vue';
import { Button } from '../../components/ui/button';
import AccountSecurity from '../../components/AccountSecurity.vue';
import DialogClose from '../../components/DialogClose.vue';
import FormField from '../../components/FormField.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import Tabs, { type Tab } from '../../components/Tabs.vue';
import { containDialogFocus } from '../../composables/useDialogFocus';
import { useValidationFocus } from '../../composables/useValidationFocus';
import FoundationShell from '../../layouts/FoundationShell.vue';
import WorkspaceCreateForm from './WorkspaceCreateForm.vue';
import type {
    AccountSecurityStatus,
    AccountSummary,
    FieldErrors,
    WorkspaceAction,
    WorkspaceSummary,
} from '../../types/foundation';

const props = withDefaults(
    defineProps<{
        account: AccountSummary;
        workspaces: WorkspaceSummary[];
        activeOrgId?: string | null;
        pendingAction?: WorkspaceAction | null;
        errors?: FieldErrors;
        status?: string;
        accessRevoked?: boolean;
        security?: AccountSecurityStatus;
    }>(),
    {
        activeOrgId: null,
        pendingAction: null,
        errors: () => ({}),
        status: '',
        accessRevoked: false,
        security: () => ({ enrolled: false, required: false }),
    },
);
const emit = defineEmits<{
    create: [name: string, kind: 'creator', experience: 'partner' | 'professional'];
    select: [orgId: string];
    rename: [name: string];
    revokeSessions: [password: string];
    logout: [];
}>();
const root = ref<HTMLElement>();
const password = ref('');
const busy = computed(() => props.pendingAction !== null);
const selected = computed(() =>
    props.accessRevoked ? undefined : props.workspaces.find((workspace) => workspace.id === props.activeOrgId),
);
const canEdit = computed(() => !!selected.value && ['owner', 'admin'].includes(selected.value.role));
const owns = computed(() => props.workspaces.filter((workspace) => workspace.role === 'owner').length);

const typeLabel = (workspace: WorkspaceSummary) =>
    workspace.kind === 'brand' ? 'Brand' : workspace.experience === 'professional' ? 'Professional' : 'Partner';
const hub = (workspace: WorkspaceSummary) =>
    workspace.kind === 'brand'
        ? { href: '/brand', label: 'Open brand hub' }
        : workspace.experience === 'professional'
          ? { href: '/professional', label: 'Open Professional Hub' }
          : { href: '/partner/leads', label: 'Open partner portal' };
const role = (value: string) => value.charAt(0).toUpperCase() + value.slice(1);

// Tabs, kept in the address so a reload or shared link returns to the same view.
const actionNeeded = computed(() => props.security.required && !props.security.enrolled);
const tabs = computed<Tab[]>(() => [
    { id: 'workspaces', label: 'Workspaces', count: props.workspaces.length },
    { id: 'security', label: 'Account security', badge: actionNeeded.value ? 'Action needed' : undefined },
]);
const initialTab =
    typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('tab') === 'security'
        ? 'security'
        : 'workspaces';
const tab = ref(initialTab);
watch(tab, (value) => {
    const url = new URL(window.location.href);
    if (value === 'security') url.searchParams.set('tab', 'security');
    else url.searchParams.delete('tab');
    history.replaceState(history.state, '', url.pathname + url.search + url.hash);
});
// A rejected password belongs to the security tab; show it before focus moves to the field.
watch(
    () => props.errors.password,
    (error) => {
        if (error) tab.value = 'security';
    },
    { immediate: true, flush: 'sync' },
);
useValidationFocus(
    root,
    () => props.errors,
    () => !busy.value,
);

// New workspace dialog. With no workspaces, the same form is shown on the page instead.
const createDialog = ref<HTMLDialogElement>();
const createOpen = ref(false);
const createForm = ref<InstanceType<typeof WorkspaceCreateForm>>();
let trigger: HTMLElement | null = null;
async function openCreate(event: Event): Promise<void> {
    trigger = event.currentTarget as HTMLElement;
    createOpen.value = true;
    await nextTick();
    createDialog.value?.showModal();
    createDialog.value?.querySelector<HTMLInputElement>('input[type="radio"]:checked')?.focus();
}
function onCreateClosed(): void {
    createOpen.value = false;
    trigger?.focus();
}

// Rename dialog, for owners and admins of the current workspace.
const renameDialog = ref<HTMLDialogElement>();
const renameOpen = ref(false);
const currentName = ref('');
async function openRename(event: Event): Promise<void> {
    trigger = event.currentTarget as HTMLElement;
    currentName.value = selected.value?.name ?? '';
    renameOpen.value = true;
    await nextTick();
    renameDialog.value?.showModal();
    document.getElementById('current_workspace_name')?.focus();
}
function onRenameClosed(): void {
    renameOpen.value = false;
    trigger?.focus();
}
function rename(): void {
    if (!busy.value && canEdit.value) emit('rename', currentName.value.trim());
}
function select(id: string): void {
    if (!busy.value) emit('select', id);
}
function revoke(): void {
    if (!busy.value) emit('revokeSessions', password.value);
}

const tools = computed(() =>
    [
        {
            href: '/automation',
            label: 'Automation preferences',
            text: 'Timezone and scheduled notices',
            icon: Settings2,
            owner: false,
        },
        {
            href: '/finance/invoices',
            label: 'Private finance records',
            text: 'Invoices and payment records',
            icon: CreditCard,
            owner: true,
        },
        {
            href: '/workspace/entitlements',
            label: 'Pilot entitlements',
            text: 'Plan limits for this workspace',
            icon: Gauge,
            owner: true,
        },
        {
            href: '/workspace/support-access',
            label: 'Support access requests',
            text: 'Approve view-only support access',
            icon: LifeBuoy,
            owner: true,
        },
    ].filter((tool) => !tool.owner || selected.value?.role === 'owner'),
);

defineExpose({
    clearPasswords: () => {
        password.value = '';
    },
    clearCreateForm: () => {
        createForm.value?.clear();
        createDialog.value?.close();
    },
    closeRename: () => renameDialog.value?.close(),
});
</script>

<template>
    <FoundationShell :account-name="account.name" :account-email="account.email" @logout="emit('logout')">
        <div ref="root" class="ws">
            <header class="ws-header">
                <div>
                    <h1>Workspaces</h1>
                    <p class="ws-sub">Switch where you work, open its tools, and manage your account security.</p>
                </div>
                <button
                    v-if="workspaces.length"
                    type="button"
                    class="wd-table-button ws-primary"
                    :disabled="busy"
                    @click="openCreate"
                >
                    <Plus :size="16" aria-hidden="true" />New workspace
                </button>
            </header>
            <p v-if="accessRevoked" class="wd-field-error wd-banner" role="alert">
                Your access to the previous workspace has changed. Choose an available workspace below.
            </p>
            <Tabs v-model="tab" label="Workspaces and security" id-prefix="ws" :tabs="tabs" />

            <div
                v-show="tab === 'workspaces'"
                id="ws-panel-workspaces"
                class="ws-panel"
                role="tabpanel"
                aria-labelledby="ws-tab-workspaces"
            >
                <section v-if="!workspaces.length" class="ws-card ws-first" aria-labelledby="workspace-create-title">
                    <h2 id="workspace-create-title">Create your first workspace</h2>
                    <p class="ws-sub">You don’t have an active workspace yet. Create one to get started.</p>
                    <WorkspaceCreateForm
                        ref="createForm"
                        :busy="busy"
                        :pending="pendingAction === 'create'"
                        :error="errors.create_name"
                        @create="(name, kind, experience) => emit('create', name, kind, experience)"
                    />
                </section>
                <template v-else>
                    <h2 class="wd-sr-only">Your workspaces</h2>
                    <ul class="ws-list" :aria-busy="pendingAction === 'select'">
                        <li
                            v-for="workspace in workspaces"
                            :key="workspace.id"
                            class="ws-item"
                            :class="{ 'is-current': selected?.id === workspace.id }"
                        >
                            <div class="ws-item-top">
                                <span class="ws-initial" aria-hidden="true">{{
                                    workspace.name.slice(0, 1).toUpperCase()
                                }}</span>
                                <span class="ws-item-name"
                                    ><strong>{{ workspace.name }}</strong
                                    ><small
                                        ><span class="ws-type" :class="`is-${typeLabel(workspace).toLowerCase()}`">{{
                                            typeLabel(workspace)
                                        }}</span>
                                        {{ role(workspace.role) }}</small
                                    ></span
                                >
                                <StatusBadge v-if="selected?.id === workspace.id" label="Current" tone="success" />
                            </div>
                            <Link
                                v-if="selected?.id === workspace.id"
                                :href="hub(workspace).href"
                                class="wd-table-button ws-primary"
                                >{{ hub(workspace).label }}</Link
                            >
                            <button
                                v-else
                                type="button"
                                class="wd-table-button"
                                :aria-label="`Switch to ${workspace.name}, ${typeLabel(workspace)} workspace`"
                                :disabled="busy"
                                @click="select(workspace.id)"
                            >
                                Switch
                            </button>
                        </li>
                    </ul>
                    <p v-if="errors.org_id" class="wd-field-error" role="alert">{{ errors.org_id }}</p>
                    <p v-if="pendingAction === 'select'" class="wd-hint" role="status">Switching workspace…</p>

                    <section v-if="selected" class="ws-current" aria-labelledby="workspace-settings-title">
                        <div class="ws-current-side">
                            <span class="ws-eyebrow">Current workspace</span>
                            <h2 id="workspace-settings-title">{{ selected.name }}</h2>
                            <p class="ws-sub">
                                <span class="ws-type" :class="`is-${typeLabel(selected).toLowerCase()}`">{{
                                    typeLabel(selected)
                                }}</span>
                                {{ role(selected.role) }}
                            </p>
                            <button
                                v-if="canEdit"
                                type="button"
                                class="wd-table-button"
                                :disabled="busy"
                                @click="openRename"
                            >
                                <Pencil :size="14" aria-hidden="true" />Rename
                            </button>
                            <p v-else class="ws-sub">
                                Your {{ selected.role }} role does not include workspace settings changes.
                            </p>
                            <p class="ws-sub ws-requests">
                                Brand workspaces are reviewed before they are created.
                                <Link href="/brand/requests" class="wd-link">Brand workspace requests</Link>
                            </p>
                        </div>
                        <ul class="ws-tools" aria-label="Workspace tools">
                            <li v-for="tool in tools" :key="tool.href">
                                <component :is="tool.icon" :size="16" aria-hidden="true" class="ws-tool-icon" />
                                <span class="ws-tool-text"
                                    ><Link :href="tool.href">{{ tool.label }}</Link
                                    ><small>{{ tool.owner ? 'Owner · ' : '' }}{{ tool.text }}</small></span
                                >
                                <ChevronRight :size="16" aria-hidden="true" class="ws-tool-chevron" />
                            </li>
                        </ul>
                    </section>
                    <p v-else class="ws-sub">Choose Switch on a workspace to open its tools.</p>
                </template>
            </div>

            <div
                v-show="tab === 'security'"
                id="ws-panel-security"
                class="ws-panel"
                role="tabpanel"
                aria-labelledby="ws-tab-security"
            >
                <AccountSecurity
                    v-model="password"
                    :account-name="account.name"
                    :busy="busy"
                    :pending="pendingAction === 'revoke-sessions'"
                    :error="errors.password"
                    :security="security"
                    @revoke="revoke"
                />
            </div>

            <dialog
                ref="createDialog"
                class="wd-dialog ws-dialog"
                aria-labelledby="workspace-dialog-title"
                @close="onCreateClosed"
                @keydown="containDialogFocus($event, createDialog)"
            >
                <div class="wd-dialog-header">
                    <h2 id="workspace-dialog-title">New workspace</h2>
                    <DialogClose label="Close new workspace dialog" @click="createDialog?.close()" />
                </div>
                <WorkspaceCreateForm
                    v-if="createOpen && workspaces.length"
                    ref="createForm"
                    in-dialog
                    :busy="busy"
                    :pending="pendingAction === 'create'"
                    :error="errors.create_name"
                    @create="(name, kind, experience) => emit('create', name, kind, experience)"
                    @cancel="createDialog?.close()"
                />
            </dialog>
            <dialog
                ref="renameDialog"
                class="wd-dialog ws-dialog"
                aria-labelledby="rename-dialog-title"
                @close="onRenameClosed"
                @keydown="containDialogFocus($event, renameDialog)"
            >
                <div class="wd-dialog-header">
                    <h2 id="rename-dialog-title">Rename workspace</h2>
                    <DialogClose label="Close rename dialog" @click="renameDialog?.close()" />
                </div>
                <form v-if="renameOpen && canEdit" :aria-busy="pendingAction === 'rename'" @submit.prevent="rename">
                    <fieldset class="wd-fields" :disabled="busy">
                        <legend class="wd-sr-only">Workspace settings</legend>
                        <FormField
                            id="current_workspace_name"
                            v-model="currentName"
                            label="Workspace name"
                            autocomplete="organization"
                            :maxlength="120"
                            :error="errors.rename_name"
                        />
                        <div class="wd-dialog-footer">
                            <button type="button" class="wd-button wd-button-secondary" @click="renameDialog?.close()">
                                Cancel
                            </button>
                            <Button type="submit" class="wd-button wd-button-primary">{{
                                pendingAction === 'rename' ? 'Saving…' : 'Save name'
                            }}</Button>
                        </div>
                    </fieldset>
                </form>
            </dialog>
        </div>
    </FoundationShell>
</template>

<style scoped>
.ws {
    display: flex;
    flex-direction: column;
    gap: 14px;
    max-width: 1280px;
    margin: auto;
    color: var(--wd-ink);
    font-size: 14px;
}
.ws-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 12px 24px;
}
.ws h1 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 28px;
    font-weight: 700;
    letter-spacing: -0.01em;
    line-height: 1.15;
}
.ws-sub {
    margin: 4px 0 0;
    color: var(--wd-muted);
    font-size: 13px;
}
.ws-primary {
    border-color: var(--wd-brand);
    background: var(--wd-brand);
    color: #fff;
}
.ws .ws-primary:hover:not(:disabled) {
    border-color: var(--wd-brand-hover);
    background: var(--wd-brand-hover);
    color: #fff;
}
/* Tabs on this page keep 44px targets at every width. */
.ws :deep(.wd-inline-tabs [role='tab']) {
    min-height: 44px;
}
.ws-panel {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.ws-card,
.ws-current {
    border: 1px solid var(--wd-line);
    border-radius: 12px;
    background: var(--wd-surface);
}
.ws-first {
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-width: 640px;
    padding: 16px;
}
.ws-first h2,
.ws-current h2 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 16px;
    font-weight: 600;
}
.ws-first .ws-sub {
    margin: -8px 0 0;
}
.ws-list {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.ws-item {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
    min-width: 0;
    padding: 14px;
    border: 1px solid var(--wd-line);
    border-radius: 12px;
    background: var(--wd-surface);
}
.ws-item.is-current {
    border-color: var(--wd-brand);
    box-shadow: 0 0 0 1px var(--wd-brand);
}
.ws-item-top {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
}
.ws-initial {
    display: grid;
    flex: none;
    place-items: center;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: #f6e3ec;
    color: var(--wd-brand-hover);
    font-size: 13px;
    font-weight: 700;
}
.ws-item-name {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
}
.ws-item-name strong {
    overflow: hidden;
    font-family: var(--wd-font-display);
    font-size: 15px;
    font-weight: 600;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.ws-item-name small {
    color: var(--wd-muted);
    font-size: 12px;
}
.ws-type {
    display: inline-flex;
    align-items: center;
    height: 20px;
    margin-right: 4px;
    padding: 0 7px;
    border-radius: 999px;
    background: #f6e3ec;
    color: var(--wd-brand-hover);
    font-size: 11.5px;
    font-weight: 700;
}
.ws-type.is-professional {
    background: #e8eef5;
    color: #33506f;
}
.ws-type.is-brand {
    background: var(--wd-warning-bg);
    color: var(--wd-warning);
}
.ws-current {
    display: grid;
    grid-template-columns: 260px minmax(0, 1fr);
    overflow: clip;
}
.ws-current-side {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
    padding: 14px;
    border-right: 1px solid var(--wd-line);
    background: var(--wd-paper);
}
.ws-current-side .ws-sub {
    margin: 0;
}
.ws-eyebrow {
    color: var(--wd-muted);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}
.ws-requests {
    margin-top: auto !important;
    padding-top: 8px;
    font-size: 12px !important;
}
.ws-tools {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-content: start;
    margin: 0;
    padding: 0;
    list-style: none;
}
.ws-tools li {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    min-height: 44px;
    padding: 12px 14px;
    border-bottom: 1px solid var(--wd-divider);
}
.ws-tools li:nth-child(odd) {
    border-right: 1px solid var(--wd-divider);
}
.ws-tools li:hover {
    background: var(--wd-paper);
}
.ws-tool-icon {
    flex: none;
    box-sizing: content-box;
    padding: 6px;
    border-radius: 8px;
    background: var(--wd-divider);
    color: var(--wd-brand-hover);
}
.ws-tool-text {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.ws-tool-text a {
    color: var(--wd-ink);
    font-weight: 700;
    text-decoration: none;
}
/* The whole tile opens the link; the link keeps its own accessible name. */
.ws-tool-text a::after {
    position: absolute;
    inset: 0;
    content: '';
}
.ws-tools li:hover a {
    color: var(--wd-brand);
}
.ws-tool-text a:focus-visible {
    outline: none;
}
.ws-tools li:has(a:focus-visible) {
    outline: 3px solid var(--wd-focus);
    outline-offset: -3px;
}
.ws-tool-text small {
    color: var(--wd-muted);
    font-size: 12px;
}
.ws-tool-chevron {
    flex: none;
    align-self: center;
    color: var(--wd-muted);
}
.ws-dialog[open] {
    display: flex;
    flex-direction: column;
    gap: 14px;
    width: min(540px, calc(100vw - 32px));
}
.ws-dialog .wd-dialog-header {
    margin-bottom: 0;
}
@media (min-width: 600px) and (pointer: fine) {
    .ws-header,
    .ws-item,
    .ws-current-side {
        --wd-table-control: 32px;
    }
}
@media (max-width: 1099px) {
    .ws-list {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 899px) {
    .ws-current {
        grid-template-columns: minmax(0, 1fr);
    }
    .ws-current-side {
        border-right: 0;
        border-bottom: 1px solid var(--wd-line);
    }
    .ws-tools {
        grid-template-columns: minmax(0, 1fr);
    }
    .ws-tools li:nth-child(odd) {
        border-right: 0;
    }
}
@media (max-width: 599px) {
    .ws h1 {
        font-size: 24px;
    }
    .ws-list {
        grid-template-columns: minmax(0, 1fr);
    }
    .ws-item {
        flex-direction: row;
        flex-wrap: wrap;
        align-items: center;
    }
    .ws-item-top {
        flex: 1;
        width: auto;
        min-width: 0;
    }
    .ws-item.is-current > a {
        justify-content: center;
        width: 100%;
    }
}
</style>
