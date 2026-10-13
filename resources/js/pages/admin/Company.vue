<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
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
import { Label } from '@/components/ui/label';
import { confirmDialog } from '@/composables/useConfirm';
import { edit, update } from '@/routes/admin/company';
import {
    destroy as removeLogoRoute,
    store as storeLogo,
} from '@/routes/admin/company/logo';

/**
 * E-92: the agency's details and logo on invoices, work reports and the client status page.
 */
const props = defineProps<{
    company: {
        name: string;
        legal_name: string | null;
        address: string | null;
        tax_id: string | null;
        branch_code: string | null;
        vat_registered: boolean;
        contact: string | null;
        brand_color: string;
        invoice_footer: string | null;
        has_logo: boolean;
    };
    logoUrl: string | null;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Company', href: edit() }] },
});

const form = useForm({
    name: props.company.name,
    legal_name: props.company.legal_name ?? '',
    address: props.company.address ?? '',
    tax_id: props.company.tax_id ?? '',
    branch_code: props.company.branch_code ?? '',
    vat_registered: props.company.vat_registered,
    contact: props.company.contact ?? '',
    brand_color: props.company.brand_color,
    invoice_footer: props.company.invoice_footer ?? '',
});

const logoForm = useForm<{ logo: File | null }>({ logo: null });
const fileInput = ref<HTMLInputElement | null>(null);
function pickLogo(e: Event): void {
    logoForm.logo = (e.target as HTMLInputElement).files?.[0] ?? null;
    if (logoForm.logo) {
        logoForm.post(storeLogo.url(), {
            preserveScroll: true,
            forceFormData: true,
            onFinish: () => {
                logoForm.reset();
                if (fileInput.value) {
                    fileInput.value.value = '';
                }
            },
        });
    }
}
async function removeLogo(): Promise<void> {
    if (
        await confirmDialog(
            'Remove the logo? New PDFs will show the company name only.',
        )
    ) {
        router.delete(removeLogoRoute.url(), { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Company" />

    <div class="flex max-w-3xl flex-1 flex-col gap-4 p-4">
        <Heading
            title="Company"
            description="Your agency's details and logo on invoices, work reports and the client status page. Finalized invoices keep the copy stored when they were finalized."
        />

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Logo</CardTitle>
                <CardDescription
                    >PNG or JPG, up to 1 MB. A wide logo on a transparent or
                    white background prints best.</CardDescription
                >
            </CardHeader>
            <CardContent class="flex flex-wrap items-center gap-4">
                <div
                    class="flex h-16 w-48 items-center justify-center rounded-md border bg-white p-2"
                >
                    <img
                        v-if="logoUrl"
                        :src="logoUrl"
                        alt="Current logo"
                        class="max-h-12 max-w-full object-contain"
                    />
                    <span v-else class="text-xs text-muted-foreground"
                        >No logo yet</span
                    >
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <label
                        class="inline-flex cursor-pointer items-center rounded-md border px-3 py-1.5 text-sm hover:bg-accent"
                    >
                        {{ logoUrl ? 'Replace logo' : 'Upload logo' }}
                        <input
                            ref="fileInput"
                            type="file"
                            accept="image/png,image/jpeg"
                            class="sr-only"
                            @change="pickLogo"
                        />
                    </label>
                    <Button
                        v-if="logoUrl"
                        variant="ghost"
                        size="sm"
                        @click="removeLogo"
                        >Remove</Button
                    >
                </div>
                <InputError class="w-full" :message="logoForm.errors.logo" />
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Details</CardTitle>
                <CardDescription
                    >Printed in the header of every invoice and
                    report.</CardDescription
                >
            </CardHeader>
            <CardContent>
                <form
                    class="grid gap-4 sm:grid-cols-2"
                    @submit.prevent="
                        form.put(update.url(), { preserveScroll: true })
                    "
                >
                    <div class="grid gap-1">
                        <Label for="c-name">Trading name</Label>
                        <Input
                            id="c-name"
                            v-model="form.name"
                            required
                            maxlength="120"
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="c-legal">Registered (legal) name</Label>
                        <Input
                            id="c-legal"
                            v-model="form.legal_name"
                            maxlength="160"
                            placeholder="As on your BIR registration"
                        />
                    </div>
                    <div class="grid gap-1 sm:col-span-2">
                        <Label for="c-address">Address</Label>
                        <textarea
                            id="c-address"
                            v-model="form.address"
                            rows="3"
                            maxlength="500"
                            class="rounded-md border bg-transparent px-3 py-2 text-sm"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label for="c-tin">TIN</Label>
                        <Input
                            id="c-tin"
                            v-model="form.tax_id"
                            maxlength="30"
                            placeholder="000-000-000"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label for="c-branch">Branch code</Label>
                        <Input
                            id="c-branch"
                            v-model="form.branch_code"
                            maxlength="10"
                            placeholder="00000"
                        />
                    </div>
                    <label
                        class="flex items-center gap-2 text-sm sm:col-span-2"
                    >
                        <input
                            v-model="form.vat_registered"
                            type="checkbox"
                            class="size-4"
                        />
                        VAT-registered (invoices say "VAT" instead of "Tax")
                    </label>
                    <div class="grid gap-1">
                        <Label for="c-contact">Contact line</Label>
                        <Input
                            id="c-contact"
                            v-model="form.contact"
                            maxlength="200"
                            placeholder="billing@… · +63 …"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label for="c-color">Brand colour</Label>
                        <div class="flex items-center gap-2">
                            <input
                                id="c-color"
                                v-model="form.brand_color"
                                type="color"
                                class="h-9 w-12 rounded border"
                            />
                            <Input
                                v-model="form.brand_color"
                                class="w-28"
                                maxlength="7"
                                aria-label="Brand colour hex"
                            />
                        </div>
                        <InputError :message="form.errors.brand_color" />
                    </div>
                    <div class="grid gap-1 sm:col-span-2">
                        <Label for="c-footer">Footer note</Label>
                        <Input
                            id="c-footer"
                            v-model="form.invoice_footer"
                            maxlength="500"
                            placeholder="e.g. Thank you for your business."
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <Button type="submit" :disabled="form.processing"
                            >Save details</Button
                        >
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
