import { Button, Collapse, Group, MultiSelect, NumberInput, Select, SimpleGrid, Stack, TextInput } from '@mantine/core'
import { useDebouncedCallback, useDisclosure } from '@mantine/hooks'
import { IconAdjustmentsHorizontal, IconFilterOff, IconSearch } from '@tabler/icons-react'
import { useState } from 'react'
import { useOrganizationOptions, usePipelines } from '@/api/queries'
import { useEnum } from '@/lib/meta'
import type { CableFilters } from './useMapFeatures'

interface CableFilterBarProps {
  value: CableFilters
  onChange: (next: CableFilters) => void
  /** Stacked layout for narrow panels (map); secondary filters fold behind a toggle */
  compact?: boolean
}

const SECONDARY = ['gas_pipeline_asset_id', 'usage_status', 'technical_condition', 'risk_level', 'laid_year_from', 'laid_year_to', 'length_min', 'length_max']

const isSet = (v: unknown) => v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && v.length === 0)

/** Shared registry / map filters — both views query the API with identical parameters. */
export function CableFilterBar({ value, onChange, compact }: CableFilterBarProps) {
  const organizations = useOrganizationOptions()
  const { data: pipelines = [] } = usePipelines()
  const dismantling = useEnum('dismantling_status')
  const usage = useEnum('usage_status')
  const condition = useEnum('technical_condition')
  const risk = useEnum('risk_level')
  const [search, setSearch] = useState(String(value.q ?? ''))
  const debouncedSearch = useDebouncedCallback((q: string) => onChange({ ...value, q }), 350)
  const secondaryActive = SECONDARY.filter((k) => isSet(value[k])).length
  const [moreOpened, more] = useDisclosure(secondaryActive > 0)

  const set = (key: string, v: unknown) => onChange({ ...value, [key]: v as CableFilters[string] })
  const arr = (key: string) => (Array.isArray(value[key]) ? (value[key] as string[]) : [])
  const num = (key: string) => (value[key] === undefined || value[key] === null ? '' : Number(value[key]))
  const hasFilters = Object.entries(value).some(([k, v]) => !['sort', 'direction'].includes(k) && isSet(v))
  const w = (inline: number | string) => (compact ? '100%' : inline)

  const searchInput = (
    <TextInput
      aria-label="Qidiruv"
      placeholder="Reyestr raqami, kabel ID, gaz quvuri, MGQB, manzil…"
      leftSection={<IconSearch size={16} />}
      value={search}
      onChange={(e) => {
        setSearch(e.currentTarget.value)
        debouncedSearch(e.currentTarget.value)
      }}
      w={w(320)}
      size="sm"
    />
  )

  const primary = (
    <>
      {organizations.length > 1 && (
        <Select
          aria-label="MGQB"
          placeholder="MGQB"
          data={organizations}
          value={value.organization_id ? String(value.organization_id) : null}
          onChange={(v) => set('organization_id', v)}
          clearable
          searchable
          w={w(190)}
          size="sm"
        />
      )}
      <MultiSelect
        aria-label="Demontaj holati"
        placeholder={arr('dismantling_status').length ? undefined : 'Demontaj holati'}
        data={dismantling.selectData}
        value={arr('dismantling_status')}
        onChange={(v) => set('dismantling_status', v)}
        clearable
        w={w(220)}
        size="sm"
        maxDropdownHeight={320}
      />
    </>
  )

  const secondary = (
    <>
      <Select
        aria-label="Gaz quvuri"
        placeholder="Gaz quvuri"
        data={pipelines.map((p) => ({ value: String(p.id), label: p.name }))}
        value={value.gas_pipeline_asset_id ? String(value.gas_pipeline_asset_id) : null}
        onChange={(v) => set('gas_pipeline_asset_id', v)}
        clearable
        searchable
        w={w(200)}
        size="sm"
      />
      <MultiSelect
        aria-label="Foydalanish holati"
        placeholder={arr('usage_status').length ? undefined : 'Foydalanish'}
        data={usage.selectData}
        value={arr('usage_status')}
        onChange={(v) => set('usage_status', v)}
        clearable
        w={w(200)}
        size="sm"
      />
      <MultiSelect
        aria-label="Texnik holat"
        placeholder={arr('technical_condition').length ? undefined : 'Texnik holat'}
        data={condition.selectData}
        value={arr('technical_condition')}
        onChange={(v) => set('technical_condition', v)}
        clearable
        w={w(180)}
        size="sm"
      />
      <MultiSelect
        aria-label="Xavf darajasi"
        placeholder={arr('risk_level').length ? undefined : 'Xavf'}
        data={risk.selectData}
        value={arr('risk_level')}
        onChange={(v) => set('risk_level', v)}
        clearable
        w={w(150)}
        size="sm"
      />
      <SimpleGrid cols={2} spacing="xs" w={compact ? '100%' : 210}>
        <NumberInput aria-label="Yotqizilgan yil (dan)" placeholder="Yil, dan" value={num('laid_year_from')} onChange={(v) => set('laid_year_from', v === '' ? undefined : v)} min={1900} max={2100} size="sm" hideControls />
        <NumberInput aria-label="Yotqizilgan yil (gacha)" placeholder="Yil, gacha" value={num('laid_year_to')} onChange={(v) => set('laid_year_to', v === '' ? undefined : v)} min={1900} max={2100} size="sm" hideControls />
      </SimpleGrid>
      <SimpleGrid cols={2} spacing="xs" w={compact ? '100%' : 250}>
        <NumberInput aria-label="Uzunlik (m, dan)" placeholder="Uzunlik ≥ m" value={num('length_min')} onChange={(v) => set('length_min', v === '' ? undefined : v)} min={0} size="sm" hideControls />
        <NumberInput aria-label="Uzunlik (m, gacha)" placeholder="Uzunlik ≤ m" value={num('length_max')} onChange={(v) => set('length_max', v === '' ? undefined : v)} min={0} size="sm" hideControls />
      </SimpleGrid>
    </>
  )

  const reset = hasFilters && (
    <Button
      variant="subtle"
      size="sm"
      leftSection={<IconFilterOff size={16} />}
      onClick={() => {
        setSearch('')
        onChange({ sort: value.sort, direction: value.direction })
      }}
    >
      Filtrlarni tozalash
    </Button>
  )

  if (compact) {
    return (
      <Stack gap="xs">
        {searchInput}
        {primary}
        <Button
          variant="subtle"
          size="xs"
          leftSection={<IconAdjustmentsHorizontal size={14} />}
          onClick={more.toggle}
          aria-expanded={moreOpened}
          justify="flex-start"
        >
          Qo'shimcha filtrlar{secondaryActive ? ` (${secondaryActive})` : ''}
        </Button>
        <Collapse expanded={moreOpened}>
          <Stack gap="xs">{secondary}</Stack>
        </Collapse>
        {reset}
      </Stack>
    )
  }

  return (
    <Group gap="xs" align="flex-end" wrap="wrap">
      {searchInput}
      {primary}
      {secondary}
      {reset}
    </Group>
  )
}
