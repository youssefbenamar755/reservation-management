<script setup lang="ts">
import { Copy, CheckCircle2 } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import EntryFieldValue from './EntryFieldValue.vue'

defineProps<{
  field: { key: string; label: string; value: unknown }
  copiedField: string | null
}>()
defineEmits<{ copy: [value: unknown, key: string] }>()
</script>

<template>
    <dl class="min-w-0 rounded-lg bg-muted/30 p-4">
        <div>
            <dt class="mb-2 flex min-w-0 items-start justify-between gap-3">
                <span
                    class="pt-1 text-xs font-medium [overflow-wrap:anywhere] text-muted-foreground"
                    >{{ field.label }}</span
                >
                <Button
                    variant="ghost"
                    size="icon"
                    class="-mt-1 -mr-1 h-7 w-7 shrink-0"
                    :aria-label="`Copy ${field.label}`"
                    :title="
                        copiedField === field.key
                            ? 'Copied'
                            : `Copy ${field.label}`
                    "
                    @click="$emit('copy', field.value, field.key)"
                >
                    <CheckCircle2
                        v-if="copiedField === field.key"
                        class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400"
                    />
                    <Copy v-else class="h-3.5 w-3.5" />
                </Button>
            </dt>
            <dd class="min-w-0"><EntryFieldValue :value="field.value" /></dd>
        </div>
    </dl>
</template>
