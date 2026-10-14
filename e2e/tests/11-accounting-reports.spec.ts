import { test, expect, Page } from '@playwright/test'
import { nav, dismissWhatsNew } from './helpers'
import { execSync } from 'node:child_process'
import { resolve } from 'node:path'

/**
 * BUG-004 (cash flow blank), BUG-005 (balance sheet adds the provision to assets),
 * BUG-006 (P&L waterfall skips accounts). Data comes from e2e/seed/audit-ledger.php, which refuses
 * to run on any database not ending in "test".
 *
 * Seeded postings (Sept 2026): opening 5,000 | loan interest 1,000 | till->bank 400 | provision 300
 * | pawning interest 400 + cash overage 50.
 */
const EVIDENCE = process.env.EVIDENCE_TAG || 'after'

test.beforeAll(() => {
  execSync(`php artisan tinker --execute="require base_path('../e2e/seed/audit-ledger.php');"`, {
    cwd: resolve(process.cwd(), '../backend'),
    env: { ...process.env, DB_DATABASE: process.env.E2E_DB || 'tazz_test' },
    stdio: 'ignore',
  })
})

test.beforeEach(async ({ page }) => {
  await page.goto('/')
  await dismissWhatsNew(page)
  await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible()
})

async function setRange(page: Page, from: string, to: string) {
  await page.locator('label:has(span:text-is("From")) input').fill(from)
  await page.locator('label:has(span:text-is("To")) input').fill(to)
}

test('BUG-004: Cash Flow statement shows cash posted to till and bank accounts', async ({ page }) => {
  await page.goto('/acc/cf')
  await expect(page.getByRole('heading', { level: 1, name: 'Cash Flow Statement' })).toBeVisible()
  await setRange(page, '2026-09-01', '2026-09-30')
  await expect(page.getByRole('cell', { name: '4010' })).toBeVisible()
  // Opening 5,000 + 1,000 + 450 collected; the 400 moved till -> bank is not an inflow.
  await expect(page.getByText('Closing Cash').locator('xpath=following-sibling::span')).toHaveText(/6,450\.00/)
  await expect(page.getByText('Reconciled')).toBeVisible()
  await page.screenshot({ path: `../audit/evidence/BUG-004-${EVIDENCE}-cash-flow.png`, fullPage: true })
})

test('BUG-005: Balance Sheet subtracts the provision and stays balanced', async ({ page }) => {
  await page.goto('/acc/bs')
  await expect(page.getByRole('heading', { level: 1, name: 'Balance Sheet' })).toBeVisible()
  await page.locator('label:has(span:text-is("As Of")) input').fill('2026-09-30')
  await expect(page.getByText('Balanced', { exact: true })).toBeVisible()
  // The 1190 row is shown as a deduction.
  const row = page.getByRole('row', { name: /1190/ })
  await expect(row).toContainText(/-\s?300\.00|\(300\.00\)/)
  await page.screenshot({ path: `../audit/evidence/BUG-005-${EVIDENCE}-balance-sheet.png`, fullPage: true })
})

test('BUG-006: management P&L waterfall includes pawning interest and other income', async ({ page }) => {
  await page.goto('/acc/pl')
  await expect(page.getByRole('heading', { level: 1, name: /Profit/ })).toBeVisible()
  await setRange(page, '2026-09-01', '2026-09-30')
  await expect(page.getByText('Miscellaneous Income (Other Income, Cash Overage, other)').first()).toBeVisible()
  await page.screenshot({ path: `../audit/evidence/BUG-006-${EVIDENCE}-profit-loss.png`, fullPage: true })
})
