<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { ItineraryAttachmentContext } from '@/types/document-delivery';
import {
    CheckCircle2,
    Download,
    FileText,
    Loader2,
    Plus,
    RefreshCw,
} from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    attachment?: ItineraryAttachmentContext;
    submissionId?: number;
    orderNumber: number | string;
    recipient: string;
    busy?: boolean;
    adding?: boolean;
    selected?: boolean;
}>();
defineEmits<{ add: []; refresh: [] }>();
const submissionId = computed(
    () => props.attachment?.source?.submission_id ?? props.submissionId,
);
const source = computed(() =>
    props.attachment?.available ? props.attachment.source : null,
);
</script>

<template>
    <section
        class="space-y-3 rounded-lg border bg-muted/20 p-4"
        aria-label="Generated PDF attachment"
    >
        <div class="flex items-start gap-3">
            <FileText class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
            <div class="min-w-0 flex-1 space-y-1">
                <h3 class="text-sm font-semibold">Generated PDF</h3>
                <template v-if="source">
                    <p class="text-sm [overflow-wrap:anywhere]">
                        {{ source.name }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        From submission #{{ source.submission_number }}
                    </p>
                </template>
                <p v-else class="text-sm text-muted-foreground">
                    {{
                        attachment?.reason ||
                        'Generate the itinerary in the submission, then refresh it here.'
                    }}
                </p>
            </div>
            <Button
                type="button"
                size="sm"
                variant="ghost"
                :disabled="busy"
                @click="$emit('refresh')"
            >
                <RefreshCw class="size-3.5" /><span
                    class="sr-only sm:not-sr-only"
                    >Refresh</span
                >
            </Button>
        </div>
        <template v-if="source">
            <p class="text-xs leading-relaxed text-muted-foreground">
                Review the PDF. Adding it approves this version for order #{{
                    orderNumber
                }}
                and {{ recipient }}. It will be selected for email review;
                nothing is sent yet.
            </p>
            <div class="flex flex-wrap items-center gap-3">
                <a
                    :href="source.download_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1.5 text-sm underline underline-offset-4"
                >
                    <Download class="size-3.5" />Download PDF
                </a>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="busy || selected"
                    @click="$emit('add')"
                >
                    <Loader2 v-if="adding" class="size-4 animate-spin" />
                    <CheckCircle2 v-else-if="selected" class="size-4" />
                    <Plus v-else class="size-4" />
                    {{
                        adding
                            ? 'Adding itinerary…'
                            : selected
                              ? 'Selected for review'
                              : 'Add itinerary'
                    }}
                </Button>
            </div>
        </template>
        <a
            v-else-if="submissionId"
            :href="`/submissions/entries/${submissionId}`"
            target="_blank"
            rel="noopener noreferrer"
            class="inline-block text-sm underline underline-offset-4"
        >
            Open submission to generate a PDF
        </a>
    </section>
</template>
