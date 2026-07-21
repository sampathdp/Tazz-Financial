import { test, expect } from '@playwright/test'
import { login, ADMIN, resetRateLimit } from './helpers'

// These tests exercise the login gate itself, so they start with a clean
// (unauthenticated) storage state rather than the shared admin session.
test.use({ storageState: { cookies: [], origins: [] } })

// /login is throttled to 5/min; reset the limiter before each attempt.
test.beforeEach(() => resetRateLimit())

test.describe('Authentication', () => {
  test('rejects invalid credentials', async ({ page }) => {
    await login(page, ADMIN.email, 'wrong-password')
    await expect(page.getByText(/invalid|failed|credentials/i)).toBeVisible()
    // Still on the login screen.
    await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible()
  })

  test('logs in with valid credentials and reaches the dashboard', async ({ page }) => {
    await login(page)
    await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible()
  })

  test('persists the session across reload', async ({ page }) => {
    await login(page)
    await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible()
    await page.reload()
    await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Sign in' })).toHaveCount(0)
  })

  test('logs out back to the login screen', async ({ page }) => {
    await login(page)
    await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible()
    await page.getByRole('button', { name: 'Logout' }).click()
    await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible()
  })
})
