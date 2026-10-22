import dayjs from 'dayjs'
import {
  Alert, Anchor, Badge, Button, Grid, Group, List, Modal, NumberInput, Paper, Select, SimpleGrid, Stack, Stepper, Table, Text, Textarea,
  TextInput, ThemeIcon, Timeline, Title,
} from '@mantine/core'
import { DateInput, DateTimePicker } from '@mantine/dates'
import { useForm } from '@mantine/form'
import {
  IconAlertTriangle, IconBuildingWarehouse, IconCheck, IconCircleDashed, IconFileTypePdf, IconPlus, IconShieldCheck, IconX,
} from '@tabler/icons-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { api, downloadFile } from '@/api/client'
import type { DismantlingOrder } from '@/api/types'
import { useAuth } from '@/auth/AuthContext'
import { DocumentsPanel } from '@/components/common/DocumentsPanel'
import { notifyError, notifySuccess } from '@/components/common/notify'
import { PageHeader } from '@/components/common/PageHeader'
import { ReasonModal } from '@/components/common/ReasonModal'
import { ApiErrorAlert, ErrorState, PageSkeleton } from '@/components/common/States'
import { StatusBadge } from '@/components/common/StatusBadge'
import { formatDate, formatDateTime, formatLength, formatPercent, formatSignedLength } from '@/lib/format'
import { useEnum } from '@/lib/meta'
import { MetalBalanceCard } from './MetalBalanceCard'

/** Main path shown in the stepper; side states (suspended, leave in place, cancelled) are shown as badges. */
const MAIN_PATH = ['draft', 'pending_review', 'pending_agreement', 'approved', 'in_progress', 'completed']
const REASON_REQUIRED = ['suspended', 'cancelled', 'leave_in_place_pending']
const AUTHORIZE_ONLY = ['approved', 'left_in_place']

