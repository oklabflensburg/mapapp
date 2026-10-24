import { ref } from 'vue'
import { defineStore } from 'pinia'
import i18n from '../i18n'
import { useAuthStore } from './auth'

export type AppLocale = 'en' | 'de'
// How strongly a user wants routing to favor or disfavor a tag. Neutral is
// the implicit default when the user has not picked a level yet.
export type TagPriority = 'neutral' | 'avoid' | 'try_to_avoid' | 'prefer' | 'strongly_prefer'

const LOCALE_KEY = 'accessapp.settings.locale'
const TAG_PRIORITIES_KEY = 'accessapp.settings.tagPriorities'

const TAG_PRIORITY_LEVELS: readonly TagPriority[] = [
  'neutral',
  'avoid',
  'try_to_avoid',
  'prefer',
  'strongly_prefer',
]

// Tags a user can prioritize on the profile page. Keep in sync with
// TagPriorityManager::tags() in the backend.
export const PRIORITIZABLE_TAGS = ['stairs', 'toilets'] as const
export type PrioritizableTag = (typeof PRIORITIZABLE_TAGS)[number]

function readStored<T extends string>(key: string, fallback: T, allowed: readonly T[]): T {
  try {
    const value = localStorage.getItem(key) as T | null
    return value !== null && allowed.includes(value) ? value : fallback
  } catch {
    return fallback
  }
}

function writeStored(key: string, value: string): void {
  try {
    localStorage.setItem(key, value)
  } catch {
    // Storage may be unavailable (private mode, quota); settings then only
    // last for the current session.
  }
}

function defaultTagPriorities(): Record<PrioritizableTag, TagPriority> {
  return Object.fromEntries(PRIORITIZABLE_TAGS.map((tag) => [tag, 'neutral'])) as Record<
    PrioritizableTag,
    TagPriority
  >
}

// Accepts only known tags with known levels; everything else falls back to
// the neutral default. Used for both the localStorage fallback and the
// backend response.
function normalizeTagPriorities(input: unknown): Record<PrioritizableTag, TagPriority> {
  const priorities = defaultTagPriorities()
  if (typeof input !== 'object' || input === null) {
    return priorities
  }
  for (const tag of PRIORITIZABLE_TAGS) {
    const value = (input as Record<string, unknown>)[tag]
    if (typeof value === 'string' && TAG_PRIORITY_LEVELS.includes(value as TagPriority)) {
      priorities[tag] = value as TagPriority
    }
  }
  return priorities
}

function readStoredTagPriorities(): Record<PrioritizableTag, TagPriority> {
  try {
    return normalizeTagPriorities(JSON.parse(localStorage.getItem(TAG_PRIORITIES_KEY) ?? '{}'))
  } catch {
    return defaultTagPriorities()
  }
}

// Client-side settings from the profile page. They are persisted in
// localStorage; tag priorities are additionally stored per user on the
// backend when logged in.
export const useSettingsStore = defineStore('settings', () => {
  const locale = ref<AppLocale>(readStored(LOCALE_KEY, 'en', ['en', 'de']))
  const tagPriorities = ref<Record<PrioritizableTag, TagPriority>>(readStoredTagPriorities())

  // Apply the persisted locale on startup, before the first render.
  i18n.global.locale.value = locale.value

  function setLocale(value: AppLocale): void {
    locale.value = value
    i18n.global.locale.value = value
    writeStored(LOCALE_KEY, value)
  }

  function applyTagPriorities(priorities: Record<PrioritizableTag, TagPriority>): void {
    tagPriorities.value = priorities
    writeStored(TAG_PRIORITIES_KEY, JSON.stringify(priorities))
  }

  // Replaces the local tag priorities with the ones stored for the logged-in
  // user; a failed request keeps the localStorage fallback.
  async function loadTagPriorities(): Promise<void> {
    try {
      const response = await fetch('/api/tag-priorities')
      if (!response.ok) {
        return
      }
      const data = (await response.json()) as { priorities?: unknown }
      applyTagPriorities(normalizeTagPriorities(data.priorities))
    } catch (error) {
      console.warn('Failed to load tag priorities', error)
    }
  }

  function setTagPriority(tag: PrioritizableTag, value: TagPriority): void {
    applyTagPriorities({ ...tagPriorities.value, [tag]: value })

    // Persist the priorities for the logged-in user; anonymous visitors
    // keep the localStorage-only behavior.
    if (useAuthStore().user) {
      void fetch('/api/tag-priorities', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ priorities: tagPriorities.value }),
      }).catch((error) => console.warn('Failed to save tag priorities', error))
    }
  }

  return {
    locale,
    tagPriorities,
    setLocale,
    setTagPriority,
    loadTagPriorities,
  }
})
