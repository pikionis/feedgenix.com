import { Button } from '~/components/ui/button'

/** Messages for the ?error= codes the Google callback sends back. */
export const GOOGLE_ERRORS: Record<string, string> = {
  google: 'Google sign-in did not complete. Try again.',
  unverified_email: 'Google has not verified the address of that account, so it cannot be used here.',
  conflict: 'This address is already linked to a different Google account.',
  invite: 'FeedGenix is invite-only for now. Use the link from your invite to sign up.',
  closed: 'Sign-up is closed at the moment.',
}

/** A full-page navigation (not a fetch): the server redirects to Google and back. */
export function GoogleButton({ invite }: { invite?: string | null | undefined }) {
  const href = invite ? `/auth/google/redirect?invite=${encodeURIComponent(invite)}` : '/auth/google/redirect'

  return (
    <Button variant="secondary" className="w-full" asChild>
      <a href={href}>Continue with Google</a>
    </Button>
  )
}

export function Divider() {
  return (
    <div className="my-5 flex items-center gap-3 text-xs text-faint">
      <span className="h-px flex-1 bg-hairline" />
      or
      <span className="h-px flex-1 bg-hairline" />
    </div>
  )
}
