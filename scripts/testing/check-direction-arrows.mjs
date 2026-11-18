// Deterministic browser check; requires npm run dev (set APP_URL to override
// the default http://localhost:5173). API, session, and OSM fixtures are
// intercepted so this check never writes to the development database.
// Verifies the way-direction spec (specs/map/way-direction/spec.md):
// a way stroke carries a direction arrowhead pointing from its first to its
// last coordinate (and reverses with the geometry, AC1), long lines carry
// several arrowheads (AC5), a closed-way loop keeps arrowheads around the
// loop (AC2), a saved relation's line carries an arrowhead in member order
// and the dashed edit-mode preview gets live arrowheads (AC3), and clicking
// an arrowhead still selects the way underneath (AC5-non-interference).
import assert from 'node:assert/strict'
import { chromium, expect } from '@playwright/test'

const APP_URL = process.env.APP_URL ?? 'http://localhost:5173'
// Must match DEFAULT_CENTER / DEFAULT_ZOOM in src/map/mapConfig.ts.
const CENTER = { lat: 54.791, lon: 9.437 }
const ZOOM = 14
// Viewport center in screen pixels; the map keeps DEFAULT_CENTER centered.
const VIEW_CENTER = { x: 640, y: 400 }

// Web Mercator projection to world pixels, matching Leaflet's CRS.
function project(lon, lat, zoom) {
  const scale = 256 * 2 ** zoom
  const x = ((lon + 180) / 360) * scale
  const sinLat = Math.sin((lat * Math.PI) / 180)
  const y = (0.5 - Math.log((1 + sinLat) / (1 - sinLat)) / (4 * Math.PI)) * scale
  return [x, y]
}

// A geojson coordinate as a screen pixel position at the default view.
function screenPos(lon, lat) {
  const [x, y] = project(lon, lat, ZOOM)
  const [cx, cy] = project(CENTER.lon, CENTER.lat, ZOOM)
  return { x: VIEW_CENTER.x + x - cx, y: VIEW_CENTER.y + y - cy }
}

// The fixture's way sample points, placed to land in the viewport at the
// default view (zoom 14 is ~11651 px per degree longitude and ~20200 px per
// degree latitude there). The busy south of the screen is covered by the loop
// and the long street; all fixture lines stay clear of each other's arrow
// sample strips.
const STREET = { name: 'Hammer Weg', lat: 54.7895, lonWest: 9.42919, lonEast: 9.44481 }
const LONG_STREET = { lat: 54.77219, lonWest: 9.38776, lonEast: 9.48624 }
const LOOP = { lonWest: 9.39353, lonEast: 9.44503, latTop: 54.78704, latBottom: 54.77466 }
const RELATION = { lat: 54.80011, lonWest: 9.4326, lonEast: 9.4414 }

// The relation-mode preview is placed roughly north-west of the screen center
// where no fixture renders.
const PREVIEW = { xA: 400, xB: 500, y: 280 }

let reverseStreet = false

const fixture = () => ({
  type: 'FeatureCollection',
  features: [
    {
      type: 'Feature',
      id: 'w-street',
      properties: { name: STREET.name, highway: 'residential' },
      geometry: {
        type: 'LineString',
        coordinates: reverseStreet
          ? [[STREET.lonEast, STREET.lat], [STREET.lonWest, STREET.lat]]
          : [[STREET.lonWest, STREET.lat], [STREET.lonEast, STREET.lat]],
      },
    },
    {
      type: 'Feature',
      id: 'w-long-street',
      properties: { highway: 'residential' },
      geometry: { type: 'LineString', coordinates: [[LONG_STREET.lonWest, LONG_STREET.lat], [LONG_STREET.lonEast, LONG_STREET.lat]] },
    },
    {
      type: 'Feature',
      id: 'w-roundabout',
      properties: { highway: 'residential' },
      geometry: {
        type: 'LineString',
        coordinates: [
          [LOOP.lonWest, LOOP.latTop], [LOOP.lonEast, LOOP.latTop],
          [LOOP.lonEast, LOOP.latBottom], [LOOP.lonWest, LOOP.latBottom],
          [LOOP.lonWest, LOOP.latTop],
        ],
      },
    },
  ],
})

