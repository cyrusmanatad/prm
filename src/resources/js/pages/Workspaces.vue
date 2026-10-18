<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import WorkspaceScreen from '../features/workspaces/WorkspaceScreen.vue';
import type { AccountSummary, FieldErrors, WorkspaceAction, WorkspaceSummary } from '../types/foundation';

const props = withDefaults(defineProps<{
    account: AccountSummary;
    workspaces: WorkspaceSummary[];
    activeOrgId: string | null;
    accessRevoked?: boolean;
    status?: string;
    errors?: Record<string, string | FieldErrors>;
}>(), { accessRevoked: false, status: '', errors: () => ({}) });
const screen = ref<InstanceType<typeof WorkspaceScreen>>();
const pending = ref<WorkspaceAction | null>(null);
const loggingOut = ref(false);
const errors = computed<FieldErrors>(() => {
    const result: FieldErrors = {};
    for (const [key, value] of Object.entries(props.errors)) {
        if (typeof value === 'string') result[key] = value;
        else for (const [field, message] of Object.entries(value)) {
            result[field === 'name' && (key === 'create' || key === 'rename') ? `${key}_name` : field] = message;
        }
    }
    return result;
});

function submit(action: WorkspaceAction, data: Record<string, string>): void {
    if (pending.value || loggingOut.value) return;
    const paths: Record<WorkspaceAction, string> = {
        create: '/workspaces', select: '/workspace/select', rename: '/workspace', 'revoke-sessions': '/sessions',
    };
    router.visit(paths[action], {
        method: action === 'rename' ? 'patch' : action === 'revoke-sessions' ? 'delete' : 'post',
        data, errorBag: action, preserveScroll: true, preserveState: true,
        onStart: () => { pending.value = action; },
        onSuccess: () => { if (action === 'create') screen.value?.clearCreateForm(); },
        onFinish: () => { pending.value = null; screen.value?.clearPasswords(); },
    });
}
function logout(): void {
    if (pending.value || loggingOut.value) return;
    router.post('/logout', {}, {
        onStart: () => { loggingOut.value = true; },
        onFinish: () => { loggingOut.value = false; },
    });
}
</script>

<template>
  <Head title="Workspaces" />
  <WorkspaceScreen ref="screen" :account="account" :workspaces="workspaces" :active-org-id="activeOrgId"
    :access-revoked="accessRevoked" :status="status" :errors="errors" :pending-action="pending"
    @create="submit('create', { name: $event })" @select="submit('select', { org_id: $event })"
    @rename="submit('rename', { name: $event })" @revoke-sessions="submit('revoke-sessions', { password: $event })" @logout="logout" />
</template>
