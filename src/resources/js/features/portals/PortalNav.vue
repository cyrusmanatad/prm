<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { TooltipContent, TooltipPortal, TooltipRoot, TooltipTrigger } from 'reka-ui';
import {
    activePortalHref,
    experienceLabel,
    isUnavailable,
    portalGroups,
    UNAVAILABLE_TEXT,
    type PortalExperience,
} from './portalNavigation';

const props = withDefaults(
    defineProps<{
        experience: PortalExperience;
        owner?: boolean;
        /** Icon-only desktop presentation; labels stay available to assistive technology and as tooltips. */
        collapsed?: boolean;
        /** Distinguishes the sidebar and drawer copies, which may both exist in the document. */
        idPrefix: string;
        fixture?: boolean;
    }>(),
    { owner: false, collapsed: false, fixture: false },
);
defineEmits<{ navigate: [] }>();
const page = usePage();
const groups = computed(() => portalGroups(props.experience, props.owner));
const active = computed(() => activePortalHref(props.experience, page.url ?? ''));
</script>

<template>
    <nav
        class="wd-portal-nav"
        :class="{ 'is-collapsed': collapsed }"
        :aria-label="`${experienceLabel[experience]} tools`"
    >
        <div
            v-for="(group, index) in groups"
            :key="group.label ?? `group-${index}`"
            class="wd-portal-nav-group"
            :class="{ 'has-label': group.label }"
        >
            <p v-if="group.label" :id="`${idPrefix}-group-${index}`" class="wd-portal-nav-heading">
                <span :class="{ 'wd-sr-only': collapsed }">{{ group.label }}</span>
            </p>
            <ul v-bind="group.label ? { 'aria-labelledby': `${idPrefix}-group-${index}` } : {}">
                <li v-for="item in group.items" :key="item.label">
                    <TooltipRoot :disabled="!collapsed" :delay-duration="150">
                        <TooltipTrigger as-child>
                            <span
                                v-if="isUnavailable(item)"
                                class="wd-portal-nav-item is-unavailable"
                                aria-disabled="true"
                                v-bind="collapsed ? { tabindex: 0 } : {}"
                            >
                                <component :is="item.icon" :size="20" aria-hidden="true" class="wd-portal-nav-icon" />
                                <span class="wd-portal-nav-text" :class="{ 'wd-sr-only': collapsed }">
                                    <span class="wd-portal-nav-label">{{ item.label }}</span>
                                    <span class="wd-portal-nav-status"
                                        ><span class="wd-sr-only">, </span>{{ UNAVAILABLE_TEXT }}</span
                                    >
                                </span>
                            </span>
                            <component
                                :is="fixture ? 'a' : Link"
                                v-else
                                :href="item.href"
                                class="wd-portal-nav-item"
                                v-bind="{ 'aria-current': active === item.href ? 'page' : undefined }"
                                @click="$emit('navigate')"
                            >
                                <component :is="item.icon" :size="20" aria-hidden="true" class="wd-portal-nav-icon" />
                                <span class="wd-portal-nav-label" :class="{ 'wd-sr-only': collapsed }">{{
                                    item.label
                                }}</span>
                            </component>
                        </TooltipTrigger>
                        <TooltipPortal>
                            <TooltipContent side="right" :side-offset="8" class="wd-portal-tooltip">
                                {{ item.label
                                }}<span v-if="isUnavailable(item)" class="wd-portal-tooltip-status">
                                    · {{ UNAVAILABLE_TEXT }}</span
                                >
                            </TooltipContent>
                        </TooltipPortal>
                    </TooltipRoot>
                </li>
            </ul>
        </div>
    </nav>
</template>
