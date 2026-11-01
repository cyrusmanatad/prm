<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Button } from '../../components/ui/button';
import FormField from '../../components/FormField.vue';
import AccountSecurity from '../../components/AccountSecurity.vue';
import ActionGroup from '../../components/ActionGroup.vue';
import DialogClose from '../../components/DialogClose.vue';
import { containDialogFocus } from '../../composables/useDialogFocus';
import { useValidationFocus } from '../../composables/useValidationFocus';
import FoundationShell from '../../layouts/FoundationShell.vue';
import type { AccountSummary, FieldErrors, WorkspaceAction, WorkspaceSummary } from '../../types/foundation';

const props = withDefaults(
    defineProps<{
        account: AccountSummary;
        workspaces: WorkspaceSummary[];
        activeOrgId?: string | null;
        pendingAction?: WorkspaceAction | null;
        errors?: FieldErrors;
        status?: string;
        accessRevoked?: boolean;
    }>(),
    { activeOrgId: null, pendingAction: null, errors: () => ({}), status: '', accessRevoked: false },
);
const emit = defineEmits<{
    create: [name: string, kind: 'creator' | 'brand'];
    select: [orgId: string];
    open: [orgId: string, href: string];
    rename: [name: string];
    revokeSessions: [password: string];
    logout: [];
}>();
const root = ref<HTMLElement>();
const newName = ref('');
const kind = ref<'creator' | 'brand'>('creator');
const kinds = [
    {
        value: 'brand',
        label: 'Business',
        description: 'For companies managing their partners. Opens the Brand portal.',
    },
    {
        value: 'creator',
        label: 'Professional',
        description: 'For individuals managing leads, sales and client onboarding. Opens the Professional portal.',
    },
] as const;
// Partner workspaces are created only from brand invitations or sign-up links, so they are listed but not offered (D32).
const groupTypes = [...kinds, { value: 'partner', label: 'Partner', description: '' }] as const;
const groups = computed(() =>
    groupTypes
        .map((type) => ({
            ...type,
            workspaces: props.workspaces.filter((row) => (row.kind ?? 'creator') === type.value),
        }))
        .filter((group) => group.workspaces.length),
);
function portal(workspace: WorkspaceSummary): { href: string; label: string } {
    if (workspace.kind === 'brand') return { href: '/brand', label: 'Brand portal' };
    return workspace.kind === 'partner'
        ? { href: '/partner', label: 'Partner portal' }
        : { href: '/pro/leads', label: 'Professional portal' };
}
const createDialog = ref<HTMLDialogElement | null>(null);
async function openCreate(): Promise<void> {
    createDialog.value?.showModal();
    await nextTick();
    document.getElementById('workspace_name')?.focus();
}
const currentName = ref('');
const password = ref('');
const busy = computed(() => props.pendingAction !== null);
const selected = computed(() =>
    props.accessRevoked ? undefined : props.workspaces.find((workspace) => workspace.id === props.activeOrgId),
);
const canEdit = computed(() => selected.value && ['owner', 'admin'].includes(selected.value.role));
watch(
    () => selected.value?.name,
    (name) => {
        currentName.value = name ?? '';
    },
    { immediate: true },
);
// Rejected names reopen the dialog so the error is visible beside its field.
watch(
    () => props.errors.create_name,
    (error) => {
        if (error && !createDialog.value?.open) createDialog.value?.showModal();
    },
);
useValidationFocus(
    root,
    () => props.errors,
    () => !busy.value,
);
function create(): void {
    if (!busy.value) emit('create', newName.value.trim(), kind.value);
}
function rename(): void {
    if (!busy.value && canEdit.value) emit('rename', currentName.value.trim());
}
function revoke(): void {
    if (!busy.value) emit('revokeSessions', password.value);
}
defineExpose({
    clearPasswords: () => {
        password.value = '';
    },
    clearCreateForm: () => {
        newName.value = '';
        createDialog.value?.close();
    },
});
</script>

