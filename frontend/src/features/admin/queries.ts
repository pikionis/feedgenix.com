import { queryOptions } from '@tanstack/react-query'
import { api } from '~/lib/api/client'

export const twoFactorStatusQuery = queryOptions({
  queryKey: ['two-factor'],
  queryFn: async () => {
    const { data, response } = await api.GET('/v1/security/two-factor')
    if (data === undefined) throw new Error(`Could not load the two-factor status (${response.status}).`)

    return data
  },
  staleTime: 0,
})

export const adminOverviewQuery = queryOptions({
  queryKey: ['admin', 'overview'],
  queryFn: async () => {
    const { data, response } = await api.GET('/v1/admin/overview')
    if (data === undefined) throw new Error(`Could not load the overview (${response.status}).`)

    return data
  },
})
