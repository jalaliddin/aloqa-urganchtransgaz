import { afterEach, describe, expect, it } from 'vitest'
import { testMeta } from '@/test/utils'
import { availableBaseLayers, readBaseLayer, rememberBaseLayer, resolveBaseLayer } from './baseLayers'

const settings = testMeta.map

describe('base layers', () => {
  afterEach(() => window.localStorage.clear())

  it('offers scheme, satellite and hybrid when imagery and labels are configured', () => {
    expect(availableBaseLayers(settings).map((l) => [l.key, l.name, l.imagery])).toEqual([
      ['scheme', 'Xarita', false],
      ['satellite', "Sun'iy yo'ldosh", true],
      ['hybrid', 'Gibrid', true],
    ])
  })

  it('hides the hybrid variant without a labels layer and all imagery when satellite is switched off', () => {
    expect(availableBaseLayers({ ...settings, satellite_labels_url: null }).map((l) => l.key)).toEqual(['scheme', 'satellite'])
    expect(availableBaseLayers({ ...settings, satellite_url: null }).map((l) => l.key)).toEqual(['scheme'])
  })

  it('restores the remembered layer and falls back to the scheme map when it is no longer offered', () => {
    rememberBaseLayer('satellite')
    expect(readBaseLayer()).toBe('satellite')
    expect(resolveBaseLayer(readBaseLayer(), availableBaseLayers(settings)).key).toBe('satellite')
    expect(resolveBaseLayer(readBaseLayer(), availableBaseLayers({ ...settings, satellite_url: null })).key).toBe('scheme')
    expect(resolveBaseLayer(null, availableBaseLayers(settings)).key).toBe('scheme')
    expect(resolveBaseLayer('unknown', availableBaseLayers(settings)).key).toBe('scheme')
  })
})