<template>
    <FoundationShell :account-name="account.name" :account-email="account.email" @logout="emit('logout')">
        <div ref="root" class="wd-workspace-content">
            <div class="wd-page-heading wd-workspaces-heading">
                <div>
                    <h1>Workspaces</h1>
                    <p class="wd-description">Open a workspace to go to its portal, or create a new one.</p>
                </div>
                <Button
                    v-if="workspaces.length"
                    type="button"
                    class="wd-button wd-button-primary"
                    :disabled="busy"
                    @click="openCreate"
                    >+ New workspace</Button
                >
            </div>
            <p v-if="accessRevoked" class="wd-field-error wd-banner" role="alert">
                Your access to the previous workspace has changed. Choose an available workspace below.
            </p>

            <section v-if="!workspaces.length" class="wd-card" aria-labelledby="workspace-first-title">
                <h2 id="workspace-first-title">Create your first workspace</h2>
                <p class="wd-description">
                    You don’t have an active workspace yet. Create a Business or Professional workspace to get started.
                </p>
                <Button type="button" class="wd-button wd-button-primary" :disabled="busy" @click="openCreate"
                    >+ New workspace</Button
                >
            </section>

            <section
                v-for="group in groups"
                :key="group.value"
                class="wd-workspace-group"
                :aria-labelledby="`workspace-group-${group.value}`"
                :aria-busy="pendingAction === 'select'"
            >
                <h2 :id="`workspace-group-${group.value}`">{{ group.label }}</h2>
                <ul class="wd-workspace-cards">
                    <li
                        v-for="workspace in group.workspaces"
                        :key="workspace.id"
                        class="wd-workspace-card"
                        :class="{ 'wd-workspace-card-current': selected?.id === workspace.id }"
                    >
                        <button
                            type="button"
                            class="wd-workspace-option"
                            :aria-pressed="selected?.id === workspace.id"
                            :disabled="busy"
                            @click="emit('select', workspace.id)"
                        >
                            <span class="wd-workspace-initial" aria-hidden="true">{{
                                workspace.name.slice(0, 1).toUpperCase()
                            }}</span>
                            <span class="wd-workspace-label"
                                ><strong :id="`workspace-name-${workspace.id}`">{{ workspace.name }}</strong
                                ><span class="wd-role"
                                    >{{
                                        { brand: 'Business', creator: 'Professional', partner: 'Partner' }[
                                            workspace.kind ?? 'creator'
                                        ]
                                    }}
                                    · {{ workspace.role }}</span
                                ></span
                            >
                            <span v-if="selected?.id === workspace.id" class="wd-active-label">Current</span>
                        </button>
                        <button
                            type="button"
                            class="wd-button wd-button-secondary"
                            :disabled="busy"
                            :aria-describedby="`workspace-name-${workspace.id}`"
                            @click="emit('open', workspace.id, portal(workspace).href)"
                        >
                            Open {{ portal(workspace).label }}
                        </button>
                    </li>
                </ul>
            </section>
            <p v-if="errors.org_id" class="wd-field-error" role="alert">{{ errors.org_id }}</p>
            <p v-if="pendingAction === 'select'" class="wd-hint" role="status">Switching workspace…</p>

            <div class="wd-settings-row">
                <section v-if="selected" class="wd-card wd-settings-card" aria-labelledby="workspace-settings-title">
                    <span class="wd-eyebrow">Current workspace</span>
                    <h2 id="workspace-settings-title">{{ selected.name }}</h2>
                    <Link class="wd-link inline-flex min-h-11 items-center" :href="portal(selected).href"
                        >Open {{ portal(selected).label }}</Link
                    >
                    <ActionGroup v-if="selected.role === 'owner'">
                        <Link class="wd-link" href="/finance/invoices">Private finance records</Link>
                        <Link class="wd-link" href="/workspace/entitlements">Pilot entitlements</Link>
                    </ActionGroup>
                    <p v-if="!canEdit" class="wd-description">
                        Your {{ selected.role }} role does not include workspace settings changes.
                    </p>
                    <form v-else :aria-busy="pendingAction === 'rename'" @submit.prevent="rename">
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
                            <Button type="submit" class="wd-button wd-button-secondary">{{
                                pendingAction === 'rename' ? 'Saving…' : 'Save name'
                            }}</Button>
                        </fieldset>
                    </form>
                </section>
                <AccountSecurity
                    v-model="password"
                    :account-name="account.name"
                    :busy="busy"
                    :pending="pendingAction === 'revoke-sessions'"
                    :error="errors.password"
                    @revoke="revoke"
                />
            </div>
            <dialog
                ref="createDialog"
                class="wd-dialog"
                aria-labelledby="workspace-create-title"
                @keydown="containDialogFocus($event, createDialog)"
            >
                <div class="wd-dialog-header">
                    <h2 id="workspace-create-title">New workspace</h2>
                    <DialogClose label="Close new workspace dialog" @click="createDialog?.close()" />
                </div>
                <form id="workspace-create-form" :aria-busy="pendingAction === 'create'" @submit.prevent="create">
                    <fieldset class="wd-fields" :disabled="busy">
                        <legend class="wd-sr-only">Create a workspace</legend>
                        <fieldset class="wd-type-options">
                            <legend>Workspace type</legend>
                            <label v-for="type in kinds" :key="type.value" class="wd-type-option">
                                <input v-model="kind" type="radio" name="workspace-kind" :value="type.value" />
                                <span
                                    ><strong>{{ type.label }}</strong
                                    ><span>{{ type.description }}</span></span
                                >
                            </label>
                        </fieldset>
                        <FormField
                            id="workspace_name"
                            v-model="newName"
                            label="Workspace name"
                            autocomplete="organization"
                            :maxlength="120"
                            :error="errors.create_name"
                        />
                        <div class="wd-dialog-footer">
                            <button type="button" class="wd-button wd-button-secondary" @click="createDialog?.close()">
                                Cancel
                            </button>
                            <Button type="submit" class="wd-button wd-button-primary">{{
                                pendingAction === 'create' ? 'Creating…' : 'Create workspace'
                            }}</Button>
                        </div>
                    </fieldset>
                </form>
            </dialog>
        </div>
    </FoundationShell>
</template>
