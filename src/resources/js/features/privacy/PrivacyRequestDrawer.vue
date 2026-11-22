<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import DetailList from '../../components/DetailList.vue';
import DialogClose from '../../components/DialogClose.vue';
import FormField from '../../components/FormField.vue';
import SelectField from '../../components/SelectField.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { Button } from '../../components/ui/button';
import { confirmAction } from '../../composables/useConfirmation';
import { containDialogFocus } from '../../composables/useDialogFocus';
import { useValidationFocus } from '../../composables/useValidationFocus';
import ClosureImpact from './ClosureImpact.vue';
import { privacyStatus, type ReviewRequest } from './review';

/**
 * One closure request over the queue: its metadata, the closure impact counts and the handling form (assign, record
 * review, or cancel a verified request). Every change needs a reason, the operator's password and a fresh code.
 * Nothing here erases records. The drawer stays open on errors and closes when the update is saved. Cancelling a
 * verified request ends the closure on the requester's behalf, so it is confirmed first (Cancel-first, danger tone).
 */
const props = defineProps<{ request: ReviewRequest | null }>();
const emit = defineEmits<{ close: [] }>();
const dialog = ref<HTMLDialogElement>();
const root = ref<HTMLElement>();
const form = useForm({ version: 0, action: 'assign', reason: '', verification_reference: '', password: '', code: '' });
const errors = computed(() => form.errors as Record<string, string>);
useValidationFocus(
    root,
    () => form.errors,
    () => !form.processing,
);
const name = computed(() =>
    props.request ? `${props.request.subject_label} — request ${props.request.id.slice(0, 8)}` : '',
);
const status = computed(() => (props.request ? privacyStatus(props.request) : null));
watch(
    () => props.request,
    async (request) => {
        if (!request) {
            if (dialog.value?.open) dialog.value.close();
            return;
        }
        form.clearErrors();
        form.version = request.version;
        form.action = 'assign';
        form.reason = '';
        form.verification_reference = '';
        form.password = '';
        form.code = '';
        await nextTick();
        if (!dialog.value?.open) dialog.value?.showModal();
        dialog.value?.querySelector<HTMLElement>('.wd-dialog-close')?.focus();
    },
);
function send(id: string): void {
    form.post(`/admin/privacy-requests/${id}`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onFinish: () => {
            form.password = '';
            form.code = '';
        },
    });
}
function save(event: SubmitEvent): void {
    const request = props.request;
    if (form.processing || !request) return;
    if (form.action !== 'cancel') return send(request.id);
    const subject =
        request.scope === 'account' ? `the account ${request.subject_label}` : `the workspace ${request.subject_label}`;
    confirmAction(
        'Cancel verified closure request',
        `Cancel the closure of ${subject} on the requester's behalf? Erasure will not go ahead and eligible access is restored. Publication, links, partnerships and cancelled work do not resume automatically.`,
        () => send(request.id),
        event,
        { tone: 'danger' },
    );
}
</script>

<template>
    <dialog
        ref="dialog"
        class="wd-dialog rec-drawer"
        :aria-label="name"
        @close="emit('close')"
        @cancel="form.processing && $event.preventDefault()"
        @keydown="containDialogFocus($event, dialog)"
    >
        <template v-if="request && status">
            <header class="rec-drawer-head">
                <div class="rec-drawer-identity">
                    <h2>{{ request.subject_label }}</h2>
                    <p class="rec-drawer-status">
                        <StatusBadge :label="status.label" :tone="status.tone" />
                        <span class="rec-fine">Request {{ request.id.slice(0, 8) }}</span>
                    </p>
                </div>
                <DialogClose label="Close privacy request" :disabled="form.processing" @click="dialog?.close()" />
            </header>
            <div
                ref="root"
                class="rec-drawer-body rec-drawer-scroll"
                tabindex="0"
                role="region"
                aria-label="Request details"
            >
                <DetailList
                    :items="[
                        { key: 'scope', label: 'Scope', value: request.scope === 'account' ? 'Account' : 'Workspace' },
                        {
                            key: 'requester',
                            label: 'Requested by',
                            value: `${request.requested_by} (${request.requester_email})`,
                        },
                        { key: 'reason', label: 'Reason', value: request.reason },
                        { key: 'handler', label: 'Handler', value: request.handler ?? 'Unassigned' },
                        {
                            key: 'earliest',
                            label: 'Earliest erasure',
                            value: new Date(request.not_before).toLocaleString(),
                        },
                    ]"
                />
                <ClosureImpact :counts="request.counts" />
                <form
                    v-if="request.state !== 'cancelled'"
                    id="privacy-request-form"
                    :aria-busy="form.processing"
                    @submit.prevent="save"
                >
                    <h3 class="adm-drawer-heading">Handle this request</h3>
                    <p class="rec-fine">
                        Assign the request to yourself before recording a review. Review does not erase records. A
                        cancellation on behalf of the requester needs a reference to their verified request.
                    </p>
                    <fieldset class="wd-fields" :disabled="form.processing">
                        <legend class="wd-sr-only">Privacy review for {{ request.subject_label }}</legend>
                        <SelectField
                            :id="`privacy_action_${request.id}`"
                            v-model="form.action"
                            label="Review action"
                            :searchable="false"
                            :options="[
                                { value: 'assign', label: 'Assign to me' },
                                { value: 'review', label: 'Record review' },
                                { value: 'cancel', label: 'Cancel verified closure request' },
                            ]"
                        />
                        <FormField
                            :id="`privacy_reason_${request.id}`"
                            v-model="form.reason"
                            label="Review reason"
                            :maxlength="240"
                            :error="form.errors.reason"
                        />
                        <FormField
                            v-if="form.action === 'cancel'"
                            :id="`privacy_reference_${request.id}`"
                            v-model="form.verification_reference"
                            label="Verified request reference"
                            hint="Use a case reference. Do not enter identity documents or private account content."
                            :maxlength="160"
                            :minlength="8"
                            :error="form.errors.verification_reference"
                        />
                        <FormField
                            :id="`privacy_password_${request.id}`"
                            v-model="form.password"
                            label="Current password"
                            type="password"
                            autocomplete="current-password"
                            :error="form.errors.password"
                        />
                        <FormField
                            :id="`privacy_code_${request.id}`"
                            v-model="form.code"
                            label="Authenticator code"
                            autocomplete="one-time-code"
                            inputmode="numeric"
                            :error="form.errors.code"
                        />
                        <p v-if="errors.version || errors.closure" class="wd-field-error" role="alert">
                            {{ errors.version || errors.closure }}
                        </p>
                    </fieldset>
                </form>
                <p v-else class="rec-fine">This request was cancelled. Its history is kept.</p>
            </div>
            <footer v-if="request.state !== 'cancelled'" class="rec-drawer-foot">
                <Button
                    type="button"
                    class="wd-button wd-button-secondary"
                    :disabled="form.processing"
                    @click="dialog?.close()"
                    >Close</Button
                >
                <Button
                    type="submit"
                    form="privacy-request-form"
                    class="wd-button wd-button-primary"
                    :disabled="form.processing"
                    >{{ form.processing ? 'Saving…' : 'Save request update' }}</Button
                >
            </footer>
        </template>
    </dialog>
</template>
