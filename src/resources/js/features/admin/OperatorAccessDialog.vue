<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import DialogClose from '../../components/DialogClose.vue';
import FormField from '../../components/FormField.vue';
import { Button } from '../../components/ui/button';
import { confirmAction } from '../../composables/useConfirmation';
import { containDialogFocus } from '../../composables/useDialogFocus';
import { useValidationFocus } from '../../composables/useValidationFocus';
import { capabilityLabels, capabilityNames as labels } from './format';

export type Operator = {
    user_id: number;
    name: string;
    email: string;
    verified: boolean;
    permissions: string[];
    version: number;
    revoked_at: string | null;
    mfa_ready: boolean;
    self: boolean;
    last_owner: boolean;
    suspended: boolean;
};
export type AccessMode = 'grant' | 'change' | 'revoke';
type Target = { id: number; name: string; email: string };

/**
 * Grants, changes or revokes another operator's platform access. Saving always needs a reason, the operator's
 * password and a fresh authenticator code, then the shared Cancel-first confirmation that names the change.
 * The dialog stays open behind the confirmation so server errors appear beside the fields; it closes on success.
 */
const props = defineProps<{ capabilities: string[]; operators: Operator[] }>();
const emit = defineEmits<{ saved: [userId: number] }>();
const dialog = ref<HTMLDialogElement>();
const root = ref<HTMLElement>();
const selected = ref<({ mode: AccessMode } & Target) | null>(null);
let trigger: HTMLElement | null = null;
const form = useForm({
    user: 0,
    expected_version: 0,
    revoke: false,
    capabilities: [] as string[],
    reason: '',
    password: '',
    code: '',
});
useValidationFocus(
    root,
    () => form.errors,
    () => !form.processing,
);
const owner = computed(() => form.capabilities.includes('platform.owner'));
const current = computed(() => props.operators.find((row) => row.user_id === selected.value?.id));
const removed = computed(() =>
    selected.value?.mode === 'change' && current.value
        ? current.value.permissions.filter((key) => !owner.value && !form.capabilities.includes(key))
        : [],
);
const title = computed(() =>
    selected.value?.mode === 'revoke'
        ? 'Revoke platform access'
        : selected.value?.mode === 'change'
          ? 'Change capabilities'
          : 'Grant platform access',
);

function open(mode: AccessMode, target: Target, operator?: Operator, source?: HTMLElement | null): void {
    trigger = source ?? null;
    selected.value = { mode, ...target };
    form.clearErrors();
    form.user = target.id;
    form.expected_version = operator?.version ?? 0;
    form.revoke = mode === 'revoke';
    form.capabilities = mode === 'change' && operator ? [...operator.permissions] : [];
    form.reason = '';
    form.password = '';
    form.code = '';
    if (!dialog.value?.open) dialog.value?.showModal();
    nextTick(() => root.value?.querySelector<HTMLElement>('input')?.focus());
}
function close(): void {
    if (!form.processing) dialog.value?.close();
}
function closed(): void {
    selected.value = null;
    trigger?.focus();
}
function submit(event: SubmitEvent): void {
    if (form.processing || !selected.value) return;
    const who = `${selected.value.name} (${selected.value.email})`;
    const signOut = 'Their current sessions are signed out and they must verify again.';
    const [heading, description, tone] =
        selected.value.mode === 'revoke'
            ? ([
                  'Revoke platform access',
                  `Revoke all platform access for ${who}? They can no longer open administration. ${signOut} Their workspace memberships are not affected.`,
                  'danger',
              ] as const)
            : removed.value.length
              ? ([
                    'Reduce platform access',
                    `Remove ${labels(removed.value)} from ${who}? They keep: ${labels(form.capabilities)}. ${signOut}`,
                    'danger',
                ] as const)
              : ([
                    'Record platform access',
                    `Give ${who} these capabilities: ${labels(form.capabilities)}? ${owner.value ? 'Platform owner includes every capability and managing other operators. ' : ''}${signOut}`,
                    'default',
                ] as const);
    confirmAction(
        heading,
        description,
        () =>
            form.post('/admin/operators', {
                preserveScroll: true,
                // Keep the list's view, search and the row pinned for the changed operator.
                preserveState: true,
                onSuccess: () => {
                    emit('saved', form.user);
                    dialog.value?.close();
                },
                onFinish: () => form.reset('password', 'code'),
            }),
        event,
        { tone },
    );
}
defineExpose({ open });
</script>

