<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import ContentSection from '../../components/ContentSection.vue';
import FormField from '../../components/FormField.vue';
import SelectField from '../../components/SelectField.vue';
import ActionGroup from '../../components/ActionGroup.vue';
import { Button } from '../../components/ui/button';
import { confirmAction } from '../../composables/useConfirmation';
import { useValidationFocus } from '../../composables/useValidationFocus';
import { resolutionOptions as resolutions, type DeliveryNotice, type DeliveryFilters } from './types';
const props = defineProps<{ notice: DeliveryNotice; filters: DeliveryFilters }>();
const emit = defineEmits<{ close: []; busy: [value: boolean] }>();
const root = ref<HTMLElement>();
const form = useForm({ version: props.notice.resolution_version, resolution: 'unknown_closed', reason: '' });
useValidationFocus(
    root,
    () => form.errors,
    () => !form.processing,
);
function reload() {
    router.get('/admin/notifications', props.filters, { preserveState: false });
}
function resolve(event: Event) {
    if (form.processing) return;
    confirmAction(
        'Record delivery resolution',
        `Record this resolution for notification ${props.notice.id}? The uncertain transport history is retained. No email will be resent.`,
        () => {
            const query = new URLSearchParams({
                workspace: props.filters.workspace,
                state: props.filters.state,
                page: String(props.filters.page),
            });
            form.post(`/admin/notifications/${props.notice.id}/resolve?${query}`, {
                preserveScroll: true,
                onStart: () => emit('busy', true),
                onFinish: () => emit('busy', false),
                onSuccess: () => {
                    emit('busy', false);
                    emit('close');
                },
            });
        },
        event,
    );
}
</script>
<template>
    <ContentSection
        title="Record a resolution"
        description="An uncertain email is never replayed. A replacement send requires a new invitation or a fresh draft approval."
    >
        <form ref="root" @submit.prevent="resolve">
            <p class="break-all">Notification {{ notice.id }}</p>
            <p v-if="form.errors.version" class="wd-banner" role="alert">
                {{ form.errors.version }}
                <button type="button" class="wd-link" @click="reload">Reload diagnostics</button>
            </p>
            <fieldset class="wd-fields" :disabled="form.processing">
                <legend class="wd-sr-only">Delivery resolution</legend>
                <SelectField
                    id="delivery-resolution"
                    v-model="form.resolution"
                    label="Provider evidence"
                    :options="resolutions"
                    :error="form.errors.resolution"
                /><FormField
                    id="delivery-reason"
                    v-model="form.reason"
                    label="Reason"
                    :maxlength="240"
                    :error="form.errors.reason"
                    hint="Record the evidence without addresses, message content or credentials."
                /><ActionGroup
                    ><Button type="submit" class="wd-button wd-button-primary">Record resolution</Button
                    ><Button type="button" class="wd-button wd-button-secondary" @click="$emit('close')"
                        >Cancel</Button
                    ></ActionGroup
                >
            </fieldset>
        </form>
    </ContentSection>
</template>
