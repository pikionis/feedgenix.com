import { execFileSync } from 'node:child_process'
import { resolve } from 'node:path'
import { e2eEnvironment } from './environment'

/** A clean, migrated database for every end-to-end run (never the development database). */
export default function globalSetup(): void {
  execFileSync('php', ['artisan', 'migrate:fresh', '--force'], {
    cwd: resolve(import.meta.dirname, '..', '..'),
    env: { ...process.env, ...e2eEnvironment },
    stdio: 'inherit',
  })
}
