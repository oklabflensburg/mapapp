import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

export interface CurrentUser {
  name: string
  email: string
  roles: string[]
}

// Session-based authentication against the Symfony backend. The session
// cookie is sent automatically with the same-origin /api requests.
export const useAuthStore = defineStore('auth', () => {
  const user = ref<CurrentUser | null>(null)
  const loaded = ref(false)

  // Only editors (and admins, who inherit the editor role) may create or
  // edit nodes; anonymous visitors can view the map.
  const canEdit = computed(() => {
    const roles = user.value?.roles ?? []
    return roles.includes('ROLE_EDITOR') || roles.includes('ROLE_ADMIN')
  })

  async function load(): Promise<void> {
    try {
      const response = await fetch('/api/me')
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`)
      }
      const data = (await response.json()) as { user: CurrentUser | null }
      user.value = data.user
    } catch (error) {
      console.warn('Failed to load current user', error)
      user.value = null
    } finally {
      loaded.value = true
    }
  }

  async function login(email: string, password: string): Promise<boolean> {
    try {
      const response = await fetch('/api/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password }),
      })
      if (!response.ok) {
        return false
      }
      const data = (await response.json()) as { user: CurrentUser | null }
      user.value = data.user
      return data.user !== null
    } catch (error) {
      console.warn('Failed to log in', error)
      return false
    }
  }

  async function logout(): Promise<void> {
    try {
      await fetch('/api/logout', { method: 'POST' })
    } catch (error) {
      console.warn('Failed to log out', error)
    }
    user.value = null
  }

  return { user, loaded, canEdit, load, login, logout }
})
