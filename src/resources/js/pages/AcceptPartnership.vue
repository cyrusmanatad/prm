<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import FormField from '../components/FormField.vue';
const props = defineProps<{ account: { name: string; email: string } | null; savedToken?: string }>();
const form = useForm({ token: props.savedToken ?? '', consent: false, choose: false });
onMounted(() => {
    if (window.location.hash) form.token = window.location.hash.slice(1);
    history.replaceState(null, '', window.location.pathname);
});
</script>
<template>
    <Head title="Accept brand invitation" />
    <FoundationShell>
        <div class="wd-workspace-content">
            <div class="wd-page-heading">
                <h1>Accept brand invitation</h1>
                <p class="wd-description">
                    Sign in as the invited recipient, then select a creator workspace you own or administer.
                </p>
            </div>
            <section class="wd-card">
                <button
                    v-if="account"
                    type="button"
                    class="wd-link"
                    @click="
                        form.choose = true;
                        form.post('/partnerships/prepare');
                    "
                >
                    Select creator workspace
                </button>
                <form
                    class="wd-fields"
                    @submit.prevent="form.post(account ? '/partnerships/accept' : '/partnerships/prepare')"
                >
                    <FormField
                        id="invitation-token"
                        v-model="form.token"
                        label="Invitation token"
                        autocomplete="off"
                        :minlength="64"
                        :maxlength="64"
                        pattern="[a-f0-9]{64}"
                        :error="form.errors.token"
                    />
                    <p class="wd-hint">
                        Your invitation is retained securely for 15 minutes while you sign in and select a workspace.
                    </p>
                    <label v-if="account" class="wd-checkbox" for="partnership-consent"
                        ><input id="partnership-consent" v-model="form.consent" type="checkbox" required /> I accept
                        this partnership and agree to share my workspace display name and relationship status with the
                        inviting brand. Private CRM data stays private.</label
                    >
                    <p v-for="(error, key) in form.errors" :key="key" role="alert" class="wd-field-error">
                        {{ error }}
                    </p>
                    <button class="wd-button wd-button-primary" :disabled="form.processing">
                        {{ account ? 'Accept partnership' : 'Sign in to accept' }}
                    </button>
                </form>
            </section>
        </div>
    </FoundationShell>
</template>
