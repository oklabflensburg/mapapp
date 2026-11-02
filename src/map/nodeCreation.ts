import type { SaveUserNodeInput } from '../stores/userNodes'

export interface NodeCreationState {
  count: number
  parentNames: string[]
  saving: boolean
  failed: boolean
}

export type NodeCreationInput = Pick<SaveUserNodeInput, 'tags' | 'translationLocale' | 'translations'> & {
  name: string
}
