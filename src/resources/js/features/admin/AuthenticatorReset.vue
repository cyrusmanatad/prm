<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormField from '../../components/FormField.vue';
import UnavailableNotice from '../../components/UnavailableNotice.vue';
import { Button } from '../../components/ui/button';
import { confirmAction } from '../../composables/useConfirmation';
import FormDialog from './FormDialog.vue';

// Reset after the written identity check (D35). The server refuses operators and the caller's own account.
const props = defineProps<{
    account: number;
    name: string;
    enrolled: boolean;
    platformGrant: boolean;
    self: boolean;
    canManage: boolean;
}>();
// A compact row in the Security tab; the form opens in a dialog and the Cancel-first confirmation follows.
const dialog = ref<InstanceType<typeof FormDialog>>();
const form = useForm({ reason: '', password: '', code: '' });
function start(event: Event) {
    form.clearErrors();
    form.reset();
    dialog.value?.open(event.currentTarget as HTMLElement);
}
function submit(event: SubmitEvent) {
    if (form.processing) return;
    confirmAction(
        'Reset authenticator',
        `Reset the authenticator for ${props.name}? Their authenticator and recovery codes stop working, every session is signed out, and they must set up a new authenticator at their next sign-in. Until then the account is protected by its password alone.`,
        () =>
            form.post(`/admin/accounts/${props.account}/reset-authenticator`, {
                preserveScroll: true,
                onSuccess: () => dialog.value?.close(),
                onFinish: () => form.reset('password', 'code'),
            }),
        event,
        { tone: 'danger' },
    );
}
</script>

<template>
    <template v-if="enrolled">
        <UnavailableNotice
            v-if="platformGrant"
            title="This authenticator cannot be reset here"
            reason="Accounts with platform access are recovered offline by an authorized operator."
        />
        <UnavailableNotice
            v-else-if="self"
            title="You cannot reset your own authenticator"
            reason="Replace it from your own account security instead."
        />
        <UnavailableNotice
            v-else-if="!canManage"
            title="Resetting an authenticator is outside your capabilities"
            reason="It needs the access management capability."
        />
        <template v-else>
            <div class="adm-action-row">
                <div>
                    <h3>Reset authenticator</h3>
                    <p>Only after confirming identity through the verified email. Every session is signed out.</p>
                </div>
                <Button type="button" class="wd-button wd-button-danger-outline" @click="start"
                    >Reset authenticator</Button
                >
            </div>
            <FormDialog
                id="reset-authenticator"
                ref="dialog"
                title="Reset authenticator"
                :description="`For ${name}.`"
                submit-label="Review reset"
                tone="danger"
                :processing="form.processing"
                :errors="form.errors"
                @submit="submit"
            >
                <p>
                    Use this only after confirming the person's identity through their verified email, following the
                    support procedure. They will set up a new authenticator at their next sign-in.
                </p>
                <fieldset class="wd-fields" :disabled="form.processing">
                    <legend class="wd-sr-only">Reset authenticator</legend>
                    <FormField
                        id="reset-reason"
                        v-model="form.reason"
                        label="Reason"
                        :maxlength="240"
                        :error="form.errors.reason"
                        hint="Record how identity was confirmed. Kept in the administration audit and not shown to the person."
                    />
                    <FormField
                        id="reset-password"
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        label="Your password"
                        :error="form.errors.password"
                    />
                    <FormField
                        id="reset-code"
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
</template>
