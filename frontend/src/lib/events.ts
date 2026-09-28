import type { LimitHit } from '~/lib/api/types'

type Listener<T> = (payload: T) => void

function channel<T>() {
  const listeners = new Set<Listener<T>>()

  return {
    emit(payload: T): void {
      for (const listener of listeners) listener(payload)
    },
    /** Subscribe; returns the unsubscribe function (fits useEffect cleanup). */
    on(listener: Listener<T>): () => void {
      listeners.add(listener)
      return () => {
        listeners.delete(listener)
      }
    },
  }
}

/** Any API answer 402: a plan limit or a higher-plan feature. The upgrade dialog listens. */
export const limitHit = channel<LimitHit>()

/** Any API answer 401: the session is gone. The app shell sends the person to sign in. */
export const unauthenticated = channel<void>()
