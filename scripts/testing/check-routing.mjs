// Deterministic browser check for routing mode; requires npm run dev. The
// /api/route endpoint is intercepted, so this check never reaches the
// backend routing service.
import assert from 'node:assert/strict'
import { chromium, expect } from '@playwright/test'

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1280, height: 800 } })

const center = { lat: 54.791, lng: 9.437 }
const route = {
  distance: 1234,
  time: 900,
  points: [
    [54.791, 9.437],
    [54.7915, 9.438],
    [54.792, 9.439],
  ],
}
const requests = []
let failNext = false

await page.route('**/data/flensburg.geojson', route => route.fulfill({ json: { type: 'FeatureCollection', features: [] } }))
await page.route('**/api/nodes', route => route.fulfill({ json: [] }))
await page.route('**/api/route*', intercepted => {
  requests.push(new URL(intercepted.request().url()).searchParams)
  if (failNext) {
    failNext = false
    return intercepted.fulfill({ status: 422, json: { error: 'no_route' } })
  }
  return intercepted.fulfill({ json: route })
})

function project(lat, lng) {
  const scale = 256 * 2 ** 14
  const sine = Math.sin((lat * Math.PI) / 180)
  return [
    ((lng + 180) / 360) * scale,
    (0.5 - Math.log((1 + sine) / (1 - sine)) / (4 * Math.PI)) * scale,
  ]
}

// Inverse of project(): viewport pixel back to coordinates at zoom 14.
function unproject(x, y) {
  const scale = 256 * 2 ** 14
  const origin = project(center.lat, center.lng)
  const px = origin[0] + x - 640
  const py = origin[1] + y - 400
  const lng = (px / scale) * 360 - 180
  const n = Math.PI * (1 - (2 * py) / scale)
  const lat = (Math.atan(Math.sinh(n)) * 180) / Math.PI
  return { lat, lng }
}

const routingButton = page.getByRole('button', { name: 'Plan a route', exact: true })

try {
  await page.goto(process.env.APP_URL ?? 'http://localhost:5173')
  await expect(routingButton).toBeVisible()

  // Escape leaves routing mode before any point is placed.
  await routingButton.click()
  await expect(routingButton).toHaveAttribute('aria-pressed', 'true')
  await expect(page.getByRole('status').filter({ hasText: 'Click the map to set a start and an end point' })).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(routingButton).toHaveAttribute('aria-pressed', 'false')
  assert.equal(requests.length, 0)

  // Two clicks place start and end; the route and its summary render.
  await routingButton.click()
  await page.mouse.click(600, 400)
  await page.mouse.click(640, 400)
  await expect.poll(() => requests.length).toBe(1)
  const from = unproject(600, 400)
  const to = unproject(640, 400)
  const params = requests[0]
  assert.ok(Math.abs(Number(params.get('fromLat')) - from.lat) < 1e-4, 'fromLat matches the first click')
  assert.ok(Math.abs(Number(params.get('fromLon')) - from.lng) < 1e-4, 'fromLon matches the first click')
  assert.ok(Math.abs(Number(params.get('toLat')) - to.lat) < 1e-4, 'toLat matches the second click')
  assert.ok(Math.abs(Number(params.get('toLon')) - to.lng) < 1e-4, 'toLon matches the second click')
  await expect(page.getByText('1.2 km · 15 min walk')).toBeVisible()

  // A further click starts a new route: the next click is its start point,
  // the one after its end point.
  await page.mouse.click(700, 450)
  await page.mouse.click(720, 450)
  await expect.poll(() => requests.length).toBe(2)
  assert.ok(Math.abs(Number(requests[1].get('fromLat')) - unproject(700, 450).lat) < 1e-4, 'a new route starts at the third click')

  // An unroutable pair shows an error instead of a route.
  failNext = true
  await page.mouse.click(400, 500)
  await page.mouse.click(420, 500)
  await expect(page.getByRole('alert').filter({ hasText: 'No walkable route was found between these points.' })).toBeVisible()
  assert.equal(requests.length, 3)

  // Leaving routing mode clears markers and route.
  await routingButton.click()
  await expect(routingButton).toHaveAttribute('aria-pressed', 'false')
  await expect(page.getByText('1.2 km · 15 min walk')).toBeHidden()

  console.log('PASS: routing mode toggle, start/end placement, route summary, new route, no-route error, exit')
} finally {
  await browser.close()
}
