<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ value: unknown }>()

const isEmpty = computed(() => props.value === null || props.value === undefined || props.value === '' ||
  (typeof props.value === 'string' && !props.value.trim()) ||
  (typeof props.value === 'object' && Object.keys(props.value).length === 0))
const link = computed(() => {
  if (typeof props.value !== 'string') return null
  if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(props.value)) return { href: `mailto:${props.value}`, external: false }
  try {
    const url = new URL(props.value)
    if (url.protocol === 'https:' || url.protocol === 'http:') return { href: url.href, external: true }
  } catch { /* Plain text values do not need a link. */ }
  return null
})

function label(key: string) {
  return key.replace(/_/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2').replace(/^./, (letter) => letter.toUpperCase())
}
</script>

<template>
    <span v-if="isEmpty" class="text-sm font-normal text-muted-foreground"
        >Not provided</span
    >
    <ul v-else-if="Array.isArray(value)" class="min-w-0 space-y-2">
        <li
            v-for="(item, index) in value"
            :key="index"
            class="min-w-0 border-l-2 border-border pl-3"
        >
            <EntryFieldValue :value="item" />
        </li>
    </ul>
    <dl
        v-else-if="typeof value === 'object' && value !== null"
        class="min-w-0 space-y-2"
    >
        <div v-for="(item, key) in value" :key="key" class="min-w-0">
            <dt class="mb-0.5 text-xs font-normal text-muted-foreground">
                {{ label(String(key)) }}
            </dt>
            <dd class="min-w-0 text-sm"><EntryFieldValue :value="item" /></dd>
        </div>
    </dl>
    <a
        v-else-if="link"
        :href="link.href"
        :target="link.external ? '_blank' : undefined"
        :rel="link.external ? 'noopener noreferrer' : undefined"
        class="text-sm leading-relaxed font-medium [overflow-wrap:anywhere] underline-offset-4 hover:underline"
        >{{ value }}</a
    >
    <span
        v-else
        class="block text-sm leading-relaxed font-medium [overflow-wrap:anywhere] whitespace-pre-wrap"
        >{{ typeof value === 'boolean' ? (value ? 'Yes' : 'No') : value }}</span
    >
</template>
