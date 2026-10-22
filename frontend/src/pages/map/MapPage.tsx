import {
  Alert, Badge, Box, Button, Drawer, FileInput, Group, Menu, Modal, Paper, ScrollArea, Select, Stack, Table, Text, TextInput,
} from '@mantine/core'
import { useDisclosure, useMediaQuery } from '@mantine/hooks'
import {
  IconAdjustments, IconAlertTriangle, IconDeviceFloppy, IconDots, IconDownload, IconMapPinOff, IconPencil, IconPlus, IconTrash, IconUpload, IconX,
} from '@tabler/icons-react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useState } from 'react'
import { api, ApiError, downloadFile } from '@/api/client'
import type { CableSegment, Geometry } from '@/api/types'
import { useAuth } from '@/auth/AuthContext'
import { notifyError, notifySuccess } from '@/components/common/notify'
import { ReasonModal } from '@/components/common/ReasonModal'
import { ApiErrorAlert } from '@/components/common/States'
import { CableFilterBar } from '@/components/map/CableFilterBar'
import { CableMap } from '@/components/map/CableMap'
import { MapLegend } from '@/components/map/MapLegend'
import type { ColorMode } from '@/components/map/mapColors'
import { SelectedLinePanel } from '@/components/map/SelectedLinePanel'
import { cleanParams, useMapFeatures, useMissingGeometry } from '@/components/map/useMapFeatures'
import { formatLength } from '@/lib/format'
import { useUrlFilters } from '@/lib/useUrlFilters'

interface EditState {
  segment: CableSegment
  geometry: Geometry | null
  drawToken: number
}

const MAP_RESERVED = ['selected', 'segment', 'view', 'page', 'per_page', 'tab', 'color', 'edit']

