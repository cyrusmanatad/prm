<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import FormField from '../../components/FormField.vue';
import UnavailableNotice from '../../components/UnavailableNotice.vue';
import { Button } from '../../components/ui/button';
import { confirmAction } from '../../composables/useConfirmation';
import FormDialog from './FormDialog.vue';
import { dateTime } from './format';

export type AccessImpact = {
    suspended: boolean;
    version: number;
    suspension: { reason: string; since: string; by: string } | null;
    // Account
    sessions?: number;
    memberships?: number;
    sole_owner_of?: { id: string; name: string }[];
    platform_grant?: boolean;
    self?: boolean;
    last_owner?: boolean;
    // Workspace
    members?: number;
    partnerships?: number;
    pending_notifications?: number;
    ready_runs?: number;
    approved_drafts?: number;
    public_links?: number;
};
type Mode = 'suspend' | 'reactivate' | 'sign-out';
const props = defineProps<{
    kind: 'account' | 'workspace';
    reference: string;
    name: string;
    access: AccessImpact | null;
}>();

const path = computed(() => `/admin/${props.kind === 'account' ? 'accounts' : 'workspaces'}/${props.reference}`);
/**
 * Access actions as compact rows in the page's Access card. Each opens a dialog with the server-computed impact, a
 * reason, the operator's password and a fresh code; saving then asks for the shared Cancel-first confirmation.
 */
const mode = ref<Mode | null>(null);
const dialog = ref<InstanceType<typeof FormDialog>>();
const form = useForm({ expected_version: 0, reason: '', password: '', code: '' });
const plural = (count: number, one: string, many: string) => `${count} ${count === 1 ? one : many}`;
// What the server says will happen, in the operator's words. Counts are current as of this page load.
const consequences = computed(() => {
    const a = props.access;
    if (!a) return [];
    if (props.kind === 'account')
        return [
            `Signs out ${plural(a.sessions ?? 0, 'session', 'sessions')} and refuses sign-in on every device.`,
            `Blocks access to ${plural(a.memberships ?? 0, 'workspace', 'workspaces')}. Those workspaces keep working for their other members.`,
            ...(a.sole_owner_of?.length
                ? [
                      `Leaves ${a.sole_owner_of.map((row) => row.name).join(', ')} with no owner who can sign in. ${a.sole_owner_of.length === 1 ? 'That workspace is' : 'Those workspaces are'} not suspended by this action.`,
                  ]
                : []),
            ...(a.platform_grant ? ['Blocks this person from administration. Their platform grant is kept.'] : []),
            'Memberships, grants and records are not changed. Password reset does not restore access.',
        ];
    return [
        `Blocks ${plural(a.members ?? 0, 'member', 'members')} from opening the workspace. They can still sign in.`,
        `Makes ${plural(a.public_links ?? 0, 'public link', 'public links')} respond as unavailable.`,
        `Pauses ${plural(a.partnerships ?? 0, 'active partnership', 'active partnerships')}; the relationships stay recorded.`,
        `Cancels ${plural(a.pending_notifications ?? 0, 'queued notification', 'queued notifications')}, ${plural(a.ready_runs ?? 0, 'scheduled run', 'scheduled runs')} and ${plural(a.approved_drafts ?? 0, 'approved follow-up', 'approved follow-ups')}. They are not replayed on reactivation.`,
        'Ends any open support access. Records are kept unchanged and cannot be read or exported while suspended.',
    ];
});
const blocked = computed(() => {
    const a = props.access;
    if (!a || props.kind !== 'account' || a.suspended) return null;
    if (a.self) return 'You cannot suspend your own account or sign out your own sessions here.';
    if (a.last_owner)
        return 'This is the last usable platform owner. Give another verified person platform owner access first.';
    return null;
});
function open(next: Mode, event: Event) {
    mode.value = next;
    form.clearErrors();
    form.expected_version = props.access?.version ?? 0;
    form.reason = '';
    form.password = '';
    form.code = '';
    dialog.value?.open(event.currentTarget as HTMLElement);
}
// One-line previews for the rows; the dialog lists the full impact.
const summary = computed(() => {
    const a = props.access;
    if (!a) return '';
    if (props.kind === 'account')
        return `Signs out ${plural(a.sessions ?? 0, 'session', 'sessions')} and blocks ${plural(a.memberships ?? 0, 'workspace', 'workspaces')}.${a.sole_owner_of?.length ? ` Sole owner of ${a.sole_owner_of.map((row) => row.name).join(', ')}.` : ''}`;
    return `Blocks ${plural(a.members ?? 0, 'member', 'members')}. Queued work is cancelled and not replayed on reactivation.`;
});
const labels: Record<Mode, { title: string; submit: string }> = {
    suspend: { title: `Suspend ${props.kind}`, submit: 'Review suspension' },
    reactivate: { title: `Reactivate ${props.kind}`, submit: 'Review reactivation' },
    'sign-out': { title: 'Sign out sessions', submit: 'Review sign-out' },
};
function submit(event: SubmitEvent) {
    if (form.processing || !mode.value) return;
    const current = mode.value;
    const description =
        current === 'suspend'
            ? `Suspend ${props.name}? ${consequences.value.join(' ')}`
            : current === 'reactivate'
              ? `Reactivate ${props.name}? Access is restored from now on. Revoked memberships and grants, expired links and cancelled work are not restored or replayed.`
              : `Sign out every session for ${props.name}? They can sign in again straight away. Nothing else changes.`;
    confirmAction(
        labels[current].title,
        description,
        () =>
            form.post(`${path.value}/${current}`, {
                preserveScroll: true,
                onSuccess: () => dialog.value?.close(),
                onFinish: () => form.reset('password', 'code'),
            }),
        event,
        { tone: current === 'reactivate' ? 'default' : 'danger' },
    );
}
</script>

