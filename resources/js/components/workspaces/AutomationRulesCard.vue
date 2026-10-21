<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import AutomationRuleController from '@/actions/App/Http/Controllers/Workspaces/AutomationRuleController';
import InputError from '@/components/InputError.vue';
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
import { timeAgo } from '@/lib/format';
import type { MemberOption } from '@/types/workspace';

/**
 * E-37: When -> If -> Then rules for this client. Each event fires a rule once; rules
 * that set each other off stop after 3 steps. The last runs show what happened.
 */
export type RuleRow = {
    id: number;
    name: string;
    trigger: string;
    trigger_value: string | null;
    conditions: Record<string, string | number | null>;
    actions: { type: string; value: string | null }[];
    enabled: boolean;
    runs: number;
    log: { outcome: string; detail: string | null; at: string }[];
};

const props = defineProps<{
    workspaceSlug: string;
    rules: RuleRow[];
    triggers: Record<string, string>;
    actions: Record<string, string>;
    statusSlugs: Record<string, string>;
    labels: { id: number; name: string }[];
    members: MemberOption[];
    departments: { id: number; name: string }[];
    taskTypes: Record<string, string>;
}>();

const args = { workspace: props.workspaceSlug };
const form = useForm({
    name: '',
    trigger: 'label_added',
    trigger_value: '' as string,
    conditions: { department_id: '' as string | number, type: '' },
    actions: [{ type: 'notify', value: 'leads' as string | null }],
});
function save(): void {
    form.post(AutomationRuleController.store.url(args), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
function toggle(r: RuleRow): void {
    router.put(
        AutomationRuleController.update.url({ ...args, rule: r.id }),
        { enabled: !r.enabled },
        { preserveScroll: true },
    );
}
async function remove(r: RuleRow): Promise<void> {
    if (await confirmDialog(`Delete the rule "${r.name}"?`)) {
        router.delete(
            AutomationRuleController.destroy.url({ ...args, rule: r.id }),
            { preserveScroll: true },
        );
    }
}
const outcomeClass: Record<string, string> = {
    done: 'text-emerald-700',
    failed: 'text-rose-600',
    loop: 'text-amber-700',
    skipped: 'text-muted-foreground',
};
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="text-base">Automation rules</CardTitle>
            <CardDescription
                >When something happens on a task, do something for you. Rules
                act as the lead who turned them on, and review sign-off and
                required checklist lines still apply.</CardDescription
            >
        </CardHeader>
        <CardContent class="space-y-4 text-sm">
            <ul v-if="rules.length" class="divide-y rounded border">
                <li v-for="r in rules" :key="r.id" class="space-y-1 px-3 py-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ r.name }}</span>
                        <span class="text-xs text-muted-foreground"
                            >{{ triggers[r.trigger]
                            }}{{
                                r.trigger_value
                                    ? ` ${statusSlugs[r.trigger_value] ?? labels.find((l) => String(l.id) === r.trigger_value)?.name ?? ''}`
                                    : ''
                            }}
                            →
                            {{
                                r.actions.map((a) => actions[a.type]).join(', ')
                            }}
                            · ran {{ r.runs }}×</span
                        >
                        <span class="flex-1" />
                        <Button
                            size="sm"
                            :variant="r.enabled ? 'default' : 'outline'"
                            @click="toggle(r)"
                            >{{ r.enabled ? 'On' : 'Off' }}</Button
                        >
                        <Button
                            size="icon"
                            variant="ghost"
                            :aria-label="`Delete ${r.name}`"
                            @click="remove(r)"
                            ><Trash2 class="size-4"
                        /></Button>
                    </div>
                    <ul v-if="r.log.length" class="text-xs">
                        <li v-for="(l, i) in r.log" :key="i">
                            <span :class="outcomeClass[l.outcome]">{{
                                l.outcome
                            }}</span>
                            · {{ timeAgo(l.at)
                            }}<template v-if="l.detail">
                                · {{ l.detail }}</template
                            >
                        </li>
                    </ul>
                </li>
            </ul>

            <form class="grid gap-2 rounded border p-3" @submit.prevent="save">
                <Input
                    v-model="form.name"
                    placeholder="Rule name (e.g. Urgent label → tell dept lead)"
                    maxlength="120"
                    aria-label="Rule name"
                />
                <div class="flex flex-wrap items-center gap-2">
                    <span class="w-12 text-xs font-medium">When</span>
                    <select
                        v-model="form.trigger"
                        class="h-8 rounded border bg-background px-2"
                        aria-label="When"
                    >
                        <option
                            v-for="(label, key) in triggers"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </select>
                    <select
                        v-if="form.trigger === 'status_changed'"
                        v-model="form.trigger_value"
                        class="h-8 rounded border bg-background px-2"
                        aria-label="Status"
                    >
                        <option value="">Choose status</option>
                        <option
                            v-for="(name, slug) in statusSlugs"
                            :key="slug"
                            :value="slug"
                        >
                            {{ name }}
                        </option>
                    </select>
                    <select
                        v-if="form.trigger === 'label_added'"
                        v-model="form.trigger_value"
                        class="h-8 rounded border bg-background px-2"
                        aria-label="Label"
                    >
                        <option value="">Choose label</option>
                        <option
                            v-for="l in labels"
                            :key="l.id"
                            :value="String(l.id)"
                        >
                            {{ l.name }}
                        </option>
                    </select>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="w-12 text-xs font-medium">If</span>
                    <select
                        v-model="form.conditions.department_id"
                        class="h-8 rounded border bg-background px-2"
                        aria-label="Department"
                    >
                        <option value="">any department</option>
                        <option
                            v-for="d in departments"
                            :key="d.id"
                            :value="d.id"
                        >
                            {{ d.name }}
                        </option>
                    </select>
                    <select
                        v-model="form.conditions.type"
                        class="h-8 rounded border bg-background px-2"
                        aria-label="Type"
                    >
                        <option value="">any type</option>
                        <option
                            v-for="(label, key) in taskTypes"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </select>
                </div>
                <div
                    v-for="(a, i) in form.actions"
                    :key="i"
                    class="flex flex-wrap items-center gap-2"
                >
                    <span class="w-12 text-xs font-medium">{{
                        i === 0 ? 'Then' : 'and'
                    }}</span>
                    <select
                        v-model="a.type"
                        class="h-8 rounded border bg-background px-2"
                        aria-label="Action"
                        @change="a.value = null"
                    >
                        <option
                            v-for="(label, key) in actions"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </select>
                    <select
                        v-if="a.type === 'assign'"
                        v-model="a.value"
                        class="h-8 rounded border bg-background px-2"
                        aria-label="Person"
                    >
                        <option
                            v-for="m in members"
                            :key="m.id"
                            :value="String(m.id)"
                        >
                            {{ m.name }}
                        </option>
                    </select>
                    <select
                        v-else-if="a.type === 'set_status'"
                        v-model="a.value"
                        class="h-8 rounded border bg-background px-2"
                        aria-label="Status"
                    >
                        <option
                            v-for="(name, slug) in statusSlugs"
                            :key="slug"
                            :value="slug"
                        >
                            {{ name }}
                        </option>
                    </select>
                    <select
                        v-else-if="a.type === 'add_label'"
                        v-model="a.value"
                        class="h-8 rounded border bg-background px-2"
                        aria-label="Label"
                    >
                        <option
                            v-for="l in labels"
                            :key="l.id"
                            :value="String(l.id)"
                        >
                            {{ l.name }}
                        </option>
                    </select>
                    <select
                        v-else-if="a.type === 'notify'"
                        v-model="a.value"
                        class="h-8 rounded border bg-background px-2"
                        aria-label="Who"
                    >
                        <option value="leads">Client leads</option>
                        <option value="dept_leads">
                            Department leads (task's department)
                        </option>
                        <option value="assignee">The assignee</option>
                        <option
                            v-for="m in members"
                            :key="m.id"
                            :value="String(m.id)"
                        >
                            {{ m.name }}
                        </option>
                    </select>
                    <input
                        v-else-if="a.type === 'create_subtask'"
                        v-model="a.value"
                        class="h-8 w-64 rounded border bg-background px-2"
                        placeholder="Subtask title"
                        aria-label="Subtask title"
                    />
                    <button
                        v-if="form.actions.length > 1"
                        type="button"
                        aria-label="Remove action"
                        @click="form.actions.splice(i, 1)"
                    >
                        <Trash2 class="size-3.5" />
                    </button>
                </div>
                <div class="flex gap-2">
                    <Button
                        v-if="form.actions.length < 5"
                        type="button"
                        size="sm"
                        variant="ghost"
                        @click="
                            form.actions.push({
                                type: 'notify',
                                value: 'leads',
                            })
                        "
                        ><Plus /> Add action</Button
                    >
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="form.processing || !form.name.trim()"
                        >Add rule</Button
                    >
                </div>
                <InputError
                    :message="
                        form.errors.trigger_value ??
                        form.errors.name ??
                        form.errors.actions
                    "
                />
            </form>
        </CardContent>
    </Card>
</template>