export default function MapPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const isMobile = useMediaQuery('(max-width: 62em)', false, { getInitialValueInEffect: false })
  const { filters, setFilters, getParam, setParam } = useUrlFilters(MAP_RESERVED)
  const [bbox, setBbox] = useState<string | null>(null)
  const [filtersOpened, filtersDrawer] = useDisclosure(false)
  const [missingOpened, missingDrawer] = useDisclosure(false)
  const [importOpened, importModal] = useDisclosure(false)
  const [saveOpened, saveModal] = useDisclosure(false)
  const [clearOpened, clearModal] = useDisclosure(false)
  const [editing, setEditing] = useState<EditState | null>(null)

  const selectedLineId = getParam('selected') ? Number(getParam('selected')) : null
  const selectedSegmentId = getParam('segment') ? Number(getParam('segment')) : null
  const colorBy = (getParam('color') as ColorMode | null) ?? 'dismantling_status'

  // With an active filter / search the whole matching set is loaded (so results outside the view are found);
  // otherwise only the visible area is requested.
  const activeFilters = cleanParams(filters)
  const filtered = Object.keys(activeFilters).length > 0
  const features = useMapFeatures(filters, filtered ? null : bbox, filtered || bbox !== null)
  const missing = useMissingGeometry(filters)

  // The line selected on arrival (e.g. "Xaritada" from the registry) is loaded regardless of the viewport
  const [initialLineId] = useState(selectedLineId)
  const selectedFeatures = useMapFeatures({ line_id: selectedLineId }, null, selectedLineId !== null)
  const mergedFeatures = useMemo(() => {
    const byId = new Map((features.data?.features ?? []).map((f) => [f.id, f]))
    ;(selectedFeatures.data?.features ?? []).forEach((f) => byId.set(f.id, f))
    return Array.from(byId.values())
  }, [features.data, selectedFeatures.data])

  // Fit only once the data for the current filters / initial selection has arrived
  const filtersKey = JSON.stringify(activeFilters)
  const filtersReady = filtered && !!features.data && !features.isPlaceholderData
  const initialReady = initialLineId !== null && !!selectedFeatures.data && !selectedFeatures.isPlaceholderData
  // Without filters or a selection, zoom once to the routes loaded for the starting view
  const overviewReady = !filtered && initialLineId === null && !!features.data && features.data.features.length > 0
  const fitKey = filtersReady ? `filters-${filtersKey}` : initialReady ? `line-${initialLineId}` : overviewReady ? 'overview' : null

  const startEdit = async (segmentId: number) => {
    try {
      const segment = (await api.get<{ data: CableSegment }>(`/cable-segments/${segmentId}`)).data.data
      setParam('segment', segmentId)
      setParam('selected', segment.cable_line_id)
      setEditing({ segment, geometry: segment.geometry, drawToken: 0 })
      missingDrawer.close()
    } catch (e) {
      notifyError(e, "Uchastkani ochib bo'lmadi")
    }
  }

  // Arriving from the registry with ?edit=1 opens the editor directly
  useEffect(() => {
    if (getParam('edit') === '1' && selectedSegmentId && can('cable_lines.edit_geometry')) {
      void startEdit(selectedSegmentId)
      setParam('edit', null)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const refreshGeometry = () => {
    void queryClient.invalidateQueries({ queryKey: ['map-features'] })
    void queryClient.invalidateQueries({ queryKey: ['map-missing'] })
    void queryClient.invalidateQueries({ queryKey: ['cable-line'] })
    void queryClient.invalidateQueries({ queryKey: ['cable-lines'] })
  }

  const exportGeoJson = async () => {
    try {
      await downloadFile('/map/export', activeFilters)
    } catch (e) {
      notifyError(e, 'Eksport bajarilmadi')
    }
  }

  const filterPanel = (
    <Stack gap="sm">
      <CableFilterBar value={filters} onChange={setFilters} compact />
      <Text size="xs" c="dimmed">
        {filtered
          ? `Filtrga mos ${features.data?.features.length ?? 0} ta uchastka xaritada.`
          : "Filtr bo'lmasa, faqat ko'rinib turgan hudud yuklanadi."}
      </Text>
    </Stack>
  )

  const editBar = editing && (
    <Paper p="sm" shadow="md" style={{ borderTop: '3px solid var(--app-signal)' }}>
      <Stack gap="xs">
        <Group justify="space-between" wrap="nowrap">
          <div>
            <Text fw={600} size="sm">
              Tahrirlash: {editing.segment.code}
            </Text>
            <Text size="xs" c="dimmed">
              {editing.geometry
                ? `Uzunlik (taxminiy): ${formatLength(approxLength(editing.geometry))}. Nuqtalarni suring; o'rtadagi nuqtani bosib yangisini qo'shing.`
                : "Trassani chizish uchun xaritaga bosing; oxirgi nuqtani yana bosib tugating."}
            </Text>
          </div>
          <Badge color="signal" variant="light">
            Saqlanmagan
          </Badge>
        </Group>
        <Group gap="xs">
          <Button size="xs" variant="default" leftSection={<IconPlus size={14} />} onClick={() => setEditing((e) => e && { ...e, drawToken: e.drawToken + 1 })}>
            Chiziq qo'shish
          </Button>
          <Button size="xs" leftSection={<IconDeviceFloppy size={14} />} disabled={!editing.geometry} onClick={saveModal.open}>
            Saqlash
          </Button>
          {editing.segment.geometry && (
            <Button size="xs" variant="subtle" color="red" leftSection={<IconTrash size={14} />} onClick={clearModal.open}>
              Geometriyani o'chirish
            </Button>
          )}
          <Button size="xs" variant="subtle" color="gray" leftSection={<IconX size={14} />} onClick={() => setEditing(null)}>
            Bekor qilish
          </Button>
        </Group>
      </Stack>
    </Paper>
  )

  return (
    <Box pos="relative" h="100%">
      <CableMap
        features={mergedFeatures}
        colorBy={colorBy}
        selectedLineId={selectedLineId}
        selectedSegmentId={selectedSegmentId}
        onSelect={(p) => {
          setParam('selected', p.line_id)
          setParam('segment', p.segment_id)
        }}
        onViewChange={setBbox}
        fitKey={fitKey}
        loading={features.isFetching}
        editing={
          editing
            ? {
                segmentId: editing.segment.id,
                geometry: editing.segment.geometry,
                drawToken: editing.drawToken,
                onChange: (geometry) => setEditing((e) => e && { ...e, geometry }),
              }
            : null
        }
      >
        {/* Toolbar */}
        <Group pos="absolute" top={10} left={56} right={56} gap="xs" style={{ zIndex: 500 }} wrap="wrap">
          {isMobile ? (
            <Button size="xs" leftSection={<IconAdjustments size={14} />} onClick={filtersDrawer.open}>
              Filtr va qidiruv
            </Button>
          ) : null}
          {isMobile ? (
            <Menu position="bottom-start" withinPortal>
              <Menu.Target>
                <Button size="xs" variant="white" leftSection={<IconDots size={14} />}>
                  Amallar
                </Button>
              </Menu.Target>
              <Menu.Dropdown>
                <Menu.Item leftSection={<IconMapPinOff size={14} />} onClick={missingDrawer.open}>
                  Geometriya kiritilmagan ({missing.data?.length ?? 0})
                </Menu.Item>
                <Menu.Item leftSection={<IconDownload size={14} />} onClick={exportGeoJson}>
                  GeoJSON eksport
                </Menu.Item>
                {can('cable_lines.edit_geometry') && (
                  <Menu.Item leftSection={<IconUpload size={14} />} onClick={importModal.open}>
                    GeoJSON import
                  </Menu.Item>
                )}
              </Menu.Dropdown>
            </Menu>
          ) : (
            <>
              <Button size="xs" variant="white" leftSection={<IconMapPinOff size={14} />} onClick={missingDrawer.open}>
                Geometriya kiritilmagan ({missing.data?.length ?? 0})
              </Button>
              <Button size="xs" variant="white" leftSection={<IconDownload size={14} />} onClick={exportGeoJson}>
                GeoJSON eksport
              </Button>
              {can('cable_lines.edit_geometry') && (
                <Button size="xs" variant="white" leftSection={<IconUpload size={14} />} onClick={importModal.open}>
                  GeoJSON import
                </Button>
              )}
            </>
          )}
        </Group>

        {/* Desktop filter panel */}
        {!isMobile && (
          <Paper pos="absolute" top={56} left={10} w={320} p="sm" shadow="sm" style={{ zIndex: 500, maxHeight: 'calc(100% - 140px)', overflowY: 'auto' }}>
            {filterPanel}
          </Paper>
        )}

        {/* Legend */}
        <Box pos="absolute" bottom={24} left={10} style={{ zIndex: 500 }}>
          <MapLegend mode={colorBy} onModeChange={(m) => setParam('color', m)} defaultOpened={!isMobile} />
        </Box>

        {/* Selected line / edit bar (right side on desktop) */}
        {!isMobile && (selectedLineId || editing) && (
          <Stack pos="absolute" top={56} right={10} w={360} gap="sm" style={{ zIndex: 500, maxHeight: 'calc(100% - 80px)' }}>
            {editBar}
            {selectedLineId && !editing && (
              <Box h="min(640px, calc(100vh - 160px))">
                <SelectedLinePanel
                  lineId={selectedLineId}
                  segmentId={selectedSegmentId}
                  onClose={() => {
                    setParam('selected', null)
                    setParam('segment', null)
                  }}
                  onSelectSegment={(id) => setParam('segment', id)}
                  onEditGeometry={(id) => void startEdit(id)}
                />
              </Box>
            )}
          </Stack>
        )}
        {isMobile && editing && (
          <Box pos="absolute" bottom={10} left={10} right={10} style={{ zIndex: 600 }}>
            {editBar}
          </Box>
        )}
      </CableMap>

      {/* Mobile: filters and selection in drawers */}
      <Drawer opened={!!isMobile && filtersOpened} onClose={filtersDrawer.close} title="Filtr va qidiruv" position="left">
        {filterPanel}
      </Drawer>
      <Drawer
        opened={!!isMobile && selectedLineId !== null && !editing}
        onClose={() => setParam('selected', null)}
        position="bottom"
        size="70%"
        withCloseButton={false}
        padding={0}
      >
        {selectedLineId && (
          <SelectedLinePanel
            lineId={selectedLineId}
            segmentId={selectedSegmentId}
            onClose={() => setParam('selected', null)}
            onSelectSegment={(id) => setParam('segment', id)}
            onEditGeometry={(id) => void startEdit(id)}
          />
        )}
      </Drawer>

      <Drawer opened={missingOpened} onClose={missingDrawer.close} title="Geometriya kiritilmagan uchastkalar" position="right" size="md">
        <Stack gap="xs">
          <Text size="sm" c="dimmed">
            Bu uchastkalar xaritada ko'rsatilmaydi — joylashuv taxminiy koordinata bilan to'ldirilmaydi. Ma'lum bo'lsa, trassani chizing yoki GPS
            ma'lumotini import qiling.
          </Text>
          {(missing.data ?? []).map((row) => (
            <Paper key={row.id} p="sm">
              <Group justify="space-between" wrap="nowrap" align="flex-start">
                <div>
                  <Text size="sm" fw={600}>
                    {row.code}
                  </Text>
                  <Text size="xs" c="dimmed">
                    {row.line_name}
                    {row.organization ? `, ${row.organization}` : ''}
                  </Text>
                  {row.location_description && <Text size="xs">{row.location_description}</Text>}
                </div>
                {can('cable_lines.edit_geometry') && (
                  <Button size="xs" variant="light" leftSection={<IconPencil size={14} />} onClick={() => void startEdit(row.id)}>
                    Chizish
                  </Button>
                )}
              </Group>
            </Paper>
          ))}
          {missing.data?.length === 0 && <Text size="sm">Barcha uchastkalarda geometriya mavjud.</Text>}
        </Stack>
      </Drawer>

      {editing && (
        <SaveGeometryModal
          opened={saveOpened}
          onClose={saveModal.close}
          segment={editing.segment}
          geometry={editing.geometry}
          onSaved={() => {
            setEditing(null)
            refreshGeometry()
          }}
        />
      )}
      <ReasonModal
        opened={clearOpened}
        onClose={clearModal.close}
        title="Geometriyani o'chirish"
        description="Uchastka xaritada “geometriya kiritilmagan” deb ko'rsatiladi. Eski qiymat audit jurnalida saqlanadi."
        confirmLabel="O'chirish"
        color="red"
        onConfirm={async (reason) => {
          await api.delete(`/cable-segments/${editing!.segment.id}/geometry`, { data: { reason } })
          notifySuccess("Geometriya o'chirildi")
          setEditing(null)
          refreshGeometry()
        }}
      />
      <ImportModal opened={importOpened} onClose={importModal.close} onApplied={refreshGeometry} />
    </Box>
  )
}

/** Rough client-side length for live feedback while drawing; the server computes the stored value. */
function approxLength(geometry: Geometry): number {
  const lines = geometry.type === 'LineString' ? [geometry.coordinates] : geometry.coordinates
  const r = 6371008.8
  const rad = (d: number) => (d * Math.PI) / 180
  let total = 0
  lines.forEach((line) => {
    for (let i = 1; i < line.length; i++) {
      const [lng1, lat1] = line[i - 1]
      const [lng2, lat2] = line[i]
      const h = Math.sin(rad(lat2 - lat1) / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(rad(lng2 - lng1) / 2) ** 2
      total += 2 * r * Math.asin(Math.min(1, Math.sqrt(h)))
    }
  })
  return total
}

function SaveGeometryModal({
  opened, onClose, segment, geometry, onSaved,
}: { opened: boolean; onClose: () => void; segment: CableSegment; geometry: Geometry | null; onSaved: () => void }) {
  const [source, setSource] = useState<string>('manual')
  const [note, setNote] = useState('')
  const save = useMutation({
    mutationFn: async () => api.put(`/cable-segments/${segment.id}/geometry`, { geometry, source, note: note || null }),
    onSuccess: () => {
      notifySuccess(`${segment.code} geometriyasi saqlandi`)
      onClose()
      onSaved()
    },
  })

  return (
    <Modal opened={opened} onClose={onClose} title={<Text fw={600}>Geometriyani saqlash — {segment.code}</Text>}>
      <Stack gap="sm">
        {geometry && (
          <Text size="sm">
            Chizilgan uzunlik (taxminiy): <b>{formatLength(approxLength(geometry))}</b>. Hisobdagi uzunlik:{' '}
            {formatLength(segment.measured_length_m ?? segment.design_length_m)}.
          </Text>
        )}
        <Select
          label="Ma'lumot manbasi"
          data={[
            { value: 'manual', label: "Xaritada qo'lda chizilgan" },
            { value: 'gps', label: "GPS o'lchov asosida" },
          ]}
          value={source}
          onChange={(v) => setSource(v ?? 'manual')}
          allowDeselect={false}
        />
        <TextInput label="Manba izohi" placeholder="Masalan: 2026-yil oktabr GPS dala o'lchovi" value={note} onChange={(e) => setNote(e.currentTarget.value)} />
        <Text size="xs" c="dimmed">
          Koordinatalar serverda tekshiriladi. Sezilarli o'zgarish (uzunlik yoki joylashuv) nomuvofiqlik sifatida qayd etiladi.
        </Text>
        <ApiErrorAlert error={save.error} />
        <Group justify="flex-end">
          <Button variant="default" onClick={onClose}>
            Ortga
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Saqlash
          </Button>
        </Group>
      </Stack>
    </Modal>
  )
}

interface ImportReportRow {
  index: number
  segment_code: string | null
  status: 'ok' | 'error'
  message: string | null
  length_m?: string
  replaces_existing?: boolean
}

function ImportModal({ opened, onClose, onApplied }: { opened: boolean; onClose: () => void; onApplied: () => void }) {
  const [file, setFile] = useState<File | null>(null)
  const [source, setSource] = useState('import')
  const [note, setNote] = useState('')
  const [report, setReport] = useState<{ message: string; applied: boolean; report: ImportReportRow[] } | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [busy, setBusy] = useState(false)

  const reset = () => {
    setFile(null)
    setReport(null)
    setError(null)
    setNote('')
    onClose()
  }

  const run = async (dryRun: boolean) => {
    if (!file) return
    setBusy(true)
    setError(null)
    const body = new FormData()
    body.append('file', file)
    body.append('source', source)
    body.append('source_note', note)
    body.append('dry_run', dryRun ? '1' : '0')
    try {
      const response = await api.post('/map/import', body)
      setReport(response.data)
      if (!dryRun) {
        notifySuccess(response.data.message)
        onApplied()
      }
    } catch (e) {
      // A 422 carries the per-feature report — show it instead of a generic error
      const body = e instanceof ApiError ? (e.body as { report?: ImportReportRow[] } | null) : null
      if (body?.report && e instanceof ApiError) {
        setReport({ message: e.message, applied: false, report: body.report })
      } else {
        setError(e)
      }
    } finally {
      setBusy(false)
    }
  }

  const ok = useMemo(() => report?.report.every((r) => r.status === 'ok') ?? false, [report])

  return (
    <Modal opened={opened} onClose={reset} title={<Text fw={600}>GeoJSON import</Text>} size="xl">
      <Stack gap="sm">
        <Text size="sm">
          FeatureCollection faylidagi har bir obyekt <b>properties.segment_code</b> (yoki segment_id) bilan mavjud uchastkaga bog'lanadi. Avval
          tekshiring — xato bo'lsa hech narsa saqlanmaydi.
        </Text>
        <FileInput label="GeoJSON fayl" accept=".geojson,.json,application/geo+json,application/json" value={file} onChange={setFile} clearable required />
        <Group grow align="flex-start">
          <Select
            label="Manba"
            data={[
              { value: 'import', label: 'GeoJSON import' },
              { value: 'gps', label: "GPS o'lchov" },
            ]}
            value={source}
            onChange={(v) => setSource(v ?? 'import')}
            allowDeselect={false}
          />
          <TextInput label="Manba izohi" required placeholder="Kim, qachon, qanday asbob bilan o'lchagan" value={note} onChange={(e) => setNote(e.currentTarget.value)} />
        </Group>
        <ApiErrorAlert error={error} />
        {report && (
          <>
            <Alert color={ok ? 'teal' : 'red'} icon={ok ? undefined : <IconAlertTriangle size={18} />}>
              {report.message}
            </Alert>
            <ScrollArea.Autosize mah={260}>
              <Table fz="sm">
                <Table.Thead>
                  <Table.Tr>
                    <Table.Th>#</Table.Th>
                    <Table.Th>Uchastka</Table.Th>
                    <Table.Th>Natija</Table.Th>
                  </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                  {report.report.map((row) => (
                    <Table.Tr key={row.index}>
                      <Table.Td>{row.index + 1}</Table.Td>
                      <Table.Td>{row.segment_code ?? '—'}</Table.Td>
                      <Table.Td>
                        {row.status === 'ok' ? (
                          <Text size="sm" c="teal.8">
                            To'g'ri, {formatLength(row.length_m)}
                            {row.replaces_existing ? ' (mavjud geometriya almashtiriladi)' : ''}
                          </Text>
                        ) : (
                          <Text size="sm" c="red.8">
                            {row.message}
                          </Text>
                        )}
                      </Table.Td>
                    </Table.Tr>
                  ))}
                </Table.Tbody>
              </Table>
            </ScrollArea.Autosize>
          </>
        )}
        <Group justify="flex-end">
          <Button variant="default" onClick={reset}>
            Yopish
          </Button>
          <Button variant="light" disabled={!file || note.trim().length === 0} loading={busy} onClick={() => run(true)}>
            Tekshirish
          </Button>
          <Button disabled={!file || !ok || report?.applied || note.trim().length === 0} loading={busy} onClick={() => run(false)}>
            Saqlash
          </Button>
        </Group>
      </Stack>
    </Modal>
  )
}
