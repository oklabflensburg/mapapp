<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import InputText from 'primevue/inputtext'
import ParentNodeTags from './ParentNodeTags.vue'
import { nodeKey, useUserNodesStore, type NodeEditTarget } from '../stores/userNodes'
import { useAuthStore } from '../stores/auth'

interface TagRow {
  key: string
  value: string
}

const props = defineProps<{
  visible: boolean
  node: NodeEditTarget | null
}>()

const emit = defineEmits<{
  (event: 'update:visible', visible: boolean): void
}>()

const { t } = useI18n()
const userNodes = useUserNodesStore()
const auth = useAuthStore()

const rows = ref<TagRow[]>([])

const osmTagEntries = computed(() => Object.entries(props.node?.osmTags ?? {}))
const savedNode = computed(() => props.node ? userNodes.byIdentity.get(nodeKey(props.node)) : undefined)
const relationGroups = computed(() => [
  { label: 'nodeEditor.parents', nodes: savedNode.value?.parents ?? [] },
  { label: 'nodeEditor.children', nodes: savedNode.value?.children ?? [] },
])

watch(
  () => [props.visible, props.node] as const,
  ([visible, node]) => {
    if (!visible || !node) {
      return
    }
    const existing = userNodes.byIdentity.get(nodeKey(node))?.tags ?? {}
    rows.value = Object.entries(existing).map(([key, value]) => ({ key, value }))
  },
)

function addRow(): void {
  rows.value.push({ key: '', value: '' })
}

function removeRow(index: number): void {
  rows.value.splice(index, 1)
}

function close(): void {
  emit('update:visible', false)
}

async function save(): Promise<void> {
  if (!props.node) {
    return
  }
  const tags: Record<string, string> = {}
  for (const row of rows.value) {
    const key = row.key.trim()
    if (key !== '') {
      tags[key] = row.value
    }
  }
  // Saving keeps the panel open: it stays as long as the element is
  // selected, and only closing it deselects.
  await userNodes.save({
    provider: props.node.provider,
    providerNodeId: props.node.providerNodeId,
    latitude: props.node.latitude,
    longitude: props.node.longitude,
    tags,
  })
}
</script>

<template>
  <Drawer
    :visible="visible"
    position="right"
    :modal="false"
    :header="t('nodeEditor.title')"
    :style="{ width: '24rem', maxWidth: '100vw' }"
    @update:visible="emit('update:visible', $event)"
  >
    <section v-if="savedNode" class="relations-section" :aria-label="t('nodeEditor.relations')">
      <h4 class="section-title">{{ t('nodeEditor.relations') }}</h4>
      <p v-if="!savedNode.parents.length && !savedNode.children.length" class="empty-hint">
        {{ t('nodeEditor.noRelations') }}
      </p>
      <template v-for="group in relationGroups" :key="group.label">
        <div v-if="group.nodes.length" class="relation-group">
          <h5 class="relation-heading">{{ t(group.label) }}</h5>
          <ul class="relation-list">
            <li v-for="related in group.nodes" :key="related.id" class="relation-item">
              <span>{{ related.name || related.providerNodeId }}</span>
              <small>{{ related.provider }}<template v-if="related.name"> · {{ related.providerNodeId }}</template></small>
              <ParentNodeTags v-if="group.label === 'nodeEditor.parents'" :parent="related" />
            </li>
          </ul>
        </div>
      </template>
    </section>

    <section v-if="osmTagEntries.length > 0" class="osm-tags-section">
      <h4 class="section-title">{{ t('nodeEditor.osmTags') }}</h4>
      <table class="tag-table">
        <tr v-for="[key, value] in osmTagEntries" :key="key">
          <th>{{ key }}</th>
          <td>{{ value }}</td>
        </tr>
      </table>
    </section>

    <section>
      <h4 class="section-title">{{ t('nodeEditor.customTags') }}</h4>
      <p v-if="rows.length === 0 && auth.canEdit" class="empty-hint">{{ t('nodeEditor.empty') }}</p>
      <p v-if="!auth.canEdit" class="empty-hint">{{ t('nodeEditor.loginRequired') }}</p>
      <template v-if="auth.canEdit">
        <div v-for="(row, index) in rows" :key="index" class="tag-row">
          <InputText v-model="row.key" :placeholder="t('nodeEditor.key')" class="tag-key" />
          <InputText v-model="row.value" :placeholder="t('nodeEditor.value')" class="tag-value" />
          <Button
            :aria-label="t('nodeEditor.removeTag')"
            icon="pi pi-times"
            text
            severity="danger"
            @click="removeRow(index)"
          />
        </div>
        <Button
          :label="t('nodeEditor.addTag')"
          icon="pi pi-plus"
          text
          severity="secondary"
          @click="addRow"
        />
      </template>
      <template v-else>
        <table v-if="rows.length > 0" class="tag-table">
          <tr v-for="row in rows" :key="row.key">
            <th>{{ row.key }}</th>
            <td>{{ row.value }}</td>
          </tr>
        </table>
      </template>
      <p v-if="userNodes.saveFailed" class="save-error" role="alert">
        {{ t('nodeEditor.saveFailed') }}
      </p>
    </section>

    <template #footer>
      <Button :label="t('nodeEditor.cancel')" text severity="secondary" @click="close" />
      <Button
        v-if="auth.canEdit"
        :label="t('nodeEditor.save')"
        :loading="userNodes.saving"
        icon="pi pi-check"
        @click="save"
      />
    </template>
  </Drawer>
</template>

<style scoped>
.section-title {
  margin: 0 0 0.375rem;
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--p-text-muted-color);
}

.osm-tags-section,
.relations-section {
  margin-bottom: 0.75rem;
}

.relation-heading {
  margin: 0.5rem 0 0.25rem;
  font-size: 0.8rem;
  font-weight: 600;
}

.relation-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.relation-item {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  padding: 0.375rem 0;
  border-bottom: 1px solid var(--p-content-border-color);
  font-size: 0.875rem;
  overflow-wrap: anywhere;
}

.relation-item small {
  color: var(--p-text-muted-color);
}

.tag-table {
  border-collapse: collapse;
  max-height: 12rem;
  overflow-y: auto;
  display: block;
}

.tag-table th,
.tag-table td {
  text-align: left;
  padding: 0.1rem 0.4rem 0.1rem 0;
  font-size: 0.8rem;
  vertical-align: top;
  border-bottom: 1px solid var(--p-content-border-color);
}

.tag-table th {
  white-space: nowrap;
  font-weight: 600;
  color: var(--p-text-muted-color);
}

.empty-hint {
  margin: 0 0 0.375rem;
  font-size: 0.875rem;
  color: var(--p-text-muted-color);
}

.tag-row {
  display: flex;
  gap: 0.375rem;
  align-items: center;
  margin-bottom: 0.375rem;
}

.tag-key {
  flex: 1;
}

.tag-value {
  flex: 1.5;
}

.save-error {
  margin: 0.5rem 0 0;
  font-size: 0.875rem;
  color: var(--p-red-500);
}
</style>

<style>
/* The panel reflects the selection state instead of appearing transiently,
   so it shows and hides instantly rather than sliding. The classes are
   global because PrimeVue renders the drawer in a portal. */
.p-drawer-enter-active,
.p-drawer-leave-active {
  animation: none !important;
}
</style>
