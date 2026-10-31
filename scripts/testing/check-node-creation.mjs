// Deterministic browser check; requires npm run dev. API, session, and OSM
// fixtures are intercepted so this check never writes to the development
// database. Verifies: edit mode places nodes one by one, the dialog shows
// the placed count, the name input and the parent node (the relation anchor),
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

const nodes = []
const nodeRequests = []
const groups = []
const groupRequests = []
let failNext = false
let nextNodeId = 1
let nextGroupId = 1

function withRelations(node) {
  return { ...node, parents: [], children: [] }
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
  node.tags = input.tags ?? {}
  node.observations = input.tags ?? {}
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
      id: nextGroupId++, name: input.name ?? null, members: [], tags: input.tags ?? {},
      observations: {}, createdAt: new Date().toISOString(), updatedAt: new Date().toISOString(),
    }
    groups.push(group)
  }
  for (const reference of input.members ?? []) {
    const node = nodes.find(n => n.id === reference.id)
    if (node && !group.members.some(member => member.id === node.id)) {
      group.members.push({ id: node.id, provider: node.provider, providerNodeId: node.providerNodeId, name: node.tags.name ?? null, tags: node.tags })
    }
  }
  return route.fulfill({ status: input.id != null ? 200 : 201, json: group })
})

const add = page.getByRole('button', { name: 'Add node', exact: true })
const dialog = page.getByRole('form', { name: 'Placed nodes', exact: true })
const save = dialog.getByRole('button', { name: 'Save', exact: true })
const abort = dialog.getByRole('button', { name: 'Abort', exact: true })

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
  await page.mouse.click(400, 550)
  await expect(dialog).toBeVisible()
  await expect(dialog).toContainText('1 nodes placed')
  await expect(dialog).toContainText('Node 1')
  await page.mouse.click(430, 570)
  await expect(dialog).toContainText('2 nodes placed')
  await expect(dialog).toContainText('Node 1')
  assert.equal(nodeRequests.length, 0, 'placed nodes are not saved yet')

  // Escape discards the placed nodes and leaves edit mode.
  await page.keyboard.press('Escape')
  await expect(add).toHaveAttribute('aria-pressed', 'false')
  await expect(dialog).toBeHidden()
  assert.equal(nodeRequests.length, 0, 'escape saves nothing')

  // Saving several nodes creates a named relation from them.
  await add.click()
  await page.mouse.click(400, 550)
  await page.mouse.click(430, 570)
  await dialog.getByLabel('Name', { exact: true }).fill('Hochallee north part')
  await save.click()
  await expect(page.locator('.p-drawer')).toBeVisible()
  assert.equal(nodes.length, 2, 'both placed nodes are saved')
  assert.deepEqual(nodeRequests.map(request => request.parent), [undefined, undefined])
  assert.equal(groupRequests.length, 1)
  assert.equal(groupRequests[0].name, 'Hochallee north part')
  assert.deepEqual(groupRequests[0].members.map(member => member.id), nodes.map(node => node.id))
  await expect(page.locator('.p-drawer')).toContainText('Hochallee north part')
  await closeEditor()

  // Abort discards the placed nodes; the mode ends without saving.
  await add.click()
  await page.mouse.click(400, 600)
  await abort.click()
  await expect(add).toHaveAttribute('aria-pressed', 'false')
  await expect(dialog).toBeHidden()
  assert.equal(nodes.length, 2, 'abort saves nothing')

  // A single placed node becomes a plain node; the name is its name tag.
  await add.click()
  const singlePoint = { x: 450, y: 650 }
  await page.mouse.click(singlePoint.x, singlePoint.y)
  await dialog.getByLabel('Name', { exact: true }).fill('Solo test node')
  await save.click()
  await expect(page.locator('.p-drawer')).toBeVisible()
  const single = nodeRequests.at(-1)
  assert.deepEqual(single.tags, { name: 'Solo test node' })
  assert.equal(single.parent, undefined)
  assert.equal(groupRequests.length, 1, 'a single node creates no relation')
  const projected = pixel(single.latitude, single.longitude)
  assert.ok(Math.abs(projected.x - singlePoint.x) < 1 && Math.abs(projected.y - singlePoint.y) < 1, 'marker clicks retain actual click coordinates')
  await expect(page.getByPlaceholder('Value', { exact: true })).toHaveValue('Solo test node')
  await closeEditor()

  // Clicking a feature places a child of that feature.
  await add.click()
  await page.mouse.click(644, 400)
  await expect(dialog).toContainText('Fixture point')
  await save.click()
  await expect(page.locator('.p-drawer')).toBeVisible()
  assert.equal(nodeRequests.at(-1).parent.providerNodeId, 'n-fixture')
  await closeEditor()

  // A failed save keeps the placed nodes; retry re-sends without duplicating.
  failNext = true
  await add.click()
  await page.mouse.click(400, 700)
  await page.mouse.click(430, 700)
  await save.click()
  await expect(page.getByRole('alert')).toContainText('Could not save')
  const failed = nodeRequests.at(-1)
  const failedCount = nodeRequests.length
  await expect(add).toHaveAttribute('aria-pressed', 'true')
  await page.getByRole('button', { name: 'Retry', exact: true }).click()
  await expect(page.locator('.p-drawer')).toBeVisible()
  assert.equal(nodeRequests.length, failedCount + 2, 'retry re-sends the failed node and saves the remaining one')
  assert.deepEqual(nodeRequests[failedCount], failed, 'retry sends the same node')
  assert.equal(nodes.length, 6, 'retry does not duplicate the committed creation')
  assert.equal(groupRequests.length, 2, 'the relation is created once on retry')
  await closeEditor()
  await page.screenshot({ path: '/tmp/map-edit-mode.png' })
  console.log('PASS: edit mode placement, pending dialog, relation save, single node save, abort, escape, feature parents, retry')
} finally {
  await browser.close()
}
