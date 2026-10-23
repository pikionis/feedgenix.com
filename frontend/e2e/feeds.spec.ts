import { readFileSync } from 'node:fs'
import { createServer, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'
import { resolve } from 'node:path'
import { expect, test, type Page } from '@playwright/test'

const fixtures = resolve(import.meta.dirname, 'fixtures')

// The shop's feed URL: a local server the backend fetches from (allowed in end-to-end runs only).
let shop: Server
let feedUrl: string

test.beforeAll(async () => {
  const xml = readFileSync(resolve(fixtures, 'google.xml'))
  shop = createServer((request, response) => {
    if (request.url !== '/google.xml') return response.writeHead(404).end()
    response.writeHead(200, { 'content-type': 'application/xml; charset=utf-8' }).end(xml)
  })
  await new Promise<void>((done) => shop.listen(0, '127.0.0.1', done))
  feedUrl = `http://127.0.0.1:${(shop.address() as AddressInfo).port}/google.xml`
})

test.afterAll(async () => {
  await new Promise((done) => shop.close(done))
})

async function signUp(page: Page): Promise<void> {
  const password = 'correct horse battery'
  await page.goto('/register')
  await page.getByLabel('Name').fill('Fay Feeds')
  await page.getByLabel('Email').fill(`e2e-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByLabel('Repeat the password').fill(password)
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page).toHaveURL(/\/app$/)
}

async function addFeed(page: Page): Promise<void> {
  await page.getByRole('navigation', { name: 'Workspace' }).getByRole('link', { name: 'Feeds' }).click()
  await page.getByRole('button', { name: 'Add a feed', exact: true }).click()
}

test('an Excel upload becomes a column profile', async ({ page }) => {
  await signUp(page)
  await addFeed(page)

  const dialog = page.getByRole('dialog', { name: 'Add a feed' })
  await dialog.getByLabel('Name').fill('Tools export')
  await dialog.getByLabel('A file').check()
  await dialog.getByLabel('File', { exact: true }).setInputFiles(resolve(fixtures, 'tools.xlsx'))
  await dialog.getByRole('button', { name: 'Add feed' }).click()

  await expect(page).toHaveURL(/\/app\/feeds\/\d+$/)
  await expect(page.getByRole('heading', { name: 'Tools export' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'What we read' })).toBeVisible()

  // The six stages: import and analysis done, a mapping draft ready, nothing later pretending to run.
  const stages = page.getByRole('list', { name: 'Pipeline' }).getByRole('listitem')
  await expect(stages).toHaveCount(6)
  await expect(stages.nth(0)).toContainText('Done')
  await expect(stages.nth(1)).toContainText('Done')
  await expect(stages.nth(2)).toContainText('A draft is ready to review')
  await expect(stages.nth(3)).toContainText('Choose the fields to translate')
  await expect(stages.nth(5)).toContainText('A feed URL per language')

  const columns = page.getByRole('table', { name: 'Columns' })
  await expect(columns.getByRole('row', { name: /^SKU/ })).toContainText('Code')
  await expect(columns.getByRole('row', { name: /^EAN/ })).toContainText('GTIN')
  await expect(columns.getByRole('row', { name: /^Price/ })).toContainText('Decimal')
  await expect(columns.getByRole('row', { name: /^Colour/ })).toContainText('75%')
  await expect(page.getByRole('table', { name: 'Sample products' })).toContainText('Claw hammer')
  await expect(page.getByText(/SKU · filled and unique in every row/)).toBeVisible()

  // The mapping: a free draft (no AI), the feed's language said, approved.
  await page.getByRole('link', { name: 'Review the mapping' }).click()
  await expect(page.getByRole('heading', { name: 'Mapping' })).toBeVisible()
  await expect(page.getByLabel('SKU', { exact: true })).toHaveValue('SKU')
  await expect(page.getByLabel('GTIN / EAN', { exact: true })).toHaveValue('EAN')
  await expect(page.getByText(/AI suggestions are switched off for now/)).toBeVisible()
  await page.getByLabel('The feed is written in').selectOption('en')
  await expect(page.getByLabel('Title (English)')).toHaveValue('Title')
  await page.getByRole('button', { name: 'Approve the mapping' }).click()
  await expect(page.getByText(/Changing a field sends it back to draft/)).toBeVisible()

  // Approving reads the products into the catalogue; the translation plan waits for a choice.
  await page.getByRole('link', { name: 'Tools export' }).click()
  await expect(stages.nth(2)).toContainText('Approved')
  await expect(stages.nth(3)).toContainText('Choose and start')

  // The plan: a language first (a new workspace has none), then the texts and the work.
  await page.getByRole('link', { name: 'Plan the translation' }).click()
  await expect(page.getByRole('heading', { name: 'Translation', exact: true })).toBeVisible()
  await expect(page.getByText('Your workspace has no languages yet.')).toBeVisible()
  await page.getByRole('link', { name: 'Add one in the settings' }).click()
  await page.getByLabel('Add a language').selectOption('fr')
  await page.getByRole('button', { name: 'Save languages' }).click()
  await expect(page.getByRole('list', { name: 'Selected target languages' })).toContainText('French')
  await page.goBack()
  await page.reload()

  await page.getByRole('checkbox', { name: 'French' }).check()
  await expect(page.getByRole('checkbox', { name: 'French' })).toBeChecked()
  await expect(page.getByLabel('Title')).toHaveValue('translate')
  await expect(page.getByRole('table')).toContainText('French')
  // AI is off here: no estimate, and the work waits instead of failing.
  await expect(page.getByText(/AI is not switched on yet/)).toBeVisible()
  await page.getByRole('button', { name: 'Start translating' }).click()
  await expect(page.getByRole('heading', { name: 'Waiting' })).toBeVisible()
  await expect(page.getByText(/Some texts wait/)).toBeVisible()

  // Reviewing: nothing is translated yet, so the owner writes one text themselves.
  await page.getByRole('link', { name: 'Review the texts' }).click()
  await expect(page.getByRole('heading', { name: 'Review the texts' })).toBeVisible()
  await page.getByRole('button', { name: 'Translate it yourself' }).first().click()
  await page.getByRole('textbox', { name: /your translation/ }).fill('Marteau de charpentier')
  await page.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByText('Yours', { exact: true })).toBeVisible()
  await page.getByRole('tab', { name: /^Yours/ }).click()
  await expect(page.getByRole('tab', { name: 'Yours (1)', selected: true })).toBeVisible()
  await expect(page.getByText('Marteau de charpentier')).toBeVisible()

  await page.getByRole('link', { name: 'Tools export: translation' }).click()
  await page.getByRole('link', { name: 'Tools export' }).click()
  await expect(stages.nth(4)).toContainText('Waiting')

  // Publishing does not wait for the translation: every mapped field as CSV, its URL serving the build.
  await page.getByRole('link', { name: 'Publish the feed' }).click()
  await expect(page.getByRole('heading', { name: 'Publish', exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Create the export' }).click()
  const urls = page.getByRole('list', { name: 'Every mapped field: feed URLs' })
  await expect(urls).toBeVisible()
  await expect(page.getByText('Live', { exact: true })).toBeVisible()
  // Straight from Laravel: the URL's host is the app's, the path is what a channel pulls.
  const path = new URL(await urls.getByRole('textbox').first().inputValue()).pathname
  const file = await page.request.get(`http://127.0.0.1:8000${path}`)
  expect(file.status()).toBe(200)
  expect(file.headers()['x-robots-tag']).toBe('noindex, nofollow')
  const csv = await file.text()
  expect(csv.split('\r\n')[0]).toMatch(/^sku,/)
  expect(csv).toContain('Marteau de charpentier')

  await page.getByRole('link', { name: 'Tools export' }).click()
  await expect(stages.nth(5)).toContainText('1 feed URL live')
})

test('a Google Shopping URL becomes a column profile, and the Free plan stops at one feed', async ({ page }) => {
  await signUp(page)
  await addFeed(page)

  const dialog = page.getByRole('dialog', { name: 'Add a feed' })
  await dialog.getByLabel('Name').fill('Alpine Google feed')
  await dialog.getByLabel('Feed URL').fill(feedUrl)
  await dialog.getByRole('button', { name: 'Add feed' }).click()

  await expect(page).toHaveURL(/\/app\/feeds\/\d+$/)
  await expect(page.getByText('Fetched from 127.0.0.1')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'What we read' })).toBeVisible()
  await expect(page.getByText('rss/channel/item')).toBeVisible()

  const columns = page.getByRole('table', { name: 'Columns' })
  await expect(columns.getByRole('row', { name: /^g:id/ })).toContainText('Code')
  await expect(columns.getByRole('row', { name: /^g:gtin/ })).toContainText('GTIN')
  await expect(columns.getByRole('row', { name: /^description/ })).toContainText('HTML')
  await expect(page.getByRole('table', { name: 'Sample products' })).toContainText('Merino beanie')

  // A second feed is over the Free plan: the upgrade dialog explains it.
  await addFeed(page)
  const second = page.getByRole('dialog', { name: 'Add a feed' })
  await second.getByLabel('Name').fill('Second feed')
  await second.getByLabel('Feed URL').fill(feedUrl)
  await second.getByRole('button', { name: 'Add feed' }).click()

  await expect(page.getByRole('dialog')).toContainText('Your Free plan includes 1 feed.')
})
