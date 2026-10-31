// Requires the dev server. Fixtures reproduce the API's newest-update-first
// ordering without writing to the development database.
import assert from 'node:assert/strict'
import { chromium, expect } from '@playwright/test'

const browser = await chromium.launch()
const appUrl = process.env.APP_URL ?? 'http://localhost:5173'
const childName = 'Created child'

function node(id, name, createdAt) {
  return {
    id, provider: 'local', providerNodeId: `local-${id}`,
    latitude: 54.791, longitude: 9.437, tags: { name }, observations: { name },
    parentIds: [], childIds: [], parents: [], children: [],
    createdAt, updatedAt: createdAt,
  }
}

function summary(node) {
  return { id: node.id, provider: node.provider, providerNodeId: node.providerNodeId, name: node.tags.name, tags: node.tags }
}

const building = {
  type: 'Feature', id: 'w-parent', properties: { building: 'yes', name: 'OSM parent' },
  geometry: { type: 'Polygon', coordinates: [[[9.427, 54.785], [9.447, 54.785], [9.447, 54.797], [9.427, 54.797], [9.427, 54.785]]] },
}

async function setup(nodes, features = []) {
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } })
  const requests = []
  await page.route('**/data/flensburg.geojson', route => route.fulfill({ json: { type: 'FeatureCollection', features } }))
  // Editing requires an editor session; node groups stay empty so the check
  // never depends on development database content.
  await page.route('**/api/me', route => route.fulfill({
    json: { user: { name: 'Check Editor', email: 'check-editor@example.com', roles: ['ROLE_EDITOR'] } },
  }))
  await page.route('**/api/node-groups', route => route.fulfill({ json: [] }))
  await page.route('**/api/nodes', async route => {
    if (route.request().method() === 'GET') return route.fulfill({ json: nodes })
    const input = route.request().postDataJSON()
    requests.push(input)
    const parent = nodes.find(n => n.id === input.parent?.id)
    const saved = { ...node(100, '', new Date().toISOString()), ...input,
      parentIds: parent ? [parent.id] : [], parents: parent ? [summary(parent)] : [] }
    nodes.unshift(saved)
    return route.fulfill({ status: 201, json: saved })
  })
  await page.goto(appUrl)
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  return { page, requests }
}

async function expectChild(page, x = 640, y = 400) {
  await page.mouse.click(x, y)
  await expect(page.getByPlaceholder('Value', { exact: true })).toHaveValue(childName)
}

async function closeEditor(page) {
  await page.getByRole('button', { name: 'Cancel', exact: true }).click()
  await expect(page.locator('.p-drawer')).toBeHidden()
}

try {
  for (const sameSecond of [false, true]) {
    const parent = node(sameSecond ? 1 : 20, 'Saved parent', '2026-10-20T10:00:00Z')
    const child = node(sameSecond ? 2 : 10, childName, sameSecond ? parent.createdAt : '2026-10-20T11:00:00Z')
    child.parentIds = [parent.id]
    child.parents = [summary(parent)]
    const { page, requests } = await setup([child, parent])
    await expectChild(page)
    await expect(page.getByRole('region', { name: 'Relations', exact: true })).toContainText('Saved parent')
    await closeEditor(page)
    // A parent edited after its child must not become the topmost marker.
    parent.tags = { name: 'Recently edited parent' }
    parent.updatedAt = '2026-10-20T12:00:00Z'
    child.parents = [summary(parent)]
    await page.reload()
    await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
    await expectChild(page)
    await closeEditor(page)
    // Edit mode places pending nodes; Save commits the batch. The click lands
    // on the child marker, so the placed node becomes its child.
    await page.getByRole('button', { name: 'Add node', exact: true }).click()
    await page.mouse.click(640, 400)
    await page.getByRole('button', { name: 'Save', exact: true }).click()
    await expect.poll(() => requests.length).toBe(1)
    assert.deepEqual(requests[0].parent, { id: child.id })
    await page.close()
  }

  const child = node(10, childName, '2026-10-20T11:00:00Z')
  const { page } = await setup([child], [building])
  async function highlightParent() {
    await page.mouse.click(700, 400)
    await expect(page.locator('.p-drawer')).toContainText('OSM parent')
    await expect(page.getByPlaceholder('Value', { exact: true })).toHaveCount(0)
  }
  await highlightParent()
  await expectChild(page)
  await closeEditor(page)
  await highlightParent()
  // Rerender while the parent is selected, then click the child again.
  await page.getByRole('button', { name: 'Zoom in', exact: true }).click()
  await expect(page.locator('.map')).not.toHaveClass(/leaflet-zoom-anim/)
  // moveend rebuilds the layers after the 150ms render debounce.
  await page.waitForTimeout(250)
  await expectChild(page)
  await closeEditor(page)
  await highlightParent()
  await page.mouse.move(500, 600)
  await page.mouse.down()
  await page.mouse.move(560, 620, { steps: 12 })
  await page.mouse.up()
  await expect(page.locator('.map')).not.toHaveClass(/leaflet-zoom-anim/)
  await page.waitForTimeout(250)
  await expectChild(page, 700, 420)
  await closeEditor(page)
  await page.reload()
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  await highlightParent()
  await expectChild(page)
  await page.close()
  console.log('PASS: overlapping child selection, creation-time ordering, ID tie-break, parent updates, highlighted OSM parent, zoom, pan, reload, create mode')
} finally {
  await browser.close()
}
