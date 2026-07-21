import { test, expect } from '@playwright/test'
import { ROUTES, nav } from './helpers'

// Catch runtime errors (error boundaries / uncaught exceptions) on every screen.
test.beforeEach(async ({ page }) => {
  const errors: string[] = []
  page.on('pageerror', (e) => errors.push(String(e)))
  ;(page as any)._jsErrors = errors
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible()
})

test.describe('Navigation smoke — every screen loads', () => {
  for (const [route, cfg] of Object.entries(ROUTES)) {
    test(`opens ${route} → "${cfg.title}"`, async ({ page }) => {
      await nav(page, route)
      await expect(page.getByRole('heading', { level: 1, name: cfg.title })).toBeVisible()
      // no "Screen not found" / permission-denied fallback
      await expect(page.getByText('Screen not found')).toHaveCount(0)
      await expect(page.getByText('You do not have permission')).toHaveCount(0)
      const errors: string[] = (page as any)._jsErrors || []
      expect(errors, `JS errors on ${route}: ${errors.join('; ')}`).toEqual([])
    })
  }
})
