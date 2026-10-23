<script setup lang="ts">
import { Button } from '@/components/ui/button';
import axios from 'axios';
import { ExternalLink, Loader2 } from 'lucide-vue-next';
import { computed, onUnmounted, reactive, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        websiteId: number;
        profileId?: number | null;
        trustpilotUrl?: string | null;
        disabled?: boolean;
    }>(),
    { profileId: null, trustpilotUrl: null, disabled: false },
);
const emit = defineEmits<{
    saved: [value: { websiteId: number; trustpilotUrl: string | null }];
    saving: [value: boolean];
}>();
interface ReviewLinkDraft {
    input: string;
    baseline: string | null;
    error: string;
    notice: string;
    conflicted: boolean;
}
const drafts = reactive<Record<number, ReviewLinkDraft>>({});
const saving = ref(false);
let request: AbortController | null = null;
let generation = 0;
let disposed = false;
const current = computed(() => drafts[props.websiteId]);
const hasProfile = computed(
    () => Number.isSafeInteger(props.profileId) && (props.profileId ?? 0) > 0,
);
const dirty = computed(
    () => current.value?.input.trim() !== (current.value?.baseline ?? ''),
);
const canSave = computed(
    () =>
        hasProfile.value &&
        dirty.value &&
        !props.disabled &&
        !saving.value &&
        !current.value?.conflicted,
);

function canonicalProfileUrl(value: unknown): string | null {
    if (typeof value !== 'string') return null;
    try {
        const url = new URL(value);
        return url.protocol === 'https:' &&
            url.hostname === 'www.trustpilot.com' &&
            !url.username &&
            !url.password &&
            !url.port &&
            !url.search &&
            !url.hash &&
            /^\/review\/[a-z0-9.-]+$/i.test(url.pathname)
            ? url.href
            : null;
    } catch {
        return null;
    }
}
const savedLink = computed(() => canonicalProfileUrl(current.value?.baseline));

function invalidate() {
    generation++;
    request?.abort();
    request = null;
    if (saving.value) {
        saving.value = false;
        emit('saving', false);
    }
}
watch(
    [() => props.websiteId, () => props.profileId, () => props.trustpilotUrl],
    () => {
        invalidate();
        const saved = props.trustpilotUrl || null;
        const existing = drafts[props.websiteId];
        if (!existing) {
            drafts[props.websiteId] = {
                input: saved ?? '',
                baseline: saved,
                error: '',
                notice: '',
                conflicted: false,
            };
        } else if (existing.baseline !== saved) {
            const hadEdits =
                existing.input.trim() !== (existing.baseline ?? '');
            existing.baseline = saved;
            if (!hadEdits) existing.input = saved ?? '';
            existing.error = '';
            existing.notice = hadEdits
                ? 'The saved link was refreshed. Your unsaved link is still in the field.'
                : '';
            existing.conflicted = false;
        }
    },
    { immediate: true },
);

