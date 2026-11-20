<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ContentSection from '../../components/ContentSection.vue';
import FormField from '../../components/FormField.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { Button } from '../../components/ui/button';
import { useValidationFocus } from '../../composables/useValidationFocus';
import type { ClosureRequest } from '../../types/privacy';
const props = defineProps<{ request: ClosureRequest; enrolled: boolean; accountClosing: boolean }>();
const root = ref<HTMLElement>();
const form = useForm({ version: props.request.version, reason: '', password: '', code: '' });
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
function cancel(): void {
    if (form.processing) return;
    form.post(`/account/closure/${props.request.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => form.defaults({ ...form.data(), password: '', code: '' }),
        onFinish: () => {
            form.password = '';
            form.code = '';
        },
    });
}
function date(value: string): string {
    return new Date(value).toLocaleString();
}
</script>
<template>
    <ContentSection :title="`${request.subject_label} — request ${request.id.slice(0, 8)}`">
        <div ref="root" class="space-y-4">
            <StatusBadge
                :label="
                    request.state === 'cancelled'
                        ? 'Closure cancelled'
                        : new Date(request.not_before) <= new Date()
                          ? 'Awaiting privacy review'
                          : 'Closure requested'
                "
                :tone="request.state === 'cancelled' ? 'neutral' : 'warning'"
            />
            <p class="wd-description">
                Requested {{ date(request.requested_at) }}. Erasure cannot begin before
                {{ date(request.not_before) }} and requires retention review.
            </p>
            <p v-if="request.reviewed_at" class="wd-description">
                Privacy review recorded {{ date(request.reviewed_at) }}.
            </p>
            <p v-if="request.state === 'cancelled'" class="wd-description">
                Eligible access is available. Previously ended links, partnerships, exports and cancelled work remain
                ended.
            </p>
            <details v-else-if="request.scope === 'account' || !accountClosing">
                <summary class="wd-link">Cancel this closure request</summary>
                <p class="wd-description">
                    Verify your identity to restore eligible access. Publication, links, partnerships and cancelled work
                    will not resume automatically.
                </p>
                <form :aria-busy="form.processing" @submit.prevent="cancel">
                    <fieldset class="wd-fields" :disabled="form.processing">
                        <legend class="wd-sr-only">Cancel closure for {{ request.subject_label }}</legend>
                        <FormField
                            :id="`cancel_reason_${request.id}`"
                            v-model="form.reason"
                            label="Reason for cancellation"
                            :maxlength="240"
                            :error="form.errors.reason"
                        />
                        <FormField
                            :id="`cancel_password_${request.id}`"
                            v-model="form.password"
                            label="Current password"
                            type="password"
                            autocomplete="current-password"
                            :error="form.errors.password"
                        />
                        <FormField
                            v-if="enrolled"
                            :id="`cancel_code_${request.id}`"
                            v-model="form.code"
                            label="Authenticator code"
                            autocomplete="one-time-code"
                            inputmode="numeric"
                            :error="form.errors.code"
                        />
                        <p v-if="errors.version || errors.closure" class="wd-field-error" role="alert">
                            {{ errors.version || errors.closure }}
                        </p>
                        <Button type="submit" class="wd-button wd-button-secondary">{{
                            form.processing ? 'Cancelling…' : 'Cancel closure request'
                        }}</Button>
                    </fieldset>
                </form>
            </details>
        </div>
    </ContentSection>
</template>
