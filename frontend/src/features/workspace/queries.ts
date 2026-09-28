import { queryOptions } from '@tanstack/react-query'
import { api } from '~/lib/api/client'

export const workspaceQuery = queryOptions({
  queryKey: ['workspace'],
  queryFn: async () => {
    const { data, response } = await api.GET('/v1/workspace')
    if (data === undefined) throw new Error(`Could not load the workspace (${response.status}).`)

    return data
  },
})

export const plansQuery = queryOptions({
  queryKey: ['plans'],
  queryFn: async () => {
    const { data, response } = await api.GET('/plans')
    if (data === undefined) throw new Error(`Could not load the plans (${response.status}).`)

    return data
  },
  staleTime: 10 * 60_000,
})

export const languagesQuery = queryOptions({
  queryKey: ['languages'],
  queryFn: async () => {
    const { data, response } = await api.GET('/languages')
    if (data === undefined) throw new Error(`Could not load the languages (${response.status}).`)

    return data.languages
  },
  staleTime: Infinity,
})
