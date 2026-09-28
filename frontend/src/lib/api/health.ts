import { api } from './client'

export type HealthStatus = 'ok' | 'degraded' | 'unreachable'

/** Whether the API and its database answer. Never throws: the UI shows the state instead. */
export async function fetchHealth(): Promise<HealthStatus> {
  try {
    const { data, response } = await api.GET('/health')

    return response.ok && data?.status === 'ok' ? 'ok' : 'degraded'
  } catch {
    return 'unreachable'
  }
}
