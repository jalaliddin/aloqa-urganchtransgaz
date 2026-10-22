import {
  ActionIcon, Alert, Badge, Button, Group, Modal, NumberInput, Paper, Select, SimpleGrid, Stack, Switch, Table, Tabs, Text, Textarea,
  TextInput, Title,
} from '@mantine/core'
import { useForm } from '@mantine/form'
import { IconInfoCircle, IconPlus, IconTrash } from '@tabler/icons-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router'
import { api, ApiError } from '@/api/client'
import { useOrganizations, usePipelines } from '@/api/queries'
import type { GasPipeline, MapSettings, Meta, Organization } from '@/api/types'
import { notifySuccess } from '@/components/common/notify'
import { PageHeader } from '@/components/common/PageHeader'
import { ReasonModal } from '@/components/common/ReasonModal'
import { ApiErrorAlert, PageSkeleton } from '@/components/common/States'
import { formatLength } from '@/lib/format'
import { useEnum } from '@/lib/meta'

export default function SettingsPage() {
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') ?? 'map'

  return (
    <>
      <PageHeader title="Sozlamalar" description="Tizim sozlamalari va ma'lumotnomalar. Barcha o'zgarishlar audit jurnaliga yoziladi." />
      <Tabs value={tab} onChange={(t) => setParams({ tab: t ?? 'map' })} keepMounted={false}>
        <Tabs.List mb="md">
          <Tabs.Tab value="map">Xarita provayderi</Tabs.Tab>
          <Tabs.Tab value="organizations">Tashkilotlar</Tabs.Tab>
          <Tabs.Tab value="pipelines">Gaz quvurlari</Tabs.Tab>
          <Tabs.Tab value="rules">Biznes qoidalari</Tabs.Tab>
        </Tabs.List>
        <Tabs.Panel value="map">
          <MapSettingsForm />
        </Tabs.Panel>
        <Tabs.Panel value="organizations">
          <Organizations />
        </Tabs.Panel>
        <Tabs.Panel value="pipelines">
          <Pipelines />
        </Tabs.Panel>
        <Tabs.Panel value="rules">
          <Rules />
        </Tabs.Panel>
      </Tabs>
    </>
  )
}

interface SettingsResponse {
  map: MapSettings
  tolerance: Meta['tolerance']
}

function useSettings() {
  return useQuery({
    queryKey: ['settings'],
    queryFn: async () => (await api.get<{ data: SettingsResponse }>('/settings')).data.data,
  })
}

const TILE_TEMPLATE_ERROR = "http(s) manzil va {z}/{x}/{y} o'rinbosarlari bo'lishi kerak"

const isTileTemplate = (v: string) => /^https?:\/\/\S+$/.test(v) && v.includes('{z}') && v.includes('{x}') && v.includes('{y}')

