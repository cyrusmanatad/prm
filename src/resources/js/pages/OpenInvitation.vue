<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import FormField from '../components/FormField.vue';
import { Button } from '../components/ui/button';
defineProps<{ errors?: Record<string, string> }>();
const token = ref('');
const busy = ref(false);
onMounted(() => {
  token.value = new URLSearchParams(location.hash.slice(1)).get('token') ?? '';
  history.replaceState(history.state, '', location.pathname);
});
</script>
<template>
  <Head title="Open invitation" /><FoundationShell
    ><div class="wd-workspace-content">
      <div class="wd-page-heading">
        <h1>Open your onboarding invitation</h1>
        <p class="wd-description">
          This invitation opens a private form. Keep the link private; it can be opened once.
        </p>
      </div>
      <form
        class="wd-card wd-fields"
        :aria-busy="busy"
        @submit.prevent="
          router.post(
            '/onboarding/session',
            { token },
            {
              onStart: () => {
                busy = true;
              },
              onFinish: () => {
                busy = false;
              },
            },
          )
        "
      >
        <FormField
          id="invite-token"
          v-model="token"
          label="Invitation code"
          type="password"
          :maxlength="64"
          :error="errors?.token"
        /><Button type="submit" class="wd-button wd-button-primary" :disabled="busy"
          >Open invitation</Button
        >
      </form>
    </div></FoundationShell
  >
</template>
