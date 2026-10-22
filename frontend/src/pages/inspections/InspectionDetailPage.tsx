import dayjs from 'dayjs'
import {
  Alert, Anchor, Badge, Button, Group, List, Modal, MultiSelect, NumberInput, Paper, Select, SimpleGrid, Stack, Table, Text, Textarea, Title,
} from '@mantine/core'
import { DateInput } from '@mantine/dates'
import { useForm } from '@mantine/form'
import { modals } from '@mantine/modals'
import { IconAlertTriangle, IconCheck, IconFileTypePdf, IconPlayerPlay, IconSend } from '@tabler/icons-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { api, ApiError, downloadFile } from '@/api/client'
import type { Inspection, InspectionItem } from '@/api/types'
import { useAuth } from '@/auth/AuthContext'
import { DocumentsPanel } from '@/components/common/DocumentsPanel'
import { notifyError, notifySuccess } from '@/components/common/notify'
import { PageHeader } from '@/components/common/PageHeader'
import { ReasonModal } from '@/components/common/ReasonModal'
import { ApiErrorAlert, ErrorState, PageSkeleton } from '@/components/common/States'
import { StatusBadge } from '@/components/common/StatusBadge'
import { formatDate, formatDateTime, formatLength, formatSignedLength } from '@/lib/format'
import { useEnum, useMeta } from '@/lib/meta'

