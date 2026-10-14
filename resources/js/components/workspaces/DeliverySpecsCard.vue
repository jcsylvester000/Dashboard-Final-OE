<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import DeliverySpecController from '@/actions/App/Http/Controllers/Workspaces/DeliverySpecController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { confirmDialog } from '@/composables/useConfirm';

/**
 * E-18: delivery specs for this client. Each uploaded video on a matching task is
 * checked against them (aspect, minimum size, length, file size, captions) before
 * review. Presets only fill the shape; check each platform's current spec.
 */
export type DeliverySpecRow = {
    id: number;
    name: string;
    channel: string | null;
    deliverable: string | null;
    aspect: string | null;
    min_width: number | null;
    min_height: number | null;
    max_duration_s: number | null;
    max_size_mb: number | null;
    captions_required: boolean;
};

const props = defineProps<{
    workspaceSlug: string;
    specs: DeliverySpecRow[];
    channels: string[];
    deliverables: string[];
}>();

const PRESETS: Record<string, Partial<DeliverySpecRow>> = {
    'Vertical 9:16 (1080×1920)': {
        aspect: '9:16',
        min_width: 1080,
        min_height: 1920,
    },
    'Portrait 4:5 (1080×1350)': {
        aspect: '4:5',
        min_width: 1080,
        min_height: 1350,
    },
    'Square 1:1 (1080×1080)': {
        aspect: '1:1',
        min_width: 1080,
        min_height: 1080,
    },
    'Landscape 16:9 (1920×1080)': {
        aspect: '16:9',
        min_width: 1920,
        min_height: 1080,
    },
};

const empty = () => ({
    name: '',
    channel: '' as string,
    deliverable: '' as string,
    aspect: '',
    min_width: '' as string | number,
    min_height: '' as string | number,
    max_duration_s: '' as string | number,
    max_size_mb: '' as string | number,
    captions_required: false,
});
const form = useForm(empty());
const editing = ref<number | null>(null);

