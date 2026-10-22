import { Alert, Anchor, Badge, Button, Grid, Group, Menu, SegmentedControl, Stack, Text, Tooltip } from '@mantine/core'
import { IconDownload, IconMapPin, IconMapPinOff, IconPlus } from '@tabler/icons-react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useMemo } from 'react'
import { Link } from 'react-router'
import { api, downloadFile } from '@/api/client'
import type { CableLine, Paginated } from '@/api/types'
import { useAuth } from '@/auth/AuthContext'
import { DataTable, type Column } from '@/components/common/DataTable'
import { notifyError } from '@/components/common/notify'
import { PageHeader } from '@/components/common/PageHeader'
import { StatusBadge } from '@/components/common/StatusBadge'
import { CableFilterBar } from '@/components/map/CableFilterBar'
import { CableMap } from '@/components/map/CableMap'
import { MapLegend } from '@/components/map/MapLegend'
import type { ColorMode } from '@/components/map/mapColors'
import { SelectedLinePanel } from '@/components/map/SelectedLinePanel'
import { cleanParams, useMapFeatures } from '@/components/map/useMapFeatures'
import { formatLength } from '@/lib/format'
import { useUrlFilters } from '@/lib/useUrlFilters'

type ViewMode = 'table' | 'split' | 'map'

