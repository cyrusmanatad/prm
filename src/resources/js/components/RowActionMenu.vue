<script setup lang="ts">
import { ref, type Component } from 'vue';
import { MoreHorizontal } from '@lucide/vue';
import {
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuPortal,
    DropdownMenuRoot,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from 'reka-ui';

export type RowAction = {
    label: string;
    icon?: Component;
    /** Secondary text on the right, such as the time a snooze option sets. */
    detail?: string;
    /** Opens an address instead of running `select`, for example a download or a mailto link. */
    href?: string;
    download?: boolean;
    /** Receives the menu trigger, so a confirmation dialog can return focus to it. */
    select?: (trigger: HTMLElement) => void;
    danger?: boolean;
    disabled?: boolean;
    /** Starts a new group with a separator. */
    separated?: boolean;
};

withDefaults(
    defineProps<{
        /** Accessible name of the trigger, naming the record, such as "Actions for Sam Contact". */
        label: string;
        actions: RowAction[];
        /** Visible trigger text; without it the trigger shows only an icon. */
        text?: string;
        icon?: Component;
        heading?: string;
        disabled?: boolean;
        align?: 'start' | 'end';
        /** Extra classes for the trigger button, such as a borderless variant inside table rows. */
        triggerClass?: string;
    }>(),
    { align: 'end' },
);

const trigger = ref<HTMLButtonElement>();
/** The menu closes and returns focus first; the action then runs, so a dialog it opens keeps focus. */
function run(action: RowAction): void {
    const source = trigger.value;
    if (action.select && source) setTimeout(() => action.select!(source), 0);
}
</script>

<template>
    <DropdownMenuRoot :modal="false">
        <DropdownMenuTrigger as-child>
            <button
                ref="trigger"
                type="button"
                class="wd-row-menu-trigger"
                :class="[text ? 'wd-table-button' : 'wd-table-icon-button', triggerClass]"
                :aria-label="label"
                :disabled="disabled"
            >
                <component :is="icon ?? MoreHorizontal" :size="16" aria-hidden="true" />
                <span v-if="text" aria-hidden="true">{{ text }}</span>
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuPortal>
            <DropdownMenuContent :align="align" :side-offset="4" class="wd-account-menu wd-row-menu">
                <DropdownMenuLabel v-if="heading" class="wd-row-menu-heading">{{ heading }}</DropdownMenuLabel>
                <template v-for="action in actions" :key="action.label">
                    <DropdownMenuSeparator v-if="action.separated" class="wd-row-menu-separator" />
                    <DropdownMenuItem
                        v-if="action.href"
                        as-child
                        :disabled="action.disabled"
                        :class="{ 'is-danger': action.danger }"
                    >
                        <a :href="action.href" :download="action.download ? '' : undefined"
                            ><component :is="action.icon" v-if="action.icon" :size="16" aria-hidden="true" /><span>{{
                                action.label
                            }}</span
                            ><span v-if="action.detail" class="wd-row-menu-detail">{{ action.detail }}</span></a
                        >
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-else
                        :disabled="action.disabled"
                        :class="{ 'is-danger': action.danger }"
                        @select="run(action)"
                    >
                        <component :is="action.icon" v-if="action.icon" :size="16" aria-hidden="true" /><span>{{
                            action.label
                        }}</span
                        ><span v-if="action.detail" class="wd-row-menu-detail">{{ action.detail }}</span>
                    </DropdownMenuItem>
                </template>
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
