import { Alert, Badge, Button, Group, Modal, NumberInput, Paper, Select, SimpleGrid, Stack, Table, Text, Textarea, Title, Tooltip } from '@mantine/core'
import { useForm } from '@mantine/form'
import { IconAlertTriangle, IconInfoCircle, IconScale } from '@tabler/icons-react'
import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { api, ApiError } from '@/api/client'
import { METALS, type DismantlingOrder, type MassDifference, type MetalKey } from '@/api/types'
import { notifySuccess } from '@/components/common/notify'
import { ApiErrorAlert } from '@/components/common/States'
import { MassValue } from '@/components/metal/MetalValue'
import { formatDateTime, formatLength, formatMass, formatPercent } from '@/lib/format'

const METAL_LABEL: Record<MetalKey, string> = { aluminium: 'Alyuminiy', lead: "Qo'rg'oshin", copper: 'Mis' }

function Difference({ diff }: { diff: MassDifference }) {
  if (diff.kg === null) {
    return (
      <Text size="xs" c="dimmed">
        —
      </Text>
    )
  }
  const sign = Number(diff.kg) > 0 ? '+' : ''
  const text = `${sign}${formatMass(diff.kg)}${diff.percent === null ? '' : ` (${sign}${formatPercent(diff.percent)})`}`

  return diff.exceeds ? (
    <Tooltip label="Ruxsat etilgan chegaradan katta — tekshiruv talab qilinadi">
      <Group gap={4} wrap="nowrap">
        <IconAlertTriangle size={14} color="var(--mantine-color-orange-7)" aria-label="Chegaradan oshgan" />
        <Text size="sm" fw={600} c="orange.8">
          {text}
        </Text>
      </Group>
    </Tooltip>
  ) : (
    <Text size="sm">{text}</Text>
  )
}

/**
 * Three separate figures per metal — theoretical (extracted length × kg/m), per dismantling act,
 * accepted by the warehouse — with the differences between them. Nothing is equalised or hidden.
 */
export function MetalBalanceCard({ order, onChanged }: { order: DismantlingOrder; onChanged: () => void }) {
  const [recording, setRecording] = useState(false)
  const balance = order.metal_balance
  if (!balance) return null
  const canRecord = !!order.can?.record_event && ['in_progress', 'partially_completed', 'completed'].includes(order.status)

  return (
    <Paper p="md" mb="md" aria-label="Rangli metall balansi">
      <Group justify="space-between" align="flex-start" mb="xs" wrap="wrap">
        <div>
          <Title order={3}>Rangli metall balansi</Title>
          <Text size="sm" c="dimmed">
            Chiqarilgan uzunlik: {formatLength(balance.extracted_length_m)}. Farq chegarasi: ±{balance.tolerance_percent} %.
          </Text>
        </div>
        {canRecord && (
          <Button size="xs" variant="light" leftSection={<IconScale size={14} />} onClick={() => setRecording(true)}>
            {order.act_masses.recorded_at ? 'Dalolatnoma massasini tuzatish' : 'Dalolatnoma massasini kiritish'}
          </Button>
        )}
      </Group>
      {Number(balance.unassigned_length_m) > 0 && METALS.some((m) => !balance.metals[m].theoretical_complete) && (
        <Text size="xs" c="dimmed" mb="xs">
          {formatLength(balance.unassigned_length_m)} uchastkaga biriktirilmagan holda kiritilgan; uchastkalar tarkibi har xil bo'lsa, bu qism
          uchun nazariy massa noma'lum hisoblanadi.
        </Text>
      )}
      <Table.ScrollContainer minWidth={720}>
        <Table fz="sm">
          <Table.Thead>
            <Table.Tr>
              <Table.Th>Metall</Table.Th>
              <Table.Th>Nazariy</Table.Th>
              <Table.Th>Dalolatnoma bo'yicha</Table.Th>
              <Table.Th>Omborga qabul qilingan</Table.Th>
              <Table.Th>Dalolatnoma − nazariy</Table.Th>
              <Table.Th>Ombor − dalolatnoma</Table.Th>
            </Table.Tr>
          </Table.Thead>
          <Table.Tbody>
            {METALS.map((m) => {
              const row = balance.metals[m]
              return (
                <Table.Tr key={m}>
                  <Table.Td fw={500}>{METAL_LABEL[m]}</Table.Td>
                  <Table.Td>
                    <MassValue kg={row.theoretical_kg} incomplete={!row.theoretical_complete && Number(row.theoretical_known_part_kg) > 0} knownPart={row.theoretical_known_part_kg} />
                  </Table.Td>
                  <Table.Td>
                    <MassValue kg={row.act_kg} />
                  </Table.Td>
                  <Table.Td>
                    <MassValue kg={row.warehouse_kg} />
                  </Table.Td>
                  <Table.Td>
                    <Difference diff={row.act_vs_theoretical} />
                  </Table.Td>
                  <Table.Td>
                    <Difference diff={row.warehouse_vs_act} />
                  </Table.Td>
                </Table.Tr>
              )
            })}
          </Table.Tbody>
        </Table>
      </Table.ScrollContainer>
      {order.act_masses.recorded_at && (
        <Text size="xs" c="dimmed" mt="xs">
          Dalolatnoma massasi: {order.act_masses.recorded_by ?? '—'}, {formatDateTime(order.act_masses.recorded_at)}. Tuzatishlar audit jurnalida.
        </Text>
      )}
      {recording && <ActMassesModal order={order} onClose={() => setRecording(false)} onSaved={onChanged} />}
    </Paper>
  )
}

