<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import FoundationShell from '../layouts/FoundationShell.vue';
import ActionGroup from '../components/ActionGroup.vue';
import { Button } from '../components/ui/button';

defineProps<{ statusCode: number; message: string }>();
</script>

<template>
    <Head title="Administration unavailable" />
    <FoundationShell>
        <section class="wd-card wd-content-section mx-auto max-w-xl" aria-labelledby="denied-title">
            <header>
                <span class="wd-eyebrow">Platform administration</span>
                <h1 id="denied-title" class="my-2 text-2xl font-semibold">
                    Administration is not available to this account
                </h1>
            </header>
            <p>
                Administration needs an active platform grant and a current authenticator verification. A workspace role
                does not give access. If you expected access, ask a platform owner to review your grant.
            </p>
            <ActionGroup>
                <Link href="/admin/security" class="wd-link inline-flex min-h-11 items-center"
                    >Verify administrator access</Link
                >
                <Link href="/workspaces" class="wd-link inline-flex min-h-11 items-center">Return to workspaces</Link>
                <Button type="button" class="wd-button wd-button-secondary" @click="router.post('/logout')"
                    >Sign out</Button
                >
            </ActionGroup>
        </section>
    </FoundationShell>
</template>
