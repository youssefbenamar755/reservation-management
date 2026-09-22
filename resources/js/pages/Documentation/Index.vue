<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import {
    ArrowDownToLine,
    ArrowLeft,
    ArrowRight,
    BookOpen,
    Search,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';

interface Chapter {
    id: string;
    group: string;
    title: string;
    html: string;
    text: string;
}
const props = defineProps<{ chapters: Chapter[] }>();
const query = ref('');
const selected = ref(props.chapters[0]?.id || '');
const groups = [...new Set(props.chapters.map((chapter) => chapter.group))];
const matches = computed(() => {
    const terms = query.value
        .toLocaleLowerCase()
        .trim()
        .split(/\s+/)
        .filter(Boolean);
    return props.chapters.filter((chapter) =>
        terms.every((term) =>
            `${chapter.title} ${chapter.text}`
                .toLocaleLowerCase()
                .includes(term),
        ),
    );
});
const active = computed(
    () =>
        props.chapters.find((chapter) => chapter.id === selected.value) ||
        props.chapters[0],
);
const index = computed(() =>
    props.chapters.findIndex((chapter) => chapter.id === active.value?.id),
);
const previous = computed(() => props.chapters[index.value - 1]);
const next = computed(() => props.chapters[index.value + 1]);
const heading = ref<HTMLElement | null>(null);
function readHash() {
    const id = window.location.hash.slice(1);
    selected.value = props.chapters.some((chapter) => chapter.id === id)
        ? id
        : props.chapters[0]?.id || '';
    query.value = '';
}
async function selectChapter(id: string) {
    selected.value = id;
    query.value = '';
    if (window.location.hash !== `#${id}`)
        window.history.pushState(window.history.state, '', `#${id}`);
    await nextTick();
    heading.value?.focus({ preventScroll: true });
    heading.value?.scrollIntoView({ behavior: 'auto', block: 'start' });
}
onMounted(() => {
    readHash();
    window.addEventListener('hashchange', readHash);
    window.addEventListener('popstate', readHash);
});
onUnmounted(() => {
    window.removeEventListener('hashchange', readHash);
    window.removeEventListener('popstate', readHash);
});
</script>

<template>
    <Head title="WP Hub documentation"
        ><meta name="robots" content="noindex, nofollow"
    /></Head>
    <AppLayout
        :breadcrumbs="[{ title: 'Documentation', href: '/documentation' }]"
    >
        <div class="mx-auto w-full max-w-7xl space-y-7 p-4 sm:p-6 lg:p-8">
            <header
                class="flex flex-wrap items-start justify-between gap-5 rounded-2xl border bg-card p-6 sm:p-8"
            >
                <div class="max-w-2xl space-y-3">
                    <p
                        class="flex items-center gap-2 text-xs font-semibold tracking-widest text-primary uppercase"
                    >
                        <BookOpen class="size-4" />WP Hub handbook
                    </p>
                    <h1
                        class="text-3xl font-semibold tracking-tight sm:text-4xl"
                    >
                        Know your workspace.
                    </h1>
                    <p class="text-base leading-relaxed text-muted-foreground">
                        Step-by-step help for daily work, connected websites,
                        and your next chapter as an owner.
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ chapters.length }} chapters · User guide & owner
                        handover · Reviewed 22 September 2026
                    </p>
                </div>
                <a
                    href="/documentation/download"
                    class="inline-flex shrink-0 items-center gap-2 rounded-lg border bg-background px-4 py-2.5 text-sm font-medium hover:bg-accent focus-visible:outline-2 focus-visible:outline-primary"
                    ><ArrowDownToLine class="size-4" />Download guide<span
                        class="sr-only"
                    >
                        as Markdown</span
                    ></a
                >
            </header>

            <div
                class="grid items-start gap-6 lg:grid-cols-[260px_minmax(0,1fr)]"
            >
                <aside class="rounded-xl border bg-card p-4 lg:sticky lg:top-4">
                    <label
                        for="guide-search"
                        class="mb-2 block text-sm font-medium"
                        >Search the guide</label
                    >
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute top-3 left-3 size-4 text-muted-foreground"
                        /><input
                            id="guide-search"
                            v-model="query"
                            type="search"
                            placeholder="Try Gmail or transaction ID"
                            class="w-full rounded-lg border bg-background py-2.5 pr-3 pl-9 text-sm focus-visible:outline-2 focus-visible:outline-primary"
                        />
                    </div>
                    <p
                        v-if="query.trim()"
                        role="status"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        {{ matches.length }} matching chapters
                    </p>
                    <nav
                        aria-label="Guide chapters"
                        class="mt-5 max-h-72 space-y-5 overflow-y-auto lg:max-h-[65vh]"
                    >
                        <section v-for="group in groups" :key="group">
                            <h2
                                class="mb-2 px-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                {{ group }}
                            </h2>
                            <a
                                v-for="chapter in matches.filter(
                                    (item) => item.group === group,
                                )"
                                :key="chapter.id"
                                :href="`#${chapter.id}`"
                                :aria-current="
                                    selected === chapter.id ? 'page' : undefined
                                "
                                class="block rounded-lg px-2.5 py-2 text-sm leading-snug hover:bg-accent focus-visible:outline-2 focus-visible:outline-primary"
                                :class="
                                    selected === chapter.id
                                        ? 'bg-primary/10 font-medium text-primary'
                                        : 'text-muted-foreground'
                                "
                                @click.prevent="selectChapter(chapter.id)"
                                >{{ chapter.title }}</a
                            >
                        </section>
                    </nav>
                </aside>

                <div class="min-w-0">
                    <section
                        v-if="query.trim()"
                        class="rounded-xl border bg-card p-6 sm:p-8"
                        aria-label="Search results"
                    >
                        <h2 class="text-xl font-semibold">Search results</h2>
                        <p class="mt-2 text-sm text-muted-foreground">
                            Search covers every chapter, including setup and
                            troubleshooting.
                        </p>
                        <ul class="mt-5 space-y-3">
                            <li v-for="chapter in matches" :key="chapter.id">
                                <a
                                    :href="`#${chapter.id}`"
                                    class="block rounded-xl border p-4 hover:bg-accent focus-visible:outline-2 focus-visible:outline-primary"
                                    @click.prevent="selectChapter(chapter.id)"
                                    ><span
                                        class="text-xs text-muted-foreground"
                                        >{{ chapter.group }}</span
                                    >
                                    <h3 class="mt-1 font-semibold">
                                        {{ chapter.title }}
                                    </h3>
                                    <p
                                        class="mt-2 line-clamp-2 text-sm leading-relaxed text-muted-foreground"
                                    >
                                        {{ chapter.text.trim().slice(0, 240) }}
                                    </p></a
                                >
                            </li>
                        </ul>
                        <p
                            v-if="!matches.length"
                            class="mt-6 rounded-lg bg-muted p-4 text-sm"
                        >
                            No matching chapters. Try a shorter term such as
                            “orders”, “Gmail”, or “campaign”.
                        </p>
                        <button
                            type="button"
                            class="mt-5 text-sm font-medium text-primary underline underline-offset-4"
                            @click="query = ''"
                        >
                            Clear search
                        </button>
                    </section>
                    <article
                        v-else-if="active"
                        :id="active.id"
                        class="rounded-xl border bg-card p-6 sm:p-8 lg:p-10"
                    >
                        <p
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            {{ active.group }} · Chapter {{ index + 1 }} of
                            {{ chapters.length }}
                        </p>
                        <h2
                            ref="heading"
                            tabindex="-1"
                            class="mt-3 scroll-mt-6 text-2xl font-semibold tracking-tight outline-none sm:text-3xl"
                        >
                            {{ active.title }}
                        </h2>
                        <!-- HTML is rendered server-side only from the two version-controlled guide files. -->
                        <div class="guide-content mt-7" v-html="active.html" />
                        <nav
                            aria-label="Chapter navigation"
                            class="mt-10 flex flex-wrap justify-between gap-4 border-t pt-6"
                        >
                            <a
                                v-if="previous"
                                :href="`#${previous.id}`"
                                class="inline-flex max-w-full items-center gap-2 text-sm font-medium text-primary hover:underline"
                                @click.prevent="selectChapter(previous.id)"
                                ><ArrowLeft class="size-4 shrink-0" />{{
                                    previous.title
                                }}</a
                            >
                            <a
                                v-if="next"
                                :href="`#${next.id}`"
                                class="ml-auto inline-flex max-w-full items-center gap-2 text-sm font-medium text-primary hover:underline"
                                @click.prevent="selectChapter(next.id)"
                                >{{ next.title
                                }}<ArrowRight class="size-4 shrink-0"
                            /></a>
                        </nav>
                    </article>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.guide-content {
    font-size: 0.95rem;
    line-height: 1.85;
    overflow-wrap: anywhere;
}
.guide-content :deep(p),
.guide-content :deep(ul),
.guide-content :deep(ol),
.guide-content :deep(pre),
.guide-content :deep(table) {
    margin: 1rem 0;
}
.guide-content :deep(h3) {
    margin-top: 2rem;
    margin-bottom: 0.75rem;
    font-size: 1.125rem;
    font-weight: 650;
    line-height: 1.5;
}
.guide-content :deep(ol) {
    list-style: decimal;
    padding-left: 1.5rem;
}
.guide-content :deep(ul) {
    list-style: disc;
    padding-left: 1.5rem;
}
.guide-content :deep(li) {
    padding-left: 0.3rem;
    margin: 0.65rem 0;
}
.guide-content :deep(a) {
    color: var(--primary);
    text-decoration: underline;
    text-underline-offset: 3px;
}
.guide-content :deep(a:focus-visible) {
    outline: 2px solid var(--primary);
    outline-offset: 3px;
}
.guide-content :deep(table) {
    display: block;
    width: 100%;
    overflow-x: auto;
    border-collapse: collapse;
    font-size: 0.875rem;
}
.guide-content :deep(th),
.guide-content :deep(td) {
    border: 1px solid var(--border);
    padding: 0.75rem;
    text-align: left;
    min-width: 120px;
    vertical-align: top;
}
.guide-content :deep(th) {
    background: var(--muted);
    font-weight: 600;
}
.guide-content :deep(code) {
    background: var(--muted);
    border-radius: 4px;
    padding: 0.15rem 0.3rem;
    font-size: 0.85em;
}
.guide-content :deep(pre) {
    background: var(--muted);
    padding: 1rem;
    border-radius: 0.75rem;
    overflow-x: auto;
}
.guide-content :deep(pre code) {
    padding: 0;
}
</style>
