import { Page, expect } from '@playwright/test'
import { execSync } from 'node:child_process'
import { resolve } from 'node:path'

export const ADMIN = { email: 'admin@absl.local', password: 'password' }

const BACKEND_DIR = process.env.BACKEND_DIR || resolve(process.cwd(), '../backend')

/** Clear the Laravel rate-limiter (login is throttled to 5/min, too tight for E2E). */
export function resetRateLimit() {
  try {
    execSync('php artisan cache:clear', { cwd: BACKEND_DIR, stdio: 'ignore' })
  } catch {
    /* best effort — a failed clear just risks a throttle flake, not a hang */
  }
}

/** route id -> { section (sidebar group, if any), label (nav button text), title (page <h1>) } */
export const ROUTES: Record<string, { section?: string; label: string; title: string }> = {
  dash: { label: 'Dashboard', title: 'Dashboard' },
  customers: { label: 'Customer Management', title: 'Customer Management' },
  'ad/branches': { section: 'Administration', label: 'Branch Management', title: 'Branch Management' },
  'ad/roles': { section: 'Administration', label: 'Role Management', title: 'Role Management' },
  'ad/users': { section: 'Administration', label: 'User Management', title: 'User Management' },
  'ad/perm': { section: 'Administration', label: 'User Permission', title: 'User Permission' },
  'ad/company': { section: 'Administration', label: 'Company Profile', title: 'Company Profile' },
}

/**
 * Navigate the SPA to a route. The app uses URL routes (react-router, '/<route id>'),
 * so we go straight to the URL — deterministic and immune to the sidebar's
 * expand/scroll animation. Requires an authed
 * session (storageState). Sidebar click-through is covered separately in
 * 03-sidebar.spec.ts.
 */
export async function nav(page: Page, route: keyof typeof ROUTES | string) {
  const r = ROUTES[route]
  if (!r) throw new Error(`Unknown route ${route}`)
  await page.goto('/' + route)
  await expect(page.getByRole('heading', { level: 1, name: r.title })).toBeVisible()
}

/** Navigate via the sidebar UI (expands the section, clicks the item). */
export async function navBySidebar(page: Page, route: keyof typeof ROUTES | string) {
  const r = ROUTES[route]
  if (!r) throw new Error(`Unknown route ${route}`)
  const item = page.getByRole('button', { name: r.label, exact: true })
  if (r.section && !(await item.isVisible().catch(() => false))) {
    await page.getByRole('button', { name: r.section, exact: true }).click()
  }
  // The sidebar's scrollable <nav> hit-test-blocks the button center during the
  // expand animation, so dispatch the click straight to the element. This still
  // verifies the menu item is wired to navigation.
  await expect(item).toBeVisible()
  await item.dispatchEvent('click')
  await expect(page.getByRole('heading', { level: 1, name: r.title })).toBeVisible()
}

/** UI login. Leaves the app on the Dashboard. */
export async function login(page: Page, email = ADMIN.email, password = ADMIN.password) {
  await page.goto('/')
  await page.getByPlaceholder('you@company.com').fill(email)
  await page.getByPlaceholder('••••••••').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await dismissWhatsNew(page)
}

/** The "What's new" release-notes modal opens once per user per app version and blocks clicks until dismissed. */
export async function dismissWhatsNew(page: Page) {
  const gotIt = page.getByRole('button', { name: 'Got it' })
  try {
    await gotIt.waitFor({ state: 'visible', timeout: 4000 })
    await gotIt.click()
  } catch {
    /* already seen for this user and version */
  }
}
