import type { ReactNode } from 'react'

function Dim({ children }: { children: ReactNode }) {
  return <span className="text-(--term-dim)">{children}</span>
}
function Key({ children }: { children: ReactNode }) {
  return <span className="text-(--term-key)">{children}</span>
}
function Value({ children }: { children: ReactNode }) {
  return <span className="text-(--term-value)">{children}</span>
}
function Ok({ children }: { children: ReactNode }) {
  return <span className="text-(--term-ok)">{children}</span>
}

/** One synthetic feed, read, mapped and translated: the product's flow at a glance (illustrative). */
const LINES: { id: string; content: ReactNode }[] = [
  {
    id: 'read',
    content: (
      <>
        <Dim>→</Dim>
        {' read 4,832 rows · 23 columns'}
      </>
    ),
  },
  {
    id: 'map',
    content: (
      <>
        <Dim>→</Dim>
        {' mapped 21 of 23 fields · '}
        <Ok>2 left empty, not guessed</Ok>
      </>
    ),
  },
  {
    id: 'title',
    content: (
      <>
        <Key>title</Key>
        {'  '}
        <Ok>{'"Merino wool sweater"'}</Ok>
      </>
    ),
  },
  {
    id: 'de',
    content: (
      <>
        <Dim>{'  de'}</Dim>
        {'   '}
        <Value>{'"Merinowollpullover"'}</Value>
      </>
    ),
  },
  {
    id: 'fr',
    content: (
      <>
        <Dim>{'  fr'}</Dim>
        {'   '}
        <Value>{'"Pull en laine mérinos"'}</Value>
      </>
    ),
  },
  {
    id: 'it',
    content: (
      <>
        <Dim>{'  it'}</Dim>
        {'   '}
        <Value>{'"Maglione in lana merino"'}</Value>
      </>
    ),
  },
  {
    id: 'el',
    content: (
      <>
        <Dim>{'  el'}</Dim>
        {'   '}
        <Value>{'"Πουλόβερ από μαλλί merino"'}</Value>
      </>
    ),
  },
  {
    id: 'price',
    content: (
      <>
        <Key>price</Key>
        {'  "89.00" '}
        <Dim>· untouched in every language</Dim>
      </>
    ),
  },
  {
    id: 'done',
    content: (
      <>
        <Ok>✓</Ok>
        {' 4 feed URLs ready · '}
        <Value>de fr it el</Value>
      </>
    ),
  },
]

export function FeedPreview() {
  return (
    <figure className="m-0 overflow-hidden rounded-xl border-[0.5px] border-white/5 bg-ink font-mono text-sm leading-relaxed text-(--term-text)">
      <figcaption className="flex items-center gap-2 border-b-[0.5px] border-white/10 px-3.5 py-2.5">
        <span className="size-2.5 rounded-full bg-error" aria-hidden="true" />
        <span className="size-2.5 rounded-full bg-warn" aria-hidden="true" />
        <span className="size-2.5 rounded-full bg-success" aria-hidden="true" />
        <span className="ml-auto text-xs text-(--term-dim)">alpine-shop.xml · example</span>
      </figcaption>
      <pre className="m-0 overflow-x-auto px-5 py-4.5">
        <code>
          {LINES.map((line) => (
            <span key={line.id} className="block whitespace-pre">
              {line.content}
            </span>
          ))}
        </code>
      </pre>
    </figure>
  )
}
