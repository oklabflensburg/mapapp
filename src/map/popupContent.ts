import type { Feature } from 'geojson'

// The OSM popup lists the feature's own tags, the custom tags the user saved
// for it, and the POIs located inside it. What counts as "inside" and which
// custom tags exist is runtime state, so both are injected.
export interface PopupDependencies {
  pointsInside: (feature: Feature) => Feature[]
  customTags: (providerNodeId: string | number | null | undefined) => Record<string, string> | undefined
  customTagsTitle: () => string
}

function escapeHtml(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
}

export function tagTable(props: Record<string, string>): string {
  const rows = Object.entries(props)
    .map(([key, value]) => `<tr><th>${escapeHtml(key)}</th><td>${escapeHtml(String(value))}</td></tr>`)
    .join('')
  return `<table class="osm-tags">${rows}</table>`
}

// Section listing the custom tags the user saved for the feature with this
// provider id; empty when the feature has no user node (or no tags).
function customTagsSection(providerNodeId: string | number | null | undefined, deps: PopupDependencies): string {
  const tags = deps.customTags(providerNodeId)
  if (!tags || Object.keys(tags).length === 0) {
    return ''
  }
  return `<section><h3 class="osm-poi-title">${escapeHtml(deps.customTagsTitle())}</h3>${tagTable(tags)}</section>`
}

export function osmPopupContent(feature: Feature, deps: PopupDependencies): string {
  const props = (feature.properties ?? {}) as Record<string, string>
  const poiSections = deps
    .pointsInside(feature)
    .map((poi) => {
      const poiProps = (poi.properties ?? {}) as Record<string, string>
      const title =
        poiProps.name || poiProps.shop || poiProps.amenity || poiProps.office || poiProps.tourism || poiProps.leisure || 'POI'
      return `<section><h3 class="osm-poi-title">${escapeHtml(title)}</h3>${tagTable(poiProps)}${customTagsSection(poi.id, deps)}</section>`
    })
    .join('')
  return tagTable(props) + customTagsSection(feature.id, deps) + poiSections
}
