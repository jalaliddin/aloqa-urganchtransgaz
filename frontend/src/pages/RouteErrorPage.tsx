import { Alert, Button, Center, Code, Group, Stack, Text, Title } from '@mantine/core'
import { IconAlertTriangle } from '@tabler/icons-react'
import { isRouteErrorResponse, useRouteError } from 'react-router'

/** Shown instead of a blank screen when a page fails to render or load. */
export default function RouteErrorPage() {
  const error = useRouteError()
  const detail = isRouteErrorResponse(error) ? `${error.status} ${error.statusText}` : error instanceof Error ? error.message : String(error)

  return (
    <Center mih="60vh" p="md">
      <Stack maw={560} gap="md">
        <Group gap="sm">
          <IconAlertTriangle size={28} color="var(--mantine-color-red-7)" />
          <Title order={2}>Sahifani ochib bo'lmadi</Title>
        </Group>
        <Text>
          Sahifani ko'rsatishda xatolik yuz berdi. Kiritilgan ma'lumotlar saqlangan bo'lsa, ular yo'qolmaydi. Sahifani qayta yuklang; xato
          takrorlansa, administratorga quyidagi matnni yuboring.
        </Text>
        <Alert color="red" variant="light">
          <Code block>{detail}</Code>
        </Alert>
        <Group>
          <Button onClick={() => window.location.reload()}>Qayta yuklash</Button>
          <Button variant="default" component="a" href="/">
            Dashboardga qaytish
          </Button>
        </Group>
      </Stack>
    </Center>
  )
}
