<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import { Bold, Heading3, Italic, Link as LinkIcon, List, ListOrdered, Redo2, Undo2, Unlink } from '@lucide/vue';

/**
 * Formatted text limited to paragraphs, bold, italic, one subheading level, lists and links.
 * The server sanitizes the same set again; this editor only keeps authors inside it.
 */
const props = withDefaults(
    defineProps<{
        id: string;
        label: string;
        hint?: string;
        error?: string;
        /** Visible characters allowed; markup is not counted. */
        maxLength?: number;
        disabled?: boolean;
    }>(),
    { maxLength: 5000, disabled: false },
);
const html = defineModel<string>({ default: '' });

const labelId = computed(() => `${props.id}-label`);
const countId = computed(() => `${props.id}-count`);
const describedby = computed(() =>
    [countId.value, props.hint ? `${props.id}-hint` : '', props.error ? `${props.id}-error` : '']
        .filter(Boolean)
        .join(' '),
);
const attributes = (): Record<string, string> => ({
    id: props.id,
    class: 'wd-rich-text-content',
    role: 'textbox',
    'aria-multiline': 'true',
    'aria-labelledby': labelId.value,
    'aria-describedby': describedby.value,
    ...(props.error || over.value ? { 'aria-invalid': 'true' } : {}),
    ...(props.disabled ? { 'aria-disabled': 'true' } : {}),
});
const characters = ref(0);
const over = computed(() => characters.value > props.maxLength);

const editor = useEditor({
    content: html.value,
    editable: !props.disabled,
    extensions: [
        StarterKit.configure({
            heading: { levels: [3] },
            blockquote: false,
            code: false,
            codeBlock: false,
            horizontalRule: false,
            strike: false,
            underline: false,
            link: {
                openOnClick: false,
                autolink: true,
                defaultProtocol: 'https',
                protocols: ['http', 'https', 'mailto'],
                HTMLAttributes: { rel: 'noopener noreferrer nofollow', target: null },
            },
        }),
    ],
    editorProps: { attributes: attributes() },
    onCreate: ({ editor }) => (characters.value = editor.getText().length),
    onUpdate: ({ editor }) => {
        characters.value = editor.getText().length;
        // An editor holding only empty paragraphs is empty.
        html.value = editor.isEmpty ? '' : editor.getHTML();
    },
});
watch(html, (value) => {
    const current = editor.value;
    if (!current || value === (current.isEmpty ? '' : current.getHTML())) return;
    current.commands.setContent(value || '', { emitUpdate: false });
    characters.value = current.getText().length;
});
watch(
    () => props.disabled,
    (disabled) => editor.value?.setEditable(!disabled),
);
watch([describedby, () => props.error, over, () => props.disabled], () =>
    editor.value?.setOptions({ editorProps: { attributes: attributes() } }),
);
onBeforeUnmount(() => editor.value?.destroy());

type Tool = { name: string; icon: typeof Bold; shortcut: string; active: () => boolean; run: () => void };
const tools = computed<Tool[]>(() => {
    const current = editor.value;
    if (!current) return [];
    const chain = () => current.chain().focus();
    return [
        {
            name: 'Bold',
            icon: Bold,
            shortcut: 'Ctrl+B',
            active: () => current.isActive('bold'),
            run: () => chain().toggleBold().run(),
        },
        {
            name: 'Italic',
            icon: Italic,
            shortcut: 'Ctrl+I',
            active: () => current.isActive('italic'),
            run: () => chain().toggleItalic().run(),
        },
        {
            name: 'Subheading',
            icon: Heading3,
            shortcut: 'Ctrl+Alt+3',
            active: () => current.isActive('heading', { level: 3 }),
            run: () => chain().toggleHeading({ level: 3 }).run(),
        },
        {
            name: 'Bulleted list',
            icon: List,
            shortcut: 'Ctrl+Shift+8',
            active: () => current.isActive('bulletList'),
            run: () => chain().toggleBulletList().run(),
        },
        {
            name: 'Numbered list',
            icon: ListOrdered,
            shortcut: 'Ctrl+Shift+7',
            active: () => current.isActive('orderedList'),
            run: () => chain().toggleOrderedList().run(),
        },
    ];
});

