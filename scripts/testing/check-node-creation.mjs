// Deterministic browser check; requires npm run dev. API, session, and OSM
// fixtures are intercepted so this check never writes to the development
// database. Verifies: edit mode places nodes one by one, the dialog shows
// the placed count, the name input and distinct per-node parents,
// Save commits — a single node becomes a named node, several become a node
// relation — Abort and Escape discard unsaved nodes, feature clicks keep the
// child-of-feature behavior, and failed saves retry without duplicating.
import assert from 'node:assert/strict'
import { chromium, expect } from '@playwright/test'

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1280, height: 800 } })
const center = { lat: 54.791, lng: 9.437 }
const fixture = {
  type: 'FeatureCollection',
  features: [
    { type: 'Feature', id: 'n-fixture', properties: { name: 'Fixture point', amenity: 'bench' }, geometry: { type: 'Point', coordinates: [center.lng, center.lat] } },
    { type: 'Feature', id: 'w-street-a', properties: { name: 'Street Alpha', highway: 'residential' }, geometry: { type: 'LineString', coordinates: [[9.42, 54.797], [9.45, 54.797]] } },
    { type: 'Feature', id: 'w-street-b', properties: { name: 'Street Beta', highway: 'residential' }, geometry: { type: 'LineString', coordinates: [[9.42, 54.793], [9.45, 54.793]] } },
    { type: 'Feature', id: 'w-fixture', properties: { name: 'Fixture building', building: 'yes' }, geometry: { type: 'Polygon', coordinates: [[[9.446, 54.790], [9.449, 54.790], [9.449, 54.792], [9.446, 54.792], [9.446, 54.790]]] } },
  ],
}

function pixel(lat, lng) {
  const project = (lat, lng) => {
    const scale = 256 * 2 ** 14
    const sine = Math.sin(lat * Math.PI / 180)
    return [(lng + 180) / 360 * scale, (0.5 - Math.log((1 + sine) / (1 - sine)) / (4 * Math.PI)) * scale]
  }
  const point = project(lat, lng)
  const origin = project(center.lat, center.lng)
  return { x: 640 + point[0] - origin[0], y: 400 + point[1] - origin[1] }
}

const labels = { name: 'Name' }
const values = {}
await page.route('**/api/i18n/tag-keys/*', route => route.fulfill({ json: { labels, values } }))
const nodes = []
const nodeRequests = []
const groups = []
const groupRequests = []
let failNext = false
let nextNodeId = 1
let nextGroupId = 1

const tagListValue = (tags, key) => (tags ?? []).find(tag => tag.key === key)?.value

function withRelations(node) {
  const reference = node.parent
  const parent = reference?.id != null ? nodes.find(n => n.id === reference.id) : reference?.providerNodeId ? {
    id: -1, provider: 'OSM', providerNodeId: reference.providerNodeId, tags: [],
  } : undefined
  return { ...node, parentIds: parent ? [parent.id] : [], parents: parent ? [{
    id: parent.id, provider: parent.provider, providerNodeId: parent.providerNodeId,
    name: tagListValue(parent.tags, 'name') ?? null, tags: parent.tags,
  }] : [], children: [] }
}

