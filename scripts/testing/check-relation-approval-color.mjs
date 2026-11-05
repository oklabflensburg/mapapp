// Requires the dev server; fixtures never write to the development database.
import { chromium, expect } from '@playwright/test'

const browser = await chromium.launch()
const appUrl = process.env.APP_URL ?? 'http://localhost:5173'

// A relation publishes like a node: once one of its tags is approved, its
// member line renders green (approved marker green); without any approved
// tag it stays amber like a pending node marker.

try {
  for (const [approved, rgb] of [
    [true, [46, 125, 50]],
    [false, [217, 119, 6]],
  ]) {
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 } })
    await page.route('**/data/flensburg.geojson', route =>
      route.fulfill({ json: { type: 'FeatureCollection', features: [] } }))
    await page.route('**/api/me', route =>
      route.fulfill({ json: { user: { name: 'Editor', email: 'editor@example.test', roles: ['ROLE_EDITOR'] } } }))
    await page.route('**/api/nodes', route => route.fulfill({ json: [] }))
    await page.route('**/api/node-groups', route => route.fulfill({ json: [{
      id: 1, name: 'Check part',
      members: [
        { id: 1, provider: 'OSM', providerNodeId: 'n-check-1', name: null, tags: [], latitude: 54.7908, longitude: 9.4368 },
        { id: 2, provider: 'OSM', providerNodeId: 'n-check-2', name: null, tags: [], latitude: 54.7912, longitude: 9.4374 },
      ],
      area: null,
      tags: [{ key: 'surface', value: 'asphalt' }],
      observations: approved ? [] : [{ key: 'surface', value: 'asphalt' }],
      approved,
      createdAt: '2026-10-20T10:00:00Z', updatedAt: '2026-10-20T10:00:00Z',
    }] }))
    await page.goto(appUrl)
    // Inspect the actual canvas stroke: the member line is drawn with the
    // approved green or the pending amber.
    await expect.poll(() => page.locator('.leaflet-overlay-pane canvas').evaluateAll((canvases, stroke) =>
      canvases.some(canvas => {
        const pixels = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data
        for (let i = 0; i < pixels.length; i += 4) {
          if (pixels[i + 3] > 200 && stroke.every((v, channel) => Math.abs(pixels[i + channel] - v) <= 2)) return true
        }
        return false
      }), rgb), { message: `Relation line stroke for approved=${approved}` }).toBe(true)
    await page.close()
  }
  console.log('PASS: approved relation lines render green, pending ones amber')
} finally {
  await browser.close()
}

