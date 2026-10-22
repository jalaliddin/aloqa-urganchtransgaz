import { Alert, Button, Checkbox, Group, NumberInput, Paper, Select, SimpleGrid, Stack, Table, Text, Textarea, TextInput, Title } from '@mantine/core'
import { DatePickerInput, DateInput } from '@mantine/dates'
import { useForm } from '@mantine/form'
import { useDebouncedValue } from '@mantine/hooks'
import { IconInfoCircle } from '@tabler/icons-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { useUserDirectory } from '@/api/queries'
import type { CableLine, DismantlingOrder, Paginated } from '@/api/types'
import { notifySuccess } from '@/components/common/notify'
import { PageHeader } from '@/components/common/PageHeader'
import { ApiErrorAlert } from '@/components/common/States'
import { StatusBadge } from '@/components/common/StatusBadge'
import { formatLength } from '@/lib/format'
import { useEnum } from '@/lib/meta'

export default function DismantlingFormPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [params] = useSearchParams()
  const flags = useEnum('complexity_flag')
  const [lineSearch, setLineSearch] = useState('')
  const [q] = useDebouncedValue(lineSearch, 300)

  const form = useForm({
    initialValues: {
      cable_line_id: params.get('line') ?? '',
      segments: {} as Record<number, { selected: boolean; planned_length_m: number | '' }>,
      work_plan_number: '',
      order_number: '',
      order_date: null as string | null,
      brigade_name: '',
      work_supervisor_id: null as string | null,
      work_supervisor_name: '',
      planned: [null, null] as [string | null, string | null],
      notes: '',
    },
    validate: {
      cable_line_id: (v) => (!v ? 'Kabel liniyasini tanlang' : null),
    },
  })

  const lines = useQuery({
    queryKey: ['line-picker', q],
    queryFn: async () => (await api.get<Paginated<CableLine>>('/cable-lines', { params: { q: q || undefined, per_page: 50 } })).data.data,
  })
  const line = useQuery({
    queryKey: ['cable-line', Number(form.values.cable_line_id)],
    queryFn: async () => (await api.get<{ data: CableLine }>(`/cable-lines/${form.values.cable_line_id}`)).data.data,
    enabled: !!form.values.cable_line_id,
  })
  const people = useUserDirectory(line.data?.organization_id ?? null)

  const segments = line.data?.segments ?? []
  const available = segments.filter((s) => !['completed', 'left_in_place'].includes(s.dismantling_status))
  const selectedIds = available.filter((s) => form.values.segments[s.id]?.selected).map((s) => s.id)
  const notInspected = available.filter((s) => selectedIds.includes(s.id) && s.inspection_status !== 'inspected')
  const complex = available.filter((s) => selectedIds.includes(s.id) && s.is_complex)

  const create = useMutation({
    mutationFn: async (values: typeof form.values) =>
      (
        await api.post<{ data: DismantlingOrder }>('/dismantling-orders', {
          cable_line_id: Number(values.cable_line_id),
          segments: selectedIds.map((id) => ({
            id,
            planned_length_m: values.segments[id]?.planned_length_m === '' ? null : values.segments[id]?.planned_length_m,
          })),
          work_plan_number: values.work_plan_number || null,
          order_number: values.order_number || null,
          order_date: values.order_date,
          brigade_name: values.brigade_name || null,
          work_supervisor_id: values.work_supervisor_id ? Number(values.work_supervisor_id) : null,
          work_supervisor_name: values.work_supervisor_name || null,
          planned_start: values.planned[0],
          planned_end: values.planned[1],
          notes: values.notes || null,
        })
      ).data.data,
    onSuccess: (order) => {
      notifySuccess(`${order.number} demontaj topshirig'i yaratildi`)
      void queryClient.invalidateQueries({ queryKey: ['dismantling-orders'] })
      navigate(`/dismantling/${order.id}`)
    },
  })

  const toggle = (id: number, selected: boolean, defaultLength: string | null) =>
    form.setFieldValue(`segments.${id}`, { selected, planned_length_m: form.values.segments[id]?.planned_length_m ?? (defaultLength ? Number(defaultLength) : '') })

  return (
    <>
      <PageHeader title="Yangi demontaj topshirig'i" description="Topshiriq qoralama sifatida yaratiladi; keyin ko'rik va kelishuv bosqichlaridan o'tadi." />
      <form onSubmit={form.onSubmit((values) => create.mutate(values))} noValidate>
        <Stack gap="md" maw={1100}>
          <Paper p="md">
            <Select
              label="Kabel liniyasi"
              required
              searchable
              searchValue={lineSearch}
              onSearchChange={setLineSearch}
              data={[
                ...(line.data && !lines.data?.some((l) => l.id === line.data!.id) ? [{ value: String(line.data.id), label: `${line.data.registry_number} — ${line.data.name}` }] : []),
                ...(lines.data ?? []).map((l) => ({ value: String(l.id), label: `${l.registry_number} — ${l.name}` })),
              ]}
              filter={({ options }) => options}
              nothingFoundMessage="Liniya topilmadi"
              {...form.getInputProps('cable_line_id')}
              onChange={(v) => {
                form.setFieldValue('cable_line_id', v ?? '')
                form.setFieldValue('segments', {})
              }}
            />
          </Paper>

          {line.data && (
            <Paper p="md">
              <Title order={3} mb="xs">
                Uchastkalar
              </Title>
              <Table fz="sm">
                <Table.Thead>
                  <Table.Tr>
                    <Table.Th w={40} />
                    <Table.Th>Uchastka</Table.Th>
                    <Table.Th>Tekshiruv</Table.Th>
                    <Table.Th>Murakkablik</Table.Th>
                    <Table.Th w={180}>Rejalashtirilgan, m</Table.Th>
                  </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                  {available.map((s) => (
                    <Table.Tr key={s.id}>
                      <Table.Td>
                        <Checkbox
                          aria-label={s.code}
                          checked={!!form.values.segments[s.id]?.selected}
                          onChange={(e) => toggle(s.id, e.currentTarget.checked, s.measured_length_m ?? s.design_length_m)}
                        />
                      </Table.Td>
                      <Table.Td>
                        <Text size="sm" fw={500}>
                          {s.code}
                        </Text>
                        <Text size="xs" c="dimmed">
                          Hisobda: {formatLength(s.measured_length_m ?? s.design_length_m)}
                        </Text>
                      </Table.Td>
                      <Table.Td>
                        <StatusBadge enumKey="segment_inspection_status" value={s.inspection_status} size="sm" />
                      </Table.Td>
                      <Table.Td>
                        <Text size="xs">{s.complexity_flags.map((f) => flags.label(f)).join(', ') || '—'}</Text>
                      </Table.Td>
                      <Table.Td>
                        <NumberInput
                          size="xs"
                          aria-label={`${s.code} rejalashtirilgan uzunlik`}
                          min={0}
                          decimalScale={2}
                          disabled={!form.values.segments[s.id]?.selected}
                          {...form.getInputProps(`segments.${s.id}.planned_length_m`)}
                        />
                      </Table.Td>
                    </Table.Tr>
                  ))}
                </Table.Tbody>
              </Table>
              {available.length < segments.length && (
                <Text size="xs" c="dimmed" mt="xs">
                  Demontaji yakunlangan yoki joyida qoldirilgan uchastkalar ko'rsatilmaydi.
                </Text>
              )}
              {notInspected.length > 0 && (
                <Alert color="blue" icon={<IconInfoCircle size={18} />} mt="sm">
                  {notInspected.map((s) => s.code).join(', ')} hali tasdiqlangan inventarizatsiyadan o'tmagan — kelishuv bosqichiga o'tishdan oldin
                  inventarizatsiya talab qilinadi.
                </Alert>
              )}
              {complex.length > 0 && (
                <Alert color="orange" icon={<IconInfoCircle size={18} />} mt="sm">
                  Murakkab/xavfli uchastkalar tanlangan: ishga ruxsatdan oldin mutaxassislar ko'rigi va rasmiy kelishuvlar qayd etilishi shart.
                </Alert>
              )}
            </Paper>
          )}

          <Paper p="md">
            <SimpleGrid cols={{ base: 1, sm: 2, lg: 3 }}>
              <TextInput label="Ish reja raqami" {...form.getInputProps('work_plan_number')} />
              <TextInput label="Buyruq raqami" {...form.getInputProps('order_number')} />
              <DateInput label="Buyruq sanasi" valueFormat="DD.MM.YYYY" clearable {...form.getInputProps('order_date')} />
              <TextInput label="Ish bajaruvchi brigada" {...form.getInputProps('brigade_name')} />
              <Select
                label="Ish rahbari (tizim foydalanuvchisi)"
                searchable
                clearable
                data={(people.data ?? []).map((p) => ({ value: String(p.id), label: p.name }))}
                {...form.getInputProps('work_supervisor_id')}
              />
              <TextInput label="Ish rahbari (tashqi bo'lsa)" {...form.getInputProps('work_supervisor_name')} />
              <DatePickerInput type="range" label="Rejalashtirilgan muddat" valueFormat="DD.MM.YYYY" clearable {...form.getInputProps('planned')} />
            </SimpleGrid>
            <Textarea label="Izoh" mt="sm" autosize minRows={2} {...form.getInputProps('notes')} />
          </Paper>

          <ApiErrorAlert error={create.error} />
          <Group justify="flex-end">
            <Button variant="default" onClick={() => navigate(-1)}>
              Bekor qilish
            </Button>
            <Button type="submit" loading={create.isPending} disabled={selectedIds.length === 0}>
              Topshiriqni yaratish
            </Button>
          </Group>
        </Stack>
      </form>
    </>
  )
}