<template>
    <dialog
        ref="dialog"
        class="wd-dialog rec-dialog"
        aria-labelledby="operator-access-title"
        @close="closed"
        @cancel="form.processing && $event.preventDefault()"
        @keydown="containDialogFocus($event, dialog)"
    >
        <header class="rec-dialog-head">
            <div>
                <h2 id="operator-access-title">{{ title }}</h2>
                <p v-if="selected" class="rec-dialog-sub">For {{ selected.name }} ({{ selected.email }}).</p>
            </div>
            <DialogClose :label="`Close ${title.toLowerCase()}`" :disabled="form.processing" @click="close" />
        </header>
        <form v-if="selected" ref="root" class="rec-dialog-form" novalidate @submit.prevent="submit">
            <div class="rec-dialog-body">
                <p v-if="form.errors.expected_version" class="wd-banner" role="alert">
                    {{ form.errors.expected_version }}
                    <Link href="/admin/operators" class="wd-link">Reload platform access</Link>
                </p>
                <p v-if="form.errors.user" class="wd-banner" role="alert">{{ form.errors.user }}</p>
                <fieldset class="wd-fields" :disabled="form.processing">
                    <legend class="wd-sr-only">Platform access</legend>
                    <fieldset v-if="selected.mode !== 'revoke'" class="adm-capabilities">
                        <legend>Capabilities</legend>
                        <div v-for="key in capabilities" :key="key">
                            <label class="wd-checkbox"
                                ><input
                                    v-model="form.capabilities"
                                    type="checkbox"
                                    :value="key"
                                    :aria-describedby="`capability-${key}`"
                                />{{ capabilityLabels[key]?.label ?? key }}</label
                            >
                            <p :id="`capability-${key}`" class="wd-hint">{{ capabilityLabels[key]?.grants }}</p>
                        </div>
                        <p v-if="form.errors.capabilities" class="wd-field-error" role="alert">
                            {{ form.errors.capabilities }}
                        </p>
                    </fieldset>
                    <p v-else class="rec-warning">
                        They can no longer open administration and their sessions are signed out. Their workspace
                        memberships are not affected.
                    </p>
                    <p v-if="removed.length" class="wd-warning" role="status">This removes: {{ labels(removed) }}.</p>
                    <FormField
                        id="operator-reason"
                        v-model="form.reason"
                        label="Reason"
                        :maxlength="240"
                        :error="form.errors.reason"
                        hint="Recorded in the administration audit."
                    />
                    <FormField
                        id="operator-password"
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        label="Your password"
                        :error="form.errors.password"
                    />
                    <FormField
                        id="operator-code"
                        v-model="form.code"
                        label="Your authenticator code"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        :maxlength="6"
                        :error="form.errors.code"
                        hint="A fresh six-digit code. Changing platform access always needs one."
                    />
                </fieldset>
            </div>
            <footer class="rec-dialog-foot">
                <Button type="button" class="wd-button wd-button-secondary" :disabled="form.processing" @click="close"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    class="wd-button"
                    :class="selected.mode === 'revoke' ? 'wd-button-danger' : 'wd-button-primary'"
                    :disabled="form.processing"
                    >{{ selected.mode === 'revoke' ? 'Revoke platform access' : 'Review and save' }}</Button
                >
            </footer>
        </form>
    </dialog>
</template>
