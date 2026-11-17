<script setup lang="ts">
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import ContentSection from '../components/ContentSection.vue';
import CopyReference from '../components/CopyReference.vue';
import FormField from '../components/FormField.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { Button } from '../components/ui/button';
import { useValidationFocus } from '../composables/useValidationFocus';

const props = defineProps<{ enrolled: boolean; secret?: string }>();
const root = ref<HTMLElement>();
const form = useForm({ password: '', code: '' });
useValidationFocus(
    root,
    () => form.errors,
    () => !form.processing,
);
function submit() {
    form.post(props.enrolled || props.secret ? '/admin/security/verify' : '/admin/security/enroll', {
        onFinish: () => form.reset('password', 'code'),
    });
}
</script>

<template>
    <AdminShell
        title="Verify your identity"
        description="Use your password and authenticator. Administrative access expires after 15 minutes."
        eyebrow="My admin security"
    >
        <ContentSection :title="enrolled ? 'Authenticator verification' : 'Set up an authenticator'">
            <p v-if="enrolled">
                <StatusBadge label="Authenticator enrolled" tone="success" />
            </p>
            <p v-else-if="!secret">
                Enter your password to generate a setup key. Add the key to your authenticator app as a time-based,
                six-digit account.
            </p>
            <div v-if="secret" class="adm-setup-key">
                <p>
                    Add this setup key to your authenticator app as a time-based, six-digit account, then enter the
                    current code. The key is shown once and expires in 10 minutes.
                </p>
                <p>Setup key: <CopyReference :value="secret" label="setup key" /></p>
            </div>
            <form ref="root" @submit.prevent="submit">
                <fieldset class="wd-fields" :disabled="form.processing">
                    <legend class="wd-sr-only">Administrator verification</legend>
                    <FormField
                        id="admin-password"
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        label="Password"
                        :error="form.errors.password"
                    />
                    <FormField
                        v-if="enrolled || secret"
                        id="admin-code"
                        v-model="form.code"
                        label="Authenticator code"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        :maxlength="6"
                        :error="form.errors.code"
                        hint="Six digits. Each code works once."
                    />
                    <Button type="submit" class="wd-button wd-button-primary">{{
                        enrolled || secret ? 'Verify and continue' : 'Generate setup key'
                    }}</Button>
                </fieldset>
            </form>
        </ContentSection>
        <ContentSection title="Lost your authenticator?">
            <p>
                Recovery is performed offline by an authorized operator and cannot be started from this page. Recovery
                removes the current authenticator and signs out every administrative session for this account.
            </p>
            <Link href="/workspaces" class="wd-link inline-flex min-h-11 items-center"
                >Workspaces and account security</Link
            >
        </ContentSection>
    </AdminShell>
</template>
