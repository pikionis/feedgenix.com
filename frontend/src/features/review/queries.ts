import { keepPreviousData, queryOptions } from '@tanstack/react-query'
import { api } from '~/lib/api/client'
import type { ReviewFilter, ReviewPage } from '~/lib/api/types'

export interface ReviewQuery {
  locale: string
  filter: ReviewFilter
  search: string
  page: number
}

export function reviewQuery(feedId: number, query: ReviewQuery) {
  return queryOptions({
    queryKey: ['feeds', feedId, 'products', query],
    queryFn: async (): Promise<ReviewPage> => {
      const { data, response } = await api.GET('/v1/feeds/{feed}/products', {
        params: {
          path: { feed: feedId },
          query: {
            locale: query.locale,
            filter: query.filter,
            page: query.page,
            ...(query.search.trim() === '' ? {} : { search: query.search.trim() }),
          },
        },
      })
      if (data === undefined) throw new Error(`Could not load the products (${response.status}).`)

      return data
    },
    // Paging and filtering keep the last page on screen until the next one is there.
    placeholderData: keepPreviousData,
  })
}
