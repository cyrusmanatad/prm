<script setup lang="ts">
import { confirmAction } from '../../composables/useConfirmation';
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Button } from '../../components/ui/button';
import FormField from '../../components/FormField.vue';
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
    rename: [name: string];
    revokeSessions: [password: string];
    logout: [];
}>();
const root = ref<HTMLElement>();
const newName = ref('');
const kind = ref<'creator' | 'brand'>('creator');
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
    },
});
</script>

<template>
    <FoundationShell :account-name="account.name" @logout="emit('logout')">
        <div ref="root" class="wd-workspace-content">
            <div class="wd-page-heading">
                <span class="wd-eyebrow">Your workspaces</span>
                <h1>A place for your next chapter.</h1>
                <p class="wd-description">Choose where you want to work, or create a workspace of your own.</p>
            </div>
            <p v-if="accessRevoked" class="wd-field-error wd-banner" role="alert">
                Your access to the previous workspace has changed. Choose an available workspace below.
            </p>
            <p v-if="status" class="wd-status" role="status">{{ status }}</p>
            <p v-if="errors.general" class="wd-field-error wd-banner" role="alert">{{ errors.general }}</p>
            <div class="wd-workspace-grid">
                <section class="wd-card" aria-labelledby="workspace-list-title" :aria-busy="pendingAction === 'select'">
                    <h2 id="workspace-list-title">Your spaces</h2>
                    <p v-if="!workspaces.length" class="wd-empty">
                        You don’t have an active workspace yet. Create one to get started.
                    </p>
                    <ul v-else class="wd-workspace-list">
                        <li v-for="workspace in workspaces" :key="workspace.id">
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
                                    ><strong>{{ workspace.name }}</strong
                                    ><span class="wd-role"
                                        >{{ workspace.kind === 'brand' ? 'Brand' : 'Partner' }} ·
                                        {{ workspace.role }}</span
                                    ></span
                                >
                                <span v-if="selected?.id === workspace.id" class="wd-active-label">Selected</span>
                                <span v-else aria-hidden="true">↗</span>
                            </button>
                        </li>
                    </ul>
                    <p v-if="errors.org_id" class="wd-field-error" role="alert">{{ errors.org_id }}</p>
                    <p v-if="pendingAction === 'select'" class="wd-hint" role="status">Switching workspace…</p>
                </section>
                <section class="wd-card" aria-labelledby="workspace-create-title">
                    <h2 id="workspace-create-title">Start something new.</h2>
                    <p class="wd-description">Give your workspace a name you’ll recognize.</p>
                    <form :aria-busy="pendingAction === 'create'" @submit.prevent="create">
                        <fieldset class="wd-fields" :disabled="busy">
                            <legend class="wd-sr-only">Create a workspace</legend>
                            <label for="workspace-kind">Workspace type</label
                            ><select id="workspace-kind" v-model="kind">
                                <option value="creator">Partner / creator</option>
                                <option value="brand">Brand</option>
                            </select>
                            <FormField
                                id="workspace_name"
                                v-model="newName"
                                label="Workspace name"
                                autocomplete="organization"
                                :maxlength="120"
                                :error="errors.create_name"
                            />
                            <Button type="submit" class="wd-button wd-button-primary">{{
                                pendingAction === 'create' ? 'Creating…' : 'Create workspace'
                            }}</Button>
                        </fieldset>
                    </form>
                </section>
                <section v-if="selected" class="wd-card wd-settings-card" aria-labelledby="workspace-settings-title">
                    <span class="wd-eyebrow">Selected workspace</span>
                    <h2 id="workspace-settings-title">{{ selected.name }}</h2>
                    <Link
                        class="wd-link inline-flex min-h-11 items-center"
                        :href="selected.kind === 'brand' ? '/brand' : '/partner/leads'"
                        >Open {{ selected.kind === 'brand' ? 'brand hub' : 'partner portal' }}</Link
                    >
                    <p v-if="selected.role === 'owner'">
                        <Link class="wd-link" href="/finance/invoices">Private finance records</Link> ·
                        <Link class="wd-link" href="/workspace/entitlements">Pilot entitlements</Link>
                    </p>
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
            </div>
            <details class="wd-card wd-security-card">
                <summary>Account security</summary>
                <p class="wd-description">
                    Sign out every session, including this one. Enter your current password to continue.
                </p>
                <form
                    :aria-busy="pendingAction === 'revoke-sessions'"
                    @submit.prevent="
                        confirmAction(
                            'Sign out all sessions',
                            `Sign out every session for ${account.name}, including this one? You will need to sign in again.`,
                            () => revoke(),
                            $event,
                        )
                    "
                >
                    <fieldset class="wd-fields" :disabled="busy">
                        <legend class="wd-sr-only">Revoke account sessions</legend>
                        <FormField
                            id="revoke_password"
                            v-model="password"
                            label="Current password"
                            type="password"
                            autocomplete="current-password"
                            :error="errors.password"
                        />
                        <Button type="submit" class="wd-button wd-button-secondary">{{
                            pendingAction === 'revoke-sessions' ? 'Signing out…' : 'Sign out all sessions'
                        }}</Button>
                    </fieldset>
                </form>
            </details>
        </div>
    </FoundationShell>
</template>
