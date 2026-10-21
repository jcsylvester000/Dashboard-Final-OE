<script setup lang="ts">
import { computed } from 'vue';
import type { Answers, FormSchemaShape } from '@/lib/forms';
import { passes } from '@/lib/forms';

/**
 * E-53: renders a form from its schema with conditional sections and fields
 * (builder preview and in-app filling). Files are collected per field key.
 */
const props = defineProps<{
    schema: FormSchemaShape;
    errors?: Record<string, string>;
}>();
const answers = defineModel<Answers>('answers', { required: true });
const files = defineModel<Record<string, File[]>>('files', {
    default: () => ({}),
});

/** Which sections and fields show for the current answers. */
const shown = computed(() => {
    const seen = new Set<string>();
    const sections = new Set<string>();
    const fields = new Set<string>();
    for (const s of props.schema.sections) {
        if (!passes(s.show_if, answers.value, seen)) {
            continue;
        }
        sections.add(s.id);
        for (const f of s.fields) {
            if (passes(f.show_if, answers.value, seen)) {
                fields.add(f.key);
                seen.add(f.key);
            }
        }
    }
    return { sections, fields };
});

function set(key: string, value: Answers[string]): void {
    answers.value = { ...answers.value, [key]: value };
}
function toggle(key: string, option: string, on: boolean): void {
    const now = Array.isArray(answers.value[key])
        ? (answers.value[key] as string[])
        : [];
    set(
        key,
        on ? [...new Set([...now, option])] : now.filter((o) => o !== option),
    );
}
function pick(key: string, e: Event): void {
    files.value = {
        ...files.value,
        [key]: Array.from((e.target as HTMLInputElement).files ?? []),
    };
}
const input = 'mt-1 w-full rounded-md border bg-background px-2 py-1.5 text-sm';
</script>

<template>
    <div class="space-y-5">
        <template v-for="s in schema.sections" :key="s.id">
            <fieldset v-if="shown.sections.has(s.id)" class="space-y-4">
                <legend v-if="s.title" class="mb-2 text-base font-semibold">
                    {{ s.title }}
                </legend>
                <template v-for="f in s.fields" :key="f.key">
                    <div v-if="shown.fields.has(f.key)">
                        <label :for="`ff-${f.key}`" class="text-sm font-medium"
                            >{{ f.label
                            }}<span v-if="f.required" class="text-rose-600">
                                *</span
                            ></label
                        >
                        <p v-if="f.help" class="text-xs text-muted-foreground">
                            {{ f.help }}
                        </p>
                        <textarea
                            v-if="f.type === 'textarea'"
                            :id="`ff-${f.key}`"
                            :class="input"
                            rows="4"
                            :value="(answers[f.key] as string) ?? ''"
                            @input="
                                set(
                                    f.key,
                                    ($event.target as HTMLTextAreaElement)
                                        .value,
                                )
                            "
                        />
                        <select
                            v-else-if="f.type === 'select'"
                            :id="`ff-${f.key}`"
                            :class="input"
                            :value="(answers[f.key] as string) ?? ''"
                            @change="
                                set(
                                    f.key,
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="">Choose…</option>
                            <option v-for="o in f.options" :key="o" :value="o">
                                {{ o }}
                            </option>
                        </select>
                        <div
                            v-else-if="f.type === 'multiselect'"
                            :id="`ff-${f.key}`"
                            class="mt-1 flex flex-wrap gap-3 text-sm"
                        >
                            <label
                                v-for="o in f.options"
                                :key="o"
                                class="flex items-center gap-1"
                            >
                                <input
                                    type="checkbox"
                                    :checked="
                                        Array.isArray(answers[f.key]) &&
                                        (answers[f.key] as string[]).includes(o)
                                    "
                                    @change="
                                        toggle(
                                            f.key,
                                            o,
                                            ($event.target as HTMLInputElement)
                                                .checked,
                                        )
                                    "
                                />
                                {{ o }}
                            </label>
                        </div>
                        <div
                            v-else-if="f.type === 'yesno'"
                            :id="`ff-${f.key}`"
                            class="mt-1 flex gap-4 text-sm"
                        >
                            <label
                                v-for="o in ['yes', 'no']"
                                :key="o"
                                class="flex items-center gap-1 capitalize"
                            >
                                <input
                                    type="radio"
                                    :name="`ff-${f.key}`"
                                    :checked="answers[f.key] === o"
                                    @change="set(f.key, o)"
                                />
                                {{ o }}
                            </label>
                        </div>
                        <div
                            v-else-if="f.type === 'rating'"
                            :id="`ff-${f.key}`"
                            class="mt-1 flex gap-3 text-sm"
                        >
                            <label
                                v-for="n in 5"
                                :key="n"
                                class="flex items-center gap-1"
                            >
                                <input
                                    type="radio"
                                    :name="`ff-${f.key}`"
                                    :checked="
                                        String(answers[f.key]) === String(n)
                                    "
                                    @change="set(f.key, n)"
                                />
                                {{ n }}
                            </label>
                        </div>
                        <input
                            v-else-if="f.type === 'file'"
                            :id="`ff-${f.key}`"
                            type="file"
                            class="mt-1 text-sm"
                            :multiple="(f.max_files ?? 1) > 1"
                            @change="pick(f.key, $event)"
                        />
                        <input
                            v-else
                            :id="`ff-${f.key}`"
                            :class="input"
                            :type="
                                f.type === 'number'
                                    ? 'number'
                                    : f.type === 'date'
                                      ? 'date'
                                      : f.type === 'url'
                                        ? 'url'
                                        : 'text'
                            "
                            :value="(answers[f.key] as string) ?? ''"
                            @input="
                                set(
                                    f.key,
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                        />
                        <p
                            v-if="errors?.[f.key]"
                            class="mt-1 text-xs text-rose-600"
                            role="alert"
                        >
                            {{ errors[f.key] }}
                        </p>
                    </div>
                </template>
            </fieldset>
        </template>
    </div>
</template>