export default function DismantlingDetailPage() {
  const { id } = useParams()
  const queryClient = useQueryClient()
  const { can } = useAuth()
  const statuses = useEnum('dismantling_order_status')
  const approvalTypes = useEnum('approval_type')
  const eventTypes = useEnum('dismantling_event_type')
  const [transition, setTransition] = useState<string | null>(null)
  const [approvalOpen, setApprovalOpen] = useState(false)
  const [eventOpen, setEventOpen] = useState(false)
  const [revoking, setRevoking] = useState<number | null>(null)
  const key = ['dismantling-order', Number(id)]

  const { data: order, isLoading, error, refetch } = useQuery({
    queryKey: key,
    queryFn: async () => (await api.get<{ data: DismantlingOrder }>(`/dismantling-orders/${id}`)).data.data,
  })

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: key })
    void queryClient.invalidateQueries({ queryKey: ['dismantling-orders'] })
    void queryClient.invalidateQueries({ queryKey: ['cable-line'] })
  }

  if (isLoading) return <PageSkeleton />
  if (error || !order) return <ErrorState error={error} onRetry={() => void refetch()} />

  const activeStep = MAIN_PATH.indexOf(order.status)
  const requiredApprovals = order.checks?.required_approvals ?? []
  const validApprovals = (order.approvals ?? []).filter((a) => !a.revoked_at && a.decision === 'approved')
  const canTransition = !!order.can?.transition
  const transitions = order.allowed_transitions.filter((t) => !AUTHORIZE_ONLY.includes(t) || order.can?.authorize)

  return (
    <>
      <PageHeader
        title={`${order.number} — demontaj topshirig'i`}
        description={
          <>
            <Anchor component={Link} to={`/registry/${order.cable_line_id}`} size="sm">
              {order.line?.registry_number} — {order.line?.name}
            </Anchor>
            {order.organization ? `, ${order.organization}` : ''}
          </>
        }
        badges={
          <Group gap={6}>
            {order.is_demo && <Badge color="orange">DEMO</Badge>}
            {order.is_complex && (
              <Badge color="orange" variant="outline" leftSection={<IconAlertTriangle size={12} />}>
                Murakkab uchastka
              </Badge>
            )}
            <StatusBadge enumKey="dismantling_order_status" value={order.status} size="lg" />
          </Group>
        }
        actions={
          <Button variant="default" leftSection={<IconFileTypePdf size={16} />} onClick={() => downloadFile(`/dismantling-orders/${order.id}/act`).catch((e) => notifyError(e))}>
            Dalolatnoma loyihasi (PDF)
          </Button>
        }
      />

      <Alert color="gray" variant="light" icon={<IconShieldCheck size={18} />} mb="md">
        Dastur xavfsizlik ruxsatini bermaydi va qazish usulini belgilamaydi. U faqat vakolatli shaxslar kiritgan ko'rik, kelishuv va ruxsatlarni qayd
        etadi; magistral gaz quvuri yaqinidagi ishlar amaldagi xavfsizlik talablari asosida bajariladi.
      </Alert>

      <Paper p="md" mb="md">
        <Stepper active={activeStep === -1 ? 0 : activeStep} size="xs" allowNextStepsSelect={false} iconSize={28}>
          {MAIN_PATH.map((step) => (
            <Stepper.Step key={step} label={statuses.label(step)} />
          ))}
        </Stepper>
        {activeStep === -1 && (
          <Text size="sm" mt="sm">
            Joriy holat: <StatusBadge enumKey="dismantling_order_status" value={order.status} />
            {order.suspension_reason && order.status === 'suspended' ? ` — ${order.suspension_reason}` : ''}
          </Text>
        )}
      </Paper>

      {canTransition && transitions.length > 0 && (
        <Paper p="md" mb="md">
          <Title order={3} mb="sm">
            Keyingi qadam
          </Title>
          <SimpleGrid cols={{ base: 1, sm: 2, lg: 3 }} spacing="sm">
            {transitions.map((target) => {
              const missing = (order.checks?.[target] ?? []).filter((m) => !m.startsWith('Sabab'))
              return (
                <Paper key={target} p="sm" bg={missing.length ? 'gray.0' : undefined}>
                  <Group justify="space-between" wrap="nowrap" mb={missing.length ? 6 : 0}>
                    <StatusBadge enumKey="dismantling_order_status" value={target} />
                    <Button size="xs" variant={missing.length ? 'default' : 'filled'} color={['cancelled', 'suspended'].includes(target) ? 'red' : undefined} onClick={() => setTransition(target)}>
                      O'tkazish
                    </Button>
                  </Group>
                  {missing.length > 0 && (
                    <List size="xs" c="dimmed" spacing={2} icon={<IconCircleDashed size={12} />}>
                      {missing.map((m) => (
                        <List.Item key={m}>{m}</List.Item>
                      ))}
                    </List>
                  )}
                </Paper>
              )
            })}
          </SimpleGrid>
        </Paper>
      )}

      <MetalBalanceCard order={order} onChanged={refresh} />

      <Grid gap="md">
        <Grid.Col span={{ base: 12, lg: 7 }}>
          <Stack gap="md">
            <Paper p="md">
              <SimpleGrid cols={{ base: 1, sm: 2 }} spacing="sm">
                <Field label="Ish reja / buyruq" value={`${order.work_plan_number ?? '—'} / ${order.order_number ?? '—'} ${order.order_date ? `(${formatDate(order.order_date)})` : ''}`} />
                <Field label="Brigada" value={order.brigade_name} />
                <Field label="Ish rahbari" value={order.work_supervisor ?? order.work_supervisor_name} />
                <Field label="Reja muddati" value={`${formatDate(order.planned_start)} — ${formatDate(order.planned_end)}`} />
                <Field label="Amaldagi muddat" value={`${formatDate(order.actual_start)} — ${formatDate(order.actual_end)}`} />
                <Field label="Yaratgan" value={order.created_by} />
              </SimpleGrid>
              <SimpleGrid cols={3} mt="md">
                <Stat label="Rejalashtirilgan" value={formatLength(order.planned_length_m)} />
                <Stat label="Amalda chiqarilgan" value={formatLength(order.actual_length_m)} />
                <Stat label="Farq / bajarilish" value={`${formatSignedLength(order.difference_m)}, ${formatPercent(order.percent)}`} />
              </SimpleGrid>
              {order.notes && (
                <Text size="sm" mt="sm">
                  {order.notes}
                </Text>
              )}
            </Paper>

            <Paper p={0}>
              <Title order={3} p="md" pb="xs">
                Uchastkalar
              </Title>
              <Table.ScrollContainer minWidth={560}>
                <Table fz="sm">
                  <Table.Thead>
                    <Table.Tr>
                      <Table.Th>Uchastka</Table.Th>
                      <Table.Th ta="right">Reja</Table.Th>
                      <Table.Th>Tekshiruv</Table.Th>
                      <Table.Th>Xavf</Table.Th>
                    </Table.Tr>
                  </Table.Thead>
                  <Table.Tbody>
                    {order.segments?.map((s) => (
                      <Table.Tr key={s.id}>
                        <Table.Td>
                          {s.code}
                          {!s.has_geometry && (
                            <Text size="xs" c="dimmed">
                              Geometriya kiritilmagan
                            </Text>
                          )}
                        </Table.Td>
                        <Table.Td ta="right">{formatLength(s.planned_length_m)}</Table.Td>
                        <Table.Td>
                          <StatusBadge enumKey="segment_inspection_status" value={s.inspection_status} size="sm" />
                        </Table.Td>
                        <Table.Td>
                          <StatusBadge enumKey="risk_level" value={s.risk_level} size="sm" />
                        </Table.Td>
                      </Table.Tr>
                    ))}
                  </Table.Tbody>
                </Table>
              </Table.ScrollContainer>
            </Paper>

            <Paper p="md">
              <Group justify="space-between" mb="sm">
                <Title order={3}>Bajarilgan ishlar va hodisalar</Title>
                {order.can?.record_event && (
                  <Button size="xs" leftSection={<IconPlus size={14} />} onClick={() => setEventOpen(true)}>
                    Qayd etish
                  </Button>
                )}
              </Group>
              <Timeline bulletSize={20} lineWidth={2}>
                {order.events?.map((e) => (
                  <Timeline.Item
                    key={e.id}
                    color={e.type === 'incident' || e.type === 'nonconformity' ? 'red' : e.type === 'progress' ? 'teal' : 'steel'}
                    title={
                      <Text size="sm" fw={600}>
                        {e.type === 'status_change' ? `${statuses.label(e.from_status)} → ${statuses.label(e.to_status)}` : eventTypes.label(e.type)}
                        {e.extracted_length_m ? `: ${formatLength(e.extracted_length_m)}` : ''}
                      </Text>
                    }
                  >
                    {e.description && <Text size="sm">{e.description}</Text>}
                    <Text size="xs" c="dimmed">
                      {formatDateTime(e.occurred_at)}
                      {e.user ? `, ${e.user}` : ''}
                      {e.segment_code ? `, ${e.segment_code}` : ''}
                    </Text>
                  </Timeline.Item>
                ))}
              </Timeline>
            </Paper>
          </Stack>
        </Grid.Col>

        <Grid.Col span={{ base: 12, lg: 5 }}>
          <Stack gap="md">
            <Paper p="md">
              <Group justify="space-between" mb="sm">
                <Title order={3}>Kelishuvlar va ko'riklar</Title>
                {(order.can?.record_safety || order.can?.record_specialist || order.can?.authorize) && !['completed', 'left_in_place', 'cancelled'].includes(order.status) && (
                  <Button size="xs" leftSection={<IconPlus size={14} />} onClick={() => setApprovalOpen(true)}>
                    Qayd etish
                  </Button>
                )}
              </Group>
              <Stack gap={6} mb="md">
                {requiredApprovals.map((type) => {
                  const ok = validApprovals.some((a) => a.type === type)
                  return (
                    <Group key={type} gap="xs" wrap="nowrap">
                      <ThemeIcon size="sm" radius="xl" color={ok ? 'teal' : 'gray'} variant={ok ? 'filled' : 'light'}>
                        {ok ? <IconCheck size={12} /> : <IconCircleDashed size={12} />}
                      </ThemeIcon>
                      <Text size="sm">
                        {approvalTypes.label(type)} — {ok ? 'mavjud' : 'talab qilinadi'}
                      </Text>
                    </Group>
                  )
                })}
              </Stack>
              <Stack gap="xs">
                {(order.approvals ?? []).map((a) => (
                  <Paper key={a.id} p="xs" withBorder style={{ opacity: a.revoked_at ? 0.6 : 1 }}>
                    <Group justify="space-between" wrap="nowrap" align="flex-start">
                      <div>
                        <Text size="sm" fw={500}>
                          {approvalTypes.label(a.type)}
                        </Text>
                        <Group gap={6}>
                          <StatusBadge enumKey="approval_decision" value={a.decision} size="sm" />
                          {a.revoked_at && (
                            <Badge size="sm" color="gray" variant="outline">
                              Bekor qilingan
                            </Badge>
                          )}
                        </Group>
                        <Text size="xs" c="dimmed">
                          {[a.reference_number, a.reference_date && formatDate(a.reference_date), a.external_party].filter(Boolean).join(', ')}
                        </Text>
                        <Text size="xs" c="dimmed">
                          {a.decided_by}, {formatDateTime(a.decided_at)}
                        </Text>
                        {a.comment && <Text size="xs">{a.comment}</Text>}
                        {a.revoke_reason && (
                          <Text size="xs" c="red">
                            Bekor qilish sababi: {a.revoke_reason}
                          </Text>
                        )}
                      </div>
                      {!a.revoked_at && (order.can?.record_safety || order.can?.record_specialist || order.can?.authorize) && (
                        <Button size="xs" variant="subtle" color="red" style={{ flexShrink: 0 }} onClick={() => setRevoking(a.id)}>
                          Bekor qilish
                        </Button>
                      )}
                    </Group>
                  </Paper>
                ))}
              </Stack>
            </Paper>

            <Paper p="md">
              <Group justify="space-between" mb="sm">
                <Title order={3}>Ombor kirimlari</Title>
                {can('materials.receive') && ['in_progress', 'partially_completed', 'completed'].includes(order.status) && (
                  <Button size="xs" variant="light" leftSection={<IconBuildingWarehouse size={14} />} component={Link} to={`/materials/new?order=${order.id}`}>
                    Kirim qilish
                  </Button>
                )}
              </Group>
              {(order.material_receipts ?? []).length === 0 ? (
                <Text size="sm" c="dimmed">
                  Kirim hujjati yo'q.
                </Text>
              ) : (
                <Stack gap={4}>
                  {order.material_receipts!.map((r) => (
                    <Group key={r.id} justify="space-between">
                      <Anchor component={Link} to={`/materials/${r.id}`} size="sm">
                        {r.number} ({formatDate(r.receipt_date)})
                      </Anchor>
                      <StatusBadge enumKey="material_receipt_status" value={r.status} size="sm" />
                    </Group>
                  ))}
                </Stack>
              )}
            </Paper>

            <Paper p="md">
              <DocumentsPanel
                documentableType="dismantling_order"
                documentableId={order.id}
                canUpload={!!order.can?.attach}
                suggestedTypes={['work_permit', 'agreement', 'dismantling_act', 'leave_in_place_decision', 'company_order', 'photo']}
                onChanged={refresh}
                compact
              />
            </Paper>
          </Stack>
        </Grid.Col>
      </Grid>

      {transition && (
        <TransitionModal order={order} target={transition} onClose={() => setTransition(null)} onDone={refresh} />
      )}
      {approvalOpen && <ApprovalModal order={order} onClose={() => setApprovalOpen(false)} onDone={refresh} />}
      {eventOpen && <EventModal order={order} onClose={() => setEventOpen(false)} onDone={refresh} />}
      <ReasonModal
        opened={revoking !== null}
        onClose={() => setRevoking(null)}
        title="Kelishuvni bekor qilish"
        description="Bekor qilingan kelishuv keyingi holat o'zgarishlarida hisobga olinmaydi."
        confirmLabel="Bekor qilish"
        color="red"
        onConfirm={async (reason) => {
          await api.post(`/dismantling-orders/${order.id}/approvals/${revoking}/revoke`, { reason })
          notifySuccess('Kelishuv bekor qilindi')
          refresh()
        }}
      />
    </>
  )
}

