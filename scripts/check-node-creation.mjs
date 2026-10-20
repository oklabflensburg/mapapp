// Deterministic browser check; requires npm run dev. API and OSM fixtures
// are intercepted so this check never writes to the development database.
import assert from 'node:assert/strict'
import { chromium, expect } from '@playwright/test'

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1280, height: 800 } })
const nodes = []
const osmReferences = []
const requests = []
let failNext = false
let nextId = 1
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

await page.route('**/data/flensburg.geojson', route => route.fulfill({ json: fixture }))
function withRelations(node) {
  const summary = id => {
    const related = [...nodes, ...osmReferences].find(n => n.id === id)
    return { id, provider: related.provider, providerNodeId: related.providerNodeId, name: related.tags.name ?? null, tags: related.tags }
  }
  return { ...node, parents: node.parentIds.map(summary), children: node.childIds.map(summary) }
}

await page.route('**/api/nodes', async route => {
  if (route.request().method() === 'GET') return route.fulfill({ json: nodes.map(withRelations) })
  const input = route.request().postDataJSON()
  requests.push(input)
  let node = nodes.find(n => n.provider === input.provider && n.providerNodeId === input.providerNodeId)
  if (!node) {
    node = { id: nextId++, ...input, parentIds: [], childIds: [], createdAt: new Date().toISOString(), updatedAt: new Date().toISOString() }
    nodes.push(node)
  }
  node.tags = input.tags
  if (input.parent) {
    let parent = nodes.find(n => n.id === input.parent.id)
    if (!parent) {
      parent = osmReferences.find(n => n.providerNodeId === input.parent.providerNodeId)
      if (!parent) {
        parent = { id: nextId++, provider: 'OSM', ...input.parent, tags: {}, childIds: [] }
        osmReferences.push(parent)
      }
    }
    node.parentIds = [parent.id]
    if (!parent.childIds.includes(node.id)) parent.childIds.push(node.id)
  }
  // Simulate a lost response after the write has committed.
  if (failNext) {
    failNext = false
    return route.fulfill({ status: 503, json: { error: 'Simulated failure' } })
  }
  return route.fulfill({ status: 200, json: withRelations(node) })
})

const add = page.getByRole('button', { name: 'Add node', exact: true })
async function ready() {
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  await expect(add).toBeVisible()
}
async function createAt(point) {
  await add.click()
  await expect(add).toHaveAttribute('aria-pressed', 'true')
  const count = requests.length
  await page.mouse.click(point.x, point.y)
  await expect(page.locator('.p-drawer')).toBeVisible()
  assert.equal(requests.length, count + 1, 'one request per click')
  await expect(add).toHaveAttribute('aria-pressed', 'false')
  return requests.at(-1)
}
async function closeEditor() {
  await page.getByRole('button', { name: 'Cancel', exact: true }).click()
  await expect(page.locator('.p-drawer')).toBeHidden()
}

