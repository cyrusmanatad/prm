<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { onMounted } from 'vue';
const props = defineProps<{account: {name:string;email:string} | null; savedToken?:string}>();
import FoundationShell from '../layouts/FoundationShell.vue';
const form = useForm({token:props.savedToken ?? '',consent:false,choose:false});
onMounted(()=>{if (window.location.hash) form.token=window.location.hash.slice(1);history.replaceState(null,'',window.location.pathname);});
</script>
<template><Head title="Accept brand invitation" /><FoundationShell><section class="wd-card"><h1>Accept brand invitation</h1><p>Sign in as the invited recipient, then select a creator workspace you own or administer.</p><button v-if="account" type="button" class="wd-link" @click="form.choose=true; form.post('/partnerships/prepare')">Select creator workspace</button><form @submit.prevent="form.post(account ? '/partnerships/accept' : '/partnerships/prepare')"><label class="wd-field">Invitation token<input v-model="form.token" required pattern="[a-f0-9]{64}" autocomplete="off"></label><p>Your invitation is retained securely for 15 minutes while you sign in and select a workspace.</p><label v-if="account"><input v-model="form.consent" type="checkbox" required> I accept this partnership and agree to share my workspace display name and relationship status with the inviting brand. Private CRM data stays private.</label><p v-for="(error, key) in form.errors" :key="key" role="alert">{{ error }}</p><button class="wd-button wd-button-primary" :disabled="form.processing">{{ account ? 'Accept partnership' : 'Sign in to accept' }}</button></form></section></FoundationShell></template>