const groups = [{
  id: 1,
  name: 'Arrow Relation',
  approved: true,
  members: [
    { id: 101, provider: 'OSM', providerNodeId: 'n-west', name: null, tags: [], latitude: RELATION.lat, longitude: RELATION.lonWest },
    { id: 102, provider: 'OSM', providerNodeId: 'n-east', name: null, tags: [], latitude: RELATION.lat, longitude: RELATION.lonEast },
  ],
  area: null,
  tags: [],
  observations: [],
  createdAt: new Date().toISOString(),
  updatedAt: new Date().toISOString(),
}]

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1280, height: 800 } })

await page.route('**/data/flensburg.geojson', route => route.fulfill({ json: fixture() }))
// Editing requires an editor session; the check never touches the backend.
await page.route('**/api/me', route => route.fulfill({
  json: { user: { name: 'Check Editor', email: 'check-editor@example.com', roles: ['ROLE_EDITOR'] } },
}))
await page.route('**/api/nodes', route => route.fulfill({ json: [] }))
await page.route('**/api/node-groups', route => route.fulfill({ json: groups }))

// Samples the overlay canvas around a position for arrowhead colors. The
// canvas holds only what the app drew (tiles are a separate pane), so the
// stroke and arrow colors are exact: gray ways #888888, relation green
// #2e7d32, pending/preview amber #d97706.
async function hasColor(x, y, kind, radius = 1) {
  return page.evaluate(({ x, y, kind, radius }) => {
    const canvas = document.querySelector('.leaflet-overlay-pane canvas')
    if (!canvas) {
      return false
    }
    const rect = canvas.getBoundingClientRect()
    const scale = canvas.width / rect.width
    const x0 = Math.max(0, Math.floor((x - rect.left - radius) * scale))
    const y0 = Math.max(0, Math.floor((y - rect.top - radius) * scale))
    const size = Math.ceil((radius * 2 + 1) * scale)
    const data = canvas.getContext('2d').getImageData(x0, y0, size, size).data
    const matches = (r, g, b, a) => {
      if (a < 90) {
        return false
      }
      if (kind === 'gray') {
        return Math.abs(r - 136) < 30 && Math.abs(g - 136) < 30 && Math.abs(b - 136) < 30
      }
      if (kind === 'green') {
        return r < 100 && g > 100 && b < 75 && g - b > 60
      }
      return r > 150 && g > 80 && b < 90 && r - b > 90
    }
    for (let i = 0; i < data.length; i += 4) {
      if (matches(data[i], data[i + 1], data[i + 2], data[i + 3])) {
        return true
      }
    }
    return false
  }, { x, y, kind, radius })
}

// Counts clusters of arrowhead-colored columns in a horizontal band, or rows
// in a vertical band. Long lines carry several arrowheads, so the band must
// contain several separated clusters (one per arrowhead's off-line wing).
async function countClusters({ horizontal, from, to, fixed, kind }) {
  return page.evaluate(({ horizontal, from, to, fixed, kind }) => {
    const canvas = document.querySelector('.leaflet-overlay-pane canvas')
    if (!canvas) {
      return 0
    }
    const rect = canvas.getBoundingClientRect()
    const scale = canvas.width / rect.width
    const a0 = Math.floor((from - rect[horizontal ? 'left' : 'top']) * scale)
    const b0 = Math.floor((fixed - 1 - rect[horizontal ? 'top' : 'left']) * scale)
    const length = Math.ceil((to - from + 1) * scale)
    const size = 3
    // getImageData bounds are clamped; short bands still sample correctly.
    const data = canvas.getContext('2d').getImageData(
      Math.max(0, horizontal ? a0 : b0), Math.max(0, horizontal ? b0 : a0),
      horizontal ? length : size, horizontal ? size : length,
    ).data
    const rowMatches = []
    for (let step = 0; step < length; step++) {
      let match = false
      for (let k = 0; k < size; k++) {
        const i = (horizontal ? k * length + step : step * size + k) * 4
        const [r, g, b, a] = [data[i], data[i + 1], data[i + 2], data[i + 3]]
        const colored = a >= 90 && (
          (kind === 'gray' && Math.abs(r - 136) < 30 && Math.abs(g - 136) < 30 && Math.abs(b - 136) < 30) ||
          (kind === 'green' && r < 100 && g > 100 && b < 75 && g - b > 60)
        )
        if (colored) {
          match = true
          break
        }
      }
      rowMatches.push(match)
    }
    let clusters = 0
    let gap = Infinity
    for (const match of rowMatches) {
      if (match) {
        if (gap >= 40) {
          clusters += 1
        }
        gap = 0
      } else {
        gap += 1
      }
    }
    return clusters
  }, { horizontal, from, to, fixed, kind })
}

