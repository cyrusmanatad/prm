<script setup lang="ts">
import { computed } from 'vue';
import { useWorkspaceExperience } from '../../composables/useWorkspaceExperience';
import FormField from '../../components/FormField.vue';
import SelectField from '../../components/SelectField.vue';
import { currencyOptions, snoozeChoices, snoozeValue } from './leadFields';
import type { LeadForm } from './types';

const { clientLabel } = useWorkspaceExperience();
const form = defineModel<LeadForm>({ required: true });
const props = defineProps<{ prefix: string; errors: Record<string, string> }>();
const currencies = computed(() => currencyOptions(form.value.currency));
const valueDescribedBy = computed(() =>
    [`${props.prefix}-value-hint`, props.errors.value_minor ? `${props.prefix}-amount-error` : '']
        .filter(Boolean)
        .join(' '),
);
</script>

<template>
    <div class="entry">
        <section class="entry-section" :aria-label="`${clientLabel} and value`">
            <FormField
                :id="`${prefix}-brand`"
                v-model="form.brand_name"
                :label="clientLabel"
                :maxlength="160"
                :error="errors.brand_name"
            />
            <div class="wd-field">
                <div class="entry-label">
                    <label :for="`${prefix}-amount`">Estimated value</label>
                    <span class="entry-optional" aria-hidden="true">Optional</span>
                </div>
                <div class="entry-value">
                    <SelectField
                        :id="`${prefix}-currency`"
                        v-model="form.currency"
                        class="entry-currency"
                        label="Currency"
                        :options="currencies"
                        empty-text="No matches"
                        search-placeholder="Search code or name"
                        search-label="Search currencies"
                        show-mark
                        show-search-icon
                        :error="errors.currency"
                    />
                    <input
                        :id="`${prefix}-amount`"
                        v-model="form.amount"
                        class="entry-amount"
                        inputmode="decimal"
                        maxlength="24"
                        placeholder="0.00"
                        :aria-invalid="errors.value_minor ? true : undefined"
                        :aria-describedby="valueDescribedBy"
                    />
                </div>
                <p :id="`${prefix}-value-hint`" class="wd-hint">Totals are kept separate for each currency.</p>
                <p v-if="errors.value_minor" :id="`${prefix}-amount-error`" class="wd-field-error" role="alert">
                    {{ errors.value_minor }}
                </p>
            </div>
        </section>
        <section class="entry-section" :aria-labelledby="`${prefix}-contact-title`">
            <h3 :id="`${prefix}-contact-title`" class="entry-kicker">
                Primary contact <span class="entry-kicker-note">· optional</span>
            </h3>
            <div class="entry-grid">
                <FormField
                    :id="`${prefix}-contact`"
                    v-model="form.contact_name"
                    :required="false"
                    label="Contact name"
                    :maxlength="120"
                    :error="errors.contact_name"
                />
                <FormField
                    :id="`${prefix}-email`"
                    v-model="form.contact_email"
                    :required="false"
                    label="Contact email"
                    type="email"
                    :maxlength="255"
                    :error="errors.contact_email"
                />
            </div>
        </section>
        <section class="entry-section" :aria-labelledby="`${prefix}-follow-title`">
            <h3 :id="`${prefix}-follow-title`" class="entry-kicker">
                Follow-up <span class="entry-kicker-note">· optional</span>
            </h3>
            <div class="entry-grid entry-follow">
                <FormField
                    :id="`${prefix}-due`"
                    v-model="form.next_action_due"
                    :required="false"
                    label="Next action"
                    type="datetime-local"
                    hint="Your local time."
                    :error="errors.next_action_due"
                >
                    <div class="entry-snooze" role="group" :aria-label="'Set next action'">
                        <button
                            v-for="choice in snoozeChoices"
                            :key="choice.days"
                            type="button"
                            class="entry-chip"
                            @click="form.next_action_due = snoozeValue(choice.days)"
                        >
                            {{ choice.label }}
                        </button>
                    </div>
                </FormField>
                <div class="wd-field">
                    <label :for="`${prefix}-notes`">Private notes</label>
                    <textarea
                        :id="`${prefix}-notes`"
                        v-model="form.notes"
                        rows="3"
                        maxlength="5000"
                        placeholder="Only you can see these"
                        :aria-invalid="errors.notes ? true : undefined"
                        :aria-describedby="errors.notes ? `${prefix}-notes-error` : undefined"
                    />
                    <p v-if="errors.notes" :id="`${prefix}-notes-error`" class="wd-field-error" role="alert">
                        {{ errors.notes }}
                    </p>
                </div>
            </div>
        </section>
    </div>
</template>

<style scoped>
.entry {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.entry-section {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.entry-section + .entry-section {
    padding-top: 14px;
    border-top: 1px solid var(--wd-divider);
}
.entry-kicker {
    margin: 0 0 -4px;
    color: var(--wd-muted);
    font-family: var(--wd-font-body);
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.entry-kicker-note {
    font-weight: 500;
    letter-spacing: 0;
    text-transform: none;
}
.entry-label {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
}
.entry-optional {
    font-size: 12.5px;
}
.entry-optional {
    color: var(--wd-muted);
    font-weight: 500;
}
.entry-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: start;
    gap: 12px;
}
.entry-value {
    display: flex;
    align-items: flex-start;
    gap: 8px;
}
.entry-currency {
    position: relative;
    flex: none;
    width: 104px;
}
/* The amount's label names the pair; the currency keeps its own accessible label. */
.entry-currency :deep(> label) {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: 0;
    padding: 0;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
.entry-currency :deep(.wd-select-panel) {
    right: auto;
    width: min(320px, calc(100vw - 48px));
}
.entry-amount {
    flex: 1;
    min-width: 0;
    font-variant-numeric: tabular-nums;
    text-align: right;
}
.entry textarea {
    height: 92px;
    resize: vertical;
}
.entry-snooze {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 2px;
}
.entry-chip {
    min-height: 30px;
    padding: 0 10px;
    border: 1px solid var(--wd-control-line);
    border-radius: 999px;
    background: var(--wd-surface);
    color: var(--wd-ink);
    font: inherit;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
}
.entry-chip:hover {
    background: var(--wd-paper);
    color: var(--wd-brand);
}
.entry-chip:focus-visible {
    outline: 3px solid var(--wd-focus);
    outline-offset: 2px;
}
@media (max-width: 599px) {
    .entry-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
@media (max-width: 599px), (pointer: coarse) {
    .entry-chip {
        min-height: 44px;
        padding: 0 14px;
    }
}
</style>
