import { expect, it } from 'vitest'
import { formatBytes, formatWait, formatWhen, isDelimiter, isEncoding } from './labels'

it('writes sizes the way people read them', () => {
  expect(formatBytes(0)).toBe('0 B')
  expect(formatBytes(1023)).toBe('1023 B')
  expect(formatBytes(1024)).toBe('1 KB')
  expect(formatBytes(1536)).toBe('1.5 KB')
  expect(formatBytes(5 * 1024 * 1024)).toBe('5 MB')
  expect(formatBytes(2 * 1024 ** 3)).toBe('2 GB')
  expect(formatBytes(3 * 1024 ** 4)).toBe('3,072 GB')
})

it('says when, relative to now', () => {
  const now = new Date('2027-01-10T12:00:00Z')

  expect(formatWhen(null, now)).toBe('')
  expect(formatWhen('2027-01-10T11:59:30Z', now)).toBe('30 seconds ago')
  expect(formatWhen('2027-01-10T11:57:00Z', now)).toBe('3 minutes ago')
  expect(formatWhen('2027-01-09T12:00:00Z', now)).toBe('yesterday')
  expect(formatWhen('2026-01-10T12:00:00Z', now)).toBe('last year')
})

it('says how long to wait', () => {
  expect(formatWait(60)).toBe('60 seconds')
  expect(formatWait(600)).toBe('10 minutes')
  expect(formatWait(7200)).toBe('2 hours')
})

it('only accepts the encodings and separators the API knows', () => {
  expect(isEncoding('Windows-1253')).toBe(true)
  expect(isEncoding('EBCDIC')).toBe(false)
  expect(isEncoding('toString')).toBe(false)
  expect(isEncoding(null)).toBe(false)
  expect(isDelimiter('\t')).toBe(true)
  expect(isDelimiter(':')).toBe(false)
})
