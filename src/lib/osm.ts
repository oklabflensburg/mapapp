import type { IndexedFeature } from '../map/featureIndex'

// A node counts as an OSM way when it references one ("w..." id) and the
// referenced feature is a highway or a line geometry; when the feature is
// not (yet) loaded, the id pattern alone decides.
export function isOsmWay(
  node: { provider: string; providerNodeId: string } | null | undefined,
  features: IndexedFeature[] | undefined,
): boolean {
  if (node?.provider !== 'OSM' || !/^w\d+$/.test(node.providerNodeId)) return false
  const feature = features?.find(entry => String(entry.feature.id) === node.providerNodeId)?.feature
  return !feature || !!feature.properties?.highway || ['LineString', 'MultiLineString'].includes(feature.geometry.type)
}