const toInput = (v: string | null): number | '' => (v === null ? '' : Number(v))

function ActMassesModal({ order, onClose, onSaved }: { order: DismantlingOrder; onClose: () => void; onSaved: () => void }) {
  const correction = order.act_masses.recorded_at !== null
  const acts = (order.documents ?? []).filter((d) => d.type === 'dismantling_act' && !d.voided_at)
  const form = useForm({
    initialValues: {
      aluminium_kg: toInput(order.act_masses.aluminium_kg),
      lead_kg: toInput(order.act_masses.lead_kg),
      copper_kg: toInput(order.act_masses.copper_kg),
      document_id: order.act_masses.document_id ? String(order.act_masses.document_id) : acts[0] ? String(acts[0].id) : null,
      reason: '',
    },
    validate: {
      document_id: (v) => (!v ? 'Demontaj dalolatnomasini tanlang' : null),
      reason: (v) => (correction && v.trim().length < 5 ? 'Tuzatish sababini kiriting' : null),
      aluminium_kg: (_v, values) =>
        values.aluminium_kg === '' && values.lead_kg === '' && values.copper_kg === '' ? "Kamida bitta massani kiriting (olinmagan bo'lsa 0)" : null,
    },
  })

  const save = useMutation({
    mutationFn: async (values: typeof form.values) =>
      api.put(`/dismantling-orders/${order.id}/act-masses`, {
        aluminium_kg: values.aluminium_kg === '' ? null : values.aluminium_kg,
        lead_kg: values.lead_kg === '' ? null : values.lead_kg,
        copper_kg: values.copper_kg === '' ? null : values.copper_kg,
        document_id: Number(values.document_id),
        reason: values.reason || null,
      }),
    onSuccess: () => {
      notifySuccess('Dalolatnoma massasi saqlandi')
      onSaved()
      onClose()
    },
    onError: (e) => {
      if (e instanceof ApiError) form.setErrors(Object.fromEntries(Object.entries(e.fieldErrors).map(([k, v]) => [k, v[0]])))
    },
  })

  return (
    <Modal opened onClose={onClose} size="lg" title={<Text fw={600}>Dalolatnoma bo'yicha amalda olingan massa</Text>}>
      <form onSubmit={form.onSubmit((v) => save.mutate(v))} noValidate>
        <Stack gap="sm">
          <Alert variant="light" color="blue" icon={<IconInfoCircle size={18} />}>
            Massalar dalolatnomadagi kabi kiritiladi va nazariy hisob bilan almashtirilmaydi. Bo'sh maydon — <b>noma'lum</b>, <b>0</b> — metall
            olinmagan.
          </Alert>
          {acts.length === 0 ? (
            <Alert color="orange" icon={<IconAlertTriangle size={18} />}>
              Avval topshiriqqa demontaj dalolatnomasini biriktiring.
            </Alert>
          ) : (
            <Select
              label="Demontaj dalolatnomasi"
              required
              data={acts.map((d) => ({ value: String(d.id), label: `${d.title}${d.number ? ` № ${d.number}` : ''}` }))}
              {...form.getInputProps('document_id')}
            />
          )}
          <SimpleGrid cols={{ base: 1, sm: 3 }}>
            {(['aluminium', 'lead', 'copper'] as const).map((m) => (
              <NumberInput
                key={m}
                label={METAL_LABEL[m]}
                placeholder="noma'lum"
                min={0}
                decimalScale={3}
                allowNegative={false}
                thousandSeparator=" "
                rightSection={<Text size="xs" c="dimmed">kg</Text>}
                {...form.getInputProps(`${m}_kg`)}
              />
            ))}
          </SimpleGrid>
          {correction && (
            <>
              <Badge color="orange" variant="light">
                Avvalgi qiymatlar audit jurnalida saqlanadi
              </Badge>
              <Textarea label="Tuzatish sababi" required autosize minRows={2} {...form.getInputProps('reason')} />
            </>
          )}
          <ApiErrorAlert error={save.error} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose}>
              Bekor qilish
            </Button>
            <Button type="submit" loading={save.isPending} disabled={acts.length === 0}>
              Saqlash
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  )
}
