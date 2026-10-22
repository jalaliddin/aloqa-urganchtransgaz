import { screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { renderWithProviders } from '@/test/utils'
import { KgPerMetreValue, MassValue } from './MetalValue'

describe('metal values', () => {
  it('shows unknown explicitly and never as zero', () => {
    renderWithProviders(<MassValue kg={null} />)

    expect(screen.getByText("noma'lum")).toBeInTheDocument()
    expect(screen.queryByText(/0 kg/)).not.toBeInTheDocument()
  })

  it('shows a verified zero as 0 kg', () => {
    renderWithProviders(<MassValue kg="0.000" />)

    expect(screen.getByText('0 kg')).toBeInTheDocument()
  })

  it('marks a partial total as incomplete', () => {
    renderWithProviders(<MassValue kg={null} incomplete knownPart="1234.500" />)

    expect(screen.getByText("≥ 1 234,5 kg (to'liq emas)")).toBeInTheDocument()
  })

  it('formats kg per metre with up to six decimals', () => {
    renderWithProviders(
      <>
        <KgPerMetreValue value="0.421500" />
        <KgPerMetreValue value={null} />
      </>,
    )

    expect(screen.getByText('0,4215 kg/m')).toBeInTheDocument()
    expect(screen.getByText("noma'lum")).toBeInTheDocument()
  })
})
