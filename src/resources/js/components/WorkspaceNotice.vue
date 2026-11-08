<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { inject, ref, type Ref } from 'vue';
const subscription = inject<Ref<{ state: string } | undefined>>('professionalSubscription', ref(undefined));
defineProps<{ compact?: boolean }>();
</script>
<template>
    <aside v-if="subscription && !compact" class="wd-banner" aria-label="Professional subscription">
        <Link class="wd-link" href="/professional">Professional Hub</Link>
        <p>Subscription: {{ subscription.state }}.</p>
        <p v-if="subscription.state !== 'active'">
            Work is paused. You can read saved work, export authorized records and manage account security. Contact your
            administrator for activation.
        </p>
    </aside>
    <aside
        v-else-if="subscription"
        class="pro-subscription"
        :class="{ 'pro-subscription-paused': subscription.state !== 'active' }"
        aria-label="Professional subscription"
    >
        <span class="pro-status">{{
            subscription.state === 'active'
                ? 'Active subscription'
                : subscription.state === 'pending activation'
                  ? 'Pending activation'
                  : subscription.state === 'scheduled'
                    ? 'Scheduled subscription'
                    : 'Expired subscription'
        }}</span>
        <Link v-if="subscription.state !== 'active'" class="wd-link" href="/professional"
            >View subscription details</Link
        >
        <p v-if="subscription.state !== 'active'">
            {{
                subscription.state === 'scheduled'
                    ? 'Work begins at the scheduled start time.'
                    : subscription.state === 'expired'
                      ? 'The subscription has ended. Contact your administrator to renew access.'
                      : 'An administrator must activate this workspace before work can begin.'
            }}
            You can read saved work, export authorized records and manage account security.
        </p>
    </aside>
</template>
