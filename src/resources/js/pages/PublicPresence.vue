<script setup lang="ts">
import { confirmAction } from '../composables/useConfirmation';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PartnerChrome from '../features/portals/PartnerChrome.vue';
import FormField from '../components/FormField.vue';
import { Button } from '../components/ui/button';
const props = defineProps<{
    account: { name: string; email: string };
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
function requestSave(event: Event): void {
    if (props.kit?.published && !published.value) {
        confirmAction(
            'Unpublish media kit',
            `Unpublish ${props.kit.title}? Visitors will lose access to the public media kit.`,
            save,
            event,
            { tone: 'danger' },
        );
    } else save();
}
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
    <Head title="Media kit" />
    <PartnerChrome current="media-kit" :account-name="account.name" :account-email="account.email">
        <main id="main-content" class="mx-auto max-w-[1040px] px-6 pt-7 pb-12">
            <div class="wd-page-heading">
                <span class="wd-eyebrow">{{ workspace.name }}</span>
                <h1>Media kit</h1>
                <p class="wd-description">Choose exactly what to share publicly.</p>
            </div>
            <div v-if="errors?.version" role="alert" class="wd-banner">
                <p>{{ errors.version }}</p>
                <button
                    type="button"
                    class="wd-link"
                    @click="router.get('/partner/media-kit', {}, { preserveState: false })"
                >
                    Reload latest kit
                </button>
                <p class="wd-hint">Copy unsaved changes before reloading.</p>
            </div>
            <form class="wd-card" :aria-busy="busy" @submit.prevent="requestSave($event)">
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
                        ><textarea
                            id="kit-bio"
                            v-model="bio"
                            rows="5"
                            maxlength="5000"
                            :aria-invalid="errors?.bio ? true : undefined"
                            :aria-describedby="errors?.bio ? 'kit-bio-error' : undefined"
                        />
                        <p v-if="errors?.bio" id="kit-bio-error" class="wd-field-error" role="alert">
                            {{ errors.bio }}
                        </p>
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
                        <div v-for="(link, index) in socialLinks" :key="index" class="wd-social-row">
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
                            <button type="button" class="wd-action-danger" @click="socialLinks.splice(index, 1)">
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
                        ><input
                            v-model="consent"
                            type="checkbox"
                            :aria-invalid="errors?.consent ? true : undefined"
                            :aria-describedby="errors?.consent ? 'kit-consent-error' : undefined"
                        />I agree to publish this title, introduction, website and social links for anyone with the
                        public URL.</label
                    >
                    <p v-if="errors?.consent" id="kit-consent-error" class="wd-field-error" role="alert">
                        {{ errors.consent }}
                    </p>
                    <Button type="submit" class="wd-button wd-button-primary">Save media kit</Button>
                </fieldset>
                <p v-if="kit" class="wd-hint">{{ kit.views }} aggregate views. No individual viewer tracking.</p>
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
                <p class="wd-hint">A link shares only a contact form. It expires after 30 days and can be revoked.</p>
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
                        {{ link.revoked_at ? 'Revoked' : 'Expires ' + new Date(link.expires_at).toLocaleDateString() }}
                        <button
                            v-if="canWrite && !link.revoked_at"
                            type="button"
                            class="wd-action-danger"
                            @click="
                                confirmAction(
                                    'Revoke lead link',
                                    `Revoke lead link ${link.id}? Anyone using this link will lose access to its contact form.`,
                                    () => router.delete(`/lead-links/${link.id}`),
                                    $event,
                                    { tone: 'danger' },
                                )
                            "
                        >
                            Revoke lead link
                        </button>
                    </li>
                </ul>
            </section>
        </main>
    </PartnerChrome>
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