function Field({ label, value }: { label: string; value: string | null | undefined }) {
  return (
    <div>
      <Text size="xs" c="dimmed">
        {label}
      </Text>
      <Text size="sm">{value?.trim() || '—'}</Text>
    </div>
  )
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <Text size="xs" c="dimmed">
        {label}
      </Text>
      <Text fw={600}>{value}</Text>
    </div>
  )
}

function TransitionModal({ order, target, onClose, onDone }: { order: DismantlingOrder; target: string; onClose: () => void; onDone: () => void }) {
  const statuses = useEnum('dismantling_order_status')
  const [reason, setReason] = useState('')
  const missing = (order.checks?.[target] ?? []).filter((m) => !m.startsWith('Sabab'))
  const reasonRequired = REASON_REQUIRED.includes(target)

  const run = useMutation({
    mutationFn: async () => api.post(`/dismantling-orders/${order.id}/transition`, { status: target, reason: reason || null }),
    onSuccess: () => {
      notifySuccess(`Holat o'zgartirildi: ${statuses.label(target)}`)
      onDone()
      onClose()
    },
  })

  return (
    <Modal opened onClose={onClose} title={<Text fw={600}>{statuses.label(order.status)} → {statuses.label(target)}</Text>} size="lg">
      <Stack gap="sm">
        {missing.length > 0 ? (
          <Alert color="orange" icon={<IconAlertTriangle size={18} />} title="Shartlar bajarilmagan">
            <List size="sm">
              {missing.map((m) => (
                <List.Item key={m}>{m}</List.Item>
              ))}
            </List>
            <Text size="xs" mt="xs">
              Shartlarsiz o'tishga urinish rad etiladi va nomuvofiqlik sifatida qayd etiladi.
            </Text>
          </Alert>
        ) : (
          <Text size="sm">Holat o'zgarishi topshiriq tarixiga va audit jurnaliga yoziladi.</Text>
        )}
        <Textarea
          label={reasonRequired ? 'Sabab' : 'Izoh'}
          required={reasonRequired}
          autosize
          minRows={2}
          value={reason}
          onChange={(e) => setReason(e.currentTarget.value)}
          data-autofocus
        />
        <ApiErrorAlert error={run.error} />
        <Group justify="flex-end">
          <Button variant="default" onClick={onClose}>
            Bekor qilish
          </Button>
          <Button
            loading={run.isPending}
            disabled={reasonRequired && reason.trim().length < 5}
            color={['cancelled', 'suspended'].includes(target) ? 'red' : undefined}
            onClick={() => run.mutate()}
          >
            Tasdiqlash
          </Button>
        </Group>
      </Stack>
    </Modal>
  )
}

