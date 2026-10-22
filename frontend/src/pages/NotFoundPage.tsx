import { Button } from '@mantine/core'
import { Link } from 'react-router'
import { EmptyState } from '@/components/common/States'

export default function NotFoundPage() {
  return (
    <EmptyState
      title="Sahifa topilmadi"
      description="Manzil noto'g'ri yoki sahifa ko'chirilgan."
      action={
        <Button component={Link} to="/" variant="light">
          Dashboardga qaytish
        </Button>
      }
    />
  )
}
