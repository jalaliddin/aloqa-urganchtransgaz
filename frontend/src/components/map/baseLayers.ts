import type { MapSettings } from '@/api/types'

export type BaseLayerKey = 'scheme' | 'satellite' | 'hybrid'

export interface BaseLayerDef {
  key: BaseLayerKey
  /** Label in the layers control; Leaflet reports the choice back by this name */
  name: string
  /** Photo background: routes get a light casing so their colours stay readable */
  imagery: boolean
}

const STORAGE_KEY = 'map-base-layer'

/** Base layers offered by the current map settings; satellite variants disappear when switched off. */
export function availableBaseLayers(settings: MapSettings): BaseLayerDef[] {
  const layers: BaseLayerDef[] = [{ key: 'scheme', name: 'Xarita', imagery: false }]
  if (settings.satellite_url) {
    layers.push({ key: 'satellite', name: "Sun'iy yo'ldosh", imagery: true })
    if (settings.satellite_labels_url) layers.push({ key: 'hybrid', name: 'Gibrid', imagery: true })
  }
  return layers
}

/** The preferred layer when it is still offered, otherwise the scheme map. */
export function resolveBaseLayer(preferred: string | null, available: BaseLayerDef[]): BaseLayerDef {
  return available.find((layer) => layer.key === preferred) ?? available[0]
}

export function readBaseLayer(): string | null {
  try {
    return window.localStorage.getItem(STORAGE_KEY)
  } catch {
    return null
  }
}

export function rememberBaseLayer(key: BaseLayerKey) {
  try {
    window.localStorage.setItem(STORAGE_KEY, key)
  } catch {
    // Storage may be unavailable (private mode) — the choice simply is not remembered
  }
}
