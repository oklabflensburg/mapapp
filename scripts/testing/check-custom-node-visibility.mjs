// Requires the dev server; fixtures never write to the development database.
import { chromium, expect } from '@playwright/test'

const browser = await chromium.launch()
const appUrl = process.env.APP_URL ?? 'http://localhost:5173'

try {
  // Node approval controls color independently of tag approval, including
  // tagless nodes and published nodes with a new pending observation.
  const pairs = (map) => Object.entries(map ?? {}).map(([key, value]) => ({ key, value }))
  for (const [approved, tags, observations, rgb] of [
    [true, { name: 'Published place' }, {}, [102, 187, 106]],
    [true, {}, {}, [102, 187, 106]],
    [true, { name: 'New observation' }, { name: 'New observation' }, [102, 187, 106]],
    [false, { name: 'Approved tag on pending node' }, {}, [251, 191, 36]],
    [false, {}, {}, [251, 191, 36]],
    [false, { name: 'Pending place' }, { name: 'Pending place' }, [251, 191, 36]],
  ]) {
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 } })
    await page.route('**/data/flensburg.geojson', route => route.fulfill({
      json: { type: 'FeatureCollection', features: [] },
    }))
    await page.route('**/api/me', route => route.fulfill({
      json: { user: { name: 'Editor', email: 'editor@example.test', roles: ['ROLE_EDITOR'] } },
    }))
    await page.route('**/api/node-groups', route => route.fulfill({ json: [] }))
    await page.route('**/api/nodes', route => route.fulfill({ json: [{
      id: 1, provider: 'local', providerNodeId: 'custom-place', approved,
      latitude: 54.791, longitude: 9.437, tags: pairs(tags), observations: pairs(observations),
      parentIds: [], childIds: [], parents: [], children: [],
      createdAt: '2026-10-20T10:00:00Z', updatedAt: '2026-10-20T10:00:00Z',
    }] }))
    await page.goto(appUrl)
    // Inspect the actual canvas fill: transparent pixels contain the original
    // RGB values, with fillOpacity represented separately in the alpha byte.
    await expect.poll(() => page.locator('.leaflet-overlay-pane canvas').evaluateAll((canvases, rgb) =>
      canvases.some(canvas => {
        const pixels = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data
        for (let i = 0; i < pixels.length; i += 4) {
          if (pixels[i + 3] > 200 && rgb.every((v, channel) => Math.abs(pixels[i + channel] - v) <= 2)) return true
        }
        return false
      }), rgb), { message: `Custom node visible with approved=${approved}, tags=${JSON.stringify(tags)}` }).toBe(true)
    await page.mouse.click(640, 400)
    await expect(page.locator('.p-drawer')).toBeVisible()
    await page.close()
  }
  console.log('PASS: approved and pending custom nodes stay visible and selectable, with colors independent of tag approval')
} finally {
  await browser.close()
}
