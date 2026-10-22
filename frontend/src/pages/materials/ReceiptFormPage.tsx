import dayjs from 'dayjs'
import { ActionIcon, Alert, Button, Group, NumberInput, Paper, Select, SimpleGrid, Stack, Text, Textarea, TextInput, Title } from '@mantine/core'
import { DateInput } from '@mantine/dates'
import { useForm } from '@mantine/form'
import { IconInfoCircle, IconPlus, IconTrash } from '@tabler/icons-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import type { MaterialReceipt } from '@/api/types'
import { useAuth } from '@/auth/AuthContext'
import { notifySuccess } from '@/components/common/notify'
import { PageHeader } from '@/components/common/PageHeader'
import { ApiErrorAlert } from '@/components/common/States'
import { formatLength, formatQuantity, formatSignedLength } from '@/lib/format'
import { useEnum } from '@/lib/meta'

interface ReceivableData {
  orders: { id: number; number: string; organization_id: number; line: string; status: string; actual_length_m: string; accepted_cable_m: string; segments: { id: number; code: string }[] }[]
  people: { id: number; name: string; position: string | null; organization_id: number }[]
}

interface ItemDraft {
  material_type: string
  unit: string
  description: string
  handed_over_quantity: number | ''
  accepted_quantity: number | ''
  measured_on: string | null
  cable_segment_id: string | null
  storage_location: string
}

const METAL_TYPES = ['aluminium', 'lead', 'copper']

const today = () => dayjs().format('YYYY-MM-DD')