function ApprovalModal({ order, onClose, onDone }: { order: DismantlingOrder; onClose: () => void; onDone: () => void }) {
  const types = useEnum('approval_type')
  const decisions = useEnum('approval_decision')
  const docTypes = useEnum('document_type')
  const allowedTypes = types.selectData.filter((t) =>
    ['safety_agreement', 'operation_agreement'].includes(t.value)
      ? order.can?.record_safety
      : t.value === 'specialist_review'
        ? order.can?.record_specialist
        : order.can?.authorize,
  )

  const form = useForm({
    initialValues: {
      type: allowedTypes[0]?.value ?? '',
      decision: 'approved',
      reference_number: '',
      reference_date: null as string | null,
      external_party: '',
      comment: '',
      document_id: null as string | null,
    },
    validate: {
      type: (v) => (!v ? 'Turini tanlang' : null),
      comment: (v, values) => (values.decision === 'rejected' && v.trim().length < 5 ? 'Rad etish sababini kiriting' : null),
    },
  })

  const record = useMutation({
    mutationFn: async (values: typeof form.values) =>
      api.post(`/dismantling-orders/${order.id}/approvals`, {
        ...values,
        document_id: values.document_id ? Number(values.document_id) : null,
        reference_number: values.reference_number || null,
        external_party: values.external_party || null,
        comment: values.comment || null,
      }),
    onSuccess: () => {
      notifySuccess('Kelishuv qayd etildi')
      onDone()
      onClose()
    },
  })

  const documents = (order.documents ?? []).filter((d) => !d.voided_at)

  return (
    <Modal opened onClose={onClose} title={<Text fw={600}>Kelishuv / ko'rikni qayd etish</Text>} size="lg">
      <form onSubmit={form.onSubmit((values) => record.mutate(values))}>
        <Stack gap="sm">
          <Text size="sm" c="dimmed">
            Faqat vakolatli shaxs tomonidan rasmiylashtirilgan qarorni kiriting. Yozuv sizning nomingiz va vaqt bilan saqlanadi.
          </Text>
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <Select label="Turi" required data={allowedTypes} allowDeselect={false} {...form.getInputProps('type')} />
            <Select label="Qaror" required data={decisions.selectData} allowDeselect={false} {...form.getInputProps('decision')} />
            <TextInput label="Hujjat / kelishuv raqami" {...form.getInputProps('reference_number')} />
            <DateInput label="Sanasi" valueFormat="DD.MM.YYYY" maxDate={new Date()} clearable {...form.getInputProps('reference_date')} />
            <TextInput label="Kelishuvchi tashkilot / xizmat" {...form.getInputProps('external_party')} />
            <Select
              label="Asos hujjati (topshiriqqa biriktirilgan)"
              clearable
              data={documents.map((d) => ({ value: String(d.id), label: `${d.title} (${docTypes.label(d.type)})` }))}
              {...form.getInputProps('document_id')}
            />
          </SimpleGrid>
          <Textarea label="Izoh / shartlar" autosize minRows={2} {...form.getInputProps('comment')} />
          <ApiErrorAlert error={record.error} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose}>
              Bekor qilish
            </Button>
            <Button type="submit" loading={record.isPending}>
              Qayd etish
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  )
}