// Links are added through an inline field instead of a browser prompt.
const linkOpen = ref(false);
const linkUrl = ref('');
const linkInput = ref<HTMLInputElement>();
const linkError = ref('');
async function openLink(): Promise<void> {
    const current = editor.value;
    if (!current) return;
    linkUrl.value = current.getAttributes('link').href ?? '';
    linkError.value = '';
    linkOpen.value = true;
    await nextTick();
    linkInput.value?.focus();
}
function applyLink(): void {
    const current = editor.value;
    if (!current) return;
    const url = linkUrl.value.trim();
    if (!url) {
        current.chain().focus().extendMarkRange('link').unsetLink().run();
        linkOpen.value = false;
        return;
    }
    const href = /^(https?:|mailto:)/i.test(url) ? url : `https://${url}`;
    try {
        const parsed = new URL(href);
        if (!['http:', 'https:', 'mailto:'].includes(parsed.protocol)) throw new Error('scheme');
    } catch {
        linkError.value = 'Enter a web address or an email link (mailto:).';
        return;
    }
    const chain = current.chain().focus().extendMarkRange('link');
    if (current.state.selection.empty && !current.isActive('link'))
        chain.insertContent(href).setTextSelection({
            from: current.state.selection.from,
            to: current.state.selection.from + href.length,
        });
    chain.setLink({ href }).run();
    linkOpen.value = false;
}
function cancelLink(): void {
    linkOpen.value = false;
    editor.value?.commands.focus();
}
function onKeydown(event: KeyboardEvent): void {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        void openLink();
    }
}
</script>

<template>
    <div class="wd-field wd-rich-text" :class="{ 'is-invalid': error || over, 'is-disabled': disabled }">
        <div class="wd-rich-text-label">
            <label :id="labelId" @click="editor?.commands.focus()">{{ label }}</label>
            <span :id="countId" class="wd-rich-text-count" :class="{ 'is-over': over }"
                >{{ characters.toLocaleString() }} / {{ maxLength.toLocaleString()
                }}<span class="wd-sr-only"> characters</span></span
            >
        </div>
        <div class="wd-rich-text-box" @keydown="onKeydown">
            <!-- Pressing a tool must not move focus out of the text, or a touch selection is lost before the command runs. -->
            <div
                class="wd-rich-text-toolbar"
                role="toolbar"
                @mousedown.prevent
                @pointerdown.prevent
                :aria-label="`${label} formatting`"
                :aria-controls="id"
            >
                <button
                    v-for="tool in tools"
                    :key="tool.name"
                    type="button"
                    :aria-label="tool.name"
                    :title="`${tool.name} (${tool.shortcut})`"
                    :aria-pressed="tool.active()"
                    :disabled="disabled"
                    @click="tool.run()"
                >
                    <component :is="tool.icon" :size="16" aria-hidden="true" />
                </button>
                <span class="wd-rich-text-divider" aria-hidden="true"></span>
                <button
                    type="button"
                    aria-label="Link"
                    title="Link (Ctrl+K)"
                    :aria-pressed="editor?.isActive('link') ?? false"
                    :aria-expanded="linkOpen"
                    :disabled="disabled"
                    @click="openLink"
                >
                    <LinkIcon :size="16" aria-hidden="true" />
                </button>
                <button
                    v-if="editor?.isActive('link')"
                    type="button"
                    aria-label="Remove link"
                    title="Remove link"
                    :disabled="disabled"
                    @click="editor?.chain().focus().extendMarkRange('link').unsetLink().run()"
                >
                    <Unlink :size="16" aria-hidden="true" />
                </button>
                <span class="wd-rich-text-spacer"></span>
                <button
                    type="button"
                    aria-label="Undo"
                    title="Undo (Ctrl+Z)"
                    :disabled="disabled || !editor?.can().undo()"
                    @click="editor?.chain().focus().undo().run()"
                >
                    <Undo2 :size="16" aria-hidden="true" />
                </button>
                <button
                    type="button"
                    aria-label="Redo"
                    title="Redo (Ctrl+Shift+Z)"
                    :disabled="disabled || !editor?.can().redo()"
                    @click="editor?.chain().focus().redo().run()"
                >
                    <Redo2 :size="16" aria-hidden="true" />
                </button>
            </div>
            <div
                v-if="linkOpen"
                class="wd-rich-text-link"
                @keydown.enter.prevent="applyLink"
                @keydown.esc.prevent.stop="cancelLink"
            >
                <label :for="`${id}-link`" class="wd-sr-only">Link address</label>
                <input
                    :id="`${id}-link`"
                    ref="linkInput"
                    v-model="linkUrl"
                    class="wd-table-input"
                    type="text"
                    inputmode="url"
                    placeholder="https://example.com or mailto:name@example.com"
                    :aria-invalid="linkError ? 'true' : undefined"
                    :aria-describedby="linkError ? `${id}-link-error` : undefined"
                />
                <button type="button" class="wd-table-button" @click="applyLink">Apply</button>
                <button type="button" class="wd-table-button" @click="cancelLink">Cancel</button>
                <p v-if="linkError" :id="`${id}-link-error`" class="wd-field-error" role="alert">{{ linkError }}</p>
            </div>
            <EditorContent :editor="editor" />
        </div>
        <p v-if="hint" :id="`${id}-hint`" class="wd-hint">{{ hint }}</p>
        <p v-if="error" :id="`${id}-error`" class="wd-field-error" role="alert">{{ error }}</p>
        <p v-else-if="over" class="wd-field-error" role="alert">
            Shorten the text to {{ maxLength.toLocaleString() }} characters to save.
        </p>
    </div>
</template>
