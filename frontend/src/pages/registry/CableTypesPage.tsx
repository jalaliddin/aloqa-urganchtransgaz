import { Alert, Badge, Button, Drawer, Group, Modal, NumberInput, Paper, Stack, Switch, Table, Text, Textarea, TextInput, Title } from '@mantine/core'
import { useForm } from '@mantine/form'
import { IconInfoCircle, IconPlus } from '@tabler/icons-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { api, ApiError } from '@/api/client'
import { useCableTypes } from '@/api/queries'
import type { CableTypeItem, MetalComposition } from '@/api/types'
import { useAuth } from '@/auth/AuthContext'
import { DocumentsPanel } from '@/components/common/DocumentsPanel'
import { notifySuccess } from '@/components/common/notify'
import { PageHeader } from '@/components/common/PageHeader'
import { ApiErrorAlert, EmptyState, PageSkeleton } from '@/components/common/States'
import { CompositionFormModal, type CompositionScope } from '@/components/metal/CompositionFormModal'
import { CompositionVersionsTable } from '@/components/metal/CompositionVersionsTable'
import { KgPerMetreValue } from '@/components/metal/MetalValue'
import { formatDate } from '@/lib/format'
import { useEnum } from '@/lib/meta'

export default function CableTypesPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const sources = useEnum('composition_source_type')
  const { data: types, isLoading } = useCableTypes()
  const [editing, setEditing] = useState<CableTypeItem | 'new' | null>(null)
  const [composing, setComposing] = useState<{ scope: CompositionScope; current?: MetalComposition | null } | null>(null)
  const [historyFor, setHistoryFor] = useState<CableTypeItem | null>(null)
  const canManage = can('metal_composition.manage')
  const canApprove = can('metal_composition.approve')

  const pending = useQuery({
    queryKey: ['metal-compositions', 'pending'],
    queryFn: async () => (await api.get<{ data: MetalComposition[] }>('/metal-compositions', { params: { status: 'pending' } })).data.data,
    enabled: canApprove || canManage,
  })

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['cable-types'] })
    void queryClient.invalidateQueries({ queryKey: ['metal-compositions'] })
    void queryClient.invalidateQueries({ queryKey: ['cable-type'] })
    void queryClient.invalidateQueries({ queryKey: ['line-metal'] })
  }

  if (isLoading) return <PageSkeleton />

  return (
    <>
      <PageHeader
        title="Kabel turlari va rangli metall tarkibi"
        description="1 metr kabeldagi alyuminiy, qo'rg'oshin va mis (kg/m). Liniya va uchastkalar qiymatni kabel turidan meros oladi; zarur bo'lsa ular uchun alohida tasdiqlangan qiymat kiritiladi."
        actions={
          canManage && (
            <Button leftSection={<IconPlus size={16} />} onClick={() => setEditing('new')}>
              Kabel turi qo'shish
            </Button>
          )
        }
      />

      <Alert variant="light" color="blue" icon={<IconInfoCircle size={18} />} mb="md">
        Bo'sh qiymat "noma'lum" degani va nazariy massada 0 deb olinmaydi. Har bir yangi qiymat ikkinchi vakolatli shaxs tasdiqlaganidan keyin
        amalga kiradi; eski qiymatlar o'chirilmaydi.
      </Alert>

      {(pending.data?.length ?? 0) > 0 && (
        <Paper p="md" mb="md" style={{ borderLeft: '3px solid var(--mantine-color-orange-6)' }}>
          <Title order={3} mb="sm">
            Tasdiq kutilayotgan qiymatlar ({pending.data!.length})
          </Title>
          <CompositionVersionsTable versions={pending.data!} onChanged={refresh} />
        </Paper>
      )}

      <Paper p={0}>
        {(types ?? []).length === 0 ? (
          <EmptyState title="Kabel turi kiritilmagan" description="Kabel markasini qo'shing va uning metall tarkibini kiriting." />
        ) : (
          <Table.ScrollContainer minWidth={1100}>
            <Table fz="sm" highlightOnHover>
              <Table.Thead>
                <Table.Tr>
                  <Table.Th>Kabel turi / markasi</Table.Th>
                  <Table.Th>Alyuminiy</Table.Th>
                  <Table.Th>Qo'rg'oshin</Table.Th>
                  <Table.Th>Mis</Table.Th>
                  <Table.Th>Manba</Table.Th>
                  <Table.Th ta="right">Liniyalar</Table.Th>
                  <Table.Th />
                </Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {types!.map((type) => {
                  const c = type.current_composition
                  return (
                    <Table.Tr key={type.id}>
                      <Table.Td>
                        <Group gap={6}>
                          <Text size="sm" fw={600}>
                            {type.name}
                          </Text>
                          {type.is_demo && (
                            <Badge size="xs" color="orange">
                              DEMO
                            </Badge>
                          )}
                          {!type.is_active && (
                            <Badge size="xs" color="gray">
                              Nofaol
                            </Badge>
                          )}
                        </Group>
                        <Text size="xs" c="dimmed">
                          {type.code}
                          {type.core_count ? `, ${type.core_count} tomir` : ''}
                        </Text>
                      </Table.Td>
                      {c ? (
                        <>
                          <Table.Td>
                            <KgPerMetreValue value={c.aluminium_kg_per_m} />
                          </Table.Td>
                          <Table.Td>
                            <KgPerMetreValue value={c.lead_kg_per_m} />
                          </Table.Td>
                          <Table.Td>
                            <KgPerMetreValue value={c.copper_kg_per_m} />
                          </Table.Td>
                          <Table.Td maw={260}>
                            <Text size="sm" lineClamp={1}>
                              {c.source_reference}
                            </Text>
                            <Text size="xs" c="dimmed">
                              {sources.label(c.source_type)}; tasdiqladi: {c.decided_by ?? '—'}, {formatDate(c.decided_at)}
                            </Text>
                          </Table.Td>
                        </>
                      ) : (
                        <Table.Td colSpan={4}>
                          <Text size="sm" c="orange.8">
                            Tasdiqlangan tarkib yo'q — bu turdagi liniyalar uchun nazariy massa noma'lum.
                          </Text>
                        </Table.Td>
                      )}
                      <Table.Td ta="right">{type.lines_count ?? 0}</Table.Td>
                      <Table.Td>
                        <Group gap={4} justify="flex-end" wrap="nowrap">
                          {(type.pending_count ?? 0) > 0 && (
                            <Badge color="orange" variant="light">
                              {type.pending_count} tasdiq kutmoqda
                            </Badge>
                          )}
                          <Button size="compact-sm" variant="subtle" onClick={() => setHistoryFor(type)}>
                            Tarix va hujjatlar
                          </Button>
                          {canManage && (
                            <Button
                              size="compact-sm"
                              variant="light"
                              onClick={() => setComposing({ scope: { type: 'cable_type', id: type.id, label: type.name }, current: c })}
                            >
                              Qiymat kiritish
                            </Button>
                          )}
                          {canManage && (
                            <Button size="compact-sm" variant="subtle" onClick={() => setEditing(type)}>
                              Tahrirlash
                            </Button>
                          )}
                        </Group>
                      </Table.Td>
                    </Table.Tr>
                  )
                })}
              </Table.Tbody>
            </Table>
          </Table.ScrollContainer>
        )}
      </Paper>

      {editing && <CableTypeModal type={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={refresh} />}
      {composing && <CompositionFormModal scope={composing.scope} current={composing.current} onClose={() => setComposing(null)} onSaved={refresh} />}
      <Drawer opened={historyFor !== null} onClose={() => setHistoryFor(null)} position="right" size="xl" title={<Text fw={600}>{historyFor?.name}</Text>}>
        {historyFor && <TypeHistory type={historyFor} canAttach={canManage} onChanged={refresh} />}
      </Drawer>
    </>
  )
}

