import { StatusBadge } from './StatusBadge'

export function EnumCell({ enumKey, value }: { enumKey: string; value: string }) {
  return <StatusBadge enumKey={enumKey} value={value} size="sm" />
}
