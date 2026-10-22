import { Alert, Button, Group, Modal, NumberInput, Select, SimpleGrid, Stack, Text, Textarea, TextInput } from '@mantine/core'
import { useForm } from '@mantine/form'
import { IconInfoCircle } from '@tabler/icons-react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { DocumentItem, MetalComposition, Paginated } from '@/api/types'
import { notifySuccess } from '@/components/common/notify'
import { ApiErrorAlert } from '@/components/common/States'
import { useEnum } from '@/lib/meta'

export interface CompositionScope {
  type: 'cable_type' | 'cable_line' | 'cable_segment'
  id: number
  label: string
  /** For a segment, documents of its line may also be used as the source */
  lineId?: number
}

interface Props {
  scope: CompositionScope
  /** Pre-fill with the currently effective values */
  current?: Pick<MetalComposition, 'aluminium_kg_per_m' | 'lead_kg_per_m' | 'copper_kg_per_m'> | null
  onClose: () => void
  onSaved: () => void
}

const toInput = (v: string | null | undefined): number | '' => (v === null || v === undefined ? '' : Number(v))

/**
 * Enters a new kg/m version. An empty field is sent as null ("unknown"); 0 means "checked, none".
 * The value becomes effective only after another authorised person approves it.
 */
export function CompositionFormModal({ scope, current, onClose, onSaved }: Props) {
  const sources = useEnum('composition_source_type')
  const docTypes = useEnum('document_type')

  const documents = useQuery({
    queryKey: ['documents', 'composition-sources', scope.type, scope.id, scope.lineId ?? null],
    queryFn: async () => {
      const owners: [string, number][] = [[scope.type, scope.id]]
      if (scope.lineId) owners.push(['cable_line', scope.lineId])
      const lists = await Promise.all(
        owners.map(async ([type, id]) =>
          (await api.get<Paginated<DocumentItem>>('/documents', { params: { documentable_type: type, documentable_id: id, per_page: 100 } })).data.data,
        ),
      )
      return lists.flat()
    },
  })

  const form = useForm({
    initialValues: {
      aluminium_kg_per_m: toInput(current?.aluminium_kg_per_m),
      lead_kg_per_m: toInput(current?.lead_kg_per_m),
      copper_kg_per_m: toInput(current?.copper_kg_per_m),
      source_type: 'cable_passport',
      source_reference: '',
      document_id: null as string | null,
      note: '',
    },
    validate: {
      source_reference: (v) => (v.trim().length < 3 ? 'Manbani kiriting (hujjat nomi, raqami, sanasi)' : null),
      document_id: (v, values) => (values.source_type === 'supporting_document' && !v ? 'Tasdiqlovchi hujjatni tanlang' : null),
      aluminium_kg_per_m: (_v, values) =>
        values.aluminium_kg_per_m === '' && values.lead_kg_per_m === '' && values.copper_kg_per_m === ''
          ? "Kamida bitta qiymat kiriting (metall yo'q bo'lsa 0)"
          : null,
    },
  })

  const save = useMutation({
    mutationFn: async (values: typeof form.values) =>
      api.post('/metal-compositions', {
        scope_type: scope.type,
        scope_id: scope.id,
        aluminium_kg_per_m: values.aluminium_kg_per_m === '' ? null : values.aluminium_kg_per_m,
        lead_kg_per_m: values.lead_kg_per_m === '' ? null : values.lead_kg_per_m,
        copper_kg_per_m: values.copper_kg_per_m === '' ? null : values.copper_kg_per_m,
        source_type: values.source_type,
        source_reference: values.source_reference.trim(),
        document_id: values.document_id ? Number(values.document_id) : null,
        note: values.note || null,
      }),
    onSuccess: () => {
      notifySuccess("Qiymat kiritildi — boshqa vakolatli shaxs tasdiqlagach amalga kiradi")
      onSaved()
      onClose()
    },
    onError: (e) => {
      if (e instanceof ApiError) form.setErrors(Object.fromEntries(Object.entries(e.fieldErrors).map(([k, v]) => [k, v[0]])))
    },
  })

  const metalInput = (key: 'aluminium_kg_per_m' | 'lead_kg_per_m' | 'copper_kg_per_m', label: string) => (
    <NumberInput
      label={label}
      placeholder="noma'lum"
      min={0}
      max={100}
      decimalScale={6}
      allowNegative={false}
      rightSection={<Text size="xs" c="dimmed">kg/m</Text>}
      rightSectionWidth={44}
      {...form.getInputProps(key)}
    />
  )

  return (
    <Modal opened onClose={onClose} size="lg" title={<Text fw={600}>Rangli metall tarkibi — {scope.label}</Text>}>
      <form onSubmit={form.onSubmit((values) => save.mutate(values))} noValidate>
        <Stack gap="sm">
          <Alert variant="light" color="blue" icon={<IconInfoCircle size={18} />}>
            Bo'sh maydon — <b>noma'lum</b> (hisob-kitobda 0 deb olinmaydi). <b>0</b> — tekshirilgan, metall yo'q. Qiymat 1 metr kabel uchun
            kilogrammda, kamida 4 kasr aniqlikda.
          </Alert>
          <SimpleGrid cols={{ base: 1, sm: 3 }}>
            {metalInput('aluminium_kg_per_m', 'Alyuminiy')}
            {metalInput('lead_kg_per_m', "Qo'rg'oshin")}
            {metalInput('copper_kg_per_m', 'Mis')}
          </SimpleGrid>
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <Select label="Manba turi" required data={sources.selectData} allowDeselect={false} {...form.getInputProps('source_type')} />
            <Select
              label="Manba hujjati"
              clearable
              searchable
              placeholder={documents.data?.length ? 'Tanlang' : "Biriktirilgan hujjat yo'q"}
              data={(documents.data ?? []).map((d) => ({ value: String(d.id), label: `${d.title} (${docTypes.label(d.type)})` }))}
              {...form.getInputProps('document_id')}
            />
          </SimpleGrid>
          <TextInput label="Manba" required placeholder="Masalan: Kabel pasporti № 17, 1968; GOST jadvali 3" {...form.getInputProps('source_reference')} />
          <Textarea label="Izoh" autosize minRows={2} {...form.getInputProps('note')} />
          <ApiErrorAlert error={save.error} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose}>
              Bekor qilish
            </Button>
            <Button type="submit" loading={save.isPending}>
              Tasdiqlashga yuborish
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  )
}