export default function InspectionDetailPage() {
  const { id } = useParams()
  const queryClient = useQueryClient()
  const { user, can } = useAuth()
  const roles = useEnum('inspection_member_role')
  const [recording, setRecording] = useState<InspectionItem | null>(null)
  const [rejecting, setRejecting] = useState(false)
  const [cancelling, setCancelling] = useState(false)
  const [submitError, setSubmitError] = useState<ApiError | null>(null)
  const key = ['inspection', Number(id)]

  const { data: inspection, isLoading, error, refetch } = useQuery({
    queryKey: key,
    queryFn: async () => (await api.get<{ data: Inspection }>(`/inspections/${id}`)).data.data,
  })

  const action = useMutation({
    mutationFn: async ({ path, body }: { path: string; body?: object }) => (await api.post<{ data: Inspection }>(`/inspections/${id}/${path}`, body)).data.data,
    onSuccess: (data) => {
      queryClient.setQueryData(key, data)
      void queryClient.invalidateQueries({ queryKey: ['inspections'] })
      void queryClient.invalidateQueries({ queryKey: ['cable-line'] })
      setSubmitError(null)
    },
  })

  if (isLoading) return <PageSkeleton />
  if (error || !inspection) return <ErrorState error={error} onRetry={() => void refetch()} />

  const status = inspection.status
  const canManage = !!inspection.can?.manage
  const editable = canManage && ['in_progress', 'rejected'].includes(status)
  const items = inspection.items ?? []
  const recorded = items.filter((i) => i.is_recorded).length
  const isSubmitter = inspection.submitted_by_id === user?.id

  const submit = () =>
    modals.openConfirmModal({
      title: 'Tasdiqlashga yuborish',
      children: (
        <Text size="sm">
          {recorded} ta uchastka natijasi tasdiqlashga yuboriladi. Yuborilgandan so'ng natijalarni o'zgartirib bo'lmaydi — faqat qaytarilsa.
        </Text>
      ),
      labels: { confirm: 'Yuborish', cancel: 'Bekor qilish' },
      onConfirm: () =>
        action.mutate(
          { path: 'submit' },
          {
            onSuccess: () => notifySuccess('Tasdiqlashga yuborildi'),
            onError: (e) => setSubmitError(e as ApiError),
          },
        ),
    })

  const approve = () =>
    modals.openConfirmModal({
      title: 'Natijalarni tasdiqlash',
      children: (
        <Text size="sm">
          Tasdiqlangandan so'ng aniqlangan uzunlik, texnik holat va foydalanish holati reyestrga yoziladi. Qaror sizning nomingizdan audit jurnalida
          qayd etiladi.
        </Text>
      ),
      labels: { confirm: 'Tasdiqlash', cancel: 'Bekor qilish' },
      confirmProps: { color: 'teal' },
      onConfirm: () => action.mutate({ path: 'approve' }, { onSuccess: () => notifySuccess('Natijalar tasdiqlandi'), onError: (e) => notifyError(e) }),
    })

  return (
    <>
      <PageHeader
        title={`${inspection.number} — ${inspection.title}`}
        description={inspection.organization ?? undefined}
        badges={
          <Group gap={6}>
            {inspection.is_demo && <Badge color="orange">DEMO</Badge>}
            <StatusBadge enumKey="inspection_status" value={status} size="lg" />
          </Group>
        }
        actions={
          <>
            <Button variant="default" leftSection={<IconFileTypePdf size={16} />} onClick={() => downloadFile(`/inspections/${id}/protocol`).catch((e) => notifyError(e))}>
              Bayonnoma (PDF)
            </Button>
            {canManage && status === 'draft' && (
              <Button leftSection={<IconPlayerPlay size={16} />} loading={action.isPending} onClick={() => action.mutate({ path: 'start' })}>
                Ko'rikni boshlash
              </Button>
            )}
            {editable && (
              <Button leftSection={<IconSend size={16} />} loading={action.isPending} onClick={submit} disabled={recorded === 0}>
                Tasdiqlashga yuborish
              </Button>
            )}
            {status === 'submitted' && inspection.can?.decide && (
              <>
                <Button color="teal" leftSection={<IconCheck size={16} />} onClick={approve} loading={action.isPending}>
                  Tasdiqlash
                </Button>
                <Button variant="light" color="red" onClick={() => setRejecting(true)}>
                  Qaytarish
                </Button>
              </>
            )}
            {canManage && !['approved', 'cancelled'].includes(status) && (
              <Button variant="subtle" color="red" onClick={() => setCancelling(true)}>
                Bekor qilish
              </Button>
            )}
          </>
        }
      />

      {status === 'submitted' && isSubmitter && can('inspections.approve') && (
        <Alert color="blue" mb="md">
          Natijani siz yuborgansiz — vazifalarni ajratish qoidasiga ko'ra uni boshqa vakolatli foydalanuvchi tasdiqlaydi.
        </Alert>
      )}
      {status === 'rejected' && inspection.decision_comment && (
        <Alert color="red" mb="md" title="Qaytarilgan">
          {inspection.decision_comment} ({inspection.decided_by}, {formatDateTime(inspection.decided_at)})
        </Alert>
      )}
      {status === 'approved' && (
        <Alert color="teal" mb="md" title="Tasdiqlangan">
          {inspection.decided_by}, {formatDateTime(inspection.decided_at)}
          {inspection.decision_comment ? ` — ${inspection.decision_comment}` : ''}
        </Alert>
      )}
      {submitError && (
        <Alert color="red" icon={<IconAlertTriangle size={18} />} mb="md" title={submitError.message} withCloseButton onClose={() => setSubmitError(null)}>
          <List size="sm">
            {submitError.details.map((d, i) => (
              <List.Item key={i}>{d}</List.Item>
            ))}
          </List>
        </Alert>
      )}

      <SimpleGrid cols={{ base: 1, md: 3 }} spacing="md" mb="md">
        <Paper p="md">
          <Text fw={600} mb="xs">
            Muddat
          </Text>
          <Text size="sm">
            {formatDate(inspection.planned_start)} — {formatDate(inspection.planned_end)}
          </Text>
          <Text size="xs" c="dimmed" mt="xs">
            Yaratgan: {inspection.created_by ?? '—'}
            {inspection.submitted_at && (
              <>
                <br />
                Yuborgan: {inspection.submitted_by}, {formatDateTime(inspection.submitted_at)}
              </>
            )}
          </Text>
          {inspection.description && (
            <Text size="sm" mt="xs">
              {inspection.description}
            </Text>
          )}
        </Paper>
        <Paper p="md">
          <Text fw={600} mb="xs">
            Komissiya
          </Text>
          <Stack gap={4}>
            {inspection.members?.map((m) => (
              <Text key={m.id} size="sm">
                <b>{m.full_name}</b>
                {m.position ? `, ${m.position}` : ''} — {roles.label(m.role)}
              </Text>
            ))}
          </Stack>
        </Paper>
        <Paper p="md">
          <Text fw={600} mb="xs">
            Natijalar
          </Text>
          <Text size="sm">
            {recorded} / {items.length} uchastka kiritilgan
          </Text>
          <Text size="sm">Chegaradan oshgan farqlar: {items.filter((i) => i.exceeds_tolerance).length}</Text>
        </Paper>
      </SimpleGrid>

      <Paper p={0} mb="md">
        <Title order={3} p="md" pb="xs">
          Uchastkalar bo'yicha natijalar
        </Title>
        <Table.ScrollContainer minWidth={980}>
          <Table fz="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Uchastka</Table.Th>
                <Table.Th>Ko'rik sanasi</Table.Th>
                <Table.Th ta="right">Hisobda</Table.Th>
                <Table.Th ta="right">Aniqlangan</Table.Th>
                <Table.Th ta="right">Farq</Table.Th>
                <Table.Th>Holat</Table.Th>
                <Table.Th>Ilovalar</Table.Th>
                <Table.Th />
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {items.map((item) => (
                <Table.Tr key={item.id}>
                  <Table.Td>
                    <Text size="sm" fw={500}>
                      {item.segment?.code}
                    </Text>
                    <Text size="xs" c="dimmed">
                      {item.segment?.line_name}
                    </Text>
                  </Table.Td>
                  <Table.Td>{formatDate(item.inspected_on)}</Table.Td>
                  <Table.Td ta="right">{formatLength(item.recorded_length_m)}</Table.Td>
                  <Table.Td ta="right">{formatLength(item.measured_length_m)}</Table.Td>
                  <Table.Td ta="right">
                    {item.length_difference_m !== null ? (
                      <Group gap={4} justify="flex-end" wrap="nowrap">
                        {item.exceeds_tolerance && <IconAlertTriangle size={14} color="var(--mantine-color-orange-7)" aria-label="Chegaradan oshgan" />}
                        <Text size="sm" c={item.exceeds_tolerance ? 'orange.8' : undefined} fw={item.exceeds_tolerance ? 600 : undefined}>
                          {formatSignedLength(item.length_difference_m)}
                        </Text>
                      </Group>
                    ) : (
                      '—'
                    )}
                    {item.difference_reason && (
                      <Text size="xs" c="dimmed" maw={220} ta="right">
                        {item.difference_reason}
                      </Text>
                    )}
                  </Table.Td>
                  <Table.Td>
                    {item.is_recorded ? (
                      <Stack gap={4} align="flex-start">
                        <StatusBadge enumKey="technical_condition" value={item.technical_condition} size="sm" />
                        <StatusBadge enumKey="usage_status" value={item.usage_status} size="sm" />
                      </Stack>
                    ) : (
                      <Text size="sm" c="dimmed">
                        Kiritilmagan
                      </Text>
                    )}
                  </Table.Td>
                  <Table.Td>{item.documents?.filter((d) => !d.voided_at).length ?? 0}</Table.Td>
                  <Table.Td>
                    <Button size="xs" variant={editable ? 'light' : 'subtle'} onClick={() => setRecording(item)}>
                      {editable ? (item.is_recorded ? 'Tahrirlash' : 'Natija kiritish') : "Ko'rish"}
                    </Button>
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </Table.ScrollContainer>
      </Paper>

      <Paper p="md">
        <DocumentsPanel
          documentableType="inspection"
          documentableId={inspection.id}
          canUpload={!!inspection.can?.attach}
          suggestedTypes={['commission_composition', 'commission_protocol', 'technical_conclusion', 'company_order', 'photo']}
        />
      </Paper>

      {recording && (
        <ItemModal
          inspectionId={inspection.id}
          item={items.find((i) => i.id === recording.id) ?? recording}
          editable={editable}
          canAttach={!!inspection.can?.attach && editable}
          onClose={() => setRecording(null)}
          onSaved={() => void queryClient.invalidateQueries({ queryKey: key })}
        />
      )}

      <ReasonModal
        opened={rejecting}
        onClose={() => setRejecting(false)}
        title="Natijalarni qaytarish"
        description="Topshiriq tuzatish uchun qaytariladi. Sabab komissiyaga ko'rinadi va audit jurnaliga yoziladi."
        confirmLabel="Qaytarish"
        color="red"
        onConfirm={async (reason) => {
          await action.mutateAsync({ path: 'reject', body: { reason } })
          notifySuccess('Topshiriq qaytarildi')
        }}
      />
      <ReasonModal
        opened={cancelling}
        onClose={() => setCancelling(false)}
        title="Topshiriqni bekor qilish"
        confirmLabel="Bekor qilish"
        color="red"
        onConfirm={async (reason) => {
          await action.mutateAsync({ path: 'cancel', body: { reason } })
          notifySuccess('Topshiriq bekor qilindi')
        }}
      />
      <Text size="xs" c="dimmed" mt="md">
        <Anchor component={Link} to="/inspections" size="xs">
          Barcha topshiriqlar
        </Anchor>
      </Text>
    </>
  )
}

interface ItemModalProps {
  inspectionId: number
  item: InspectionItem
  editable: boolean
  canAttach: boolean
  onClose: () => void
  onSaved: () => void
}

function ItemModal({ inspectionId, item, editable, canAttach, onClose, onSaved }: ItemModalProps) {
  const { data: meta } = useMeta()
  const condition = useEnum('technical_condition')
  const usage = useEnum('usage_status')
  const risk = useEnum('risk_level')
  const flags = useEnum('complexity_flag')
  const laying = useEnum('laying_type')

  const form = useForm({
    initialValues: {
      inspected_on: item.inspected_on ?? dayjs().format('YYYY-MM-DD'),
      measured_length_m: item.measured_length_m ? Number(item.measured_length_m) : ('' as number | ''),
      difference_reason: item.difference_reason ?? '',
      technical_condition: item.technical_condition ?? '',
      usage_status: item.usage_status ?? '',
      usage_evidence: item.usage_evidence ?? '',
      risk_level: item.risk_level ?? 'low',
      complexity_flags: item.complexity_flags ?? [],
      laying_type: item.laying_type ?? '',
      depth_m: item.depth_m ? Number(item.depth_m) : ('' as number | ''),
      findings: item.findings ?? '',
    },
    validate: {
      measured_length_m: (v) => (v === '' ? "Joyida aniqlangan uzunlikni kiriting" : null),
      technical_condition: (v) => (!v ? 'Texnik holatni tanlang' : null),
      usage_status: (v) => (!v ? 'Foydalanish holatini tanlang' : null),
      usage_evidence: (v, values) =>
        values.usage_status === 'not_in_use' && v.trim().length < 5 ? '“Ishlatilmaydi” xulosasi uchun dalil (asos) kiriting' : null,
    },
  })

  const recordedLength = Number(item.recorded_length_m ?? 0)
  const measured = form.values.measured_length_m === '' ? null : Number(form.values.measured_length_m)
  const difference = measured === null ? null : measured - recordedLength
  const tolerance = meta ? Math.max(meta.tolerance.length_m, (recordedLength * meta.tolerance.length_percent) / 100) : 0
  const exceeds = difference !== null && Math.abs(difference) > tolerance

  const save = useMutation({
    mutationFn: async (values: typeof form.values) =>
      api.put(`/inspections/${inspectionId}/items/${item.id}`, {
        ...values,
        laying_type: values.laying_type || null,
        depth_m: values.depth_m === '' ? null : values.depth_m,
        difference_reason: values.difference_reason || null,
        usage_evidence: values.usage_evidence || null,
      }),
    onSuccess: () => {
      notifySuccess(`${item.segment?.code} natijasi saqlandi`)
      onSaved()
    },
    onError: (e) => {
      if (e instanceof ApiError) form.setErrors(Object.fromEntries(Object.entries(e.fieldErrors).map(([k, v]) => [k, v[0]])))
    },
  })

  return (
    <Modal opened onClose={onClose} size="xl" title={<Text fw={600}>{item.segment?.code} — ko'rik natijasi</Text>}>
      <Stack gap="md">
        <form onSubmit={form.onSubmit((values) => save.mutate(values))}>
          <fieldset disabled={!editable} style={{ border: 0, padding: 0, margin: 0 }}>
            <Stack gap="sm">
              <SimpleGrid cols={{ base: 1, sm: 3 }}>
                <DateInput label="Ko'rik sanasi" valueFormat="DD.MM.YYYY" maxDate={new Date()} required {...form.getInputProps('inspected_on')} />
                <NumberInput label="Hisobdagi uzunlik, m" value={recordedLength} disabled readOnly decimalScale={2} />
                <NumberInput label="Joyida aniqlangan, m" required min={0} decimalScale={2} thousandSeparator=" " {...form.getInputProps('measured_length_m')} />
              </SimpleGrid>
              {difference !== null && (
                <Alert color={exceeds ? 'orange' : 'teal'} variant="light" py="xs">
                  Farq: <b>{formatSignedLength(difference)}</b>{' '}
                  {exceeds
                    ? `— ruxsat etilgan chegaradan (±${formatLength(tolerance)}) katta: sabab va tasdiqlovchi hujjat talab qilinadi.`
                    : `— chegara ichida (±${formatLength(tolerance)}).`}
                </Alert>
              )}
              {exceeds && <Textarea label="Farq sababi" required autosize minRows={2} {...form.getInputProps('difference_reason')} />}
              <SimpleGrid cols={{ base: 1, sm: 3 }}>
                <Select label="Texnik holat" required data={condition.selectData} {...form.getInputProps('technical_condition')} />
                <Select label="Aloqa tizimida foydalanish" required data={usage.selectData} {...form.getInputProps('usage_status')} />
                <Select label="Xavf darajasi" data={risk.selectData} allowDeselect={false} {...form.getInputProps('risk_level')} />
              </SimpleGrid>
              {form.values.usage_status === 'not_in_use' && (
                <Textarea
                  label="“Ishlatilmaydi” xulosasi uchun dalil"
                  description="Masalan: uzel bilan ulanish yo'qligi, o'lchov natijasi. Foto yoki dalolatnoma ham biriktiring."
                  required
                  autosize
                  minRows={2}
                  {...form.getInputProps('usage_evidence')}
                />
              )}
              <SimpleGrid cols={{ base: 1, sm: 3 }}>
                <MultiSelect label="Murakkablik belgilari" data={flags.selectData} clearable {...form.getInputProps('complexity_flags')} />
                <Select label="Yotqizish turi" data={laying.selectData} clearable {...form.getInputProps('laying_type')} />
                <NumberInput label="Chuqurlik, m" min={0} max={50} decimalScale={2} {...form.getInputProps('depth_m')} />
              </SimpleGrid>
              <Textarea label="Ko'rik xulosasi / izoh" autosize minRows={2} {...form.getInputProps('findings')} />
              <ApiErrorAlert error={save.error} />
              {editable && (
                <Group justify="flex-end">
                  <Button type="submit" loading={save.isPending}>
                    Natijani saqlash
                  </Button>
                </Group>
              )}
            </Stack>
          </fieldset>
        </form>
        <Paper p="sm" bg="gray.0">
          <DocumentsPanel
            documentableType="inspection_item"
            documentableId={item.id}
            canUpload={canAttach}
            suggestedTypes={['photo', 'commission_protocol', 'technical_conclusion', 'video']}
            onChanged={onSaved}
            compact
          />
        </Paper>
      </Stack>
    </Modal>
  )
}
