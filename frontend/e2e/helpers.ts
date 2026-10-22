import { expect, type Page } from '@playwright/test'

export const DEMO_PASSWORD = process.env.DEMO_PASSWORD ?? 'Demo12345'

export async function login(page: Page, user: string) {
  await page.goto('/login')
  await page.getByLabel('Elektron pochta').fill(`${user}@demo.aloqa.local`)
  await page.getByLabel('Parol').fill(DEMO_PASSWORD)
  await page.getByRole('button', { name: 'Kirish' }).click()
  await expect(page.getByRole('navigation', { name: 'Asosiy menyu' })).toBeVisible()
}
