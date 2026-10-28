import { mkdirSync } from 'node:fs'
import { resolve } from 'node:path'
import { expect as baseExpect, test, type Page } from '@playwright/test'

/*
 * Screenshots of every page, at desktop and phone width, for design reviews (frontend/screens/).
 * Not part of the suite: it runs only with FEEDGENIX_SCREENS=1. It builds a workspace the way a
 * person would: a file, its mapping, a language, a translation plan, a text written by hand and an
 * export.
 */

test.skip(process.env.FEEDGENIX_SCREENS !== '1', 'Screenshots only on request (FEEDGENIX_SCREENS=1).')
test.setTimeout(180_000)

// A fresh database and a cold server: the first sign-up and the first import can take longer than
// the default 5 seconds. This spec only takes pictures, so it waits longer rather than flake.
const expect = baseExpect.configure({ timeout: 20_000 })

const out = resolve(import.meta.dirname, '..', 'screens')
const fixtures = resolve(import.meta.dirname, 'fixtures')

// One page, resized: the two shots are taken one after the other.
async function shootAt(page: Page, file: string, width: number, height: number): Promise<void> {
  await page.setViewportSize({ width, height })
  await page.waitForTimeout(150)
  await page.screenshot({ path: resolve(out, file), fullPage: true })
}

async function shoot(page: Page, name: string): Promise<void> {
  await page.waitForLoadState('networkidle')
  await shootAt(page, `${name}-desktop.png`, 1440, 900)
  await shootAt(page, `${name}-phone.png`, 390, 844)
  await page.setViewportSize({ width: 1440, height: 900 })
}

test('every page, for a design review', async ({ page }) => {
  mkdirSync(out, { recursive: true })
  await page.setViewportSize({ width: 1440, height: 900 })

  await page.goto('/')
  await shoot(page, '01-home')
  await page.goto('/register')
  await shoot(page, '02-register')
  await page.goto('/login')
  await shoot(page, '03-login')

  const password = 'correct horse battery'
  await page.goto('/register')
  await page.getByLabel('Name').fill('Fay Feeds')
  await page.getByLabel('Email').fill(`screens-${Date.now()}@example.com`)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByLabel('Repeat the password').fill(password)
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page).toHaveURL(/\/app$/)
  await shoot(page, '04-overview-new')

  await page.getByRole('navigation', { name: 'Workspace' }).getByRole('link', { name: 'Feeds' }).click()
  await shoot(page, '05-feeds-empty')
  await page.getByRole('button', { name: 'Add a feed', exact: true }).click()
  const dialog = page.getByRole('dialog', { name: 'Add a feed' })
  await shoot(page, '06-add-feed-dialog')
  await dialog.getByLabel('Name').fill('Tools export')
  await dialog.getByLabel('A file').check()
  await dialog.getByLabel('File', { exact: true }).setInputFiles(resolve(fixtures, 'tools.xlsx'))
  await dialog.getByRole('button', { name: 'Add feed' }).click()
  await expect(page.getByRole('heading', { name: 'What we read' })).toBeVisible()
  await shoot(page, '07-feed-profiled')

  await page.getByRole('link', { name: 'Review the mapping' }).click()
  await expect(page.getByRole('heading', { name: 'Mapping' })).toBeVisible()
  await page.getByLabel('The feed is written in').selectOption('en')
  await expect(page.getByLabel('Title (English)')).toHaveValue('Title')
  await shoot(page, '08-mapping-draft')
  await page.getByRole('button', { name: 'Approve the mapping' }).click()
  await expect(page.getByText(/Changing a field sends it back to draft/)).toBeVisible()

  await page.goto('/app/settings')
  await page.getByLabel('Add a language').selectOption('fr')
  await page.getByRole('button', { name: 'Save languages' }).click()
  await expect(page.getByRole('list', { name: 'Selected target languages' })).toContainText('French')
  await shoot(page, '09-settings')

  await page.goto('/app/feeds')
  await shoot(page, '10-feeds')
  await page.getByRole('link', { name: 'Tools export' }).first().click()
  await page.getByRole('link', { name: 'Plan the translation' }).click()
  await page.getByRole('checkbox', { name: /French/ }).check()
  await expect(page.getByRole('table')).toContainText('French')
  await shoot(page, '11-translation-plan')
  await page.getByRole('button', { name: 'Start translating' }).click()
  await expect(page.getByRole('heading', { name: 'Waiting' })).toBeVisible()
  await shoot(page, '12-translation-waiting')

  await page.getByRole('link', { name: 'Review the texts' }).click()
  await page.getByRole('button', { name: 'Translate it yourself' }).first().click()
  await page.getByRole('textbox', { name: /your translation/ }).fill('Marteau de charpentier')
  await page.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByText('Yours', { exact: true })).toBeVisible()
  await shoot(page, '13-review')

  await page.getByRole('link', { name: /translation$/ }).click()
  await page.getByRole('link', { name: 'Tools export', exact: true }).click()
  await page.getByRole('link', { name: 'Publish the feed' }).click()
  await shoot(page, '14-publish-new')
  await page.getByRole('button', { name: 'Create the export' }).click()
  await expect(page.getByText('Live', { exact: true })).toBeVisible()
  await shoot(page, '15-publish-live')

  await page.getByRole('link', { name: 'Tools export', exact: true }).click()
  await shoot(page, '16-feed-done')

  await page.goto('/app/notices')
  await shoot(page, '17-notices')
  await page.goto('/app')
  await shoot(page, '18-overview')
  await page.goto('/app/billing')
  await shoot(page, '19-billing')
  await page.goto('/app/billing/plans')
  await shoot(page, '20-plans')
})