// A chevron opens behind its center: the wing back vertices sit 4.5px off
// the line BEHIND the arrowhead's center, while the tip only touches the
// line AHEAD of it (see drawDirectionArrows, chevron half size 4.5px). So a
// block of arrowhead pixels 4.5px off the line exists only on the direction
// of travel's back side for a horizontal line (east = west of the center).
// The opposite side stays empty there (verified against the rendered pixel
// map: only the tip's approach, closer to the line, reaches it).
function arrowSample(midX, lineY, east) {
  return east ? { x: midX - 4.5, y: lineY - 4.5 } : { x: midX + 4.5, y: lineY - 4.5 }
}

function emptySideSample(midX, lineY, east) {
  return east ? { x: midX + 6, y: lineY - 5 } : { x: midX - 6, y: lineY - 5 }
}

async function assertHorizontalArrowDirection(midX, lineY, kind, expectEast) {
  const back = arrowSample(midX, lineY, expectEast)
  const empty = emptySideSample(midX, lineY, expectEast)
  await expect.poll(() => hasColor(back.x, back.y, kind)).toBe(true)
  const opposite = await hasColor(empty.x, empty.y, kind)
  assert.equal(opposite, false, `no arrowhead color ahead of the arrowhead (line y=${lineY})`)
}

const streetWest = screenPos(STREET.lonWest, STREET.lat)
const relationWest = screenPos(RELATION.lonWest, RELATION.lat)

