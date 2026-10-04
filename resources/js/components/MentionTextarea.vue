<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import type { MemberOption } from '@/types/workspace';

/**
 * Plain <textarea> with @mention autocomplete. Selecting a person inserts
 * the token @[Name](user:ID). Works inside an Inertia <Form> via `name`.
 */
const props = withDefaults(
    defineProps<{
        name: string;
        members: MemberOption[];
        defaultValue?: string | null;
        rows?: number;
        placeholder?: string;
        id?: string;
    }>(),
    { defaultValue: '', rows: 5, placeholder: 'Type @ to tag a team member' },
);

const value = ref(props.defaultValue ?? '');
const el = ref<HTMLTextAreaElement | null>(null);
const query = ref<string | null>(null);
const anchor = ref(0);
const active = ref(0);

const matches = computed(() => {
    if (query.value === null) {
        return [];
    }
    const q = query.value.toLowerCase();

    return props.members
        .filter((m) => m.name.toLowerCase().includes(q))
        .slice(0, 6);
});

function onInput(): void {
    const textarea = el.value;
    if (!textarea) {
        return;
    }

    const caret = textarea.selectionStart;
    const before = value.value.slice(0, caret);
    const match = /(^|\s)@([\p{L}\p{N} .'-]{0,30})$/u.exec(before);

    if (match) {
        query.value = match[2];
        anchor.value = caret - match[2].length - 1;
        active.value = 0;
    } else {
        query.value = null;
    }
}

async function pick(member: MemberOption): Promise<void> {
    const textarea = el.value;
    if (!textarea) {
        return;
    }

    const caret = textarea.selectionStart;
    const token = `@[${member.name.replace(/[\]\r\n]/g, '')}](user:${member.id}) `;
    value.value =
        value.value.slice(0, anchor.value) + token + value.value.slice(caret);
    query.value = null;

    await nextTick();
    const pos = anchor.value + token.length;
    textarea.focus();
    textarea.setSelectionRange(pos, pos);
}

function onKeydown(event: KeyboardEvent): void {
    if (query.value === null || matches.value.length === 0) {
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = (active.value + 1) % matches.value.length;
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value =
            (active.value - 1 + matches.value.length) % matches.value.length;
    } else if (event.key === 'Enter' || event.key === 'Tab') {
        event.preventDefault();
        void pick(matches.value[active.value]);
    } else if (event.key === 'Escape') {
        query.value = null;
    }
}
</script>

<template>
    <div class="relative">
        <textarea
            :id="id"
            ref="el"
            v-model="value"
            :name="name"
            :rows="rows"
            :placeholder="placeholder"
            class="w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
            @input="onInput"
            @keydown="onKeydown"
            @blur="query = null"
        />
        <ul
            v-if="query !== null && matches.length"
            class="absolute z-20 mt-1 w-64 overflow-hidden rounded-md border bg-popover text-sm shadow-md"
            role="listbox"
        >
            <li
                v-for="(m, i) in matches"
                :key="m.id"
                role="option"
                :aria-selected="i === active"
                class="cursor-pointer px-3 py-2"
                :class="i === active ? 'bg-accent' : ''"
                @mousedown.prevent="pick(m)"
            >
                <div class="font-medium">{{ m.name }}</div>
                <div v-if="m.title" class="text-xs text-muted-foreground">
                    {{ m.title }}
                </div>
            </li>
        </ul>
    </div>
</template>
