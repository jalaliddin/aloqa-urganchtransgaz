import dayjs from 'dayjs'
import {
  Alert, Anchor, Badge, Button, Group, List, Modal, NumberInput, Paper, SegmentedControl, Select, SimpleGrid, Stack, Table, Text, Textarea,
  TextInput, Title,
} from '@mantine/core'
import { DateInput } from '@mantine/dates'
import { useForm } from '@mantine/form'
import { IconAlertTriangle, IconArrowsExchange } from '@tabler/icons-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { api } from '@/api/client'
import type { MaterialItem, MaterialReceipt, WarehouseTransaction } from '@/api/types'
import { useAuth } from '@/auth/AuthContext'
import { DocumentsPanel } from '@/components/common/DocumentsPanel'
import { notifySuccess } from '@/components/common/notify'
import { PageHeader } from '@/components/common/PageHeader'
import { ApiErrorAlert, ErrorState, PageSkeleton } from '@/components/common/States'
import { StatusBadge } from '@/components/common/StatusBadge'
import { formatDate, formatDateTime, formatLength, formatQuantity } from '@/lib/format'
import { useEnum } from '@/lib/meta'

export default function ReceiptDetailPage() {
  const { id } = useParams()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const types = useEnum('material_type')
  const units = useEnum('material_unit')
  const txTypes = useEnum('warehouse_transaction_type')
  const [moving, setMoving] = useState<MaterialItem | null>(null)
  const [deciding, setDeciding] = useState<WarehouseTransaction | null>(null)
  const key = ['material-receipt', Number(id)]

  const { data: receipt, isLoading, error, refetch } = useQuery({
    queryKey: key,
    queryFn: async () => (await api.get<{ data: MaterialReceipt }>(`/material-receipts/${id}`)).data.data,
  })

  const refresh = (data?: MaterialReceipt) => {
    if (data) queryClient.setQueryData(key, data)
    else void queryClient.invalidateQueries({ queryKey: key })
    void queryClient.invalidateQueries({ queryKey: ['material-receipts'] })
  }

  if (isLoading) return <PageSkeleton />
  if (error || !receipt) return <ErrorState error={error} onRetry={() => void refetch()} />

  const discrepancies = receipt.discrepancies ?? []

  return (
    <>
      <PageHeader
        title={`Kirim ${receipt.number}`}
        description={`${formatDate(receipt.receipt_date)}, ${receipt.warehouse_name}${receipt.organization ? `, ${receipt.organization}` : ''}`}
        badges={
          <Group gap={6}>
            {receipt.is_demo && <Badge color="orange">DEMO</Badge>}
            <StatusBadge enumKey="material_receipt_status" value={receipt.status} size="lg" />
          </Group>
        }
      />

      <SimpleGrid cols={{ base: 1, md: 3 }} spacing="md" mb="md">
        <Paper p="md">
          <Text size="xs" c="dimmed">
            Demontaj topshirig'i
          </Text>
          <Anchor component={Link} to={`/dismantling/${receipt.dismantling_order_id}`} fw={600}>
            {receipt.dismantling_order?.number}
          </Anchor>
          <Text size="sm">Demontaj qilingan: {formatLength(receipt.dismantling_order?.actual_length_m)}</Text>
        </Paper>
        <Paper p="md">
          <Text size="xs" c="dimmed">
            Topshiruvchi
          </Text>
          <Text fw={500}>{receipt.handed_over_by}</Text>
          <Text size="xs" c="dimmed" mt={6}>
            Qabul qiluvchi
          </Text>
          <Text fw={500}>{receipt.received_by}</Text>
        </Paper>
        <Paper p="md">
          <Text size="xs" c="dimmed">
            Farq bo'yicha qaror
          </Text>
          {receipt.discrepancy_decided_at ? (
            <>
              <Text size="sm">
                {receipt.discrepancy_decided_by}, {formatDateTime(receipt.discrepancy_decided_at)}
              </Text>
              <Text size="sm">{receipt.discrepancy_explanation}</Text>
            </>
          ) : (
            <Text size="sm">{receipt.status === 'discrepancy' ? 'Kutilmoqda' : 'Talab qilinmaydi'}</Text>
          )}
        </Paper>
      </SimpleGrid>

      {discrepancies.length > 0 && (
        <Alert color="orange" icon={<IconAlertTriangle size={18} />} mb="md" title="Miqdorlarda farq aniqlangan — tekshiruv talab qilinadi">
          <List size="sm">
            {discrepancies.map((d, i) => (
              <List.Item key={i}>{d.message}</List.Item>
            ))}
          </List>
          <Text size="xs" mt="xs">
            Miqdorlar avtomatik tenglashtirilmaydi. Tuzatish faqat alohida, tasdiqlangan ombor harakati bilan amalga oshiriladi.
          </Text>
        </Alert>
      )}

      {receipt.status === 'discrepancy' && receipt.can?.decide && <DecisionForm receipt={receipt} onDone={refresh} />}

      <Paper p={0} mb="md">
        <Title order={3} p="md" pb="xs">
          Materiallar
        </Title>
        <Table.ScrollContainer minWidth={900}>
          <Table fz="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Material</Table.Th>
                <Table.Th ta="right">Topshirilgan</Table.Th>
                <Table.Th ta="right">Qabul qilingan</Table.Th>
                <Table.Th ta="right">Farq</Table.Th>
                <Table.Th ta="right">Qoldiq</Table.Th>
                <Table.Th>O'lchangan</Table.Th>
                <Table.Th />
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {receipt.items?.map((item) => {
                const unit = units.label(item.unit)
                const diff = Number(item.difference)
                return (
                  <Table.Tr key={item.id}>
                    <Table.Td>
                      <Text size="sm" fw={500}>
                        {types.label(item.material_type)}
                      </Text>
                      <Text size="xs" c="dimmed">
                        {[item.description, item.segment_code, item.storage_location].filter(Boolean).join(', ')}
                      </Text>
                    </Table.Td>
                    <Table.Td ta="right">{formatQuantity(item.handed_over_quantity, unit)}</Table.Td>
                    <Table.Td ta="right">{formatQuantity(item.accepted_quantity, unit)}</Table.Td>
                    <Table.Td ta="right" c={diff !== 0 ? 'orange.8' : undefined} fw={diff !== 0 ? 600 : undefined}>
                      {diff === 0 ? '—' : formatQuantity(item.difference, unit)}
                    </Table.Td>
                    <Table.Td ta="right" fw={600}>
                      {formatQuantity(item.balance, unit)}
                    </Table.Td>
                    <Table.Td>{formatDate(item.measured_on)}</Table.Td>
                    <Table.Td>
                      {receipt.can?.move && (
                        <Button size="xs" variant="light" leftSection={<IconArrowsExchange size={14} />} onClick={() => setMoving(item)}>
                          Harakat
                        </Button>
                      )}
                    </Table.Td>
                  </Table.Tr>
                )
              })}
            </Table.Tbody>
          </Table>
        </Table.ScrollContainer>
      </Paper>

      <Paper p={0} mb="md">
        <Title order={3} p="md" pb="xs">
          Ombor harakatlari
        </Title>
        <Table.ScrollContainer minWidth={900}>
          <Table fz="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Sana</Table.Th>
                <Table.Th>Turi</Table.Th>
                <Table.Th ta="right">Miqdor</Table.Th>
                <Table.Th>Hujjat / kontragent</Table.Th>
                <Table.Th>Kiritgan</Table.Th>
                <Table.Th>Holat</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {receipt.items?.flatMap((item) =>
                item.transactions.map((tx) => (
                  <Table.Tr key={tx.id}>
                    <Table.Td>{formatDateTime(tx.created_at)}</Table.Td>
                    <Table.Td>
                      {txTypes.label(tx.type)}
                      <Text size="xs" c="dimmed">
                        {types.label(item.material_type)}
                      </Text>
                    </Table.Td>
                    <Table.Td ta="right">{formatQuantity(tx.quantity, units.label(item.unit))}</Table.Td>
                    <Table.Td>
                      {[tx.document_number, tx.document_date && formatDate(tx.document_date), tx.counterparty].filter(Boolean).join(', ') || '—'}
                      {tx.reason && (
                        <Text size="xs" c="dimmed">
                          {tx.reason}
                        </Text>
                      )}
                    </Table.Td>
                    <Table.Td>{tx.created_by}</Table.Td>
                    <Table.Td>
                      <Group gap={6} wrap="nowrap">
                        <StatusBadge enumKey="warehouse_transaction_status" value={tx.status} size="sm" />
                        {tx.status === 'pending_approval' && receipt.can?.approve_adjustment && tx.created_by_id !== user?.id && (
                          <Button size="xs" variant="subtle" onClick={() => setDeciding(tx)}>
                            Qaror
                          </Button>
                        )}
                      </Group>
                      {tx.decided_by && (
                        <Text size="xs" c="dimmed">
                          {tx.decided_by}: {tx.decision_comment}
                        </Text>
                      )}
                    </Table.Td>
                  </Table.Tr>
                )),
              )}
            </Table.Tbody>
          </Table>
        </Table.ScrollContainer>
      </Paper>

      <Paper p="md">
        <DocumentsPanel
          documentableType="material_receipt"
          documentableId={receipt.id}
          canUpload={!!receipt.can?.attach}
          suggestedTypes={['warehouse_receipt', 'photo', 'write_off', 'other']}
        />
      </Paper>

      {moving && <MoveModal item={moving} onClose={() => setMoving(null)} onDone={refresh} />}
      {deciding && <TransactionDecisionModal tx={deciding} onClose={() => setDeciding(null)} onDone={refresh} />}
    </>
  )
}

