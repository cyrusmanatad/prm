<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import ContentSection from '../../components/ContentSection.vue';
import FormField from '../../components/FormField.vue';
import { Button } from '../../components/ui/button';
import { confirmAction } from '../../composables/useConfirmation';
import { useValidationFocus } from '../../composables/useValidationFocus';
import ClosureImpact from './ClosureImpact.vue';
import type { ClosurePreview } from '../../types/privacy';
const props = defineProps<{ preview: ClosurePreview; enrolled: boolean }>();
const root = ref<HTMLElement>();
const form = useForm({
    scope: props.preview.scope,
    org_id: props.preview.org_id,
    manifest_hash: props.preview.hash,
    reason: '',
    password: '',
    code: '',
});
watch(
    () => props.preview.hash,
    () => {
        form.scope = props.preview.scope;
        form.org_id = props.preview.org_id;
        form.manifest_hash = props.preview.hash;
        form.password = '';
        form.code = '';
    },
);
const errors = computed(() => form.errors as Record<string, string>);
useValidationFocus(
    root,
    () => form.errors,
    () => !form.processing,
);
function request(event: Event): void {
    if (form.processing || props.preview.blockers.length || (props.preview.scope === 'workspace' && !props.enrolled))
        return;
    confirmAction(
        `Close ${props.preview.label}`,
        `Close ${props.preview.label}? Access will stop immediately. Pending work, links and shared access will end. Records are retained for privacy review, with at least 30 days before erasure can begin.`,
        () => {
            form.post('/account/closure', {
                preserveScroll: true,
                onSuccess: () => form.defaults({ ...form.data(), password: '', code: '' }),
                onFinish: () => {
                    form.password = '';
                    form.code = '';
                },
            });
        },
        event,
        { tone: 'danger' },
    );
}
</script>
<template>
    <ContentSection :title="`Review closure: ${preview.label}`">
        <div ref="root" class="space-y-4">
            <p class="wd-description">
                {{
                    preview.scope === 'account'
                        ? 'You will be signed out. Future sign-ins will open only your closure and security pages until you cancel.'
                        : 'All members will lose access to this workspace. Their accounts and other workspaces stay available.'
                }}
            </p>
            <p class="wd-description">
                Public links and partnerships end, publication stops, and eligible pending mail and schedules are
                cancelled. Cancelling closure restores eligible interactive access; it does not restore these items.
            </p>
            <p class="wd-description">
                Financial and audit records remain protected pending retention review. Nothing is erased by this action.
            </p>
            <ClosureImpact :counts="preview.counts" />
            <ul v-if="preview.blockers.length" class="wd-field-error" aria-label="Closure blockers">
                <li v-for="blocker in preview.blockers" :key="blocker">{{ blocker }}</li>
            </ul>
            <p v-if="preview.scope === 'workspace' && !enrolled" class="wd-description">
                <Link href="/account/authenticator" class="wd-link">Set up your authenticator</Link> before closing a
                workspace.
            </p>
            <form v-if="!preview.blockers.length" :aria-busy="form.processing" @submit.prevent="request">
                <fieldset class="wd-fields" :disabled="form.processing || (preview.scope === 'workspace' && !enrolled)">
                    <legend class="wd-sr-only">Confirm closure identity and reason</legend>
                    <FormField
                        id="closure_reason"
                        v-model="form.reason"
                        label="Reason for closure"
                        :maxlength="240"
                        :error="form.errors.reason"
                    />
                    <FormField
                        id="closure_password"
                        v-model="form.password"
                        label="Current password"
                        type="password"
                        autocomplete="current-password"
                        :error="form.errors.password"
                    />
                    <FormField
                        v-if="enrolled"
                        id="closure_code"
                        v-model="form.code"
                        label="Authenticator code"
                        autocomplete="one-time-code"
                        inputmode="numeric"
                        :error="form.errors.code"
                    />
                    <p v-if="errors.closure || errors.version" class="wd-field-error" role="alert">
                        {{ errors.closure || errors.version }}
                    </p>
                    <Button type="submit" class="wd-button wd-button-danger">{{
                        form.processing ? 'Requesting closure…' : `Close ${preview.scope}`
                    }}</Button>
                </fieldset>
            </form>
        </div>
    </ContentSection>
</template>