export default function RegistryPage() {
  const { can } = useAuth()
  const { filters, setFilters, getParam, setParam } = useUrlFilters()
  const view = (getParam('view') as ViewMode | null) ?? 'table'
  const colorBy = (getParam('color') as ColorMode | null) ?? 'dismantling_status'
  const selectedId = getParam('selected') ? Number(getParam('selected')) : null
  const page = Number(getParam('page') ?? 1)
  const perPage = Number(getParam('per_page') ?? 25)
  const sort = filters.sort ? { key: String(filters.sort), direction: (filters.direction as 'asc' | 'desc') ?? 'asc' } : null

  const listParams = { ...cleanParams(filters), page, per_page: perPage }
  const lines = useQuery({
    queryKey: ['cable-lines', listParams],
    queryFn: async () => (await api.get<Paginated<CableLine>>('/cable-lines', { params: listParams })).data,
    placeholderData: keepPreviousData,
  })

  // The map receives exactly the same filters as the table
  const mapFilters = useMemo(() => {
    const { sort: _s, direction: _d, ...rest } = filters
    return rest
  }, [filters])
  const features = useMapFeatures(mapFilters, null, view !== 'table')

  const selectLine = (id: number | null) => setParam('selected', id)
  const selectedOnPage = selectedId !== null && (lines.data?.data ?? []).some((l) => l.id === selectedId)

  const columns: Column<CableLine>[] = [
    {
      key: 'registry_number',
      header: 'Reyestr №',
      sortKey: 'registry_number',
      alwaysVisible: true,
      render: (l) => (
        <Anchor component={Link} to={`/registry/${l.id}`} fw={600} size="sm" style={{ whiteSpace: 'nowrap' }} onClick={(e) => e.stopPropagation()}>
          {l.registry_number}
        </Anchor>
      ),
    },
    {
      key: 'name',
      header: 'Nomi',
      sortKey: 'name',
      render: (l) => (
        <Stack gap={0} miw={200}>
          <Group gap={6} wrap="nowrap">
            <Text size="sm" lineClamp={1}>
              {l.name}
            </Text>
            {l.is_demo && (
              <Badge size="xs" color="orange" style={{ flexShrink: 0 }}>
                DEMO
              </Badge>
            )}
          </Group>
          {l.cable_code && (
            <Text size="xs" c="dimmed">
              {l.cable_code}
            </Text>
          )}
        </Stack>
      ),
    },
    { key: 'organization', header: 'MGQB', render: (l) => <Text size="sm" style={{ whiteSpace: 'nowrap' }}>{l.organization?.short_name ?? l.organization?.name ?? '—'}</Text> },
    { key: 'gas_pipeline', header: 'Gaz quvuri', hiddenByDefault: true, render: (l) => l.gas_pipeline?.name ?? '—' },
    { key: 'brand', header: 'Markasi', hiddenByDefault: true, render: (l) => l.brand ?? '—' },
    { key: 'laid_year', header: 'Yili', sortKey: 'laid_year', render: (l) => l.laid_year_label },
    { key: 'design_length_m', header: "Loyiha bo'yicha", sortKey: 'design_length_m', align: 'right', render: (l) => formatLength(l.design_length_m) },
    { key: 'measured_length_m', header: 'Aniqlangan', sortKey: 'measured_length_m', align: 'right', render: (l) => formatLength(l.measured_length_m) },
    { key: 'segments_count', header: 'Uchastka', align: 'right', hiddenByDefault: true, render: (l) => l.segments_count ?? '—' },
    { key: 'usage_status', header: 'Foydalanish', sortKey: 'usage_status', render: (l) => <StatusBadge enumKey="usage_status" value={l.usage_status} /> },
    { key: 'technical_condition', header: 'Texnik holat', hiddenByDefault: true, render: (l) => <StatusBadge enumKey="technical_condition" value={l.technical_condition} /> },
    { key: 'risk_level', header: 'Xavf', sortKey: 'risk_level', render: (l) => <StatusBadge enumKey="risk_level" value={l.risk_level} /> },
    { key: 'inspection_status', header: 'Tekshiruv', hiddenByDefault: true, render: (l) => <StatusBadge enumKey="segment_inspection_status" value={l.inspection_status} /> },
    { key: 'dismantling_status', header: 'Demontaj', sortKey: 'dismantling_status', render: (l) => <StatusBadge enumKey="dismantling_status" value={l.dismantling_status} /> },
    {
      key: 'has_geometry',
      header: 'Xarita',
      align: 'center',
      render: (l) =>
        l.has_geometry ? (
          <Tooltip label="Geometriya kiritilgan">
            <IconMapPin size={16} aria-label="Geometriya kiritilgan" />
          </Tooltip>
        ) : (
          <Tooltip label="Geometriya kiritilmagan">
            <IconMapPinOff size={16} color="var(--mantine-color-gray-5)" aria-label="Geometriya kiritilmagan" />
          </Tooltip>
        ),
    },
  ]

  const exportRegistry = async (format: 'xlsx' | 'csv') => {
    try {
      await downloadFile('/reports/registry/export', { format, organization_id: filters.organization_id })
    } catch (e) {
      notifyError(e, 'Eksport bajarilmadi')
    }
  }

  const table = (
    <DataTable
      columns={columns}
      rows={lines.data?.data}
      rowKey={(l) => l.id}
      loading={lines.isFetching}
      error={lines.error}
      onRetry={() => void lines.refetch()}
      total={lines.data?.meta.total}
      page={page}
      perPage={perPage}
      onPageChange={(p) => setParam('page', p)}
      onPerPageChange={(n) => {
        setParam('per_page', n)
        setParam('page', 1)
      }}
      sort={sort}
      onSortChange={(s) => setFilters({ ...filters, sort: s.key, direction: s.direction })}
      selectedKey={selectedId}
      onRowClick={(l) => selectLine(l.id === selectedId ? null : l.id)}
      storageKey="registry"
      emptyTitle="Filtrlarga mos liniya topilmadi"
      emptyDescription="Filtrlarni o'zgartiring yoki yangi kabel liniyasini reyestrga qo'shing."
      emptyAction={
        can('cable_lines.create') ? (
          <Button component={Link} to="/registry/new" size="xs" leftSection={<IconPlus size={14} />}>
            Liniya qo'shish
          </Button>
        ) : undefined
      }
      caption="Kabel liniyalari reyestri"
      minWidth={1040}
    />
  )

  const map = (
    <CableMap
      features={features.data?.features ?? []}
      colorBy={colorBy}
      selectedLineId={selectedId}
      onSelect={(p) => selectLine(p.line_id)}
      // Fit only once data for the current filters has arrived (not to the previous result shown meanwhile)
      fitKey={features.data && !features.isPlaceholderData ? `${selectedId ?? ''}|${JSON.stringify(mapFilters)}` : null}
      loading={features.isFetching}
      height={view === 'map' ? 'calc(100vh - 260px)' : 'calc(100vh - 300px)'}
    >
      <div style={{ position: 'absolute', bottom: 24, left: 10, zIndex: 500 }}>
        <MapLegend mode={colorBy} onModeChange={(m) => setParam('color', m)} defaultOpened={view === 'map'} />
      </div>
    </CableMap>
  )

  return (
    <>
      <PageHeader
        title="Kabel liniyalari reyestri"
        description="Liniya yoki xaritadagi trassani tanlang — ikkala ko'rinishda bir xil yozuv belgilanadi."
        actions={
          <>
            <SegmentedControl
              aria-label="Ko'rinish rejimi"
              value={view}
              onChange={(v) => setParam('view', v === 'table' ? null : v)}
              data={[
                { value: 'table', label: 'Jadval' },
                { value: 'split', label: 'Jadval va xarita' },
                { value: 'map', label: 'Xarita' },
              ]}
            />
            <Button variant="default" component={Link} to="/registry/cable-types">
              Kabel turlari va metall tarkibi
            </Button>
            {can('reports.export') && (
              <Menu position="bottom-end">
                <Menu.Target>
                  <Button variant="default" leftSection={<IconDownload size={16} />}>
                    Eksport
                  </Button>
                </Menu.Target>
                <Menu.Dropdown>
                  <Menu.Item onClick={() => exportRegistry('xlsx')}>Excel (.xlsx)</Menu.Item>
                  <Menu.Item onClick={() => exportRegistry('csv')}>CSV</Menu.Item>
                </Menu.Dropdown>
              </Menu>
            )}
            {can('cable_lines.create') && (
              <Button component={Link} to="/registry/new" leftSection={<IconPlus size={16} />}>
                Yangi liniya
              </Button>
            )}
          </>
        }
      />

      <Stack gap="sm" mb="sm">
        <CableFilterBar value={filters} onChange={setFilters} />
        {selectedId !== null && !selectedOnPage && view !== 'map' && lines.data && (
          <Alert color="signal" variant="light" py={6}>
            <Text size="sm">
              Xaritada tanlangan liniya joriy sahifada emas.{' '}
              <Anchor component={Link} to={`/registry/${selectedId}`} size="sm">
                Liniyani ochish
              </Anchor>
            </Text>
          </Alert>
        )}
      </Stack>

      {view === 'table' && table}
      {view === 'map' && (
        <Grid gap="md">
          <Grid.Col span={{ base: 12, md: selectedId ? 8 : 12 }}>{map}</Grid.Col>
          {selectedId !== null && (
            <Grid.Col span={{ base: 12, md: 4 }}>
              <SelectedLinePanel lineId={selectedId} onClose={() => selectLine(null)} />
            </Grid.Col>
          )}
        </Grid>
      )}
      {view === 'split' && (
        <Grid gap="md">
          <Grid.Col span={{ base: 12, lg: 7 }}>{table}</Grid.Col>
          <Grid.Col span={{ base: 12, lg: 5 }}>
            <Stack gap="sm">
              {map}
              {selectedId !== null && <SelectedLinePanel lineId={selectedId} onClose={() => selectLine(null)} />}
            </Stack>
          </Grid.Col>
        </Grid>
      )}
    </>
  )
}
