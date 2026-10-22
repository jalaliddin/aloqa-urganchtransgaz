/**
 * Categorical chart palette, validated with the dataviz validator (light mode):
 * lightness band, chroma floor, CVD separation ≥ 8, normal-vision ≥ 15 all pass.
 * The amber slot is below 3:1 contrast, so charts using it also ship a table view.
 * Fixed order — never cycled.
 */
export const SERIES = {
  registry: '#2b6cb0',
  inventoried: '#16a37a',
  dismantled: '#d08a0c',
} as const

export const SINGLE_SERIES = '#2b6cb0'