await page.route('**/data/flensburg.geojson', route => route.fulfill({ json: fixture }))
// Editing requires an editor session; the check never touches the backend.
await page.route('**/api/me', route => route.fulfill({
  json: { user: { name: 'Check Editor', email: 'check-editor@example.com', roles: ['ROLE_EDITOR'] } },
}))
await page.route('**/api/nodes', async route => {
  if (route.request().method() === 'GET') return route.fulfill({ json: nodes.map(withRelations) })
  const input = route.request().postDataJSON()
  nodeRequests.push(input)
  let node = nodes.find(n => n.provider === input.provider && n.providerNodeId === input.providerNodeId)
  if (!node) {
    node = { id: nextNodeId++, provider: 'local', parentIds: [], childIds: [], createdAt: new Date().toISOString(), updatedAt: new Date().toISOString(), ...input }
    nodes.push(node)
  }
  node.observationTranslations ??= []
  for (const translation of input.translations ?? []) {
    let entry = node.observationTranslations.find(
      candidate => candidate.key === translation.key && candidate.value === translation.value)
    if (!entry) {
      entry = { key: translation.key, value: translation.value, translations: {} }
      node.observationTranslations.push(entry)
    }
    entry.translations[input.translationLocale] = {
      keyTranslation: translation.keyTranslation, valueTranslation: translation.valueTranslation,
    }
  }
  node.tags = input.tags ?? []
  node.observations = input.tags ?? []
  // Simulate a lost response after the write has committed.
  if (failNext) {
    failNext = false
    return route.fulfill({ status: 503, json: { error: 'Simulated failure' } })
  }
  return route.fulfill({ status: 200, json: withRelations(node) })
})
await page.route('**/api/node-groups', async route => {
  if (route.request().method() === 'GET') return route.fulfill({ json: groups })
  const input = route.request().postDataJSON()
  groupRequests.push(input)
  let group = input.id != null ? groups.find(node => node.id === input.id) : undefined
  if (!group) {
    group = {
      id: nextGroupId++, name: input.name ?? null, members: [], tags: input.tags ?? [],
      observations: input.tags ?? [], createdAt: new Date().toISOString(), updatedAt: new Date().toISOString(),
    }
    groups.push(group)
  }
  group = { ...group, tags: input.tags ?? group.tags, observations: input.tags ?? group.observations, name: input.name ?? null }
  const groupIndex = groups.findIndex(entry => entry.id === group.id)
  if (groupIndex >= 0) {
    groups[groupIndex] = group
  }
  for (const reference of input.members ?? []) {
    const node = nodes.find(n => n.id === reference.id)
    if (node && !group.members.some(member => member.id === node.id)) {
      group.members.push({ id: node.id, provider: node.provider, providerNodeId: node.providerNodeId, name: tagListValue(node.tags, 'name') ?? null, tags: node.tags, latitude: node.latitude, longitude: node.longitude })
      const stored = groups[groups.findIndex(entry => entry.id === group.id)]
      stored.members = group.members
    }
  }
  group.parents = [...new Map(group.members.flatMap(member => withRelations(nodes.find(n => n.id === member.id)).parents).map(parent => [`${parent.provider}:${parent.providerNodeId}`, parent])).values()]
  return route.fulfill({ status: input.id != null ? 200 : 201, json: group })
})

const add = page.getByRole('button', { name: 'Add node', exact: true })
const dialog = page.getByRole('form', { name: 'Placed nodes', exact: true })
const save = page.locator('.p-drawer').getByRole('button', { name: 'Save', exact: true })
const abort = page.locator('.p-drawer').getByRole('button', { name: 'Abort', exact: true })

async function ready() {
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  await expect(add).toBeVisible()
}

async function closeEditor() {
  await page.locator('.p-drawer').getByRole('button', { name: 'Cancel', exact: true }).click()
  await expect(page.locator('.p-drawer')).toBeHidden()
}

