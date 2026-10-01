import { expect, it } from 'vitest'
import { formatWait, formatWhen, isDelimiter, isEncoding } from './labels'

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