function TypeHistory({ type, canAttach, onChanged }: { type: CableTypeItem; canAttach: boolean; onChanged: () => void }) {
  const { data } = useQuery({
    queryKey: ['cable-type', type.id],
    queryFn: async () => (await api.get<{ data: CableTypeItem }>(`/cable-types/${type.id}`)).data.data,
  })

  return (
    <Stack gap="md">
      <div>
        <Text fw={600} mb="xs">
          Qiymatlar tarixi
        </Text>
        <CompositionVersionsTable versions={data?.compositions ?? []} onChanged={onChanged} showScope={false} />
      </div>
      <DocumentsPanel documentableType="cable_type" documentableId={type.id} canUpload={canAttach} suggestedTypes={['technical_passport', 'other']} compact />
    </Stack>
  )
}

function CableTypeModal({ type, onClose, onSaved }: { type: CableTypeItem | null; onClose: () => void; onSaved: () => void }) {
  const form = useForm({
    initialValues: {
      code: type?.code ?? '',
      name: type?.name ?? '',
      brand: type?.brand ?? '',
      core_count: type?.core_count ?? ('' as number | ''),
      description: type?.description ?? '',
      is_active: type?.is_active ?? true,
    },
    validate: {
      code: (v) => (v.trim().length < 2 ? 'Kodni kiriting' : null),
      name: (v) => (v.trim().length < 2 ? 'Nomini kiriting' : null),
    },
  })
  const save = useMutation({
    mutationFn: async (values: typeof form.values) => {
      const payload = { ...values, brand: values.brand || null, core_count: values.core_count === '' ? null : values.core_count, description: values.description || null }
      return type ? api.put(`/cable-types/${type.id}`, payload) : api.post('/cable-types', payload)
    },
    onSuccess: () => {
      notifySuccess('Kabel turi saqlandi')
      onSaved()
      onClose()
    },
    onError: (e) => e instanceof ApiError && form.setErrors(Object.fromEntries(Object.entries(e.fieldErrors).map(([k, v]) => [k, v[0]]))),
  })

  return (
    <Modal opened onClose={onClose} title={<Text fw={600}>{type ? type.name : 'Yangi kabel turi'}</Text>}>
      <form onSubmit={form.onSubmit((v) => save.mutate(v))}>
        <Stack gap="sm">
          <TextInput label="Kod" required {...form.getInputProps('code')} />
          <TextInput label="Nomi (marka va kesim)" required placeholder="MKSB 4x4x1.2" {...form.getInputProps('name')} />
          <Group grow>
            <TextInput label="Markasi" placeholder="MKSB" {...form.getInputProps('brand')} />
            <NumberInput label="Tomirlar soni" min={1} max={2000} allowDecimal={false} {...form.getInputProps('core_count')} />
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
