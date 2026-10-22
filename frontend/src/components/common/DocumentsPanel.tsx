import {
  ActionIcon, Badge, Button, FileInput, Group, Paper, Select, SimpleGrid, Stack, Table, Text, TextInput, Tooltip,
} from '@mantine/core'
import { DateInput } from '@mantine/dates'
import { useForm } from '@mantine/form'
import { useDisclosure } from '@mantine/hooks'
import { IconBan, IconDownload, IconFileUpload, IconPaperclip } from '@tabler/icons-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { api, downloadFile } from '@/api/client'
import type { DocumentItem, Paginated } from '@/api/types'
import { formatDate, formatFileSize } from '@/lib/format'
import { useEnum, useMeta } from '@/lib/meta'
import { notifyError, notifySuccess } from './notify'
import { ReasonModal } from './ReasonModal'
import { ApiErrorAlert, EmptyState } from './States'

interface DocumentsPanelProps {
  documentableType: string
  documentableId: number
  canUpload: boolean
  /** Suggested document types first in the select */
  suggestedTypes?: string[]
  /** Called after upload / void, e.g. to refresh workflow checks */
  onChanged?: () => void
  compact?: boolean
}

export function DocumentsPanel({ documentableType, documentableId, canUpload, suggestedTypes = [], onChanged, compact }: DocumentsPanelProps) {
  const queryClient = useQueryClient()
  const types = useEnum('document_type')
  const { data: meta } = useMeta()
  const [showVoided, setShowVoided] = useState(false)
  const [formOpened, form] = useDisclosure(false)
  const [voiding, setVoiding] = useState<DocumentItem | null>(null)
  const queryKey = ['documents', documentableType, documentableId, showVoided]

  const { data, isLoading } = useQuery({
    queryKey,
    queryFn: async () =>
      (
        await api.get<Paginated<DocumentItem>>('/documents', {
          params: { documentable_type: documentableType, documentable_id: documentableId, include_voided: showVoided ? 1 : 0, per_page: 100 },
        })
      ).data,
  })

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['documents', documentableType, documentableId] })
    void queryClient.invalidateQueries({ queryKey: ['documents-all'] })
    onChanged?.()
  }

  const download = async (doc: DocumentItem) => {
    try {
      await downloadFile(`/documents/${doc.id}/download`)
    } catch (e) {
      notifyError(e, 'Hujjatni yuklab olib bo\'lmadi')
    }
  }

  const documents = data?.data ?? []
  const typeOptions = [
    ...types.selectData.filter((t) => suggestedTypes.includes(t.value)),
    ...types.selectData.filter((t) => !suggestedTypes.includes(t.value)),
  ]

  return (
    <Stack gap="sm">
      <Group justify="space-between">
        <Group gap="xs">
          <IconPaperclip size={18} />
          <Text fw={600}>Hujjatlar va ilovalar</Text>
          <Badge variant="default">{documents.length}</Badge>
        </Group>
        <Group gap="xs">
          <Button size="xs" variant="subtle" onClick={() => setShowVoided((v) => !v)}>
            {showVoided ? 'Faqat amaldagilar' : 'Bekor qilinganlarni ham'}
          </Button>
          {canUpload && (
            <Button size="xs" leftSection={<IconFileUpload size={16} />} onClick={form.toggle} variant={formOpened ? 'default' : 'filled'}>
              {formOpened ? 'Yopish' : 'Hujjat yuklash'}
            </Button>
          )}
        </Group>
      </Group>

      {formOpened && (
        <UploadForm
          documentableType={documentableType}
          documentableId={documentableId}
          typeOptions={typeOptions}
          defaultType={suggestedTypes[0]}
          maxKb={meta?.documents.max_kb ?? 51200}
          extensions={meta?.documents.allowed_extensions ?? []}
          onUploaded={() => {
            form.close()
            refresh()
          }}
        />
      )}

      {!isLoading && documents.length === 0 ? (
        <EmptyState
          title="Hujjat biriktirilmagan"
          description={canUpload ? "Dalolatnoma, buyruq, foto yoki boshqa asos hujjatni yuklang." : undefined}
        />
      ) : (
        <Table.ScrollContainer minWidth={compact ? 480 : 720}>
          <Table fz="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Hujjat</Table.Th>
                <Table.Th>Turi</Table.Th>
                {!compact && <Table.Th>Raqam, sana</Table.Th>}
                {!compact && <Table.Th>Yuklagan</Table.Th>}
                <Table.Th w={90} />
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {documents.map((doc) => (
                <Table.Tr key={doc.id} style={{ opacity: doc.voided_at ? 0.55 : 1 }}>
                  <Table.Td>
                    <Text size="sm" fw={500} td={doc.voided_at ? 'line-through' : undefined}>
                      {doc.title}
                    </Text>
                    <Text size="xs" c="dimmed">
                      {doc.original_name} · {formatFileSize(doc.size_bytes)}
                    </Text>
                    {doc.voided_at && (
                      <Text size="xs" c="red">
                        Bekor qilingan: {doc.void_reason}
                      </Text>
                    )}
                  </Table.Td>
                  <Table.Td>{types.label(doc.type)}</Table.Td>
                  {!compact && (
                    <Table.Td>
                      {doc.number ?? '—'}
                      <Text size="xs" c="dimmed">
                        {formatDate(doc.document_date)}
                      </Text>
                    </Table.Td>
                  )}
                  {!compact && (
                    <Table.Td>
                      {doc.uploaded_by ?? '—'}
                      <Text size="xs" c="dimmed">
                        {formatDate(doc.created_at)}
                      </Text>
                    </Table.Td>
                  )}
                  <Table.Td>
                    <Group gap={4} wrap="nowrap" justify="flex-end">
                      {!doc.voided_at && (
                        <Tooltip label="Yuklab olish">
                          <ActionIcon variant="subtle" aria-label={`${doc.title} — yuklab olish`} onClick={() => download(doc)}>
                            <IconDownload size={16} />
                          </ActionIcon>
                        </Tooltip>
                      )}
                      {doc.can_void && (
                        <Tooltip label="Bekor qilish">
                          <ActionIcon variant="subtle" color="red" aria-label={`${doc.title} — bekor qilish`} onClick={() => setVoiding(doc)}>
                            <IconBan size={16} />
                          </ActionIcon>
                        </Tooltip>
                      )}
                    </Group>
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </Table.ScrollContainer>
      )}

      <ReasonModal
        opened={voiding !== null}
        onClose={() => setVoiding(null)}
        title="Hujjatni bekor qilish"
        description="Hujjat o'chirilmaydi — bekor qilingan deb belgilanadi va audit jurnaliga yoziladi."
        confirmLabel="Bekor qilish"
        color="red"
        onConfirm={async (reason) => {
          await api.post(`/documents/${voiding!.id}/void`, { reason })
          notifySuccess('Hujjat bekor qilindi')
          refresh()
        }}
      />
    </Stack>
  )
}

interface UploadFormProps {
  documentableType: string
  documentableId: number
  typeOptions: { value: string; label: string }[]
  defaultType?: string
  maxKb: number
  extensions: string[]
  onUploaded: () => void
}

function UploadForm({ documentableType, documentableId, typeOptions, defaultType, maxKb, extensions, onUploaded }: UploadFormProps) {
  const form = useForm({
    initialValues: { file: null as File | null, type: defaultType ?? '', title: '', number: '', document_date: null as string | null },
    validate: {
      file: (f) => (!f ? 'Faylni tanlang' : f.size > maxKb * 1024 ? `Fayl hajmi ${Math.round(maxKb / 1024)} MB dan oshmasligi kerak` : null),
      type: (v) => (!v ? 'Hujjat turini tanlang' : null),
      title: (v) => (v.trim().length < 3 ? 'Sarlavhani kiriting' : null),
    },
  })

  const upload = useMutation({
    mutationFn: async (values: typeof form.values) => {
      const body = new FormData()
      body.append('file', values.file!)
      body.append('documentable_type', documentableType)
      body.append('documentable_id', String(documentableId))
      body.append('type', values.type)
      body.append('title', values.title.trim())
      if (values.number) body.append('number', values.number)
      if (values.document_date) body.append('document_date', values.document_date)
      return api.post('/documents', body)
    },
    onSuccess: () => {
      notifySuccess('Hujjat yuklandi')
      onUploaded()
    },
  })

  return (
    <Paper p="md" bg="gray.0">
      <form onSubmit={form.onSubmit((values) => upload.mutate(values))}>
        <Stack gap="sm">
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <FileInput
              label="Fayl"
              required
              clearable
              placeholder="Faylni tanlang"
              accept={extensions.map((e) => `.${e}`).join(',')}
              description={`${extensions.join(', ')}; maksimal ${Math.round(maxKb / 1024)} MB`}
              {...form.getInputProps('file')}
            />
            <Select label="Hujjat turi" required searchable data={typeOptions} {...form.getInputProps('type')} />
            <TextInput label="Sarlavha" required {...form.getInputProps('title')} />
            <Group grow align="flex-start">
              <TextInput label="Raqami" {...form.getInputProps('number')} />
              <DateInput label="Sanasi" valueFormat="DD.MM.YYYY" clearable maxDate={new Date()} {...form.getInputProps('document_date')} />
            </Group>
          </SimpleGrid>
          <ApiErrorAlert error={upload.error} />
          <Group justify="flex-end">
            <Button type="submit" loading={upload.isPending} leftSection={<IconFileUpload size={16} />}>
              Yuklash
            </Button>
          </Group>
        </Stack>
      </form>
    </Paper>
  )
}