try {
  await page.goto(process.env.APP_URL ?? 'http://localhost:5173')
  await ready()
  await add.click()
  await page.keyboard.press('Escape')
  await expect(add).toHaveAttribute('aria-pressed', 'false')
  assert.equal(requests.length, 0)
  await add.click()
  await add.click()
  await expect(add).toHaveAttribute('aria-pressed', 'false')

  const standalonePoint = { x: 450, y: 550 }
  const standalone = await createAt(standalonePoint)
  assert.equal(standalone.provider, 'local')
  assert.equal(standalone.parent, undefined)
  await expect(page.getByRole('region', { name: 'Relations', exact: true })).toContainText('No relations')
  const projected = pixel(standalone.latitude, standalone.longitude)
  assert.ok(Math.abs(projected.x - standalonePoint.x) < 1 && Math.abs(projected.y - standalonePoint.y) < 1)
  await page.getByRole('button', { name: 'Add tag', exact: true }).click()
  await page.getByPlaceholder('Key', { exact: true }).fill('name')
  await page.getByPlaceholder('Value', { exact: true }).fill('Local test node')
  await page.getByRole('button', { name: 'Save', exact: true }).click()
  await expect.poll(() => nodes[0].tags.name).toBe('Local test node')
  assert.equal(requests.at(-1).provider, 'local')
  await closeEditor()

  const child = await createAt({ x: standalonePoint.x + 4, y: standalonePoint.y })
  assert.deepEqual(child.parent, { id: nodes[0].id })
  await expect(page.getByRole('region', { name: 'Relations', exact: true })).toContainText('Parents')
  await expect(page.getByRole('region', { name: 'Relations', exact: true })).toContainText('Local test node')
  await expect(page.getByRole('region', { name: 'Relations', exact: true })).toContainText(standalone.providerNodeId)
  await expect(page.getByRole('region', { name: 'Relations', exact: true }).getByRole('table', { name: 'Custom tags', exact: true })).toContainText('Local test node')
  await expect(page.getByRole('region', { name: 'Relations', exact: true }).getByRole('textbox')).toHaveCount(0)
  assert.ok(Math.abs(pixel(child.latitude, child.longitude).x - standalonePoint.x - 4) < 1, 'marker clicks retain actual click coordinates')
  await closeEditor()

  const osmChild = await createAt({ x: 644, y: 400 })
  assert.equal(osmChild.parent.providerNodeId, 'n-fixture')
  await expect(page.getByRole('region', { name: 'Relations', exact: true })).toContainText('n-fixture')
  await expect(page.getByRole('region', { name: 'Relations', exact: true })).toContainText('OSM')
  await expect(page.getByRole('region', { name: 'Relations', exact: true }).getByRole('table', { name: 'OSM tags', exact: true })).toContainText('bench')
  await page.getByRole('button', { name: 'Save', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Save', exact: true })).toBeEnabled()
  await expect(page.getByRole('region', { name: 'Relations', exact: true })).toContainText('n-fixture')
  assert.ok(Math.abs(pixel(osmChild.latitude, osmChild.longitude).x - 644) < 1)
  await closeEditor()
  const buildingChild = await createAt(pixel(54.791, 9.4475))
  assert.equal(buildingChild.parent.providerNodeId, 'w-fixture')
  await expect(page.getByRole('region', { name: 'Relations', exact: true })).toContainText('w-fixture')
  await expect(page.getByRole('region', { name: 'Relations', exact: true }).getByRole('table', { name: 'OSM tags', exact: true })).toContainText('Fixture building')
  await expect(page.getByRole('region', { name: 'Relations', exact: true }).getByRole('table', { name: 'OSM tags', exact: true })).toContainText('building')
  await closeEditor()

  failNext = true
  await add.click()
  await page.mouse.click(400, 600)
  await expect(page.getByRole('alert')).toContainText('Could not add')
  await expect(add).toHaveAttribute('aria-pressed', 'true')
  const failed = requests.at(-1)
  const count = nodes.length
  await page.getByRole('button', { name: 'Retry', exact: true }).click()
  await expect(page.locator('.p-drawer')).toBeVisible()
  assert.deepEqual(requests.at(-1), failed)
  assert.equal(nodes.length, count, 'retry does not duplicate a committed creation')
  await page.getByRole('button', { name: 'Add tag', exact: true }).click()
  await page.getByPlaceholder('Key', { exact: true }).fill('name')
  await page.getByPlaceholder('Value', { exact: true }).fill('Reload test node')
  await page.getByRole('button', { name: 'Save', exact: true }).click()
  await expect.poll(() => nodes.at(-1).tags.name).toBe('Reload test node')
  await closeEditor()

  await page.reload()
  await ready()
  await page.mouse.click(400, 600)
  await expect(page.getByPlaceholder('Value', { exact: true })).toHaveValue('Reload test node')
  await closeEditor()
  // Both directions and multiple links must survive a reload. OSM reference
  // summaries remain available even though references are absent from GET's list.
  const childNode = nodes.find(n => n.providerNodeId === child.providerNodeId)
  childNode.parentIds.push(nodes.at(-1).id)
  nodes.at(-1).childIds.push(childNode.id)
  await page.reload()
  await ready()
  await page.mouse.click(454, 550)
  const relations = page.getByRole('region', { name: 'Relations', exact: true })
  await expect(relations).toContainText('Local test node')
  await expect(relations).toContainText('Reload test node')
  await expect(relations.getByRole('listitem')).toHaveCount(2)
  await page.screenshot({ path: '/tmp/map-node-relations.png' })
  await closeEditor()
  await page.mouse.click(400, 600)
  await page.getByPlaceholder('Value', { exact: true }).fill('Renamed parent')
  await page.getByRole('button', { name: 'Save', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Save', exact: true })).toBeEnabled()
  await closeEditor()
  await page.mouse.click(454, 550)
  await expect(relations).toContainText('Renamed parent')
  await expect(relations).not.toContainText('Reload test node')
  await expect(relations.getByRole('table', { name: 'Custom tags', exact: true }).filter({ hasText: 'Renamed parent' })).toHaveCount(1)
  await closeEditor()
  await page.mouse.click(435, 550)
  await expect(relations).toContainText('Children')
  await expect(relations).toContainText(child.providerNodeId)
  await expect(relations.getByRole('table')).toHaveCount(0)
  await closeEditor()
  const osmParent = osmReferences.find(n => n.providerNodeId === 'n-fixture')
  osmParent.tags = { amenity: 'custom-bench', note: 'Saved parent tag' }
  await page.reload()
  await ready()
  await page.mouse.click(644, 400)
  await expect(relations).toContainText('n-fixture')
  await expect(relations.getByRole('table', { name: 'OSM tags', exact: true })).toContainText('bench')
  await expect(relations.getByRole('table', { name: 'Custom tags', exact: true })).toContainText('custom-bench')
  await expect(relations.getByRole('table', { name: 'OSM tags', exact: true })).not.toContainText('custom-bench')
  await expect(relations.getByRole('table', { name: 'Custom tags', exact: true })).toContainText('Saved parent tag')
  await page.screenshot({ path: '/tmp/map-parent-tags.png' })
  await closeEditor()
  await createAt({ x: 400, y: 650 })
  await closeEditor()
  await createAt({ x: 404, y: 650 })
  await expect(relations).toContainText('No tags')
  await closeEditor()
  await page.route('**/data/flensburg.geojson', route => route.fulfill({ status: 503, body: 'Unavailable' }))
  await page.reload()
  await ready()
  await page.mouse.click(644, 400)
  await expect(relations).toContainText('Parent OSM tags are unavailable.')
  await expect(relations).toContainText('Saved parent tag')
  await expect(relations).not.toContainText('No tags')
  await closeEditor()
  await add.click()
  await page.screenshot({ path: '/tmp/map-create-mode.png' })
  console.log('PASS: creation, relations, read-only parent OSM/custom tags, buildings, tag updates, reload, unavailable OSM data')
} finally {
  await browser.close()
}
