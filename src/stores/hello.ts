import { defineStore } from 'pinia'

export const useHelloStore = defineStore('hello', {
  state: () => ({
    message: '',
    error: '',
  }),
  actions: {
    async fetchHello() {
      this.error = ''
      try {
        const response = await fetch('/api/hello')
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`)
        }
        const data = (await response.json()) as { message: string }
        this.message = data.message
      } catch {
        this.message = ''
        this.error = 'Could not reach the backend API.'
      }
    },
  },
})
