import { useState } from 'react'
import { Button } from '~/components/ui/button'
import { Notice } from '~/components/ui/notice'
import { api } from '~/lib/api/client'
import { describeFailure } from '~/lib/forms'
import { TwoFactorCodeForm } from './TwoFactorCodeForm'

type Enrolment = { secret: string; qr_svg_data_url: string }

/** Set up two-factor sign-in: scan, confirm with a first code, save the recovery codes. */
export function TwoFactorSetup({ onDone }: { onDone: () => void }) {
  const [enrolment, setEnrolment] = useState<Enrolment | null>(null)
  const [codes, setCodes] = useState<string[] | null>(null)
  const [failure, setFailure] = useState<string | null>(null)

  const begin = async () => {
    setFailure(null)
    const { data, response } = await api.POST('/v1/security/two-factor')
    if (data !== undefined) setEnrolment(data)
    else setFailure(describeFailure(response.status))
  }

  if (codes !== null) {
    return (
      <div className="flex flex-col gap-4">
        <Notice tone="warn">
          Save these recovery codes now, somewhere safe. Each works once if you lose your phone. They are not shown
          again.
        </Notice>
        <ul aria-label="Recovery codes" className="grid grid-cols-2 gap-2 font-mono text-sm">
          {codes.map((code) => (
            <li key={code} className="rounded-md bg-surface px-3 py-1.5">
              {code}
            </li>
          ))}
        </ul>
        <Button onClick={onDone}>I have saved them</Button>
      </div>
    )
  }

  if (enrolment === null) {
    return (
      <div className="flex flex-col gap-4">
        <p className="text-sm text-muted-foreground">
          The staff area sees every workspace, so it needs a second factor: a code from an authenticator app.
        </p>
        {failure !== null && <Notice tone="error">{failure}</Notice>}
        <Button onClick={() => void begin()}>Set up two-factor sign-in</Button>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-5">
      <p className="text-sm text-muted-foreground">
        Scan this with your authenticator app, then enter the code it shows.
      </p>
      <img src={enrolment.qr_svg_data_url} alt="QR code for your authenticator app" width={200} height={200} />
      <p className="text-sm text-muted-foreground">
        Or type this key: <code className="font-mono text-foreground">{enrolment.secret}</code>
      </p>
      <TwoFactorCodeForm
        label="Code from the app"
        hint="Six digits; it changes every 30 seconds."
        submitLabel="Confirm"
        onSubmit={async (code) => {
          const { data, error, response } = await api.POST('/v1/security/two-factor/confirm', { body: { code } })
          if (data !== undefined) setCodes(data.recovery_codes)

          return { ok: data !== undefined, status: response.status, error }
        }}
      />
    </div>
  )
}
