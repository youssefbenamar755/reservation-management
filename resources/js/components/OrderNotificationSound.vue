<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useOrderSound } from '@/composables/useOrderSound';
import { usePage } from '@inertiajs/vue3';
import { Volume2, VolumeX } from 'lucide-vue-next';
import { onMounted } from 'vue';

const page = usePage();
const { enabled, available, initialize, toggle } = useOrderSound();
onMounted(() => {
    if (page.props.auth?.user) initialize(page.props.auth.user.id);
});
</script>

<template>
    <Button v-if="available" variant="ghost" size="sm" class="h-8 text-xs" :aria-pressed="enabled" @click.stop="toggle">
        <component :is="enabled ? Volume2 : VolumeX" class="mr-2 h-4 w-4" aria-hidden="true" />
        {{ enabled ? 'Mute order sound' : 'Enable order sound' }}
    </Button>
</template>
