import { ref } from 'vue'

export interface Notice {
  id: number
  text: string
  color: 'success' | 'error' | 'info'
}

const notices = ref<Notice[]>([])
let nextId = 1

/** Queues a transient message for the snackbar rendered by the application root. */
export function useNotify() {
  function notify(text: string, color: Notice['color'] = 'success'): void {
    notices.value = [
      ...notices.value,
      { id: nextId++, text, color },
    ]
  }

  function dismiss(id: number): void {
    notices.value = notices.value.filter(n => n.id !== id)
  }

  return { notices, notify, dismiss }
}