function errorMessage(exception: unknown): string {
    if (axios.isAxiosError(exception)) {
        const status = exception.response?.status;
        if (status === 409)
            return 'This review link changed in another session. Keep a copy of any unsaved edits, then reload the page to review the current link before saving again.';
        if (status === 422) {
            const errors = exception.response?.data?.errors;
            for (const key of ['trustpilot_url', 'expected_trustpilot_url']) {
                const value = errors?.[key];
                const message = Array.isArray(value) ? value[0] : value;
                if (typeof message === 'string') return message;
            }
        }
        if (status === 403 || status === 404)
            return 'This website is unavailable, or your access has changed.';
        if (status === 419)
            return 'Your session expired. Reload WP Hub and sign in again.';
        if (status === 429)
            return 'Too many requests. Wait a moment before saving again.';
    }
    return 'The saved review link could not be confirmed. Your link remains in the field; reload the page to check its saved value before trying again.';
}
async function save() {
    if (!canSave.value || disposed) return;
    const websiteId = props.websiteId;
    const draft = current.value;
    const token = ++generation;
    request = new AbortController();
    saving.value = true;
    emit('saving', true);
    draft.error = '';
    draft.notice = '';
    try {
        const { data } = await axios.patch(
            `/settings/email-documents/${websiteId}/review-link`,
            {
                trustpilot_url: draft.input.trim() || null,
                expected_trustpilot_url: draft.baseline,
            },
            {
                signal: request.signal,
                timeout: 20_000,
                headers: { Accept: 'application/json' },
            },
        );
        if (disposed || token !== generation || props.websiteId !== websiteId)
            return;
        if (
            data?.website_id !== websiteId ||
            (data.trustpilot_url !== null &&
                !canonicalProfileUrl(data.trustpilot_url))
        )
            throw new Error('Invalid review link response');
        draft.baseline = data.trustpilot_url;
        draft.input = data.trustpilot_url ?? '';
        draft.notice = data.trustpilot_url
            ? 'Review link saved for this website.'
            : 'Review link removed for this website.';
        emit('saved', { websiteId, trustpilotUrl: data.trustpilot_url });
    } catch (exception) {
        if (disposed || token !== generation || props.websiteId !== websiteId)
            return;
        draft.error = errorMessage(exception);
        draft.conflicted =
            axios.isAxiosError(exception) && exception.response?.status === 409;
    } finally {
        if (!disposed && token === generation) {
            saving.value = false;
            request = null;
            emit('saving', false);
        }
    }
}
onUnmounted(() => {
    disposed = true;
    invalidate();
});
</script>

<template>
    <section
        class="min-w-0 space-y-3 rounded-xl border bg-muted/20 p-4"
        aria-labelledby="trustpilot-review-heading"
    >
        <div>
            <h3 id="trustpilot-review-heading" class="font-medium">
                Trustpilot review link
                <span class="font-normal text-muted-foreground"
                    >(optional)</span
                >
            </h3>
            <p class="mt-1 text-sm text-muted-foreground">
                Appears in document delivery and corrected-document emails in
                French and English. Order acknowledgements do not include it.
            </p>
        </div>
        <form class="space-y-3" @submit.prevent="save">
            <label
                for="trustpilot-review-url"
                class="block space-y-1.5 text-sm font-medium"
            >
                Website’s Trustpilot profile URL
                <input
                    id="trustpilot-review-url"
                    v-model="current.input"
                    type="url"
                    maxlength="2048"
                    autocomplete="off"
                    placeholder="https://www.trustpilot.com/review/your-domain.com"
                    :disabled="disabled || saving || !hasProfile"
                    :aria-invalid="Boolean(current.error)"
                    aria-describedby="trustpilot-review-help"
                    class="block w-full min-w-0 rounded-md border bg-background px-3 py-2 text-sm font-normal outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                    @input="current.notice = ''"
                />
            </label>
            <p
                id="trustpilot-review-help"
                class="text-xs text-muted-foreground"
            >
                Leave blank to remove the review link. This saves separately
                from your branding, templates, and workflow settings.
            </p>
            <p v-if="!hasProfile" class="text-sm text-muted-foreground">
                Save this website’s configuration first, then add its review
                link.
            </p>
            <p
                v-if="current.error"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ current.error }}
            </p>
            <p
                v-if="current.notice"
                role="status"
                class="text-sm text-emerald-700 dark:text-emerald-400"
            >
                {{ current.notice }}
            </p>
            <div class="flex flex-wrap items-center gap-3">
                <Button type="submit" variant="outline" :disabled="!canSave">
                    <Loader2 v-if="saving" class="size-4 animate-spin" />
                    {{ saving ? 'Saving link…' : 'Save review link' }}
                </Button>
                <a
                    v-if="savedLink"
                    :href="savedLink"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1.5 text-sm underline underline-offset-4"
                >
                    Open saved Trustpilot profile
                    <ExternalLink class="size-3.5" />
                </a>
            </div>
        </form>
    </section>
</template>
