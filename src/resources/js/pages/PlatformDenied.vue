<script setup lang="ts">
import '../../css/records.css';
import { Head, Link, router } from '@inertiajs/vue3';
import FoundationShell from '../layouts/FoundationShell.vue';
import ActionGroup from '../components/ActionGroup.vue';
import { Button } from '../components/ui/button';

// Shown outside the console (the visitor has no administration access), in the same compact card as its pages.
defineProps<{ statusCode: number; message: string }>();
</script>

<template>
    <Head title="Administration unavailable" />
    <FoundationShell>
        <section class="rec-card adm-denied mx-auto max-w-xl" aria-labelledby="denied-title">
            <header class="rec-card-head">
                <h1 id="denied-title">Administration is not available to this account</h1>
            </header>
            <div class="adm-denied-body">
                <p>
                    Administration needs an active platform grant and a current authenticator verification. A workspace
                    role does not give access. If you expected access, ask a platform owner to review your grant.
                </p>
                <ActionGroup>
                    <Link href="/admin/security" class="wd-link inline-flex min-h-11 items-center"
                        >Verify administrator access</Link
                    >
                    <Link href="/workspaces" class="wd-link inline-flex min-h-11 items-center"
                        >Return to workspaces</Link
                    >
                    <Button type="button" class="wd-button wd-button-secondary" @click="router.post('/logout')"
                        >Sign out</Button
                    >
                </ActionGroup>
            </div>
        </section>
    </FoundationShell>
</template>

<style scoped>
.adm-denied .rec-card-head h1 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 20px;
    font-weight: 600;
    text-wrap: balance;
}
.adm-denied-body {
    display: grid;
    gap: 12px;
    padding: 0 14px 14px;
    font-size: 14px;
}
.adm-denied-body p {
    margin: 0;
    color: var(--wd-body);
}
</style>
