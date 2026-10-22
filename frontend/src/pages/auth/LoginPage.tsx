import { Alert, Box, Button, Paper, PasswordInput, Stack, Text, TextInput, Title } from '@mantine/core'
import { useForm } from '@mantine/form'
import { IconAlertTriangle } from '@tabler/icons-react'
import { useState } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router'
import { ApiError } from '@/api/client'
import { useAuth } from '@/auth/AuthContext'

export default function LoginPage() {
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const from = (location.state as { from?: string } | null)?.from ?? '/'

  const form = useForm({
    initialValues: { email: '', password: '' },
    validate: {
      email: (v) => (/^\S+@\S+\.\S+$/.test(v) ? null : "Elektron pochtani to'g'ri kiriting"),
      password: (v) => (v.length > 0 ? null : 'Parolni kiriting'),
    },
  })

  if (user) return <Navigate to={from} replace />

  const submit = form.onSubmit(async ({ email, password }) => {
    setSubmitting(true)
    setError(null)
    try {
      await login(email.trim(), password)
      navigate(from, { replace: true })
    } catch (e) {
      const apiError = e instanceof ApiError ? e : null
      setError(apiError?.fieldErrors.email?.[0] ?? apiError?.message ?? 'Kirish amalga oshmadi.')
    } finally {
      setSubmitting(false)
    }
  })

  return (
    <Box
      mih="100vh"
      style={{ display: 'grid', placeItems: 'center', background: 'var(--app-navy)', padding: 16 }}
    >
      <Paper w="100%" maw={400} p="xl" radius="md" withBorder={false}>
        <form onSubmit={submit} noValidate>
          <Stack gap="md">
            <svg width="40" height="40" viewBox="0 0 32 32" aria-hidden>
              <rect width="32" height="32" rx="6" fill="#1D3B53" />
              <path d="M6 22c5 0 6-12 10-12s5 12 10 12" fill="none" stroke="#E3B505" strokeWidth="3" strokeLinecap="round" />
              <circle cx="6" cy="22" r="2.5" fill="#fff" />
              <circle cx="26" cy="22" r="2.5" fill="#fff" />
            </svg>
            <div>
              <Title order={2}>Aloqa kabel reyestri</Title>
              <Text size="sm" c="dimmed">
                Urganchtransgaz MCHJ — kabel liniyalari, inventarizatsiya va demontaj nazorati
              </Text>
            </div>
            {error && (
              <Alert color="red" icon={<IconAlertTriangle size={18} />} role="alert">
                {error}
              </Alert>
            )}
            <TextInput label="Elektron pochta" type="email" autoComplete="username" required {...form.getInputProps('email')} />
            <PasswordInput label="Parol" autoComplete="current-password" required {...form.getInputProps('password')} />
            <Button type="submit" loading={submitting} fullWidth>
              Kirish
            </Button>
            <Text size="xs" c="dimmed">
              Kirish urinishlari cheklangan va audit jurnalida qayd etiladi.
            </Text>
          </Stack>
        </form>
      </Paper>
    </Box>
  )
}
