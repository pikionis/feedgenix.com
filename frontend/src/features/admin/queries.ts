import { keepPreviousData, queryOptions } from '@tanstack/react-query'
import { NotFound } from '~/features/feeds/queries'
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

export function adminWorkspacesQuery(search: string, page: number) {
  return queryOptions({
    queryKey: ['admin', 'workspaces', search, page],
    queryFn: async () => {
      const { data, response } = await api.GET('/v1/admin/workspaces', {
        params: { query: { ...(search === '' ? {} : { search }), page } },
      })
      if (data === undefined) throw new Error(`Could not load the workspaces (${response.status}).`)

      return data
    },
    // The table stays on screen while the next page or search loads.
    placeholderData: keepPreviousData,
  })
}

export function adminWorkspaceQuery(id: number) {
  return queryOptions({
    queryKey: ['admin', 'workspaces', id],
    queryFn: async () => {
      const { data, response } = await api.GET('/v1/admin/workspaces/{workspace}', {
        params: { path: { workspace: String(id) } },
      })
      if (response.status === 404) throw new NotFound('This workspace does not exist.')
      if (data === undefined) throw new Error(`Could not load the workspace (${response.status}).`)

      return data
    },
    retry: (count, error) => !(error instanceof NotFound) && count < 1,
  })
}

export const adminAuditQuery = queryOptions({
  queryKey: ['admin', 'audit'],
  queryFn: async () => {
    const { data, response } = await api.GET('/v1/admin/audit')
    if (data === undefined) throw new Error(`Could not load the staff trail (${response.status}).`)

    return data.audit
  },
})