<template>
    <UnavailableNotice
        v-if="!access"
        title="Changing access is outside your capabilities"
        reason="Suspending, reactivating and signing out sessions need the access management capability."
    />
    <template v-else>
        <div v-if="access.suspended" class="adm-action-row">
            <div>
                <h3>Suspended</h3>
                <p v-if="access.suspension">
                    Suspended {{ dateTime(access.suspension.since) }} by {{ access.suspension.by }}. Reason:
                    {{ access.suspension.reason }}
                </p>
                <p>Reactivating restores access from now on; cancelled work is not replayed.</p>
            </div>
            <Button type="button" class="wd-button wd-button-secondary" @click="open('reactivate', $event)"
                >Reactivate {{ kind }}</Button
            >
        </div>
        <UnavailableNotice v-else-if="blocked" title="This account cannot be suspended" :reason="blocked" />
        <template v-else>
            <div class="adm-action-row">
                <div>
                    <h3>Suspend {{ kind }}</h3>
                    <p>{{ summary }}</p>
                </div>
                <Button type="button" class="wd-button wd-button-danger-outline" @click="open('suspend', $event)"
                    >Suspend {{ kind }}</Button
                >
            </div>
            <div v-if="kind === 'account' && (access.sessions ?? 0) > 0" class="adm-action-row">
                <div>
                    <h3>Sign out everywhere</h3>
                    <p>Ends {{ plural(access.sessions ?? 0, 'session', 'sessions') }}. The account stays active.</p>
                </div>
                <Button type="button" class="wd-button wd-button-secondary" @click="open('sign-out', $event)"
                    >Sign out sessions</Button
                >
            </div>
        </template>
        <FormDialog
            v-if="access.suspended || !blocked"
            :id="`access-${kind}`"
            ref="dialog"
            :title="mode ? labels[mode].title : ''"
            :description="`For ${name}.`"
            :submit-label="mode ? labels[mode].submit : ''"
            :tone="mode === 'reactivate' ? 'default' : 'danger'"
            :processing="form.processing"
            :errors="form.errors"
            @submit="submit"
        >
            <template v-if="mode === 'suspend'">
                <p>What this will do:</p>
                <ul class="adm-impact">
                    <li v-for="line in consequences" :key="line">{{ line }}</li>
                </ul>
            </template>
            <p v-else-if="mode === 'reactivate'">
                Access is restored from now on only. Revoked memberships and grants, expired links and cancelled work
                are not restored or replayed.
            </p>
            <p v-else-if="mode === 'sign-out'">
                Ends {{ plural(access.sessions ?? 0, 'session', 'sessions') }}. The person can sign in again.
            </p>
            <p v-if="form.errors.expected_version" class="wd-banner rec-banner" role="alert">
                {{ form.errors.expected_version }}
                <Link :href="path" class="wd-link">Reload this {{ kind }}</Link>
            </p>
            <fieldset v-if="mode" class="wd-fields" :disabled="form.processing">
                <legend class="wd-sr-only">{{ labels[mode].title }}</legend>
                <FormField
                    :id="`access-reason-${kind}`"
                    v-model="form.reason"
                    label="Reason"
                    :maxlength="240"
                    :error="form.errors.reason"
                    hint="Recorded in the administration audit. It is not shown to the affected person."
                />
                <FormField
                    :id="`access-password-${kind}`"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    label="Your password"
                    :error="form.errors.password"
                />
                <FormField
                    :id="`access-code-${kind}`"
                    v-model="form.code"
                    label="Your authenticator code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    :maxlength="6"
                    :error="form.errors.code"
                    hint="A fresh six-digit code."
                />
            </fieldset>
        </FormDialog>
    </template>
</template>
