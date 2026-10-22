import { Button, Group, Modal, MultiSelect, NumberInput, Select, SimpleGrid, Stack, Text, Textarea, TextInput } from '@mantine/core'
import { useForm } from '@mantine/form'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { CableSegment } from '@/api/types'
import { notifySuccess } from '@/components/common/notify'
import { ApiErrorAlert } from '@/components/common/States'
import { useEnum } from '@/lib/meta'

interface SegmentFormModalProps {
  opened: boolean
  onClose: () => void
  lineId: number
  segment?: CableSegment | null
}

export function SegmentFormModal({ opened, onClose, lineId, segment }: SegmentFormModalProps) {
  const queryClient = useQueryClient()
  const laying = useEnum('laying_type')
  const condition = useEnum('technical_condition')
  const usage = useEnum('usage_status')
  const risk = useEnum('risk_level')
  const flags = useEnum('complexity_flag')

  const form = useForm({
    initialValues: {
      name: segment?.name ?? '',
      start_point: segment?.start_point ?? '',
      end_point: segment?.end_point ?? '',
      location_description: segment?.location_description ?? '',
      laying_type: segment?.laying_type ?? 'underground',
      depth_m: segment?.depth_m ? Number(segment.depth_m) : ('' as number | ''),
      design_length_m: segment?.design_length_m ? Number(segment.design_length_m) : ('' as number | ''),
      technical_condition: segment?.technical_condition ?? 'unknown',
      usage_status: segment?.usage_status ?? 'unknown',
      risk_level: segment?.risk_level ?? 'low',
      complexity_flags: segment?.complexity_flags ?? [],
      notes: segment?.notes ?? '',
    },
    validate: {
      laying_type: (v) => (!v ? 'Yotqizish turini tanlang' : null),
    },
  })

  const save = useMutation({
    mutationFn: async (values: typeof form.values) => {
      const payload = {
        ...values,
        depth_m: values.depth_m === '' ? null : values.depth_m,
        design_length_m: values.design_length_m === '' ? null : values.design_length_m,
      }
      return segment ? api.put(`/cable-segments/${segment.id}`, payload) : api.post(`/cable-lines/${lineId}/segments`, payload)
    },
    onSuccess: () => {
      notifySuccess(segment ? 'Uchastka saqlandi' : "Uchastka qo'shildi")
      void queryClient.invalidateQueries({ queryKey: ['cable-line', lineId] })
      void queryClient.invalidateQueries({ queryKey: ['cable-lines'] })
      onClose()
    },
  })

  // "Not in use" is only set through an approved inspection (evidence + second approval)
  const usageOptions = usage.selectData.map((o) =>
    o.value === 'not_in_use' && segment?.usage_status !== 'not_in_use' ? { ...o, disabled: true, label: `${o.label} (faqat inventarizatsiya orqali)` } : o,
  )

  return (
    <Modal opened={opened} onClose={onClose} title={<Text fw={600}>{segment ? `${segment.code} — tahrirlash` : "Uchastka qo'shish"}</Text>} size="xl">
      <form onSubmit={form.onSubmit((values) => save.mutate(values))}>
        <Stack gap="sm">
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <TextInput label="Uchastka nomi" {...form.getInputProps('name')} />
            <TextInput label="Manzil / joylashuv tavsifi" {...form.getInputProps('location_description')} />
            <TextInput label="Boshlanish nuqtasi" {...form.getInputProps('start_point')} />
            <TextInput label="Tugash nuqtasi" {...form.getInputProps('end_point')} />
            <Select label="Yotqizish turi" required data={laying.selectData} allowDeselect={false} {...form.getInputProps('laying_type')} />
            <Group grow align="flex-start">
              <NumberInput label="Taxminiy chuqurlik, m" min={0} max={50} decimalScale={2} {...form.getInputProps('depth_m')} />
              <NumberInput label="Loyiha bo'yicha uzunlik, m" min={0} decimalScale={2} thousandSeparator=" " {...form.getInputProps('design_length_m')} />
            </Group>
            <Select label="Texnik holat" data={condition.selectData} allowDeselect={false} {...form.getInputProps('technical_condition')} />
            <Select
              label="Aloqa tizimida foydalanish"
              data={usageOptions}
              allowDeselect={false}
              description="“Ishlatilmaydi” holati dalil va tasdiq bilan, inventarizatsiya orqali belgilanadi."
              {...form.getInputProps('usage_status')}
            />
            <Select label="Xavf darajasi" data={risk.selectData} allowDeselect={false} {...form.getInputProps('risk_level')} />
            <MultiSelect label="Murakkablik belgilari" data={flags.selectData} clearable {...form.getInputProps('complexity_flags')} />
          </SimpleGrid>
          <Textarea label="Izoh" autosize minRows={2} {...form.getInputProps('notes')} />
          {segment && (
            <Text size="xs" c="dimmed">
              Aniqlangan uzunlik, tekshiruv va demontaj holatlari faqat tegishli ish oqimlari orqali o'zgaradi.
            </Text>
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
