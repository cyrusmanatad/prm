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
    // Guests go straight to account creation, or sign-in when the invited email already has an account.
    if (!props.account && form.token) form.post('/partnerships/prepare');
});
</script>
<template>
    <Head title="Accept brand invitation" />
    <FoundationShell>
        <div class="wd-workspace-content">
            <div class="wd-page-heading">
                <h1>Accept brand invitation</h1>
                <p class="wd-description">
                    {{
                        account
                            ? 'Select a creator workspace you own or administer, then confirm the partnership.'
                            : 'Create your account with the invited email, or sign in if it already has one. Then confirm the partnership.'
                    }}
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
                    :aria-busy="form.processing"
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
                        Your invitation is retained securely for 15 minutes while you create your account or sign in.
                    </p>
                    <label v-if="account" class="wd-checkbox" for="partnership-consent"
                        ><input id="partnership-consent" v-model="form.consent" type="checkbox" required /> I accept
                        this partnership and agree to share my workspace display name and relationship status with the
                        inviting brand. Private CRM data stays private.</label
                    >
                    <p
                        v-for="(error, key) in form.errors"
                        v-show="key !== 'token'"
                        :key="key"
                        role="alert"
                        class="wd-field-error"
                    >
                        {{ error }}
                    </p>
                    <button class="wd-button wd-button-primary" :disabled="form.processing">
                        {{ form.processing ? 'Please wait…' : account ? 'Accept partnership' : 'Continue' }}
                    </button>
                </form>
            </section>
        </div>
    </FoundationShell>
</template>
