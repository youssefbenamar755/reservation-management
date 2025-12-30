<script setup lang="ts">
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { CheckCircle2, X, XCircle } from 'lucide-vue-next'
import { computed, onMounted, ref } from 'vue'

interface Props {
  message: string
  type?: 'success' | 'error'
  duration?: number
}

const emit = defineEmits<{
  close: []
}>()

const props = withDefaults(defineProps<Props>(), {
  type: 'success',
  duration: 3000,
})

const isVisible = ref(true)

const icon = computed(() => {
  return props.type === 'success' ? CheckCircle2 : XCircle
})

const alertVariant = computed(() => {
  return props.type === 'success' ? 'default' : 'destructive'
})

onMounted(() => {
  if (props.duration > 0) {
    setTimeout(() => {
      close()
    }, props.duration)
  }
})

function close() {
  isVisible.value = false
  setTimeout(() => {
    emit('close')
  }, 300) // Wait for animation
}
</script>

<template>
  <Transition
    enter-active-class="transition ease-out duration-300"
    enter-from-class="opacity-0 translate-y-2 sm:translate-y-0 sm:translate-x-2"
    enter-to-class="opacity-100 translate-y-0 sm:translate-x-0"
    leave-active-class="transition ease-in duration-200"
    leave-from-class="opacity-100 translate-y-0 sm:translate-x-0"
    leave-to-class="opacity-0 translate-y-2 sm:translate-y-0 sm:translate-x-2"
  >
    <div
      v-if="isVisible"
      class="pointer-events-auto w-full max-w-sm overflow-hidden rounded-lg border bg-background shadow-lg"
    >
      <Alert :variant="alertVariant" class="relative border-0 pr-8">
        <component :is="icon" class="h-4 w-4" />
        <div class="flex-1">
          <AlertTitle>{{ type === 'success' ? 'Success' : 'Error' }}</AlertTitle>
          <AlertDescription class="mt-1">{{ message }}</AlertDescription>
        </div>
        <Button
          variant="ghost"
          size="icon"
          class="absolute right-2 top-2 h-6 w-6"
          @click="close"
        >
          <X class="h-4 w-4" />
        </Button>
      </Alert>
    </div>
  </Transition>
</template>