function MapSettingsForm() {
  const queryClient = useQueryClient()
  const { data, isLoading } = useSettings()
  const form = useForm({
    initialValues: {
      tile_url: '',
      attribution: '',
      max_zoom: 19,
      lat: 41.55,
      lng: 60.63,
      default_zoom: 8,
      satellite_url: '',
      satellite_attribution: '',
      satellite_max_native_zoom: 18,
      satellite_labels_url: '',
      wms_layers: [] as { name: string; url: string; layers: string; transparent: boolean }[],
    },
    validate: {
      tile_url: (v) => (isTileTemplate(v) ? null : TILE_TEMPLATE_ERROR),
      attribution: (v) => (v.trim() ? null : 'Attribution majburiy (OSM litsenziyasi talabi)'),
      satellite_url: (v) => (!v.trim() || isTileTemplate(v) ? null : TILE_TEMPLATE_ERROR),
      satellite_attribution: (v, values) => (values.satellite_url.trim() && !v.trim() ? 'Tasvir provayderining attribution matnini kiriting' : null),
      satellite_labels_url: (v) => (!v.trim() || isTileTemplate(v) ? null : TILE_TEMPLATE_ERROR),
    },
  })

  useEffect(() => {
    if (!data) return
    form.setValues({
      tile_url: data.map.tile_url,
      attribution: data.map.attribution,
      max_zoom: data.map.max_zoom,
      lat: data.map.default_center[0],
      lng: data.map.default_center[1],
      default_zoom: data.map.default_zoom,
      satellite_url: data.map.satellite_url ?? '',
      satellite_attribution: data.map.satellite_attribution ?? '',
      satellite_max_native_zoom: data.map.satellite_max_native_zoom,
      satellite_labels_url: data.map.satellite_labels_url ?? '',
      wms_layers: data.map.wms_layers.map((l) => ({ name: l.name, url: l.url, layers: l.layers, transparent: l.transparent ?? true })),
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data])

  const save = useMutation({
    mutationFn: async (values: typeof form.values) =>
      api.put('/settings/map', {
        tile_url: values.tile_url,
        attribution: values.attribution,
        max_zoom: values.max_zoom,
        default_center: [values.lat, values.lng],
        default_zoom: values.default_zoom,
        // Empty imagery URL switches the satellite layer off
        satellite_url: values.satellite_url.trim() || null,
        satellite_attribution: values.satellite_url.trim() ? values.satellite_attribution : null,
        satellite_max_native_zoom: values.satellite_max_native_zoom,
        satellite_labels_url: values.satellite_url.trim() ? values.satellite_labels_url.trim() || null : null,
        wms_layers: values.wms_layers,
      }),
    onSuccess: () => {
      notifySuccess('Xarita sozlamalari saqlandi')
      void queryClient.invalidateQueries({ queryKey: ['settings'] })
      void queryClient.invalidateQueries({ queryKey: ['meta'] })
    },
  })

  if (isLoading) return <PageSkeleton />

  return (
    <form onSubmit={form.onSubmit((values) => save.mutate(values))}>
      <Stack gap="md" maw={960}>
        <Alert color="blue" icon={<IconInfoCircle size={18} />}>
          OpenStreetMap tile serveri yuqori yuklama uchun mo'ljallanmagan. Ko'p foydalanuvchili ishlatishda korporativ yoki tijorat tile
          provayderiga o'ting va uning attribution talabini kiriting.
        </Alert>
        <Paper p="md">
          <Stack gap="sm">
            <TextInput label="Tile URL shabloni" required description="Masalan: https://tile.openstreetmap.org/{z}/{x}/{y}.png" {...form.getInputProps('tile_url')} />
            <Textarea label="Attribution (HTML, faqat havolalar)" required autosize {...form.getInputProps('attribution')} />
            <SimpleGrid cols={{ base: 2, sm: 4 }}>
              <NumberInput label="Maks. zoom" min={1} max={22} {...form.getInputProps('max_zoom')} />
              <NumberInput label="Boshlang'ich zoom" min={1} max={22} {...form.getInputProps('default_zoom')} />
              <NumberInput label="Markaz: kenglik" decimalScale={5} min={-90} max={90} {...form.getInputProps('lat')} />
              <NumberInput label="Markaz: uzunlik" decimalScale={5} min={-180} max={180} {...form.getInputProps('lng')} />
            </SimpleGrid>
          </Stack>
        </Paper>
        <Paper p="md">
          <Title order={3}>Sun'iy yo'ldosh tasviri</Title>
          <Text size="sm" c="dimmed" mb="sm">
            Xaritada "Sun'iy yo'ldosh" va "Gibrid" (tasvir ustida joy nomlari) qatlamlari. Tasvir URL bo'sh qoldirilsa, bu qatlamlar
            ko'rsatilmaydi. Standart Esri World Imagery o'rniga production'da litsenziyalangan yoki korporativ ortofoto provayderini ulang.
          </Text>
          <Stack gap="sm">
            <TextInput
              label="Tasvir tile URL shabloni"
              description="{z}, {x}, {y} o'rinbosarlari bilan; ArcGIS xizmatlarida tartib …/tile/{z}/{y}/{x}"
              {...form.getInputProps('satellite_url')}
            />
            <Textarea label="Tasvir attribution (HTML, faqat havolalar)" autosize disabled={!form.values.satellite_url.trim()} {...form.getInputProps('satellite_attribution')} />
            <SimpleGrid cols={{ base: 1, sm: 2 }}>
              <TextInput
                label="Joy nomlari qatlami (Gibrid)"
                description="Bo'sh bo'lsa, Gibrid ko'rinishi taklif qilinmaydi"
                disabled={!form.values.satellite_url.trim()}
                {...form.getInputProps('satellite_labels_url')}
              />
              <NumberInput
                label="Tasvirning maks. zoomi"
                description="Undan yaqinroqda tasvir kattalashtirib ko'rsatiladi"
                min={1}
                max={22}
                allowDecimal={false}
                disabled={!form.values.satellite_url.trim()}
                {...form.getInputProps('satellite_max_native_zoom')}
              />
            </SimpleGrid>
          </Stack>
        </Paper>
        <Paper p="md">
          <Group justify="space-between" mb="sm">
            <div>
              <Title order={3}>Korporativ GIS qatlamlari (WMS)</Title>
              <Text size="sm" c="dimmed">
                GeoServer yoki boshqa WMS xizmatidan qo'shimcha qatlamlar (masalan, gaz quvurlari trassasi).
              </Text>
            </div>
            <Button size="xs" variant="light" leftSection={<IconPlus size={14} />} onClick={() => form.insertListItem('wms_layers', { name: '', url: '', layers: '', transparent: true })}>
              Qatlam qo'shish
            </Button>
          </Group>
          <Stack gap="xs">
            {form.values.wms_layers.map((_, index) => (
              <Group key={index} align="flex-end" gap="xs" wrap="wrap">
                <TextInput label="Nomi" w={180} {...form.getInputProps(`wms_layers.${index}.name`)} />
                <TextInput label="WMS URL" style={{ flex: 1, minWidth: 240 }} {...form.getInputProps(`wms_layers.${index}.url`)} />
                <TextInput label="Qatlamlar" w={180} {...form.getInputProps(`wms_layers.${index}.layers`)} />
                <Switch label="Shaffof" mb={8} {...form.getInputProps(`wms_layers.${index}.transparent`, { type: 'checkbox' })} />
                <ActionIcon variant="subtle" color="red" size="lg" onClick={() => form.removeListItem('wms_layers', index)} aria-label="Qatlamni olib tashlash">
                  <IconTrash size={16} />
                </ActionIcon>
              </Group>
            ))}
            {form.values.wms_layers.length === 0 && (
              <Text size="sm" c="dimmed">
                Qo'shimcha qatlam ulanmagan.
              </Text>
            )}
          </Stack>
        </Paper>
        <ApiErrorAlert error={save.error} />
        <Group justify="flex-end">
          <Button type="submit" loading={save.isPending}>
            Saqlash
          </Button>
        </Group>
      </Stack>
    </form>
  )
}

function Organizations() {
  const { data = [] } = useOrganizations()
  const types = useEnum('organization_type')
  const [editing, setEditing] = useState<Organization | 'new' | null>(null)

  return (
    <Stack gap="sm">
      <Group justify="flex-end">
        <Button size="sm" leftSection={<IconPlus size={16} />} onClick={() => setEditing('new')}>
          Tashkilot qo'shish
        </Button>
      </Group>
      <Paper p={0}>
        <Table.ScrollContainer minWidth={640}>
          <Table fz="sm" highlightOnHover>
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Kod</Table.Th>
                <Table.Th>Nomi</Table.Th>
                <Table.Th>Turi</Table.Th>
                <Table.Th>Holat</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data.map((o) => (
                <Table.Tr key={o.id} onClick={() => setEditing(o)} style={{ cursor: 'pointer' }} tabIndex={0} onKeyDown={(e) => e.key === 'Enter' && setEditing(o)}>
                  <Table.Td>{o.code}</Table.Td>
                  <Table.Td>
                    {o.name}
                    {o.is_demo && (
                      <Badge size="xs" color="orange" ml={6}>
                        DEMO
                      </Badge>
                    )}
                  </Table.Td>
                  <Table.Td>{types.label(o.type)}</Table.Td>
                  <Table.Td>{o.is_active ? 'Faol' : 'Nofaol'}</Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </Table.ScrollContainer>
      </Paper>
      {editing && <OrganizationModal organization={editing === 'new' ? null : editing} parents={data} onClose={() => setEditing(null)} />}
    </Stack>
  )
}

function OrganizationModal({ organization, parents, onClose }: { organization: Organization | null; parents: Organization[]; onClose: () => void }) {
  const queryClient = useQueryClient()
  const types = useEnum('organization_type')
  const form = useForm({
    initialValues: {
      code: organization?.code ?? '',
      name: organization?.name ?? '',
      short_name: organization?.short_name ?? '',
      type: organization?.type ?? 'mgqb',
      parent_id: organization?.parent_id ? String(organization.parent_id) : null,
      address: organization?.address ?? '',
      is_active: organization?.is_active ?? true,
    },
  })
  const save = useMutation({
    mutationFn: async (values: typeof form.values) => {
      const payload = { ...values, parent_id: values.parent_id ? Number(values.parent_id) : null, short_name: values.short_name || null, address: values.address || null }
      return organization ? api.put(`/organizations/${organization.id}`, payload) : api.post('/organizations', payload)
    },
    onSuccess: () => {
      notifySuccess('Tashkilot saqlandi')
      void queryClient.invalidateQueries({ queryKey: ['organizations'] })
      onClose()
    },
    onError: (e) => e instanceof ApiError && form.setErrors(Object.fromEntries(Object.entries(e.fieldErrors).map(([k, v]) => [k, v[0]]))),
  })

  return (
    <Modal opened onClose={onClose} title={<Text fw={600}>{organization?.name ?? 'Yangi tashkilot'}</Text>}>
      <form onSubmit={form.onSubmit((v) => save.mutate(v))}>
        <Stack gap="sm">
          <TextInput label="Kod" required {...form.getInputProps('code')} />
          <TextInput label="To'liq nomi" required {...form.getInputProps('name')} />
          <TextInput label="Qisqa nomi" {...form.getInputProps('short_name')} />
          <Select label="Turi" data={types.selectData} allowDeselect={false} {...form.getInputProps('type')} />
          <Select label="Yuqori tashkilot" clearable data={parents.filter((p) => p.id !== organization?.id).map((p) => ({ value: String(p.id), label: p.name }))} {...form.getInputProps('parent_id')} />
          <TextInput label="Manzil" {...form.getInputProps('address')} />
          <Switch label="Faol" {...form.getInputProps('is_active', { type: 'checkbox' })} />
          <ApiErrorAlert error={save.error} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose}>
              Bekor qilish
            </Button>
            <Button type="submit" loading={save.isPending}>
              Saqlash
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  )
}

function Pipelines() {
  const { data = [] } = usePipelines()
  const [editing, setEditing] = useState<GasPipeline | 'new' | null>(null)

  return (
    <Stack gap="sm">
      <Group justify="flex-end">
        <Button size="sm" leftSection={<IconPlus size={16} />} onClick={() => setEditing('new')}>
          Gaz quvuri qo'shish
        </Button>
      </Group>
      <Paper p={0}>
        <Table.ScrollContainer minWidth={640}>
          <Table fz="sm" highlightOnHover>
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Kod</Table.Th>
                <Table.Th>Nomi</Table.Th>
                <Table.Th>MGQB</Table.Th>
                <Table.Th ta="right">Diametr</Table.Th>
                <Table.Th ta="right">Uzunlik</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data.map((p) => (
                <Table.Tr key={p.id} onClick={() => setEditing(p)} style={{ cursor: 'pointer' }} tabIndex={0} onKeyDown={(e) => e.key === 'Enter' && setEditing(p)}>
                  <Table.Td>{p.code}</Table.Td>
                  <Table.Td>{p.name}</Table.Td>
                  <Table.Td>{p.organization}</Table.Td>
                  <Table.Td ta="right">{p.diameter_mm ? `${p.diameter_mm} mm` : '—'}</Table.Td>
                  <Table.Td ta="right">{formatLength(p.length_m)}</Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </Table.ScrollContainer>
      </Paper>
      {editing && <PipelineModal pipeline={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </Stack>
  )
}

function PipelineModal({ pipeline, onClose }: { pipeline: GasPipeline | null; onClose: () => void }) {
  const queryClient = useQueryClient()
  const { data: organizations = [] } = useOrganizations()
  const form = useForm({
    initialValues: {
      organization_id: pipeline ? String(pipeline.organization_id) : '',
      code: pipeline?.code ?? '',
      name: pipeline?.name ?? '',
      diameter_mm: pipeline?.diameter_mm ?? ('' as number | ''),
      length_m: pipeline?.length_m ? Number(pipeline.length_m) : ('' as number | ''),
      description: pipeline?.description ?? '',
      is_active: pipeline?.is_active ?? true,
    },
  })
  const save = useMutation({
    mutationFn: async (values: typeof form.values) => {
      const payload = {
        ...values,
        organization_id: Number(values.organization_id),
        diameter_mm: values.diameter_mm === '' ? null : values.diameter_mm,
        length_m: values.length_m === '' ? null : values.length_m,
        description: values.description || null,
      }
      return pipeline ? api.put(`/gas-pipelines/${pipeline.id}`, payload) : api.post('/gas-pipelines', payload)
    },
    onSuccess: () => {
      notifySuccess('Gaz quvuri saqlandi')
      void queryClient.invalidateQueries({ queryKey: ['gas-pipelines'] })
      onClose()
    },
    onError: (e) => e instanceof ApiError && form.setErrors(Object.fromEntries(Object.entries(e.fieldErrors).map(([k, v]) => [k, v[0]]))),
  })

  return (
    <Modal opened onClose={onClose} title={<Text fw={600}>{pipeline?.name ?? 'Yangi gaz quvuri'}</Text>}>
      <form onSubmit={form.onSubmit((v) => save.mutate(v))}>
        <Stack gap="sm">
          <Select label="Mas'ul MGQB" required data={organizations.map((o) => ({ value: String(o.id), label: o.short_name ?? o.name }))} {...form.getInputProps('organization_id')} />
          <TextInput label="Kod" required {...form.getInputProps('code')} />
          <TextInput label="Nomi" required {...form.getInputProps('name')} />
          <Group grow>
            <NumberInput label="Diametr, mm" min={1} max={2000} {...form.getInputProps('diameter_mm')} />
            <NumberInput label="Uzunlik, m" min={0} decimalScale={2} {...form.getInputProps('length_m')} />
          </Group>
          <Textarea label="Tavsif" autosize {...form.getInputProps('description')} />
          <Switch label="Faol" {...form.getInputProps('is_active', { type: 'checkbox' })} />
          <ApiErrorAlert error={save.error} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose}>
              Bekor qilish
            </Button>
            <Button type="submit" loading={save.isPending}>
              Saqlash
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  )
}

function Rules() {
  const { data } = useSettings()
  const queryClient = useQueryClient()
  const t = data?.tolerance
  const [editingMetal, setEditingMetal] = useState(false)
  const [percent, setPercent] = useState<number | string>('')

  return (
    <Paper p="md" maw={760}>
      <Stack gap="sm">
        <Text size="sm">Chegaralar server muhit o'zgaruvchilari (.env) orqali boshqariladi va barcha tekshiruvlarda bir xil qo'llanadi.</Text>
        <Table fz="sm">
          <Table.Tbody>
            <Table.Tr>
              <Table.Td>Uzunlik farqi chegarasi</Table.Td>
              <Table.Td>
                ±{t?.length_m} m yoki ±{t?.length_percent}% (kattasi)
              </Table.Td>
            </Table.Tr>
            <Table.Tr>
              <Table.Td>Material miqdori farqi chegarasi</Table.Td>
              <Table.Td>±{t?.material_percent}%</Table.Td>
            </Table.Tr>
            <Table.Tr>
              <Table.Td>Rangli metall massasi farqi chegarasi (nazariy / dalolatnoma / ombor)</Table.Td>
              <Table.Td>
                <Group gap="xs">
                  ±{t?.metal_percent}%
                  <Button size="compact-xs" variant="light" onClick={() => setEditingMetal(true)}>
                    O'zgartirish
                  </Button>
                </Group>
              </Table.Td>
            </Table.Tr>
            <Table.Tr>
              <Table.Td>Rangli metall tarkibi (kg/m)</Table.Td>
              <Table.Td>Bo'sh qiymat — noma'lum (0 deb olinmaydi); farq hech qachon yashirilmaydi</Table.Td>
            </Table.Tr>
            <Table.Tr>
              <Table.Td>Uzunlik birligi</Table.Td>
              <Table.Td>Bazada metr (DECIMAL 12,2); interfeysda 1000 m dan km</Table.Td>
            </Table.Tr>
            <Table.Tr>
              <Table.Td>“Aloqa tizimida ishlatilmaydi”</Table.Td>
              <Table.Td>Faqat dalil va ikkinchi shaxs tasdig'i bilan (inventarizatsiya)</Table.Td>
            </Table.Tr>
            <Table.Tr>
              <Table.Td>Demontajga ruxsat</Table.Td>
              <Table.Td>Xavfsizlik va ekspluatatsiya kelishuvlari + naryad-dopusk; murakkab uchastkada mutaxassislar ko'rigi</Table.Td>
            </Table.Tr>
          </Table.Tbody>
        </Table>
      </Stack>
      <ReasonModal
        opened={editingMetal}
        onClose={() => setEditingMetal(false)}
        title="Rangli metall farqi chegarasi"
        description={
          <NumberInput
            label="Yangi chegara, %"
            min={0}
            max={50}
            decimalScale={2}
            value={percent === '' ? (t?.metal_percent ?? '') : percent}
            onChange={setPercent}
            mb="xs"
          />
        }
        confirmLabel="Saqlash"
        onConfirm={async (reason) => {
          await api.put('/settings/tolerance', { metal_percent: percent === '' ? t?.metal_percent : percent, reason })
          notifySuccess('Chegara yangilandi')
          setPercent('')
          void queryClient.invalidateQueries({ queryKey: ['settings'] })
          void queryClient.invalidateQueries({ queryKey: ['meta'] })
        }}
      />
    </Paper>
  )
}
