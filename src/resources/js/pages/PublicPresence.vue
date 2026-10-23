<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import FormField from '../components/FormField.vue';
import { Button } from '../components/ui/button';
const props = defineProps<{
  account: { name: string };
  workspace: { id: string; name: string };
  canWrite: boolean;
  kit: {
    id: string;
    title: string;
    bio: string;
    website: string;
    published: boolean;
    version: number;
    views: number;
    social_links?: { label: string; url: string }[];
  } | null;
  links: { id: string; expires_at: string; revoked_at: string | null }[];
  issuedLink: string | null;
  errors?: Record<string, string>;
  status?: string;
}>();
const title = ref(props.kit?.title ?? '');
const bio = ref(props.kit?.bio ?? '');
const website = ref(props.kit?.website ?? '');
const socialLinks = ref(props.kit?.social_links?.map((link) => ({ label: link.label, url: link.url })) ?? []);
const published = ref(props.kit?.published ?? false);
const consent = ref(false);
const busy = ref(false);
const version = ref(props.kit?.version ?? 0);
function save(): void {
  if (busy.value || props.errors?.version) return;
  const submittedVersion = version.value;
  router.put(
    '/media-kit',
    {
      title: title.value,
      bio: bio.value,
      website: website.value,
      social_links: socialLinks.value,
      published: published.value,
      consent: consent.value,
      version: submittedVersion,
    },
    {
      preserveState: true,
      onStart: () => {
        busy.value = true;
      },
      onFinish: () => {
        busy.value = false;
      },
      onSuccess: () => {
        version.value = submittedVersion + 1;
      },
    },
  );
}
</script>
<template>
  <Head title="Public presence" /><FoundationShell
    :account-name="account.name"
    @logout="router.post('/logout')"
    ><div class="wd-workspace-content wd-presence">
      <nav aria-label="Workspace">
        <Link class="wd-link" href="/partner/leads">Back to leads</Link>
      </nav>
      <div class="wd-page-heading">
        <span class="wd-eyebrow">{{ workspace.name }}</span>
        <h1>Public presence</h1>
        <p class="wd-description">Choose exactly what to share publicly.</p>
      </div>
      <p v-if="status" role="status" class="wd-status">{{ status }}</p>
      <div v-if="errors && Object.keys(errors).length" role="alert" class="wd-status">
        <p v-for="(msg, k) in errors" :key="k">{{ msg }}</p>
        <button
          v-if="errors.version"
          type="button"
          class="wd-link"
          @click="router.get('/partner/media-kit', {}, { preserveState: false })"
        >
          Reload latest kit
        </button>
      </div>
      <form class="wd-card" :aria-busy="busy" @submit.prevent="save">
        <fieldset class="wd-fields" :disabled="!canWrite || busy || !!errors?.version">
          <legend>Media kit</legend>
          <FormField
            id="kit-title"
            v-model="title"
            label="Public title"
            :maxlength="160"
            :error="errors?.title"
          />
          <div class="wd-field">
            <label for="kit-bio">Public introduction</label
            ><textarea id="kit-bio" v-model="bio" rows="5" maxlength="5000" />
          </div>
          <FormField
            id="kit-website"
            v-model="website"
            label="Public website (optional, https:// or http://)"
            :required="false"
            :maxlength="500"
            :error="errors?.website"
          />
          <section aria-labelledby="social-links-title" class="wd-fields">
            <h2 id="social-links-title">Social accounts</h2>
            <p class="wd-hint">
              Add up to eight public account links. Accounts are not connected or verified.
            </p>
            <div v-for="(link, index) in socialLinks" :key="index" class="wd-fields">
              <FormField
                :id="`social-label-${index}`"
                v-model="link.label"
                :label="`Account ${index + 1} name`"
                :maxlength="60"
                :error="errors?.[`social_links.${index}.label`]"
              />
              <FormField
                :id="`social-url-${index}`"
                v-model="link.url"
                :label="`Account ${index + 1} URL`"
                :maxlength="500"
                :error="errors?.[`social_links.${index}.url`]"
              />
              <button type="button" class="wd-link" @click="socialLinks.splice(index, 1)">
                Remove social account {{ index + 1 }}
              </button>
            </div>
            <Button
              type="button"
              class="wd-button wd-button-secondary"
              :disabled="socialLinks.length >= 8"
              @click="socialLinks.push({ label: '', url: '' })"
              >Add social account</Button
            >
          </section>
          <label class="wd-checkbox"
            ><input v-model="published" type="checkbox" />Publish this media kit</label
          ><label v-if="published" class="wd-checkbox"
            ><input v-model="consent" type="checkbox" />I agree to publish this title, introduction, website and social links for anyone with the public URL.</label
          ><Button type="submit" class="wd-button wd-button-primary">Save media kit</Button>
        </fieldset>
        <p v-if="kit" class="wd-hint">
          {{ kit.views }} aggregate views. No individual viewer tracking.
        </p>
        <a
          v-if="kit?.published"
          class="wd-link"
          :href="`/kits/${kit.id}`"
          target="_blank"
          rel="noopener noreferrer"
          >Open public media kit</a
        >
      </form>
      <section class="wd-card wd-fields" aria-labelledby="lead-links-title">
        <h2 id="lead-links-title">Inbound lead links</h2>
        <p class="wd-hint">
          A link shares only a contact form. It expires after 30 days and can be revoked.
        </p>
        <Button
          v-if="canWrite"
          type="button"
          class="wd-button wd-button-secondary"
          @click="router.post('/lead-links')"
          >Create lead link</Button
        >
        <div v-if="issuedLink" class="wd-field">
          <label for="issued-lead-link">Lead link (copy now)</label
          ><input id="issued-lead-link" :value="issuedLink" readonly />
        </div>
        <ul>
          <li v-for="link in links" :key="link.id">
            {{
              link.revoked_at
                ? 'Revoked'
                : 'Expires ' + new Date(link.expires_at).toLocaleDateString()
            }}
            <button
              v-if="canWrite && !link.revoked_at"
              type="button"
              class="wd-link"
              @click="router.delete(`/lead-links/${link.id}`)"
            >
              Revoke lead link
            </button>
          </li>
        </ul>
      </section>
    </div></FoundationShell
  >
</template>
<style scoped>
.wd-card {
  margin-bottom: 1rem;
}
input {
  max-width: 100%;
}
li {
  overflow-wrap: anywhere;
}
</style>
