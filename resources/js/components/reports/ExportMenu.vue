<script setup lang="ts">
import { Download } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * E-49: one "Download" button for every report: Excel (.xlsx), PDF or CSV.
 * `url` builds the download link for a format; filters travel in its query.
 */
type Format = 'xlsx' | 'pdf' | 'csv';

const props = withDefaults(
    defineProps<{
        url: (format: Format) => string;
        formats?: Format[];
        label?: string;
        size?: 'sm' | 'default';
    }>(),
    { formats: () => ['xlsx', 'pdf', 'csv'], label: 'Download', size: 'sm' },
);

const names: Record<Format, string> = {
    xlsx: 'Excel (.xlsx)',
    pdf: 'PDF',
    csv: 'CSV',
};

function go(format: Format): void {
    window.location.href = props.url(format);
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="outline" :size="size">
                <Download class="size-4" /> {{ label }}
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuItem v-for="f in formats" :key="f" @select="go(f)">{{
                names[f]
            }}</DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
