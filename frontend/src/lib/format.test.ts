import { expect, it } from 'vitest'
import { formatBytes } from './format'

it('writes sizes the way people read them', () => {
  expect(formatBytes(0)).toBe('0 B')
  expect(formatBytes(1023)).toBe('1023 B')
  expect(formatBytes(1024)).toBe('1 KB')
  expect(formatBytes(1536)).toBe('1.5 KB')
  expect(formatBytes(5 * 1024 * 1024)).toBe('5 MB')
  expect(formatBytes(2 * 1024 ** 3)).toBe('2 GB')
  expect(formatBytes(3 * 1024 ** 4)).toBe('3,072 GB')
})
