// Deterministic browser check; requires npm run dev. API, session, and OSM
// fixtures are intercepted so this check never writes to the development
// database. Verifies: relation mode collects clicked nodes, requires two
// members, toggles members, previews the pending part as a dashed line,
// creates the relation with its corridor area rendered on the map, opens its
// tag editor, and shows the relation with its tags and area when a member
// node is selected.
import assert from 'node:assert/strict'
import { chromium, expect } from '@playwright/test'

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1280, height: 800 } })
const center = { lat: 54.791, lng: 9.437 }
const points = [
  { lat: center.lat, lng: center.lng },
  { lat: center.lat + 0.0005, lng: center.lng + 0.001 },
]
const fixture = {
  type: 'FeatureCollection',
  features: points.map((point, index) => ({
    type: 'Feature',
    id: `n-fixture-${index}`,
    properties: { name: `Fixture node ${index}`, highway: 'crossing' },
    geometry: { type: 'Point', coordinates: [point.lng, point.lat] },
  })),
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

// Inverse of pixel(), for building fixture geometries at known screen spots.
function unproject(x, y) {
  const scale = 256 * 2 ** 14
  const project = (lat, lng) => {
    const sine = Math.sin(lat * Math.PI / 180)
    return [(lng + 180) / 360 * scale, (0.5 - Math.log((1 + sine) / (1 - sine)) / (4 * Math.PI)) * scale]
  }
  const origin = project(center.lat, center.lng)
  const lng = (x - 640 + origin[0]) / scale * 360 - 180
  const mercatorY = 0.5 - (y - 400 + origin[1]) / scale
  const lat = (2 * Math.atan(Math.exp(mercatorY * 2 * Math.PI)) - Math.PI / 2) * 180 / Math.PI
  return { lat, lng }
}

const groups = []
const members = []
const requests = []
let nextGroupId = 1
let nextNodeId = 1000

await page.route('**/data/flensburg.geojson', route => route.fulfill({ json: fixture }))
await page.route('**/api/me', route => route.fulfill({
  json: { user: { name: 'Check Editor', email: 'check-editor@example.com', roles: ['ROLE_EDITOR'] } },
}))
await page.route('**/api/nodes', route => route.fulfill({ json: [] }))
await page.route('**/api/node-groups', async route => {
  if (route.request().method() === 'GET') return route.fulfill({ json: groups })
  const input = route.request().postDataJSON()
  requests.push(input)
  let group = input.id != null ? groups.find(node => node.id === input.id) : undefined
  if (!group) {
    // Saved street parts carry the corridor polygon the backend buffers
    // around the member line; the fixture places it below the fixture
    // nodes (the areaBox pixels).
    group = {
      id: nextGroupId++, members: [], tags: {}, observations: {},
      area: { type: 'Polygon', coordinates: [areaBoxRing()] },
      createdAt: new Date().toISOString(), updatedAt: new Date().toISOString(),
    }
    groups.push(group)
  }
  for (const reference of input.members ?? []) {
    let node
    if (reference.id != null) {
      node = members.find(member => member.id === reference.id)
    } else {
      node = members.find(member => member.providerNodeId === reference.providerNodeId)
      if (!node) {
        node = { id: nextNodeId++, provider: 'OSM', name: null, tags: {}, ...reference }
        members.push(node)
      }
    }
    if (node && !group.members.some(member => member.id === node.id)) {
      group.members.push(node)
    }
  }
  if (input.tags != null) {
    group.tags = input.tags
    group.observations = input.tags
  }
  return route.fulfill({ status: input.id != null ? 200 : 201, json: group })
})

const relationToggle = page.getByRole('button', { name: 'Create a node relation', exact: true })
const create = page.getByRole('button', { name: 'Create relation', exact: true })
const a = pixel(points[0].lat, points[0].lng)
const b = pixel(points[1].lat, points[1].lng)

// The street part area fixture: a rectangle south of the fixture nodes whose
// border passes through known pixels, so its rendering is verifiable.
const areaBox = { x1: 635, y1: 425, x2: 655, y2: 435 }

function areaBoxRing() {
  const corners = [
    [areaBox.x1, areaBox.y1], [areaBox.x2, areaBox.y1],
    [areaBox.x2, areaBox.y2], [areaBox.x1, areaBox.y2], [areaBox.x1, areaBox.y1],
  ]
  return corners.map(([x, y]) => {
    const point = unproject(x, y)
    return [point.lng, point.lat]
  })
}

// The amber area stroke (#d97706) is drawn onto the shared overlay canvas, so
// the check samples the pixels around a page position for it.
async function amberAt(x, y) {
  return page.evaluate(({ x, y }) => {
    const canvas = document.querySelector('.leaflet-overlay-pane canvas')
    if (!canvas) {
      return false
    }
    const rect = canvas.getBoundingClientRect()
    const data = canvas.getContext('2d').getImageData(
      Math.max(0, Math.floor((x - rect.left - 9) * canvas.width / rect.width)),
      Math.max(0, Math.floor((y - rect.top - 9) * canvas.height / rect.height)),
      Math.ceil(19 * canvas.width / rect.width),
      Math.ceil(19 * canvas.height / rect.height),
    ).data
    for (let i = 0; i < data.length; i += 4) {
      const [r, g, bl, alpha] = [data[i], data[i + 1], data[i + 2], data[i + 3]]
      if (alpha > 150 && r > 150 && g > 80 && bl < 90 && r - bl > 90) {
        return true
      }
    }
    return false
  }, { x, y })
}

async function ready() {
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  await expect(relationToggle).toBeVisible()
}

async function closeEditor() {
  await page.locator('.p-drawer').getByRole('button', { name: 'Cancel', exact: true }).click()
  await expect(page.locator('.p-drawer')).toBeHidden()
}

try {
  await page.goto(process.env.APP_URL ?? 'http://localhost:5173')
  await ready()

  // Entering relation mode needs two members before the relation can be created.
  await relationToggle.click()
  await expect(relationToggle).toHaveAttribute('aria-pressed', 'true')
  await expect(create).toBeDisabled()
  await page.mouse.click(a.x, a.y)
  await expect(page.getByText('1 nodes selected')).toBeVisible()
  await expect(create).toBeDisabled()
  await page.mouse.click(b.x, b.y)
  await expect(page.getByText('2 nodes selected')).toBeVisible()
  await expect(create).toBeEnabled()

  // The collected members are connected by a dashed preview line.
  await expect.poll(() => amberAt((a.x + b.x) / 2, (a.y + b.y) / 2)).toBe(true)

  // Clicking a member again removes it from the pending relation.
  await page.mouse.click(a.x, a.y)
  await expect(page.getByText('1 nodes selected')).toBeVisible()
  await page.mouse.click(a.x, a.y)
  await expect(page.getByText('2 nodes selected')).toBeVisible()

  await create.click()
  await expect(page.locator('.p-drawer')).toBeVisible()
  // The saved street part renders its corridor area; the preview line
  // through the members is replaced by the area polygon.
  await expect.poll(() => amberAt((areaBox.x1 + areaBox.x2) / 2, areaBox.y1)).toBe(true)
  await expect.poll(() => amberAt((a.x + b.x) / 2, (a.y + b.y) / 2)).toBe(false)
  const created = requests.at(-1)
  assert.equal(created.members.length, 2, 'the relation contains both clicked nodes')
  // The first node was removed and re-added, so members keep the click order:
  // the second click comes first.
  assert.deepEqual(created.members[0], {
    providerNodeId: 'n-fixture-1',
    latitude: points[1].lat,
    longitude: points[1].lng,
  })

  // The new relation opens in its tag editor: tags save against its id.
  await page.getByRole('button', { name: 'Add observation', exact: true }).click()
  await page.getByPlaceholder('Key', { exact: true }).fill('surface')
  await page.getByPlaceholder('Value', { exact: true }).fill('cobblestone')
  await page.getByRole('button', { name: 'Save', exact: true }).click()
  await expect.poll(() => groups[0].tags.surface).toBe('cobblestone')
  assert.equal(requests.at(-1).id, groups[0].id, 'tag saves update the relation instead of creating one')
  await expect(page.getByRole('region', { name: 'Nodes', exact: true })).toContainText('n-fixture-0')
  await closeEditor()

  // Escape leaves relation mode without creating anything.
  await relationToggle.click()
  await expect(relationToggle).toHaveAttribute('aria-pressed', 'true')
  await page.keyboard.press('Escape')
  await expect(relationToggle).toHaveAttribute('aria-pressed', 'false')
  assert.equal(requests.length, 2, 'escape does not save')

  // After a reload the member node shows the relation and its tags, and the
  // street part's corridor area is drawn from the listed relations.
  await page.reload()
  await ready()
  await expect.poll(() => amberAt((areaBox.x1 + areaBox.x2) / 2, areaBox.y1)).toBe(true)
  await page.mouse.click(a.x, a.y)
  const nodeRelations = page.getByRole('region', { name: 'Node relations', exact: true })
  await expect(nodeRelations).toContainText('2 nodes')
  await expect(nodeRelations).toContainText('surface')
  await expect(nodeRelations).toContainText('cobblestone')

  // The edit button swaps the node editor for the relation editor; the node
  // drawer can still be leaving, so scope to the relation drawer.
  await nodeRelations.getByRole('button', { name: 'Edit relation', exact: true }).click()
  const relationDrawer = page.locator('.p-drawer').filter({ hasText: 'Edit node relation' })
  await expect(relationDrawer).toBeVisible()
  await expect(relationDrawer.getByRole('region', { name: 'Nodes', exact: true })).toContainText('n-fixture-1')
  await relationDrawer.getByRole('button', { name: 'Cancel', exact: true }).click()
  await expect(relationDrawer).toBeHidden()
  await page.screenshot({ path: '/tmp/map-node-relation.png' })
  console.log('PASS: relation mode, member collection, relation creation, area lines, relation tags, member selection')
} finally {
  await browser.close()
}