function DecisionForm({ receipt, onDone }: { receipt: MaterialReceipt; onDone: (r: MaterialReceipt) => void }) {
  const [approve, setApprove] = useState<'approve' | 'reject'>('approve')
  const [explanation, setExplanation] = useState('')
  const decide = useMutation({
    mutationFn: async () =>
      (await api.post<{ data: MaterialReceipt }>(`/material-receipts/${receipt.id}/decision`, { approve: approve === 'approve', explanation })).data.data,
    onSuccess: (data) => {
      notifySuccess(approve === 'approve' ? 'Farq asoslangan deb tasdiqlandi' : 'Farq asosi rad etildi — qayta tekshiruv talab qilinadi')
      onDone(data)
    },
  })

  return (
    <Paper p="md" mb="md" style={{ borderLeft: '3px solid var(--mantine-color-orange-6)' }}>
      <Title order={3} mb="xs">
        Farq bo'yicha qaror
      </Title>
      <Text size="sm" c="dimmed" mb="sm">
        Materialni topshirgan va qabul qilgan shaxslardan boshqa vakolatli shaxs qaror qabul qiladi.
      </Text>
      <Stack gap="sm">
        <SegmentedControl
          value={approve}
          onChange={(v) => setApprove(v as 'approve' | 'reject')}
          data={[
            { value: 'approve', label: 'Farq asoslangan' },
            { value: 'reject', label: 'Asos yetarli emas' },
          ]}
          w="fit-content"
        />
        <Textarea label="Tushuntirish" required autosize minRows={3} value={explanation} onChange={(e) => setExplanation(e.currentTarget.value)} />
        <ApiErrorAlert error={decide.error} />
        <Group justify="flex-end">
          <Button loading={decide.isPending} disabled={explanation.trim().length < 10} color={approve === 'approve' ? 'teal' : 'red'} onClick={() => decide.mutate()}>
            Qarorni qayd etish
          </Button>
        </Group>
      </Stack>
    </Paper>
  )
}

