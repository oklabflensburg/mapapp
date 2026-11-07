import type { SaveUserNodeInput } from '../stores/userNodes'
import type { AspectRow } from '../lib/crossSection'

export interface NodeCreationState {
  count: number
  parentNames: string[]
  saving: boolean
  failed: boolean
}

export type NodeCreationInput = Pick<SaveUserNodeInput, 'tags' | 'translationLocale' | 'translations'> & {
  name: string
  // Cross-section draft for the relation that two or more placed nodes
  // become; it is saved together with the relation as structured tags.
  crossSection: AspectRow[]
}