export default function ReceiptFormPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const [params] = useSearchParams()
  const types = useEnum('material_type')
  const units = useEnum('material_unit')

  const { data } = useQuery({
    queryKey: ['receivable-orders'],
    queryFn: async () => (await api.get<{ data: ReceivableData }>('/material-receipts/receivable-orders')).data.data,
  })

  const form = useForm({
    initialValues: {
      dismantling_order_id: params.get('order') ?? '',
      number: '',
      receipt_date: today() as string | null,
      warehouse_name: '',
      handed_over_by: null as string | null,
      notes: '',
      items: [
        { material_type: 'cable_whole', unit: 'm', description: '', handed_over_quantity: '', accepted_quantity: '', measured_on: today(), cable_segment_id: null, storage_location: '' },
      ] as ItemDraft[],
    },
    validate: {
      dismantling_order_id: (v) => (!v ? 'Demontaj topshirig\'ini tanlang' : null),
      number: (v) => (v.trim().length < 2 ? 'Kirim hujjati raqamini kiriting' : null),
      warehouse_name: (v) => (v.trim().length < 2 ? 'Omborni kiriting' : null),
      handed_over_by: (v) => (!v ? 'Topshiruvchini tanlang' : v === String(user?.id) ? "Topshiruvchi va qabul qiluvchi bir shaxs bo'la olmaydi" : null),
      items: {
        handed_over_quantity: (v) => (v === '' ? 'Miqdorni kiriting' : null),
        accepted_quantity: (v) => (v === '' ? 'Miqdorni kiriting' : null),
        measured_on: (v) => (!v ? "O'lchash sanasini kiriting" : null),
      },
    },
  })

  const order = data?.orders.find((o) => String(o.id) === form.values.dismantling_order_id)
  const people = (data?.people ?? []).filter((p) => !order || p.organization_id === order.organization_id).filter((p) => p.id !== user?.id)

  const create = useMutation({
    mutationFn: async (values: typeof form.values) =>
      (
        await api.post<{ data: MaterialReceipt }>('/material-receipts', {
          ...values,
          dismantling_order_id: Number(values.dismantling_order_id),
          handed_over_by: Number(values.handed_over_by),
          items: values.items.map((i) => ({
            ...i,
            cable_segment_id: i.cable_segment_id ? Number(i.cable_segment_id) : null,
            description: i.description || null,
            storage_location: i.storage_location || null,
          })),
        })
      ).data.data,
    onSuccess: (receipt) => {
      notifySuccess(receipt.status === 'discrepancy' ? `${receipt.number} qabul qilindi — farq aniqlandi, tasdiq talab qilinadi` : `${receipt.number} qabul qilindi`)
      void queryClient.invalidateQueries({ queryKey: ['material-receipts'] })
      navigate(`/materials/${receipt.id}`)
    },
  })

  const newCable = form.values.items
    .filter((i) => ['cable_whole', 'cable_piece'].includes(i.material_type) && i.unit === 'm' && i.accepted_quantity !== '')
    .reduce((sum, i) => sum + Number(i.accepted_quantity), 0)

  return (
    <>
      <PageHeader title="Omborga kirim" description="Siz qabul qiluvchi sifatida qayd etilasiz. Demontajni bajargan yoki unga rahbarlik qilgan shaxs materialni qabul qila olmaydi." />
      <form onSubmit={form.onSubmit((values) => create.mutate(values))} noValidate>
        <Stack gap="md" maw={1100}>
          <Paper p="md">
            <SimpleGrid cols={{ base: 1, sm: 2, lg: 3 }}>
              <Select
                label="Demontaj topshirig'i"
                required
                searchable
                data={(data?.orders ?? []).map((o) => ({ value: String(o.id), label: `${o.number} — ${o.line}` }))}
                {...form.getInputProps('dismantling_order_id')}
              />
              <TextInput label="Kirim hujjati raqami" required {...form.getInputProps('number')} />
              <DateInput label="Kirim sanasi" required valueFormat="DD.MM.YYYY" maxDate={new Date()} {...form.getInputProps('receipt_date')} />
              <TextInput label="Ombor / saqlash joyi" required {...form.getInputProps('warehouse_name')} />
              <Select
                label="Topshiruvchi"
                required
                searchable
                data={people.map((p) => ({ value: String(p.id), label: p.position ? `${p.name} (${p.position})` : p.name }))}
                {...form.getInputProps('handed_over_by')}
              />
              <TextInput label="Qabul qiluvchi" value={user?.name ?? ''} disabled readOnly />
            </SimpleGrid>
            {order && (
              <Alert color="blue" variant="light" icon={<IconInfoCircle size={18} />} mt="md">
                {order.number}: demontaj qilingan {formatLength(order.actual_length_m)}, avval omborga qabul qilingan kabel {formatLength(order.accepted_cable_m)}.
                Ushbu kirim bilan jami {formatLength(Number(order.accepted_cable_m) + newCable)} (farq{' '}
                {formatSignedLength(Number(order.accepted_cable_m) + newCable - Number(order.actual_length_m))}).
              </Alert>
            )}
          </Paper>

          <Paper p="md">
            <Group justify="space-between" mb="sm">
              <Title order={3}>Materiallar</Title>
              <Button
                size="xs"
                variant="light"
                leftSection={<IconPlus size={14} />}
                onClick={() =>
                  form.insertListItem('items', { material_type: 'scrap_metal', unit: 'kg', description: '', handed_over_quantity: '', accepted_quantity: '', measured_on: today(), cable_segment_id: null, storage_location: '' })
                }
              >
                Material qo'shish
              </Button>
            </Group>
            <Stack gap="md">
              {form.values.items.map((item, index) => {
                const diff = item.handed_over_quantity !== '' && item.accepted_quantity !== '' ? Number(item.accepted_quantity) - Number(item.handed_over_quantity) : null
                return (
                  <Paper key={index} p="sm" bg="gray.0">
                    <SimpleGrid cols={{ base: 1, sm: 2, lg: 4 }}>
                      <Select
                        label="Material turi"
                        data={types.selectData}
                        allowDeselect={false}
                        {...form.getInputProps(`items.${index}.material_type`)}
                        onChange={(v) => {
                          form.setFieldValue(`items.${index}.material_type`, v ?? 'other')
                          // Metals are weighed so they can be compared with the act and theory
                          if (v && METAL_TYPES.includes(v)) form.setFieldValue(`items.${index}.unit`, 'kg')
                        }}
                      />
                      <Select
                        label="O'lchov birligi"
                        data={units.selectData}
                        allowDeselect={false}
                        disabled={METAL_TYPES.includes(item.material_type)}
                        description={METAL_TYPES.includes(item.material_type) ? 'Rangli metall — faqat kg' : undefined}
                        {...form.getInputProps(`items.${index}.unit`)}
                      />
                      <NumberInput label="Topshirilgan miqdor" required min={0} decimalScale={3} thousandSeparator=" " {...form.getInputProps(`items.${index}.handed_over_quantity`)} />
                      <NumberInput label="Qabul qilingan miqdor" required min={0} decimalScale={3} thousandSeparator=" " {...form.getInputProps(`items.${index}.accepted_quantity`)} />
                      <DateInput label="Tortish / o'lchash sanasi" required valueFormat="DD.MM.YYYY" maxDate={new Date()} {...form.getInputProps(`items.${index}.measured_on`)} />
                      <Select label="Uchastka" clearable data={(order?.segments ?? []).map((s) => ({ value: String(s.id), label: s.code }))} {...form.getInputProps(`items.${index}.cable_segment_id`)} />
                      <TextInput label="Saqlash joyi" {...form.getInputProps(`items.${index}.storage_location`)} />
                      <TextInput label="Tavsif" {...form.getInputProps(`items.${index}.description`)} />
                    </SimpleGrid>
                    <Group justify="space-between" mt="xs">
                      <Text size="sm" c={diff !== null && diff !== 0 ? 'orange.8' : 'dimmed'}>
                        {diff === null ? 'Farq: —' : diff === 0 ? 'Farq yo\'q' : `Farq: ${diff > 0 ? '+' : '−'}${formatQuantity(Math.abs(diff), units.label(item.unit))} — saqlangandan so'ng tushuntirish va tasdiq talab qilinadi`}
                      </Text>
                      {form.values.items.length > 1 && (
                        <ActionIcon variant="subtle" color="red" onClick={() => form.removeListItem('items', index)} aria-label="Materialni olib tashlash">
                          <IconTrash size={16} />
                        </ActionIcon>
                      )}
                    </Group>
                  </Paper>
                )
              })}
            </Stack>
            <Textarea label="Izoh" mt="md" autosize minRows={2} {...form.getInputProps('notes')} />
          </Paper>

          <ApiErrorAlert error={create.error} />
          <Group justify="flex-end">
            <Button variant="default" onClick={() => navigate(-1)}>
              Bekor qilish
            </Button>
            <Button type="submit" loading={create.isPending}>
              Kirimni saqlash
            </Button>
          </Group>
        </Stack>
      </form>
    </>
  )
}