function MoveModal({ item, onClose, onDone }: { item: MaterialItem; onClose: () => void; onDone: (r: MaterialReceipt) => void }) {
  const txTypes = useEnum('warehouse_transaction_type')
  const units = useEnum('material_unit')
  const form = useForm({
    initialValues: {
      type: 'transfer',
      quantity: '' as number | '',
      document_number: '',
      document_date: dayjs().format('YYYY-MM-DD') as string | null,
      counterparty: '',
      storage_location: '',
      reason: '',
    },
    validate: {
      quantity: (v) => (v === '' || v === 0 ? 'Miqdorni kiriting' : null),
      document_number: (v, values) => (values.type !== 'adjustment' && v.trim() === '' ? 'Hujjat raqamini kiriting' : null),
      reason: (v, values) => (values.type === 'adjustment' && v.trim().length < 5 ? 'Tuzatish sababini kiriting' : null),
    },
  })

  const move = useMutation({
    mutationFn: async (values: typeof form.values) =>
      (
        await api.post<{ data: MaterialReceipt }>(`/material-items/${item.id}/transactions`, {
          ...values,
          document_number: values.document_number || null,
          document_date: values.type === 'adjustment' ? null : values.document_date,
          counterparty: values.counterparty || null,
          storage_location: values.storage_location || null,
          reason: values.reason || null,
        })
      ).data.data,
    onSuccess: (data) => {
      notifySuccess(form.values.type === 'adjustment' ? "Tuzatish so'rovi yuborildi — tasdiq kutilmoqda" : "Harakat qayd etildi")
      onDone(data)
      onClose()
    },
  })

  const isAdjustment = form.values.type === 'adjustment'

  return (
    <Modal opened onClose={onClose} title={<Text fw={600}>Ombor harakati</Text>}>
      <form onSubmit={form.onSubmit((values) => move.mutate(values))}>
        <Stack gap="sm">
          <Text size="sm">
            Joriy qoldiq: <b>{formatQuantity(item.balance, units.label(item.unit))}</b>
          </Text>
          <Select label="Turi" data={txTypes.selectData.filter((t) => t.value !== 'receipt')} allowDeselect={false} {...form.getInputProps('type')} />
          <NumberInput
            label={isAdjustment ? 'Tuzatish miqdori (+/−)' : 'Miqdor'}
            description={isAdjustment ? 'Musbat — ko\'paytirish, manfiy — kamaytirish. Ikkinchi shaxs tasdiqlaguncha qoldiqqa ta\'sir qilmaydi.' : undefined}
            required
            decimalScale={3}
            allowNegative={isAdjustment}
            {...form.getInputProps('quantity')}
          />
          {!isAdjustment && (
            <Group grow align="flex-start">
              <TextInput label="Hujjat raqami" required {...form.getInputProps('document_number')} />
              <DateInput label="Hujjat sanasi" valueFormat="DD.MM.YYYY" maxDate={new Date()} {...form.getInputProps('document_date')} />
            </Group>
          )}
          {!isAdjustment && <TextInput label="Kontragent / qabul qiluvchi" {...form.getInputProps('counterparty')} />}
          <Textarea label={isAdjustment ? 'Tuzatish sababi' : 'Izoh'} required={isAdjustment} autosize minRows={2} {...form.getInputProps('reason')} />
          <ApiErrorAlert error={move.error} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose}>
              Bekor qilish
            </Button>
            <Button type="submit" loading={move.isPending}>
              Saqlash
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  )
}

