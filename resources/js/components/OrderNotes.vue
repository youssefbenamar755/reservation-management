<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FileText, Loader2 } from 'lucide-vue-next';
import { onMounted, onUnmounted, ref, watch } from 'vue';

interface OrderNote {
    id: number | null;
    note: string;
    author: string | null;
    date_created: string | null;
    customer_note: boolean | null;
}

const props = withDefaults(
    defineProps<{
        orderId: number;
        available?: boolean;
        refreshKey?: string;
    }>(),
    { available: true, refreshKey: '' },
);

const notes = ref<OrderNote[]>([]);
const loading = ref(props.available);
const error = ref('');
let mounted = false;
let request: AbortController | null = null;
let sequence = 0;

async function loadNotes() {
    const current = ++sequence;
    request?.abort();
    if (!props.available) {
        notes.value = [];
        loading.value = false;
        return;
    }
    request = new AbortController();
    loading.value = true;
    error.value = '';
    try {
        const response = await fetch(`/orders/${props.orderId}/notes`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: request.signal,
        });
        if (!response.ok) throw new Error('Unable to load order notes');
        const data = await response.json();
        if (!Array.isArray(data.notes))
            throw new Error('Invalid notes response');
        if (current === sequence) notes.value = data.notes;
    } catch {
        if (current === sequence)
            error.value = 'Order notes could not be loaded. Please retry.';
    } finally {
        if (current === sequence) loading.value = false;
    }
}

watch(
    () => [props.orderId, props.available, props.refreshKey],
    () => {
        notes.value = [];
        if (mounted) void loadNotes();
    },
);
onMounted(() => {
    mounted = true;
    void loadNotes();
});
onUnmounted(() => {
    mounted = false;
    sequence++;
    request?.abort();
});

function formatDate(value: string): string {
    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? value
        : new Intl.DateTimeFormat('en-US', {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          }).format(date);
}
</script>

<template>
    <Card class="w-full min-w-0" :aria-busy="loading">
        <CardHeader
            class="flex flex-row items-center justify-between gap-3 px-3 sm:px-6"
        >
            <CardTitle class="flex items-center gap-2 text-base sm:text-lg">
                <FileText class="h-4 w-4 shrink-0" /> Order Notes
            </CardTitle>
            <Button
                v-if="available"
                type="button"
                variant="outline"
                size="sm"
                :disabled="loading"
                @click="loadNotes"
            >
                {{ error ? 'Retry' : 'Refresh' }}
            </Button>
        </CardHeader>
        <CardContent class="space-y-4 px-3 sm:px-6">
            <p v-if="!available" class="text-sm text-muted-foreground">
                Order notes are unavailable for this website.
            </p>
            <p
                v-else-if="loading"
                role="status"
                class="flex items-center gap-2 text-sm text-muted-foreground"
            >
                <Loader2 class="h-4 w-4 animate-spin" /> Loading order notes…
            </p>
            <p v-else-if="error" role="alert" class="text-sm text-destructive">
                {{ error }}
            </p>
            <p v-else-if="!notes.length" class="text-sm text-muted-foreground">
                No notes for this order.
            </p>
            <div
                v-for="(note, index) in notes"
                :key="note.id ?? index"
                class="space-y-3 rounded-md border-l-4 bg-muted/30 py-3 pr-3 pl-4"
                :class="
                    note.customer_note ? 'border-blue-500' : 'border-green-500'
                "
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-2 text-xs"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge variant="outline">{{
                            note.customer_note === true
                                ? 'Customer Note'
                                : note.customer_note === false
                                  ? 'Private Note'
                                  : 'Note'
                        }}</Badge>
                        <span v-if="note.author" class="text-muted-foreground"
                            >by {{ note.author }}</span
                        >
                    </div>
                    <span
                        v-if="note.date_created"
                        class="text-muted-foreground"
                        >{{ formatDate(note.date_created) }}</span
                    >
                </div>
                <p class="text-sm break-words whitespace-pre-wrap">
                    {{ note.note }}
                </p>
            </div>
        </CardContent>
    </Card>
</template>
