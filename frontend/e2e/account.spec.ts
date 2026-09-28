import { expect, test } from '@playwright/test'

test('a new account: sign up, rename the workspace, meet the language limit, sign out and back in', async ({
  page,
}) => {
  const email = `e2e-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
  const password = 'correct horse battery'

  // Sign up: straight into the workspace, asked to verify the address.
  await page.goto('/register')
  await page.getByLabel('Name').fill('Eve Tester')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByLabel('Repeat the password').fill(password)
  await page.getByRole('button', { name: 'Create account' }).click()

  await expect(page).toHaveURL(/\/app$/)
  await expect(page.getByRole('heading', { name: 'Welcome, Eve' })).toBeVisible()
  await expect(page.getByText(/Verify your address/)).toBeVisible()

  // Rename the workspace.
  await page.getByRole('link', { name: 'Settings' }).click()
  const workspace = page.locator('#workspace')
  await workspace.getByLabel('Workspace name').fill('Alpine Test Shop')
  await workspace.getByRole('button', { name: 'Save' }).click()
  await expect(workspace.getByText('Saved')).toBeVisible()

  // Free includes one target language: saving two opens the upgrade dialog with the numbers.
  const addLanguage = page.getByLabel('Add a language')
  await addLanguage.selectOption({ label: 'German · Deutsch' })
  await addLanguage.selectOption({ label: 'French · Français' })
  await page.getByRole('button', { name: 'Save languages' }).click()

  const dialog = page.getByRole('dialog')
  await expect(dialog).toContainText('Your Free plan includes 1 target language. Pro gives you 5 target languages.')
  await expect(dialog.getByRole('link', { name: 'See Pro' })).toBeVisible()
  await dialog.getByRole('button', { name: 'Not now' }).click()
  await expect(dialog).toBeHidden()

  // Sign out, then back in with the same password.
  await page.getByRole('button', { name: 'Sign out' }).click()
  await expect(page).toHaveURL(/\/login$/)

  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()

  await expect(page).toHaveURL(/\/app$/)
  await expect(page.getByText('Alpine Test Shop')).toBeVisible()
})

test('a wrong password gets one answer that reveals nothing', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('Email').fill('nobody@example.com')
  await page.getByLabel('Password').fill('wrong password')
  await page.getByRole('button', { name: 'Sign in' }).click()

  await expect(page.getByText('These credentials do not match our records.')).toBeVisible()
  await expect(page).toHaveURL(/\/login$/)
})
