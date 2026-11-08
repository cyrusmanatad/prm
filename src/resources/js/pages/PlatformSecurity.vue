<script setup lang="ts">
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import FoundationShell from '../layouts/FoundationShell.vue';
import ContentSection from '../components/ContentSection.vue';
import FormField from '../components/FormField.vue';
import { Button } from '../components/ui/button';
defineProps<{
    account: { name: string; email: string };
    enrolled: boolean;
    secret?: string;
    errors?: Record<string, string>;
}>();
const form = useForm({ password: '', code: '' });
function submit(path: string) {
    form.post(path, { onFinish: () => form.reset('password', 'code') });
}
</script>
<template>
    <Head title="Administrator verification" />
    <FoundationShell :account-name="account.name" :account-email="account.email" @logout="router.post('/logout')"
        ><div class="wd-workspace-content wd-finance wd-finance-sections">
            <header class="wd-page-heading">
                <span class="wd-eyebrow">Platform administration</span>
                <h1>Verify your identity</h1>
                <p class="wd-description">
                    Use your password and authenticator. Administrative access expires after 15 minutes.
                </p>
            </header>
            <ContentSection :title="enrolled ? 'Authenticator verification' : 'Set up an authenticator'">
                <p v-if="!enrolled">
                    Enter your password to generate a setup key. Add the key to your authenticator as a time-based,
                    six-digit account.
                </p>
                <p v-if="secret">
                    Setup key: <code>{{ secret }}</code>
                </p>
                <form
                    @submit.prevent="submit(enrolled || secret ? '/admin/security/verify' : '/admin/security/enroll')"
                >
                    <fieldset class="wd-fields" :disabled="form.processing">
                        <legend class="wd-sr-only">Administrator verification</legend>
                        <FormField
                            id="admin-password"
                            v-model="form.password"
                            type="password"
                            autocomplete="current-password"
                            label="Password"
                            :error="errors?.password"
                        />
                        <FormField
                            v-if="enrolled || secret"
                            id="admin-code"
                            v-model="form.code"
                            label="Authenticator code"
                            autocomplete="one-time-code"
                            :maxlength="6"
                            :error="errors?.code"
                        />
                        <Button type="submit" class="wd-button wd-button-primary">{{
                            enrolled || secret ? 'Verify and continue' : 'Generate setup key'
                        }}</Button>
                    </fieldset>
                </form>
                <p class="wd-hint">
                    Lost your authenticator? An authorized offline operator must recover your grant. Recovery
                    invalidates previous administrative sessions.
                </p>
            </ContentSection>
            <Link href="/workspaces" class="wd-link inline-flex min-h-11 items-center"
                >Workspaces and account security</Link
            >
        </div></FoundationShell
    >
</template>
