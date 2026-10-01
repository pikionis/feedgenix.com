import { useId, type ReactNode } from 'react'
import { Notice } from '~/components/ui/notice'
import type { FeedImport } from '~/lib/api/types'
import { formatBytes } from '~/lib/format'
import { DELIMITERS, ENCODING_REASON, FORMAT, isDelimiter, KIND } from './labels'

/** Long cells are cut in the tables; the full value is in the tooltip. */
const CELL_CHARS = 120

/** How a file was read, what each column holds, and a few rows as they will be used. */
export function ImportProfile({ data }: { data: FeedImport }) {
  return (
    <div className="flex flex-col gap-8">
      <ReadingFacts data={data} />
      {data.warnings.length > 0 && (
        <ul className="flex flex-col gap-2" aria-label="Warnings">
          {data.warnings.map((warning) => (
            <li key={warning.code}>
              <Notice tone="warn">{warning.message}</Notice>
            </li>
          ))}
        </ul>
      )}
      <Columns data={data} />
      <SampleRows data={data} />
    </div>
  )
}

function ReadingFacts({ data }: { data: FeedImport }) {
  const containers = data.containers.length > 0 ? ` in ${data.containers.join(' in ')}` : ''

  return (
    <dl className="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
      {data.original_name !== null && <Fact term="File">{data.original_name}</Fact>}
      {data.format !== null && <Fact term="Format">{`${FORMAT[data.format]}${containers}`}</Fact>}
      {data.received_bytes !== null && <Fact term="Size">{formatBytes(data.received_bytes)}</Fact>}
      {data.encoding !== null && (
        <Fact term="Encoding">
          {data.encoding.name} <span className="text-muted-foreground">· {ENCODING_REASON[data.encoding.reason]}</span>
        </Fact>
      )}
      {data.delimiter !== null && (
        <Fact term="Separator">{isDelimiter(data.delimiter) ? DELIMITERS[data.delimiter] : data.delimiter}</Fact>
      )}
      {data.xml_item_path !== null && (
        <Fact term="Products at">
          <code>{data.xml_item_path}</code>
        </Fact>
      )}
      {data.row_count !== null && <Fact term="Products">{data.row_count.toLocaleString('en')}</Fact>}
      {data.column_count !== null && <Fact term="Columns">{data.column_count.toLocaleString('en')}</Fact>}
      {data.sku !== null && (
        <Fact term="SKU column">
          {data.sku.column}{' '}
          <span className="text-muted-foreground">
            ·{' '}
            {data.sku.confidence === 'high'
              ? 'filled and unique in every row'
              : `check it: ${data.sku.empty.toLocaleString('en')} empty, ${data.sku.duplicates.toLocaleString('en')} repeated`}
          </span>
        </Fact>
      )}
    </dl>
  )
}

function Fact({ term, children }: { term: string; children: ReactNode }) {
  return (
    <div className="flex min-w-0 flex-col gap-0.5">
      <dt className="text-muted-foreground">{term}</dt>
      <dd className="truncate">{children}</dd>
    </div>
  )
}

function Columns({ data }: { data: FeedImport }) {
  const title = useId()

  return (
    <section className="flex flex-col gap-3">
      <h3 id={title} className="font-medium">
        Columns
      </h3>
      <div className="overflow-x-auto rounded-xl border-[0.5px] border-hairline">
        <table aria-labelledby={title} className="w-full text-left text-sm">
          <thead className="bg-subtle text-muted-foreground">
            <tr>
              <th className="px-3 py-2 font-medium">Column</th>
              <th className="px-3 py-2 font-medium">Looks like</th>
              <th className="px-3 py-2 font-medium">Filled</th>
              <th className="hidden px-3 py-2 font-medium md:table-cell">Longest</th>
              <th className="px-3 py-2 font-medium">Examples</th>
            </tr>
          </thead>
          <tbody>
            {data.columns.map((column) => (
              <tr key={column.position} className="border-t-[0.5px] border-hairline align-top">
                <td className="px-3 py-2 font-mono text-xs break-all">{column.name}</td>
                <td className="px-3 py-2 whitespace-nowrap">{KIND[column.kind]}</td>
                <td className="px-3 py-2 whitespace-nowrap">{percent(column.fill_rate)}</td>
                <td className="hidden px-3 py-2 md:table-cell">{column.max_length.toLocaleString('en')}</td>
                <td className="max-w-[360px] px-3 py-2 text-muted-foreground">
                  <span className="line-clamp-2 break-words" title={column.examples.join('\n')}>
                    {column.examples.map((example) => cut(example)).join(' · ')}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

function SampleRows({ data }: { data: FeedImport }) {
  const title = useId()
  if (data.sample_rows.length === 0) return null
  const names = data.columns.map((column) => column.name)
  // Rows have no id of their own; an import's samples never change, so their position is stable.
  const rows = data.sample_rows.map((row, position) => ({ position, row }))

  return (
    <section className="flex flex-col gap-3">
      <div>
        <h3 id={title} className="font-medium">
          Sample products
        </h3>
        <p className="text-sm text-muted-foreground">The first rows, exactly as read.</p>
      </div>
      <div className="overflow-x-auto rounded-xl border-[0.5px] border-hairline">
        <table aria-labelledby={title} className="text-left text-sm">
          <thead className="bg-subtle text-muted-foreground">
            <tr>
              {names.map((name) => (
                <th key={name} className="px-3 py-2 font-mono text-xs font-medium whitespace-nowrap">
                  {name}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map(({ position, row }) => (
              <tr key={position} className="border-t-[0.5px] border-hairline align-top">
                {names.map((name) => (
                  <td key={name} className="max-w-[280px] px-3 py-2" title={row[name] ?? ''}>
                    <span className="line-clamp-3 break-words">{cut(row[name] ?? '')}</span>
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

function percent(rate: number): string {
  if (rate > 0 && rate < 0.01) return '<1%'
  if (rate < 1 && rate > 0.99) return '>99%'

  return `${Math.round(rate * 100)}%`
}

function cut(value: string): string {
  return value.length > CELL_CHARS ? `${value.slice(0, CELL_CHARS)}…` : value
}
