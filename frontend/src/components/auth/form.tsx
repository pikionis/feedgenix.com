import type { ReactNode, InputHTMLAttributes, ForwardedRef } from 'react';
import { forwardRef } from 'react';

/**
 * Form primitives used across auth pages. Style matches the Auth UI kit
 * from the FeedGenix design system (hairline borders, indigo focus ring,
 * 12px labels, sentence-case copy).
 */

export function Field({
  label,
  error,
  hint,
  children,
}: {
  label: ReactNode;
  error?: string;
  hint?: string;
  children: ReactNode;
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <label className="text-[12px] font-medium text-ink">{label}</label>
      {children}
      {error && <p className="mt-0.5 text-[12px] text-[#C6392A]">{error}</p>}
      {hint && !error && <span className="mt-0.5 text-[11.5px] text-ink-400">{hint}</span>}
    </div>
  );
}

type InputProps = InputHTMLAttributes<HTMLInputElement>;

export const TextInput = forwardRef<HTMLInputElement, InputProps>(function TextInput(
  { className, ...rest },
  ref: ForwardedRef<HTMLInputElement>
) {
  return <input ref={ref} className={`fg-input ${className ?? ''}`} {...rest} />;
});

type PasswordProps = InputProps & {
  visible: boolean;
  onToggle: () => void;
};

export const PasswordInput = forwardRef<HTMLInputElement, PasswordProps>(function PasswordInput(
  { visible, onToggle, className, ...rest },
  ref: ForwardedRef<HTMLInputElement>
) {
  return (
    <div className="relative">
      <input
        ref={ref}
        type={visible ? 'text' : 'password'}
        className={`fg-input pr-10 ${className ?? ''}`}
        {...rest}
      />
      <button
        type="button"
        onClick={onToggle}
        aria-label={visible ? 'Hide password' : 'Show password'}
        className="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 text-ink-400 hover:text-ink"
      >
        <EyeIcon />
      </button>
    </div>
  );
});

/** Horizontal "OR" separator used between OAuth and email forms. */
export function Separator() {
  return (
    <div className="my-2 flex items-center gap-3 text-[11px] tracking-[0.08em] text-ink-300">
      <span className="h-px flex-1 bg-ink/14" />
      OR
      <span className="h-px flex-1 bg-ink/14" />
    </div>
  );
}

export function OAuthButton({
  provider,
  label,
}: {
  provider: 'google' | 'apple';
  label: string;
}) {
  return (
    <button
      type="button"
      className="inline-flex w-full items-center justify-center gap-2 rounded-lg border-[0.5px] border-ink/14 bg-paper px-3.5 py-2.5 text-[13px] font-medium text-ink transition-colors duration-200 hover:bg-[#FAFAF7]"
    >
      {provider === 'google' ? <GoogleMark /> : <AppleMark />}
      {label}
    </button>
  );
}

/** 4-segment password strength meter.
 *  0=empty, 1=weak (red), 2=ok (amber), 3=good (emerald), 4=strong (emerald). */
export function PasswordStrength({ score }: { score: 0 | 1 | 2 | 3 | 4 }) {
  return (
    <div className="mt-2 flex h-[3px] gap-1 overflow-hidden rounded-full">
      {[0, 1, 2, 3].map((i) => (
        <span
          key={i}
          className="flex-1 rounded-full"
          style={{ background: color(score, i) }}
        />
      ))}
    </div>
  );
}
function color(score: number, index: number): string {
  if (index >= score) return '#EDEBE3';
  if (score === 1) return '#C6392A';
  if (score === 2) return '#C87A1B';
  return '#0F9B7E';
}

export function scorePassword(v: string): 0 | 1 | 2 | 3 | 4 {
  let s = 0;
  if (v.length >= 6) s = 1;
  if (v.length >= 8 && /\d/.test(v)) s = 2;
  if (v.length >= 10 && /[A-Z]/.test(v) && /\d/.test(v)) s = 3;
  if (v.length >= 12 && /[^A-Za-z0-9]/.test(v)) s = 4;
  return s as 0 | 1 | 2 | 3 | 4;
}

/* ───────────────────────── ICONS ───────────────────────── */

function EyeIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
      <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z" />
      <circle cx="12" cy="12" r="3" />
    </svg>
  );
}
function GoogleMark() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24">
      <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.27-4.74 3.27-8.1z" />
      <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.99.66-2.26 1.05-3.71 1.05-2.86 0-5.28-1.93-6.15-4.53H2.17v2.84A11 11 0 0 0 12 23z" />
      <path fill="#FBBC05" d="M5.85 14.09a6.62 6.62 0 0 1 0-4.18V7.07H2.17a11 11 0 0 0 0 9.86l3.68-2.84z" />
      <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.2 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1A11 11 0 0 0 2.17 7.07l3.68 2.84C6.72 7.3 9.14 5.38 12 5.38z" />
    </svg>
  );
}
function AppleMark() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="#1F1D2E">
      <path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.54 4.07zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z" />
    </svg>
  );
}
