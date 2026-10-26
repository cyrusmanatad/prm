<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import ContentSection from '../../components/ContentSection.vue';
import ActionGroup from '../../components/ActionGroup.vue';
import { Button } from '../../components/ui/button';
import type { Schedule } from './types';

defineProps<{ schedules: Schedule[]; canWrite: boolean; busy: boolean; error?: string }>();
defineEmits<{ edit: [schedule: Schedule]; withdraw: [schedule: Schedule, event: Event] }>();
function due(schedule: Schedule): string {
    return schedule.next_due_at
        ? new Intl.DateTimeFormat('en', {
              dateStyle: 'medium',
              timeStyle: 'short',
              timeZone: schedule.timezone,
          }).format(new Date(schedule.next_due_at))
        : 'No future occurrence';
}
</script>

<template>
    <ContentSection
        title="Your saved schedules"
        description="Your latest 100 schedules. Disabled schedules retain their history."
    >
        <div v-if="error" class="wd-banner" role="alert">
            <p>{{ error }}</p>
            <button type="button" class="wd-link" @click="router.get('/automation', {}, { preserveState: false })">
                Reload schedules
            </button>
        </div>
        <p v-if="!schedules.length" class="wd-empty">
            No schedules yet. Enable weekly digest in your preferences, then save a weekly schedule.
        </p>
        <article v-for="schedule in schedules" :key="schedule.id" class="wd-section-stack">
            <h3>{{ schedule.name }}</h3>
            <p>{{ schedule.enabled ? 'Consent active' : 'Disabled' }} · {{ schedule.kind.replaceAll('_', ' ') }}</p>
            <p class="wd-hint">{{ due(schedule) }} · {{ schedule.timezone }}</p>
            <ActionGroup v-if="canWrite">
                <Button
                    v-if="schedule.kind === 'weekly_digest'"
                    class="wd-button wd-button-secondary"
                    type="button"
                    :disabled="busy"
                    @click="$emit('edit', schedule)"
                    >Edit {{ schedule.name }}</Button
                >
                <Button
                    v-if="schedule.enabled"
                    class="wd-button wd-button-secondary"
                    type="button"
                    :disabled="busy || !!error"
                    @click="$emit('withdraw', schedule, $event)"
                    >Withdraw consent for {{ schedule.name }}</Button
                >
            </ActionGroup>
        </article>
    </ContentSection>
</template>
