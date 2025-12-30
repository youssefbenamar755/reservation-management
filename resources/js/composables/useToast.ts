import { ref } from 'vue'

export interface Toast {
  id: string
  message: string
  type: 'success' | 'error'
  duration?: number
}

const toasts = ref<Toast[]>([])

export function useToast() {
  function show(message: string, type: 'success' | 'error' = 'success', duration = 3000) {
    const id = Math.random().toString(36).substring(7)
    toasts.value.push({ id, message, type, duration })

    // Auto remove after duration
    if (duration > 0) {
      setTimeout(() => {
        remove(id)
      }, duration)
    }

    return id
  }

  function success(message: string, duration = 3000) {
    return show(message, 'success', duration)
  }

  function error(message: string, duration = 5000) {
    return show(message, 'error', duration)
  }

  function remove(id: string) {
    const index = toasts.value.findIndex((t) => t.id === id)
    if (index > -1) {
      toasts.value.splice(index, 1)
    }
  }

  function clear() {
    toasts.value = []
  }

  return {
    toasts,
    show,
    success,
    error,
    remove,
    clear,
  }
}

