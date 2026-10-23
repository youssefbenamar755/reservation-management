<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';
import { Download, FileText, Loader2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface ItineraryDocument {
    reference: string;
    reference_type: 'internal' | 'external';
    external_pnr: string | null;
    booking_source: string | null;
    generated_at: string;
    download_url: string;
    can_update: boolean;
}

const props = defineProps<{
    entryId: number;
    itinerary?: ItineraryDocument | null;
    canGenerate: boolean;
}>();

const form = useForm({
    reference_type: props.itinerary?.reference_type ?? 'internal',
    external_pnr: props.itinerary?.external_pnr ?? '',
    booking_source: props.itinerary?.booking_source ?? '',
});
const errorMessage = ref('');
const successMessage = ref('');
const canEdit = computed(
    () => props.canGenerate && (!props.itinerary || props.itinerary.can_update),
);
const generatedAt = computed(() => {
    const value = props.itinerary?.generated_at;
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat('en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
});

watch(
    () => [props.entryId, props.itinerary],
    () => {
        form.reference_type = props.itinerary?.reference_type ?? 'internal';
        form.external_pnr = props.itinerary?.external_pnr ?? '';
        form.booking_source = props.itinerary?.booking_source ?? '';
        form.clearErrors();
        errorMessage.value = '';
        successMessage.value = '';
    },
);

function generateItinerary() {
    if (!canEdit.value || form.processing) return;
    errorMessage.value = '';
    successMessage.value = '';
    form.clearErrors();
    if (form.reference_type === 'external') {
        if (!/^[a-zA-Z0-9][a-zA-Z0-9-]{0,19}$/.test(form.external_pnr.trim())) {
            form.setError(
                'external_pnr',
                'Enter the real booking PNR: up to 20 letters, numbers or hyphens.',
            );
        }
        if (
            !form.booking_source.trim() ||
            form.booking_source.trim().length > 120
        ) {
            form.setError(
                'booking_source',
                'Enter the airline or booking system (up to 120 characters).',
            );
        }
        if (form.hasErrors) return;
    }
    form.transform((data) => ({
        ...data,
        external_pnr:
            data.reference_type === 'external' ? data.external_pnr.trim() : '',
        booking_source:
            data.reference_type === 'external'
                ? data.booking_source.trim()
                : '',
    })).post(`/submissions/entries/${props.entryId}/itinerary`, {
        preserveScroll: true,
        onSuccess: (page) => {
            const flash = page.props.flash as { error?: string } | undefined;
            if (flash?.error) {
                errorMessage.value = flash.error;
                return;
            }
            successMessage.value = 'Itinerary PDF is ready to download.';
        },
        onError: (errors) => {
            errorMessage.value =
                errors.itinerary ||
                errors.message ||
                'The itinerary could not be generated. Review the details below and try again.';
        },
    });
}
</script>

<template>
    <Card id="booking-tools" class="min-w-0 scroll-mt-6 gap-0 shadow-none">
        <CardHeader class="border-b border-border pb-5">
            <h2 class="flex items-center gap-2 text-base font-semibold">
                <FileText class="h-4 w-4 text-muted-foreground" />
                Itinerary document
            </h2>
            <CardDescription>
                Create a PDF using the passenger and flight details saved in
                this submission.
            </CardDescription>
        </CardHeader>
        <CardContent class="min-w-0 space-y-5 pt-5">
            <div v-if="itinerary" class="space-y-3">
                <dl
                    class="space-y-3 rounded-lg border border-border bg-muted/30 p-4 text-sm"
                >
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Itinerary reference
                        </dt>
                        <dd
                            class="mt-1 font-mono font-semibold [overflow-wrap:anywhere]"
                        >
                            {{ itinerary.reference }}
                        </dd>
                    </div>
                    <template v-if="itinerary.reference_type === 'external'">
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Booking PNR (entered by you)
                            </dt>
                            <dd
                                class="mt-1 font-mono font-semibold [overflow-wrap:anywhere]"
                            >
                                {{ itinerary.external_pnr }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Booking source
                            </dt>
                            <dd class="mt-1 [overflow-wrap:anywhere]">
                                {{ itinerary.booking_source }}
                            </dd>
                        </div>
                    </template>
                    <div v-if="generatedAt">
                        <dt class="text-xs text-muted-foreground">
                            Last generated
                        </dt>
                        <dd class="mt-1">{{ generatedAt }}</dd>
                    </div>
                </dl>
                <Button
                    v-if="itinerary.download_url"
                    as-child
                    variant="outline"
                    class="w-full gap-2"
                >
                    <a :href="itinerary.download_url"
                        ><Download class="h-4 w-4" />Download PDF</a
                    >
                </Button>
            </div>
            <p
                class="rounded-lg bg-muted/40 p-3 text-xs leading-relaxed text-muted-foreground"
            >
                The itinerary reference is an internal document reference, not
                an airline PNR. Creating this PDF does not make a reservation or
                issue a ticket.
            </p>
            <form
                v-if="canEdit"
                class="space-y-4"
                :aria-busy="form.processing"
                @submit.prevent="generateItinerary"
            >
                <fieldset :disabled="form.processing" class="min-w-0 space-y-3">
                    <legend class="mb-2 text-sm font-medium">
                        Reference to include
                    </legend>
                    <label
                        class="flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm"
                    >
                        <input
                            v-model="form.reference_type"
                            type="radio"
                            value="internal"
                            name="itinerary-reference-type"
                            class="mt-1 shrink-0 accent-primary"
                        />
                        <span
                            ><span class="block font-medium"
                                >Internal itinerary reference</span
                            ><span
                                class="mt-1 block text-xs leading-relaxed text-muted-foreground"
                                >Generated by WP Hub. The same reference is kept
                                when updating this document.</span
                            ></span
                        >
                    </label>
                    <label
                        class="flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm"
                    >
                        <input
                            v-model="form.reference_type"
                            type="radio"
                            value="external"
                            name="itinerary-reference-type"
                            class="mt-1 shrink-0 accent-primary"
                        />
                        <span
                            ><span class="block font-medium"
                                >Use an existing booking PNR</span
                            ><span
                                class="mt-1 block text-xs leading-relaxed text-muted-foreground"
                                >Enter a real PNR from your airline or booking
                                system.</span
                            ></span
                        >
                    </label>
                    <InputError :message="form.errors.reference_type" />
                    <div
                        v-if="form.reference_type === 'external'"
                        class="space-y-4 rounded-lg border p-3"
                    >
                        <div class="space-y-2">
                            <Label for="itinerary-external-pnr"
                                >Booking PNR (entered by you)</Label
                            >
                            <Input
                                id="itinerary-external-pnr"
                                v-model="form.external_pnr"
                                maxlength="20"
                                pattern="[A-Za-z0-9][A-Za-z0-9-]{0,19}"
                                required
                                autocomplete="off"
                                :aria-invalid="!!form.errors.external_pnr"
                                aria-describedby="itinerary-pnr-error"
                            />
                            <InputError
                                id="itinerary-pnr-error"
                                :message="form.errors.external_pnr"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="itinerary-booking-source"
                                >Airline or booking system</Label
                            >
                            <Input
                                id="itinerary-booking-source"
                                v-model="form.booking_source"
                                maxlength="120"
                                required
                                autocomplete="off"
                                :aria-invalid="!!form.errors.booking_source"
                                aria-describedby="itinerary-source-error"
                            />
                            <InputError
                                id="itinerary-source-error"
                                :message="form.errors.booking_source"
                            />
                        </div>
                        <p
                            class="text-xs leading-relaxed text-muted-foreground"
                        >
                            WP Hub records the PNR you enter. It does not verify
                            the booking or its status.
                        </p>
                    </div>
                </fieldset>
                <p
                    v-if="itinerary"
                    class="text-xs leading-relaxed text-muted-foreground"
                >
                    Updating replaces the PDF with the current submission
                    details and keeps its internal reference.
                </p>
                <p
                    v-if="errorMessage"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errorMessage }}
                </p>
                <p
                    v-if="successMessage"
                    role="status"
                    class="text-sm text-emerald-700 dark:text-emerald-400"
                >
                    {{ successMessage }}
                </p>
                <Button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full gap-2"
                >
                    <Loader2
                        v-if="form.processing"
                        class="h-4 w-4 animate-spin"
                    />
                    <FileText v-else class="h-4 w-4" />
                    {{
                        form.processing
                            ? 'Generating itinerary…'
                            : itinerary
                              ? 'Update itinerary'
                              : 'Generate itinerary'
                    }}
                </Button>
            </form>
            <p v-else class="text-xs leading-relaxed text-muted-foreground">
                You can view available itinerary documents. Generating or
                updating them is not available for this submission.
            </p>
        </CardContent>
    </Card>
</template>
