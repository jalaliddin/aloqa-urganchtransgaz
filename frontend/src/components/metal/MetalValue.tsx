import { Badge, Text, Tooltip } from '@mantine/core'
import { formatKgPerMetre, formatMass } from '@/lib/format'

const UNKNOWN_HINT = "Ma'lumot noma'lum — nol deb hisoblanmaydi"

function Unknown() {
  return (
    <Tooltip label={UNKNOWN_HINT}>
      <Badge size="sm" variant="outline" color="gray" tt="none">
        noma'lum
      </Badge>
    </Tooltip>
  )
}

/** A mass in kg. Unknown (null) is shown explicitly and never as 0. */
export function MassValue({ kg, incomplete, knownPart }: { kg: string | null | undefined; incomplete?: boolean; knownPart?: string }) {
  if (incomplete && knownPart !== undefined) {
    return (
      <Tooltip label="Ayrim uchastkalar tarkibi noma'lum: bu faqat ma'lum qismining yig'indisi, ishonchli jami emas">
        <Text size="sm" c="orange.8" span>
          ≥ {formatMass(knownPart)} (to'liq emas)
        </Text>
      </Tooltip>
    )
  }
  if (kg === null || kg === undefined) return <Unknown />

  return (
    <Text size="sm" span>
      {formatMass(kg)}
    </Text>
  )
}

export function KgPerMetreValue({ value }: { value: string | null | undefined }) {
  if (value === null || value === undefined) return <Unknown />

  return (
    <Text size="sm" span>
      {formatKgPerMetre(value)}
    </Text>
  )
}