function EventModal({ order, onClose, onDone }: { order: DismantlingOrder; onClose: () => void; onDone: () => void }) {
  const types = useEnum('dismantling_event_type')
  const progressAllowed = ['in_progress', 'partially_completed'].includes(order.status)
  const form = useForm({
    initialValues: {
      type: progressAllowed ? 'progress' : 'note',
      occurred_at: dayjs().format('YYYY-MM-DD HH:mm:ss'),
      cable_segment_id: null as string | null,
      extracted_length_m: '' as number | '',
      description: '',
    },
    validate: {
      extracted_length_m: (v, values) => (values.type === 'progress' && (v === '' || v <= 0) ? 'Chiqarilgan uzunlikni kiriting' : null),
      description: (v, values) => (values.type !== 'progress' && v.trim().length < 3 ? 'Tavsifni kiriting' : null),
    },
  })

  const save = useMutation({
    mutationFn: async (values: typeof form.values) =>
      api.post(`/dismantling-orders/${order.id}/events`, {
        type: values.type,
        occurred_at: values.occurred_at,
        cable_segment_id: values.cable_segment_id ? Number(values.cable_segment_id) : null,
        extracted_length_m: values.type === 'progress' ? values.extracted_length_m : null,
        description: values.description || null,
      }),
    onSuccess: () => {
      notifySuccess('Qayd etildi')
      onDone()
      onClose()
    },
  })

  return (
    <Modal opened onClose={onClose} title={<Text fw={600}>Ish yoki hodisani qayd etish</Text>}>
      <form onSubmit={form.onSubmit((values) => save.mutate(values))}>
        <Stack gap="sm">
          <Select
            label="Turi"
            data={types.selectData.filter((t) => t.value !== 'status_change').map((t) => ({ ...t, disabled: t.value === 'progress' && !progressAllowed }))}
            allowDeselect={false}
            {...form.getInputProps('type')}
          />
          <DateTimePicker label="Sana va vaqt" valueFormat="DD.MM.YYYY HH:mm" maxDate={new Date()} {...form.getInputProps('occurred_at')} />
          <Select
            label="Uchastka"
            clearable
            data={(order.segments ?? []).map((s) => ({ value: String(s.id), label: s.code }))}
            {...form.getInputProps('cable_segment_id')}
          />
          {form.values.type === 'progress' && (
            <NumberInput label="Chiqarilgan uzunlik, m" required min={0} decimalScale={2} thousandSeparator=" " {...form.getInputProps('extracted_length_m')} />
          )}
          <Textarea
            label={form.values.type === 'progress' ? 'Izoh' : 'Tavsif'}
            required={form.values.type !== 'progress'}
            autosize
            minRows={2}
            {...form.getInputProps('description')}
          />
          {['incident', 'nonconformity'].includes(form.values.type) && (
            <Alert color="red" variant="light" icon={<IconX size={16} />}>
              Zarur bo'lsa, topshiriqni "To'xtatilgan" holatiga sabab bilan o'tkazing.
            </Alert>
          )}
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
