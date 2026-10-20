import type { FeatureCollection } from 'geojson'
import { indexFeatures, type IndexedFeature } from './featureIndex'
import { OSM_DATA_URL } from './mapConfig'

export interface OsmData {
  features: IndexedFeature[]
  // OSM tags by feature id ("n26013095"), so a saved user node can show the
  // original tags of the OSM node it references.
  tagsById: Map<string, Record<string, string>>
}

// Fetches and indexes the static OSM export. Returns undefined when the
// request fails; the map still works without its feature layer.
export async function loadOsmData(): Promise<OsmData | undefined> {
  try {
    const response = await fetch(OSM_DATA_URL)
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`)
    }
    const collection = (await response.json()) as FeatureCollection
    return {
      features: indexFeatures(collection),
      tagsById: new Map(
        collection.features
          .filter((f) => f.id !== undefined && f.id !== null && f.geometry.type === 'Point')
          .map((f) => [String(f.id), (f.properties ?? {}) as Record<string, string>]),
      ),
    }
  } catch (error) {
    console.warn('Failed to load OSM features', error)
    return undefined
  }
}
