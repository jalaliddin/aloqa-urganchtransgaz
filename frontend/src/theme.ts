import { createTheme, type MantineColorsTuple } from '@mantine/core'

/**
 * Design system. Deep steel blue for structure, gas-pipeline marking yellow used
 * sparingly (active navigation, route markers), cool paper background, borders
 * instead of shadows. Status colours come from the backend enum definitions.
 */
const steel: MantineColorsTuple = [
  '#eef3f8', '#dbe4ee', '#b4c7da', '#8ba8c5', '#6a8eb3', '#557ea8', '#4a76a3', '#3a648f', '#2f5880', '#1d3b53',
]

const signal: MantineColorsTuple = [
  '#fffae0', '#fff3c6', '#ffe58e', '#fed651', '#fcca23', '#fbc206', '#e3b505', '#c39a00', '#ad8800', '#957300',
]

export const BRAND_NAVY = '#1D3B53'
export const SIGNAL_YELLOW = '#E3B505'

export const theme = createTheme({
  primaryColor: 'steel',
  primaryShade: { light: 7, dark: 5 },
  colors: { steel, signal },
  fontFamily: '"IBM Plex Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
  fontFamilyMonospace: '"IBM Plex Mono", ui-monospace, monospace',
  headings: {
    fontFamily: '"IBM Plex Sans", system-ui, sans-serif',
    fontWeight: '600',
    sizes: {
      h1: { fontSize: '1.625rem', lineHeight: '1.25' },
      h2: { fontSize: '1.375rem', lineHeight: '1.3' },
      h3: { fontSize: '1.125rem', lineHeight: '1.35' },
      h4: { fontSize: '1rem', lineHeight: '1.4' },
    },
  },
  defaultRadius: 'sm',
  radius: { xs: '2px', sm: '4px', md: '6px', lg: '8px', xl: '12px' },
  focusRing: 'auto',
  cursorType: 'pointer',
  components: {
    Button: { defaultProps: { radius: 'sm' } },
    Paper: { defaultProps: { withBorder: true, radius: 'md', shadow: 'none' } },
    Card: { defaultProps: { withBorder: true, radius: 'md', shadow: 'none' } },
    Badge: { defaultProps: { radius: 'sm', variant: 'light', tt: 'none' } },
    Table: { defaultProps: { verticalSpacing: 'xs', highlightOnHover: true } },
    Modal: { defaultProps: { radius: 'md', centered: true } },
    Tooltip: { defaultProps: { withArrow: true, openDelay: 300 } },
    NavLink: { defaultProps: { variant: 'subtle' } },
  },
})
