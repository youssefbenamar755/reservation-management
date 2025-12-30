<script setup lang="ts">
import Toast from '@/components/Toast.vue'
import { useToast } from '@/composables/useToast'

const { toasts, remove } = useToast()
</script>

<template>
  <div
    class="pointer-events-none fixed bottom-0 right-0 z-[100] flex max-h-screen w-full flex-col-reverse p-4 sm:bottom-auto sm:right-0 sm:top-0 sm:flex-col md:max-w-[420px]"
  >
    <Toast
      v-for="toast in toasts"
      :key="toast.id"
      :message="toast.message"
      :type="toast.type"
      :duration="toast.duration"
      @close="remove(toast.id)"
    />
  </div>
</template>

