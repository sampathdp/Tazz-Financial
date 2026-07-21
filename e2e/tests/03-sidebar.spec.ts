import { test, expect } from '@playwright/test'
import { navBySidebar } from './helpers'

// Exercises the real sidebar UI: section expand + item click navigation.
test.describe('Sidebar navigation', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/')
    await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible()
  })

  test('navigates through one screen in each section', async ({ page }) => {
    await navBySidebar(page, 'ad/company')
    // back to another to confirm re-navigation works
    await navBySidebar(page, 'ad/users')
  })
})