try {
  await page.goto(APP_URL)
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  const add = page.getByRole('button', { name: 'Add node', exact: true })
  await expect(add).toBeVisible()

  // AC1: the street's single arrowhead (one per short line) points east,
  // following the coordinate order west -> east.
  const streetMid = (streetWest.x + screenPos(STREET.lonEast, STREET.lat).x) / 2
  await assertHorizontalArrowDirection(streetMid, streetWest.y, 'gray', true)

  // AC5: the long street carries several separated arrowheads.
  const longWest = screenPos(LONG_STREET.lonWest, LONG_STREET.lat)
  const longEast = screenPos(LONG_STREET.lonEast, LONG_STREET.lat)
  await expect.poll(() =>
    countClusters({ horizontal: true, from: longWest.x + 40, to: longEast.x - 40, fixed: longWest.y - 3.5, kind: 'gray' }),
  ).toBeGreaterThanOrEqual(3)

  // AC2: the closed-way loop keeps arrowheads around its course: above the
  // top edge and beside the right edge of the loop.
  const loopWest = screenPos(LOOP.lonWest, LOOP.latTop)
  const loopEast = screenPos(LOOP.lonEast, LOOP.latTop)
  const loopSouth = screenPos(LOOP.lonEast, LOOP.latBottom)
  await expect.poll(() =>
    countClusters({ horizontal: true, from: loopWest.x + 40, to: loopEast.x - 40, fixed: loopWest.y - 3.5, kind: 'gray' }),
  ).toBeGreaterThanOrEqual(2)
  await expect.poll(() =>
    countClusters({ horizontal: false, from: loopEast.y + 40, to: loopSouth.y - 40, fixed: loopEast.x + 3.5, kind: 'gray' }),
  ).toBeGreaterThanOrEqual(2)

  // R3: the saved relation's line carries an arrowhead in member order
  // (members listed west -> east).
  const relationEast = screenPos(RELATION.lonEast, RELATION.lat)
  await assertHorizontalArrowDirection(
    (relationWest.x + relationEast.x) / 2, relationWest.y, 'green', true,
  )

  // Non-interference (R5): the click lands on the street arrowhead's
  // off-line wing, within the stroke's hit tolerance; the arrowhead must
  // not intercept it.
  const wing = arrowSample(streetMid, streetWest.y, true)
  await page.mouse.click(wing.x, wing.y)
  const drawer = page.locator('.p-drawer').filter({ hasText: STREET.name })
  await expect(drawer).toBeVisible()
  await drawer.getByRole('button', { name: 'Cancel', exact: true }).click()
  await expect(page.locator('.p-drawer').last()).toBeHidden()

  // AC1 (reversal): a reload with the geometry reversed flips the arrowhead.
  reverseStreet = true
  await page.reload()
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  await assertHorizontalArrowDirection(streetMid, streetWest.y, 'gray', false)

  // R3 (preview): while collecting a relation, the dashed preview line gets
  // live arrowheads in collection order.
  await add.click()
  await expect(add).toHaveAttribute('aria-pressed', 'true')
  await page.mouse.click(PREVIEW.xA, PREVIEW.y)
  await page.mouse.click(PREVIEW.xB, PREVIEW.y)
  await assertHorizontalArrowDirection((PREVIEW.xA + PREVIEW.xB) / 2, PREVIEW.y, 'amber', true)

  // R6: the way-direction arrows are configurable on the profile page.
  // Turning them off redraws the map with bare lines (arrows are off, the
  // strokes themselves keep rendering); toggling back on restores them.
  const arrowSwitch = () => page.locator('#setting-way-direction')
  await page.goto(`${APP_URL}/profile`)
  await expect(arrowSwitch()).toBeVisible()
  await arrowSwitch().click()
  await expect(arrowSwitch()).toHaveAttribute('data-p-checked', 'false')
  await page.goto(APP_URL)
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  // The street stroke and the relation line still render after the reload.
  await expect.poll(() => hasColor(streetMid, streetWest.y, 'gray')).toBe(true)
  await expect.poll(() => hasColor(relationWest.x, relationWest.y, 'green')).toBe(true)
  // Their arrowheads are gone (the way is still west-directed from the
  // reversal phase, so its back vertex is east of the center).
  const hiddenBack = arrowSample(streetMid, streetWest.y, false)
  const relationMid = (relationWest.x + screenPos(RELATION.lonEast, RELATION.lat).x) / 2
  const hiddenRelationBack = arrowSample(relationMid, relationWest.y, true)
  assert.equal(await hasColor(hiddenBack.x, hiddenBack.y, 'gray'), false, 'way arrowheads hidden when turned off')
  assert.equal(await hasColor(hiddenRelationBack.x, hiddenRelationBack.y, 'green'), false, 'relation arrowheads hidden when turned off')
  const loopTopEmpty = await countClusters({ horizontal: true, from: loopWest.x + 40, to: loopEast.x - 40, fixed: loopWest.y - 3.5, kind: 'gray' })
  assert.equal(loopTopEmpty, 0, 'closed-loop arrowheads hidden when turned off')

  // Toggling back on restores the arrows.
  await page.goto(`${APP_URL}/profile`)
  await arrowSwitch().click()
  await expect(arrowSwitch()).toHaveAttribute('data-p-checked', 'true')
  await page.goto(APP_URL)
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  await assertHorizontalArrowDirection(streetMid, streetWest.y, 'gray', false)

  console.log('PASS: way and relation arrowheads point along coordinate/member order, reverse with the geometry, repeat on long lines, follow closed loops, keep clicks passing through, render on the live relation preview, and hide when the profile setting turns them off')
} finally {
  await browser.close()
}