try {
  await page.goto(process.env.APP_URL ?? 'http://localhost:5173')
  await ready()

  // Edit mode places nodes; nothing is sent yet.
  await add.click()
  await expect(add).toHaveAttribute('aria-pressed', 'true')
  await expect(dialog).toBeVisible()
  await expect(save).toBeDisabled()
  const bounds = await page.locator('.p-drawer').boundingBox()
  assert.ok(bounds.y >= 400 && bounds.height >= 350 && bounds.y + bounds.height <= 801, 'editor leaves the upper map clear')
  await page.mouse.click(400, 280)
  await expect(dialog).toBeVisible()
  // Placing the first node enables Save (no placed-count line anymore).
  await expect(save).toBeEnabled()
  // Without parents or a typed name the headline stays at its fallback.
  await expect(page.locator('.p-drawer-header')).toContainText('Add node')
  await page.mouse.click(430, 300)
  await expect(page.locator('.p-drawer-header')).toContainText('Add node')
  assert.equal(nodeRequests.length, 0, 'placed nodes are not saved yet')

  // Escape discards the placed nodes and leaves edit mode.
  await page.keyboard.press('Escape')
  await expect(add).toHaveAttribute('aria-pressed', 'false')
  await expect(dialog).toBeHidden()
  assert.equal(nodeRequests.length, 0, 'escape saves nothing')

  // Saving several nodes creates a named relation from them.
  await add.click()
  await page.mouse.click(400, 280)
  await page.mouse.click(430, 300)
  await dialog.getByLabel('Name', { exact: true }).fill('Hochallee north part')
  await save.click()
  await expect(dialog).toBeHidden()
  await expect(page.locator('.p-drawer')).toBeVisible()
  assert.equal(nodes.length, 2, 'both placed nodes are saved')
  assert.deepEqual(nodeRequests.map(request => request.parent), [undefined, undefined])
  assert.equal(groupRequests.length, 1)
  assert.equal(groupRequests[0].name, 'Hochallee north part')
  // Names are relation metadata, never a relation tag (see
  // specs/map-editing/observation-relations/spec.md R1).
  assert.equal(tagListValue(groupRequests[0].tags, 'name'), undefined)
  assert.deepEqual(groupRequests[0].members.map(member => member.id), nodes.map(node => node.id))
  await expect(page.locator('.p-drawer')).toContainText('Hochallee north part')
  // Each drafted observation requires both raw fields before a save can
  // be sent; the removed draft sends nothing. Incomplete drafts surface as
  // a save-attempt alert, not by disabling the button.
  await page.getByRole('button', { name: 'Add tag', exact: true }).click()
  const relationTagSave = page.locator('.p-drawer').getByRole('button', { name: 'Save', exact: true })
  await relationTagSave.click()
  await expect(page.getByRole('alert')).toContainText('Key and value required.')
  await page.getByPlaceholder('Key', { exact: true }).fill('surface')
  await page.getByPlaceholder('Value', { exact: true }).fill('   ')
  await relationTagSave.click()
  await expect(page.getByRole('alert')).toContainText('Key and value required.')
  assert.equal(groupRequests.length, 1, 'incomplete observations send no relation')
  await page.getByPlaceholder('Value', { exact: true }).fill('rough')
  await page.getByRole('button', { name: 'Remove tag', exact: true }).click()
  await closeEditor()

  // Abort discards the placed nodes; the mode ends without saving.
  await add.click()
  await page.mouse.click(400, 320)
  await abort.click()
  await expect(add).toHaveAttribute('aria-pressed', 'false')
  await expect(dialog).toBeHidden()
  assert.equal(nodes.length, 2, 'abort saves nothing')

  // A single placed node becomes a plain node; the name is its name tag.
  await add.click()
  const singlePoint = { x: 450, y: 340 }
  await page.mouse.click(singlePoint.x, singlePoint.y)
  await dialog.getByLabel('Name', { exact: true }).fill('Solo test node')
  await save.click()
  await expect(dialog).toBeHidden()
  await expect(page.locator('.p-drawer')).toBeVisible()
  const single = nodeRequests.at(-1)
  assert.deepEqual(single.tags, [{ key: 'name', value: 'Solo test node' }])
  assert.equal(single.parent, undefined)
  assert.equal(groupRequests.length, 1, 'a single node creates no relation')
  const projected = pixel(single.latitude, single.longitude)
  assert.ok(Math.abs(projected.x - singlePoint.x) < 1 && Math.abs(projected.y - singlePoint.y) < 1, 'marker clicks retain actual click coordinates')
  await expect(page.getByPlaceholder('Value', { exact: true })).toHaveValue('Solo test node')
  await expect(page.getByLabel('Key translation', { exact: true })).toHaveValue('Name')
  await page.getByRole('button', { name: 'Add tag', exact: true }).click()
  const observation = page.locator('.tag-row').last()
  const tagSave = page.locator('.p-drawer').getByRole('button', { name: 'Save', exact: true })
  const beforeIncomplete = nodeRequests.length
  // Incomplete drafts surface on a save attempt, not by disabling the button.
  await tagSave.click()
  await expect(page.getByRole('alert')).toContainText('Key and value required.')
  await observation.getByLabel('Key translation', { exact: true }).pressSequentially('Surface')
  await expect(observation.getByRole('textbox', { name: 'Key', exact: true })).toHaveValue('surface')
  await observation.getByLabel('Value translation', { exact: true }).pressSequentially('Rough surface')
  await expect(observation.getByRole('textbox', { name: 'Value', exact: true })).toHaveValue('rough_surface')
  await observation.getByLabel('Key translation', { exact: true }).fill('Surface condition')
  await expect(observation.getByRole('textbox', { name: 'Key', exact: true })).toHaveValue('surface_condition')
  // Manual overrides stop automatic updates, even before the first save.
  await observation.getByRole('textbox', { name: 'Key', exact: true }).fill('custom_surface')
  await observation.getByRole('textbox', { name: 'Value', exact: true }).fill('custom_rough')
  await observation.getByLabel('Key translation', { exact: true }).fill('Other surface')
  await observation.getByLabel('Value translation', { exact: true }).fill('Other roughness')
  await expect(observation.getByRole('textbox', { name: 'Key', exact: true })).toHaveValue('custom_surface')
  await expect(observation.getByRole('textbox', { name: 'Value', exact: true })).toHaveValue('custom_rough')
  // Emptying a raw field allows the translation to generate it again.
  await observation.getByRole('textbox', { name: 'Key', exact: true }).fill('')
  await observation.getByRole('textbox', { name: 'Value', exact: true }).fill('')
  await tagSave.click()
  await expect(page.getByRole('alert')).toContainText('Key and value required.')
  await observation.getByLabel('Key translation', { exact: true }).fill('Surface')
  await observation.getByLabel('Value translation', { exact: true }).fill('Rough surface')
  await tagSave.click()
  // The pending name stays node metadata; the observation became its own
  // point observation relation anchored on the node.
  await expect.poll(() => groupRequests.length).toBe(2, {timeout: 5000})
  const observationSave = groupRequests.at(-1)
  assert.deepEqual(observationSave.tags, [{ key: 'surface', value: 'rough_surface' }])
  assert.deepEqual(observationSave.members.map(member => member.id), [nodes.at(-1).id])
  assert.equal(observationSave.translationLocale, 'en')
  assert.deepEqual(observationSave.translations, [{
    key: 'surface', value: 'rough_surface', keyTranslation: 'Surface', valueTranslation: 'Rough surface',
    previousKeyTranslation: '', previousValueTranslation: '',
  }])
  await expect(tagSave).not.toHaveClass(/p-button-loading/)
  await expect(observation.getByRole('textbox', { name: 'Value', exact: true })).toHaveValue('rough_surface')
  await observation.getByRole('textbox', { name: 'Key', exact: true }).fill('test_surface')
  await observation.getByRole('textbox', { name: 'Value', exact: true }).fill('rough')
  await observation.getByRole('textbox', { name: 'Value', exact: true }).fill('   ')
  await tagSave.click()
  await expect(page.getByRole('alert')).toContainText('Key and value required.')
  await observation.getByRole('textbox', { name: 'Value', exact: true }).fill('rough')
  await page.getByRole('button', { name: 'Add tag', exact: true }).click()
  await tagSave.click()
  await expect(page.getByRole('alert')).toContainText('Key and value required.')
  await page.locator('.tag-row').last().getByRole('button', { name: 'Remove tag', exact: true }).click()
  const layout = await observation.evaluate(row => {
    const inputs = row.querySelectorAll('input')
    return [...inputs].map(input => ({ y: input.getBoundingClientRect().y, font: parseFloat(getComputedStyle(input).fontSize) }))
  })
  assert.ok(layout[1].y > layout[0].y && layout[3].y > layout[2].y, 'raw fields sit below translations')
  assert.ok(layout[1].font < layout[0].font && layout[3].font < layout[2].font, 'raw fields use smaller type')
  await observation.scrollIntoViewIfNeeded()
  await page.screenshot({ path: '/tmp/map-observation-translations.png' })
  const observationRelationId = groups.at(-1).id
  const beforeUpdateSave = groupRequests.length
  await tagSave.click()
  // Editing the observation saves its own relation exactly once.
  await expect.poll(() => groupRequests.length).toBe(beforeUpdateSave + 1)
  const observationUpdate = groupRequests.at(-1)
  assert.equal(observationUpdate.id, observationRelationId)
  assert.deepEqual(observationUpdate.tags, [{ key: 'test_surface', value: 'rough' }])
  await closeEditor()

  // Clicking a feature places a child of that feature; its parent name
  // shows in the headline breadcrumb.
  await add.click()
  await page.mouse.click(644, 400)
  await expect(page.locator('.p-drawer-header')).toContainText('Fixture point')
  await save.click()
  await expect(dialog).toBeHidden()
  await expect(page.locator('.p-drawer')).toBeVisible()
  assert.equal(nodeRequests.at(-1).parent.providerNodeId, 'n-fixture')
  await closeEditor()

  // A failed save keeps the placed nodes; retry re-sends without duplicating.
  failNext = true
  const batchStartGroupCount = groupRequests.length
  await add.click()
  await page.mouse.click(400, 300)
  await page.mouse.click(430, 320)
  await expect(save).toBeEnabled()
  await save.click()
  await expect(page.getByRole('alert')).toContainText('Could not save')
  const failed = nodeRequests.at(-1)
  const failedCount = nodeRequests.length
  await expect(add).toHaveAttribute('aria-pressed', 'true')
  await page.getByRole('button', { name: 'Retry', exact: true }).click()
  await expect(dialog).toBeHidden()
  // No observations and no name were drafted, so the batch stays bare
  // nodes without a relation and the editor closes.
  await expect(page.locator('.p-drawer')).toBeHidden()
  await expect.poll(() => nodeRequests.length, { message: 'retry re-sends the failed node and saves the remaining one' }).toBe(failedCount + 2)
  assert.deepEqual(nodeRequests[failedCount], failed, 'retry sends the same node')
  assert.equal(nodes.length, 6, 'retry does not duplicate the committed creation')
  assert.equal(groupRequests.length, batchStartGroupCount, 'a batch without observations creates no relations')
  nodes.length = 0
  groups.length = 0
  await page.reload()
  await ready()

  // Empty-map placements remain parentless; a later feature click keeps
  // its own OSM parent instead of inheriting the first custom point.
  await add.click()
  await page.mouse.click(350, 280)
  await page.mouse.click(380, 300)
  await page.mouse.click(644, 400)
  const customBatchStart = nodeRequests.length
  await save.click()
  await expect(dialog).toBeHidden()
  // A bare batch (no name, no observations) creates nothing to edit.
  await expect(page.locator('.p-drawer')).toBeHidden()
  const customBatch = nodeRequests.slice(customBatchStart)
  assert.deepEqual(customBatch.map(node => node.parent), [undefined, undefined, { providerNodeId: 'n-fixture', latitude: center.lat, longitude: center.lng }])

  nodes.length = 0
  groups.length = 0
  await page.reload()
  await ready()

  // Starting on an OSM feature does not assign that parent to later
  // placements on empty map space.
  await add.click()
  await page.mouse.click(644, 400)
  await page.mouse.click(400, 280)
  await page.mouse.click(430, 300)
  const osmBatchStart = nodeRequests.length
  await save.click()
  await expect(dialog).toBeHidden()
  await expect(page.locator('.p-drawer')).toBeHidden()
  const osmBatch = nodeRequests.slice(osmBatchStart)
  assert.equal(osmBatch.length, 3)
  assert.equal(osmBatch[0].parent.providerNodeId, 'n-fixture')
  assert.equal(osmBatch[1].parent, undefined)
  assert.equal(osmBatch[2].parent, undefined)
  await page.screenshot({ path: '/tmp/map-edit-mode.png' })
  // A relation spans two streets. Each point retains the street it hit;
  // repeated parents are displayed once, including after retry and reload.
  await add.click()
  const streetPoints = [[54.797, 9.43], [54.797, 9.44], [54.793, 9.43], [54.793, 9.44]]
  for (const [lat, lng] of streetPoints) {
    const point = pixel(lat, lng)
    await page.mouse.click(point.x, point.y)
  }
  await expect(save).toBeEnabled()
  // The headline breadcrumb shows the parent names, then the typed name.
  await expect(page.locator('.p-drawer-header')).toContainText('Street Alpha, Street Beta')
  await dialog.getByLabel('Name', { exact: true }).fill('Cross-street area')
  await expect(page.locator('.p-drawer-header')).toContainText('Street Alpha, Street Beta > Cross-street area')
  const crossStreetStart = nodeRequests.length
  failNext = true
  await save.click()
  await expect(page.getByRole('alert')).toContainText('Could not save')
  await expect(page.locator('.p-drawer-header')).toContainText('Street Alpha, Street Beta > Cross-street area')
  await page.getByRole('button', { name: 'Retry', exact: true }).click()
  await expect(dialog).toBeHidden()
  const crossStreetRequests = nodeRequests.slice(crossStreetStart)
  assert.deepEqual(crossStreetRequests[1], crossStreetRequests[0], 'retry retains the original parent')
  assert.deepEqual(crossStreetRequests.slice(1).map(node => node.parent.providerNodeId), ['w-street-a', 'w-street-a', 'w-street-b', 'w-street-b'])
  // The relation's parents sit in the headline breadcrumb, not a section.
  await expect(page.locator('.p-drawer-header')).toContainText('Street Alpha, Street Beta')
  const crossStreetGroup = groups.at(-1)
  await closeEditor()
  await page.reload()
  await ready()
  // Clicking a relation member/following its relation uses the saved API
  // data: the relation editor opens with its members' parents.
  const memberPoint = pixel(streetPoints[0][0], streetPoints[0][1])
  await page.mouse.click(memberPoint.x, memberPoint.y)
  await expect(page.locator('.p-drawer')).toHaveCount(1)
  await expect(page.locator('.p-drawer-header')).toContainText('Street Alpha, Street Beta')
  assert.equal(crossStreetGroup.members.length, 4)
  await closeEditor()

  // A relation placed on a single OSM way always adopts the way's
  // direction: with the placements clicked in reverse (east first), the
  // created relation still runs west to east like Street Alpha. The points
  // sit on Street Alpha between the cross-street member markers.
  await add.click()
  const reversedStreetPoints = [[54.797, 9.4335], [54.797, 9.425]]
  for (const [lat, lng] of reversedStreetPoints) {
    const point = pixel(lat, lng)
    await page.mouse.click(point.x, point.y)
  }
  await dialog.getByLabel('Name', { exact: true }).fill('Alpha direction part')
  const reversedStart = nodeRequests.length
  await save.click()
  await expect(dialog).toBeHidden()
  const reversedBatch = nodeRequests.slice(reversedStart)
  assert.deepEqual(reversedBatch.map(node => node.parent.providerNodeId), ['w-street-a', 'w-street-a'])
  const savedById = new Map(nodes.map(node => [node.providerNodeId, node.id]))
  assert.deepEqual(groupRequests.at(-1).members.map(member => member.id), [
    savedById.get(reversedBatch[1].providerNodeId),
    savedById.get(reversedBatch[0].providerNodeId),
  ])
  await closeEditor()
  nodes.length = 0
  groups.length = 0
  await page.reload()
  await ready()

  // Creation and editing share observations and preserve drafts as points
  // are placed. Check the bounded sheet and reachable actions on mobile.
  await page.setViewportSize({ width: 390, height: 844 })
  await page.reload()
  await ready()
  await add.click()
  await dialog.getByLabel('Name', { exact: true }).fill('Mobile node')
  await page.getByRole('button', { name: 'Add tag', exact: true }).click()
  const draft = page.locator('.tag-row').last()
  await draft.getByLabel('Key translation', { exact: true }).fill('Surface')
  await draft.getByLabel('Value translation', { exact: true }).fill('Smooth')
  await expect(save).toBeDisabled()
  await page.mouse.click(150, 250)
  await expect(dialog.getByLabel('Name', { exact: true })).toHaveValue('Mobile node')
  await expect(draft.getByLabel('Value translation', { exact: true })).toHaveValue('Smooth')
  await expect(save).toBeEnabled()
  const mobileBounds = await page.locator('.p-drawer').boundingBox()
  const saveBounds = await save.boundingBox()
  assert.ok(mobileBounds.y >= 422 && mobileBounds.height >= 370 && mobileBounds.width <= 390, 'mobile editor preserves upper map and fits viewport')
  assert.ok(saveBounds.y >= mobileBounds.y && saveBounds.y + saveBounds.height <= 844, 'save remains reachable while content scrolls')
  await draft.scrollIntoViewIfNeeded()
  await page.screenshot({ path: '/tmp/map-bottom-editor-mobile.png' })
  await save.click()
  await expect(dialog).toBeHidden()
  // The saved node's own name replaces the "Edit node" fallback headline.
  await expect(page.locator('.p-drawer-header')).toContainText('Mobile node')
  // The name stays on the node; the observation is its own relation.
  assert.deepEqual(nodeRequests.at(-1).tags, [{ key: 'name', value: 'Mobile node' }])
  assert.deepEqual(groupRequests.at(-1).tags, [{ key: 'surface', value: 'smooth' }])
  assert.deepEqual(groupRequests.at(-1).members.map(member => member.id), [nodes.at(-1).id])
  assert.equal(groupRequests.at(-1).translations[0].valueTranslation, 'Smooth')
  await closeEditor()
  console.log('PASS: edit mode placement, pending dialog, relation save, single node save, observation translations and layout, abort, escape, per-node OSM/custom parents, cross-street relations, parent labels after reload, retry, mobile layout and creation drafts')
} finally {
  await browser.close()
}