function preset(e: Event): void {
    const p = PRESETS[(e.target as HTMLSelectElement).value];
    if (p) {
        form.aspect = p.aspect ?? '';
        form.min_width = p.min_width ?? '';
        form.min_height = p.min_height ?? '';
    }
}
function payload() {
    const n = (v: string | number) =>
        v === '' || v === null ? null : Number(v);

    return {
        name: form.name,
        channel: form.channel || null,
        deliverable: form.deliverable || null,
        aspect: form.aspect || null,
        min_width: n(form.min_width),
        min_height: n(form.min_height),
        max_duration_s: n(form.max_duration_s),
        max_size_mb: n(form.max_size_mb),
        captions_required: form.captions_required,
    };
}
function save(): void {
    const args = { workspace: props.workspaceSlug };
    const opts = {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults(empty());
            form.reset();
            editing.value = null;
        },
    };
    form.transform(() => payload());
    if (editing.value === null) {
        form.post(DeliverySpecController.store.url(args), opts);
    } else {
        form.put(
            DeliverySpecController.update.url({
                ...args,
                deliverySpec: editing.value,
            }),
            opts,
        );
    }
}
function edit(s: DeliverySpecRow): void {
    editing.value = s.id;
    Object.assign(form, {
        name: s.name,
        channel: s.channel ?? '',
        deliverable: s.deliverable ?? '',
        aspect: s.aspect ?? '',
        min_width: s.min_width ?? '',
        min_height: s.min_height ?? '',
        max_duration_s: s.max_duration_s ?? '',
        max_size_mb: s.max_size_mb ?? '',
        captions_required: s.captions_required,
    });
}
async function remove(s: DeliverySpecRow): Promise<void> {
    if (await confirmDialog(`Remove the spec "${s.name}"?`)) {
        router.delete(
            DeliverySpecController.destroy.url({
                workspace: props.workspaceSlug,
                deliverySpec: s.id,
            }),
            { preserveScroll: true },
        );
    }
}
function summary(s: DeliverySpecRow): string {
    return [
        s.aspect,
        s.min_width && s.min_height ? `≥ ${s.min_width}×${s.min_height}` : null,
        s.max_duration_s ? `≤ ${s.max_duration_s}s` : null,
        s.max_size_mb ? `≤ ${s.max_size_mb} MB` : null,
        s.captions_required ? 'captions' : null,
    ]
        .filter(Boolean)
        .join(' · ');
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="text-base">Delivery specs</CardTitle>
            <CardDescription
                >Videos on tasks with a matching channel (and deliverable) are
                checked against these before review. Presets fill the shape
                only: check each platform's current
                requirements.</CardDescription
            >
        </CardHeader>
        <CardContent class="space-y-4 text-sm">
            <ul v-if="specs.length" class="divide-y rounded border">
                <li
                    v-for="s in specs"
                    :key="s.id"
                    class="flex items-center gap-2 px-3 py-2"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ s.name }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ s.channel ?? 'Any channel (choose on the task)'
                            }}<template v-if="s.deliverable">
                                · {{ s.deliverable }}</template
                            >
                            · {{ summary(s) || 'no checks yet' }}
                        </p>
                    </div>
                    <Button size="sm" variant="ghost" @click="edit(s)"
                        >Edit</Button
                    >
                    <Button
                        size="icon"
                        variant="ghost"
                        :aria-label="`Remove ${s.name}`"
                        @click="remove(s)"
                        ><Trash2 class="size-4"
                    /></Button>
                </li>
            </ul>
            <form class="grid gap-3 sm:grid-cols-4" @submit.prevent="save">
                <label class="grid gap-1 sm:col-span-2">
                    <span class="text-xs text-muted-foreground">Name</span>
                    <Input
                        v-model="form.name"
                        required
                        maxlength="80"
                        placeholder="Instagram Reels"
                    />
                    <InputError :message="form.errors.name" />
                </label>
                <label class="grid gap-1">
                    <span class="text-xs text-muted-foreground">Channel</span>
                    <NativeSelect v-model="form.channel" aria-label="Channel">
                        <option value="">Any (choose on task)</option>
                        <option v-for="c in channels" :key="c" :value="c">
                            {{ c }}
                        </option>
                    </NativeSelect>
                </label>
                <label class="grid gap-1">
                    <span class="text-xs text-muted-foreground"
                        >Deliverable</span
                    >
                    <NativeSelect
                        v-model="form.deliverable"
                        aria-label="Deliverable"
                    >
                        <option value="">Any</option>
                        <option v-for="d in deliverables" :key="d" :value="d">
                            {{ d }}
                        </option>
                    </NativeSelect>
                </label>
                <label class="grid gap-1">
                    <span class="text-xs text-muted-foreground">Preset</span>
                    <select
                        class="h-9 rounded-md border bg-background px-2"
                        aria-label="Fill from a preset"
                        @change="preset"
                    >
                        <option value="">Fill from…</option>
                        <option v-for="(p, k) in PRESETS" :key="k" :value="k">
                            {{ k }}
                        </option>
                    </select>
                </label>
                <label class="grid gap-1">
                    <span class="text-xs text-muted-foreground">Aspect</span>
                    <Input
                        v-model="form.aspect"
                        placeholder="9:16"
                        maxlength="9"
                    />
                    <InputError :message="form.errors.aspect" />
                </label>
                <label class="grid gap-1">
                    <span class="text-xs text-muted-foreground">Min width</span>
                    <Input
                        v-model="form.min_width"
                        type="number"
                        min="16"
                        max="8192"
                    />
                </label>
                <label class="grid gap-1">
                    <span class="text-xs text-muted-foreground"
                        >Min height</span
                    >
                    <Input
                        v-model="form.min_height"
                        type="number"
                        min="16"
                        max="8192"
                    />
                </label>
                <label class="grid gap-1">
                    <span class="text-xs text-muted-foreground"
                        >Max length (s)</span
                    >
                    <Input
                        v-model="form.max_duration_s"
                        type="number"
                        min="1"
                    />
                </label>
                <label class="grid gap-1">
                    <span class="text-xs text-muted-foreground"
                        >Max file (MB)</span
                    >
                    <Input v-model="form.max_size_mb" type="number" min="1" />
                </label>
                <label class="flex items-center gap-2 sm:col-span-2">
                    <input
                        v-model="form.captions_required"
                        type="checkbox"
                        class="size-4"
                    />
                    Captions required
                </label>
                <div class="flex gap-2 sm:col-span-4">
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="form.processing || !form.name"
                        >{{
                            editing === null ? 'Add spec' : 'Save spec'
                        }}</Button
                    >
                    <Button
                        v-if="editing !== null"
                        type="button"
                        size="sm"
                        variant="ghost"
                        @click="
                            editing = null;
                            form.defaults(empty());
                            form.reset();
                        "
                        >Cancel</Button
                    >
                </div>
            </form>
        </CardContent>
    </Card>
</template>
