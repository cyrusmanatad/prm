<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import FormField from '../components/FormField.vue';
import { Button } from '../components/ui/button';
defineProps<{ errors?: Record<string, string>; status?: string }>();
const token = ref('');
const name = ref('');
const brand = ref('');
const email = ref('');
const consent = ref(false);
const busy = ref(false);
const done = ref(false);
const key = ref('');
onMounted(() => {
    token.value = new URLSearchParams(location.hash.slice(1)).get('token') ?? '';
    history.replaceState(history.state, '', location.pathname);
    key.value = crypto.randomUUID();
});
function submit(): void {
    router.post(
        '/lead',
        {
            token: token.value,
            brand_name: brand.value,
            contact_name: name.value,
            email: email.value,
            consent: consent.value,
            idempotency_key: key.value,
        },
        {
            onStart: () => {
                busy.value = true;
            },
            onFinish: () => {
                busy.value = false;
            },
            onSuccess: () => {
                done.value = true;
                token.value = '';
            },
        },
    );
}
</script>
<template>
    <Head title="Start a conversation" /><FoundationShell
        ><div class="wd-workspace-content">
            <div class="wd-page-heading">
                <h1>Start a conversation</h1>
                <p class="wd-description">
                    Share your business contact details with the creator who provided this link.
                </p>
            </div>
            <p v-if="status" role="status" class="wd-status">{{ status }}</p>
            <ul v-if="errors && Object.keys(errors).length" role="alert" class="wd-field-error">
                <li v-for="(message, k) in errors" :key="k">{{ message }}</li>
            </ul>
            <form v-if="!done" class="wd-card wd-fields" :aria-busy="busy" @submit.prevent="submit">
                <FormField
                    id="lead-code"
                    v-model="token"
                    type="password"
                    label="Lead link code"
                    :maxlength="64"
                    :error="errors?.token"
                /><FormField
                    id="lead-brand"
                    v-model="brand"
                    label="Business name"
                    :maxlength="160"
                    :error="errors?.brand_name"
                /><FormField id="lead-contact" v-model="name" label="Contact name" :maxlength="120" /><FormField
                    id="lead-email"
                    v-model="email"
                    label="Business email"
                    type="email"
                    :maxlength="255"
                    :error="errors?.email"
                /><label class="wd-checkbox"
                    ><input v-model="consent" type="checkbox" required />I agree to share these details with this
                    creator for this conversation.</label
                ><Button type="submit" class="wd-button wd-button-primary" :disabled="busy"
                    >Share contact details</Button
                >
            </form>
        </div></FoundationShell
    >
</template>
