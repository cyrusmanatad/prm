<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ContentSection from '../../components/ContentSection.vue';
import DetailList from '../../components/DetailList.vue';
import FormField from '../../components/FormField.vue';
import SelectField from '../../components/SelectField.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { Button } from '../../components/ui/button';
import { useValidationFocus } from '../../composables/useValidationFocus';
import ClosureImpact from './ClosureImpact.vue';
import type { ClosureRequest } from '../../types/privacy';
type ReviewRequest = ClosureRequest & {
    requested_by: string;
    requester_email: string;
    handler: string | null;
    assigned_to: number | null;
    counts: Record<string, number>;
};
const props = defineProps<{ request: ReviewRequest }>();
const root = ref<HTMLElement>();
const form = useForm({
    version: props.request.version,
    action: 'assign',
    reason: '',
    verification_reference: '',
    password: '',
    code: '',
});
watch(
    () => props.request.version,
    (version) => {
        form.version = version;
    },
);
const errors = computed(() => form.errors as Record<string, string>);
useValidationFocus(
    root,
    () => form.errors,
    () => !form.processing,
);
function save(): void {
    if (form.processing) return;
    form.post(`/admin/privacy-requests/${props.request.id}`, {
        preserveScroll: true,
        onSuccess: () => form.defaults({ ...form.data(), password: '', code: '' }),
        onFinish: () => {
            form.password = '';
            form.code = '';
        },
    });
}
</script>
<template>
    <ContentSection :title="`${request.subject_label} — request ${request.id.slice(0, 8)}`">
        <div ref="root" class="space-y-4">
            <StatusBadge
                :label="
                    request.state === 'cancelled'
                        ? 'Cancelled'
                        : request.reviewed_at
                          ? 'Review recorded'
                          : 'Awaiting review'
                "
                :tone="request.state === 'cancelled' ? 'neutral' : 'warning'"
            />
            <DetailList
                :items="[
                    { key: 'scope', label: 'Scope', value: request.scope },
                    {
                        key: 'requester',
                        label: 'Requested by',
                        value: `${request.requested_by} (${request.requester_email})`,
                    },
                    { key: 'reason', label: 'Reason', value: request.reason },
                    { key: 'handler', label: 'Handler', value: request.handler },
                    {
                        key: 'earliest',
                        label: 'Earliest erasure',
                        value: new Date(request.not_before).toLocaleString(),
                    },
                ]"
            />
            <ClosureImpact :counts="request.counts" />
            <details v-if="request.state !== 'cancelled'">
                <summary class="wd-link">Handle this request</summary>
                <p class="wd-description">
                    Assign the request to yourself before recording a review. Review does not erase records. A
                    cancellation on behalf of the requester needs a reference to their verified request.
                </p>
                <form :aria-busy="form.processing" @submit.prevent="save">
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
                        <Button type="submit" class="wd-button wd-button-primary">{{
                            form.processing ? 'Saving…' : 'Save request update'
                        }}</Button>
                    </fieldset>
                </form>
            </details>
        </div>
    </ContentSection>
</template>