function TransactionDecisionModal({ tx, onClose, onDone }: { tx: WarehouseTransaction; onClose: () => void; onDone: (r: MaterialReceipt) => void }) {
  const [comment, setComment] = useState('')
  const decide = useMutation({
    mutationFn: async (approve: boolean) =>
      (await api.post<{ data: MaterialReceipt }>(`/warehouse-transactions/${tx.id}/decision`, { approve, comment })).data.data,
    onSuccess: (data, approve) => {
      notifySuccess(approve ? 'Tuzatish tasdiqlandi' : 'Tuzatish rad etildi')
      onDone(data)
      onClose()
    },
  })

  return (
    <Modal opened onClose={onClose} title={<Text fw={600}>Tuzatish bo'yicha qaror</Text>}>
      <Stack gap="sm">
        <Text size="sm">
          Miqdor: <b>{tx.quantity}</b>. Sabab: {tx.reason}. So'ragan: {tx.created_by}.
        </Text>
        <Textarea label="Izoh" required autosize minRows={2} value={comment} onChange={(e) => setComment(e.currentTarget.value)} />
        <ApiErrorAlert error={decide.error} />
        <Group justify="flex-end">
          <Button variant="light" color="red" disabled={comment.trim().length < 5} loading={decide.isPending} onClick={() => decide.mutate(false)}>
            Rad etish
          </Button>
          <Button color="teal" disabled={comment.trim().length < 5} loading={decide.isPending} onClick={() => decide.mutate(true)}>
            Tasdiqlash
          </Button>
        </Group>
      </Stack>
    </Modal>
  )
}
