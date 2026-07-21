import { test as setup, expect } from '@playwright/test'
import { login, ADMIN, resetRateLimit } from './helpers'

const authFile = '.auth/user.json'

setup('authenticate as admin', async ({ page }) => {
  resetRateLimit()
  await login(page, ADMIN.email, ADMIN.password)
  // Dashboard heading confirms we are past the login gate.
  await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible()
  await page.context().storageState({ path: authFile })
})
