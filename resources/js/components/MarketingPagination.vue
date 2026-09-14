<script setup lang="ts">
import type { MarketingPage } from '@/types/marketing';
import { Link } from '@inertiajs/vue3';
defineProps<{ page: MarketingPage<unknown> }>();
</script>
<template>
    <div
        class="flex flex-wrap items-center justify-between gap-3 border-t px-5 py-4 text-sm"
    >
        <span class="text-muted-foreground"
            >{{ page.from || 0 }}–{{ page.to || 0 }} of {{ page.total }}</span
        >
        <nav aria-label="Pagination" class="flex items-center gap-4">
            <Link
                v-if="page.prev_page_url"
                :href="page.prev_page_url"
                preserve-scroll
                class="rounded-md border px-3 py-2"
                >Previous</Link
            ><span>{{ page.current_page }} / {{ page.last_page }}</span
            ><Link
                v-if="page.next_page_url"
                :href="page.next_page_url"
                preserve-scroll
                class="rounded-md border px-3 py-2"
                >Next</Link
            >
        </nav>
    </div>
</template>
