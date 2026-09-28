import { render, screen } from '@testing-library/react'
import { createRoutesStub } from 'react-router'
import { expect, it } from 'vitest'
import Home from './home'

it('promises the golden rule and links to the workspace', async () => {
  const Stub = createRoutesStub([{ path: '/', Component: Home }])
  render(<Stub initialEntries={['/']} />)

  expect(await screen.findByRole('heading', { level: 1 })).toHaveTextContent('every language your buyers shop in')
  expect(screen.getByText(/pass through untouched/i)).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Open your workspace' })).toHaveAttribute('href', '/app')
})
