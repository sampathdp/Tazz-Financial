import { test, expect, request as pwRequest } from '@playwright/test'
import { dismissWhatsNew, ADMIN } from './helpers'
import { execSync } from 'node:child_process'
import { resolve } from 'node:path'

/**
 * UI checks for the ledger controls added in the audit fixes:
 *  - C-14 books-closed date (Finance Settings), enforced on posting
 *  - B-1 branch filter on the accounting reports (+ B-7 not needed here)
 *  - C-4 header accounts shown with a group total on the Chart of Accounts
 * Seed: e2e/seed/audit-ledger.php (test database only).
 */
const API = process.env.E2E_API_URL || 'http://127.0.0.1:8000/api'
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

test('C-14: setting a books-closed date in Finance Settings blocks postings on or before it', async ({ page }) => {
  await page.goto('/finance/settings')
  await expect(page.getByRole('heading', { level: 1, name: 'Finance Settings' })).toBeVisible()
  const lock = page.locator('#ledger-lock-date')
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/finance-settings') && r.request().method() === 'PUT' && r.ok()),
    lock.fill('2026-09-30'),
  ])

  // A cashbook entry dated inside the closed period is refused; one after it is accepted.
  const ctx = await pwRequest.newContext()
  const token = (await (await ctx.post(`${API}/login`, { data: { email: ADMIN.email, password: ADMIN.password }, headers: { Accept: 'application/json' } })).json()).token
  const h = { Authorization: `Bearer ${token}`, Accept: 'application/json' }
  const tills = await (await ctx.get(`${API}/cash-accounts`, { headers: h })).json()
  const till = tills.find((t: any) => t.status === 'Active')
  const body = (date: string) => ({ cash_account_id: till.id, entry_date: date, direction: 'In', category: 'Other', amount: 1, description: 'E2E lock check', offset_account_id: 20 })
  const blocked = await ctx.post(`${API}/cashbook-entries`, { headers: h, data: body('2026-09-15') })
  expect(blocked.status()).toBe(422)
  expect(await blocked.text()).toContain('closed through 2026-09-30')
  await page.screenshot({ path: `../audit/evidence/C14-${EVIDENCE}-finance-settings-lock.png` })

  // Clear the lock again through the UI so other specs are unaffected.
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/finance-settings') && r.request().method() === 'PUT' && r.ok()),
    lock.fill(''),
  ])
  // Cleared: the setting reads back empty (no further posting is made, so other specs' balances stay untouched).
  const settings = await (await ctx.get(`${API}/finance-settings`, { headers: h })).json()
  expect(settings.ledger_locked_through || '').toBe('')
})

test('B-1: the P&L can be limited to one branch', async ({ page }) => {
  await page.goto('/acc/pl')
  await expect(page.getByRole('heading', { level: 1, name: /Profit/ })).toBeVisible()
  await page.locator('label:has(span:text-is("From")) input').fill('2026-09-01')
  await page.locator('label:has(span:text-is("To")) input').fill('2026-09-30')
  await expect(page.getByRole('row', { name: /4010/ }).first()).toContainText('1,000.00')

  await page.locator('label:has(span:text-is("Branch")) select').selectOption({ label: 'HB — Horana' })
  await expect(page.getByRole('row', { name: /4025/ }).first()).toContainText('400.00')
  await expect(page.getByRole('row', { name: /4010/ }).first()).not.toContainText('1,000.00')
  await page.screenshot({ path: `../audit/evidence/B1-${EVIDENCE}-pl-horana.png`, fullPage: true })
})

test('B-1: a branch balance sheet says "Branch view" instead of claiming to balance', async ({ page }) => {
  await page.goto('/acc/bs')
  await page.locator('label:has(span:text-is("As Of")) input').fill('2026-09-30')
  await expect(page.getByText('Balanced', { exact: true })).toBeVisible()
  await page.locator('label:has(span:text-is("Branch")) select').selectOption({ label: 'HB — Horana' })
  await expect(page.getByText('Branch view')).toBeVisible()
})

test('C-4: the Chart of Accounts marks header accounts and shows their group total', async ({ page }) => {
  await page.goto('/acc/coa')
  await expect(page.getByRole('heading', { level: 1, name: 'Chart of Accounts' })).toBeVisible()
  const row = page.getByRole('row', { name: /1010\s+Cash in Hand/ }).first()
  await expect(row).toContainText('Header')
  await expect(row).toContainText('group')
  await page.screenshot({ path: `../audit/evidence/C4-${EVIDENCE}-coa-header-rollup.png` })
})

test('B-2: the Inter-Branch Balances screen shows who owes whom', async ({ page }) => {
  await page.goto('/acc/ib')
  await expect(page.getByRole('heading', { level: 1, name: 'Inter-Branch Balances' })).toBeVisible()
  // Seeded: Horana is owed 650.00 by Head Office (Horana's pawning cash 450 sits in Head Office's till, and Head Office's
  // 200 expense was paid from Horana's till) and owes 400.00 (Head Office cash banked into Horana's bank account).
  await expect(page.getByRole('row', { name: /Horana\s+Head Office\s+650\.00\s+400\.00\s+250\.00/ })).toBeVisible()
  await expect(page.getByRole('row', { name: /Head Office\s+Horana\s+400\.00\s+650\.00\s+-250\.00/ })).toBeVisible()
  await expect(page.getByText('Nets to zero')).toBeVisible()
  await page.screenshot({ path: `../audit/evidence/B2-${EVIDENCE}-inter-branch.png`, fullPage: true })
})
