<script setup lang="ts">
import { computed, ref, useSlots } from 'vue';
import { CalendarDays, Search, SlidersHorizontal } from '@lucide/vue';
import DateRangeFields from './DateRangeFields.vue';

/**
 * Search, inline dropdowns and an optional calendar-day range for a compact administration list.
 * The search and dates apply on submit (Enter, or Apply dates); dropdowns in the slot apply as they are chosen.
 * On phones the dropdowns and the date button sit behind a Filters button.
 */
const props = defineProps<{
    id: string;
    label: string;
    searchLabel: string;
    placeholder: string;
    pending?: boolean;
    /** Filters set in the toolbar (dropdowns and dates), shown on the phone Filters button. */
    active?: number;
    /** Offer a date range: the button noun ("Joined"), the input id prefix and the field labels. */
    dates?: { noun: string; prefix: string; fromLabel: string; toLabel: string };
    /** The applied range, which names the date button. */
    appliedFrom?: string;
    appliedTo?: string;
}>();
const q = defineModel<string>('q', { default: '' });
const from = defineModel<string>('from', { default: '' });
const to = defineModel<string>('to', { default: '' });
const emit = defineEmits<{ submit: [] }>();
const slots = useSlots();
const hasFilters = computed(() => !!slots.default || !!props.dates);
const filtersOpen = ref(false);
const datesOpen = ref(!!(props.appliedFrom || props.appliedTo));
const datesLabel = computed(() => {
    const noun = props.dates?.noun ?? '';
    const { appliedFrom: start, appliedTo: end } = props;
    if (start && end) return `${noun} ${start} to ${end}`;
    if (start) return `${noun} from ${start}`;
    if (end) return `${noun} to ${end}`;
    return `${noun}: any time`;
});
</script>

<template>
    <form class="adm-toolbar" role="search" :aria-label="label" @submit.prevent="emit('submit')">
        <div class="adm-search">
            <label :for="`${id}-search`" class="wd-sr-only">{{ searchLabel }}</label>
            <Search :size="16" aria-hidden="true" />
            <input
                :id="`${id}-search`"
                v-model="q"
                name="q"
                type="search"
                maxlength="120"
                :placeholder="placeholder"
                autocomplete="off"
            />
        </div>
        <template v-if="hasFilters">
            <button
                type="button"
                class="wd-table-button adm-filters-toggle"
                :aria-controls="`${id}-filters`"
                :aria-expanded="filtersOpen"
                @click="filtersOpen = !filtersOpen"
            >
                <SlidersHorizontal :size="15" aria-hidden="true" />Filters<span v-if="active" class="rec-count">{{
                    active
                }}</span>
            </button>
            <div :id="`${id}-filters`" class="adm-filter-set" :class="{ 'is-open': filtersOpen }">
                <slot />
                <button
                    v-if="dates"
                    type="button"
                    class="wd-table-button"
                    :aria-controls="`${id}-dates`"
                    :aria-expanded="datesOpen"
                    @click="datesOpen = !datesOpen"
                >
                    <CalendarDays :size="15" aria-hidden="true" />{{ datesLabel }}
                </button>
            </div>
        </template>
        <div v-if="dates" v-show="datesOpen" :id="`${id}-dates`" class="adm-dates">
            <DateRangeFields
                v-model:from="from"
                v-model:to="to"
                :prefix="dates.prefix"
                :from-label="dates.fromLabel"
                :to-label="dates.toLabel"
            />
            <button type="submit" class="wd-table-button rec-primary" :disabled="pending">Apply dates</button>
        </div>
    </form>
</template>
