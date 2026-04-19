import { Link } from 'react-router-dom';

/**
 * The official FeedGenix mark — three indigo bars representing feed columns,
 * with the emerald "confirmation dot" that reappears on positive states
 * (the Golden Rule check, status pills, cache hits).
 *
 * Two variants: `dark` (indigo bars on light surfaces — default) and
 * `inverse` (white bars on Ink surfaces like the footer or dark hero).
 */

type MarkTone = 'dark' | 'inverse';

interface LogoMarkProps {
  size?: number;
  tone?: MarkTone;
  className?: string;
}

export function LogoMark({ size = 28, tone = 'dark', className = '' }: LogoMarkProps) {
  const bar = tone === 'inverse' ? '#FFFFFF' : '#4F46E5';
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 48 48"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      className={className}
      aria-hidden="true"
    >
      <rect x="6"  y="14"   width="22" height="3.2" rx="1.6" fill={bar} />
      <rect x="6"  y="21.4" width="28" height="3.2" rx="1.6" fill={bar} />
      <rect x="6"  y="28.8" width="15" height="3.2" rx="1.6" fill={bar} />
      <circle cx="36.5" cy="15.6" r="3.4" fill="#0F9B7E" />
    </svg>
  );
}

interface LogoProps {
  variant?: 'default' | 'mark' | 'wordmark';
  tone?: MarkTone;
  size?: 'sm' | 'md' | 'lg';
  className?: string;
  /** When true, renders a non-link span — useful inside footers or hero areas. */
  asStatic?: boolean;
}

const sizeMap = {
  sm: { mark: 22, text: 'text-[15px]' },
  md: { mark: 28, text: 'text-[18px]' },
  lg: { mark: 36, text: 'text-[22px]' },
};

export default function Logo({
  variant = 'default',
  tone = 'dark',
  size = 'md',
  className = '',
  asStatic = false,
}: LogoProps) {
  const s = sizeMap[size];
  const wordmarkColor = tone === 'inverse' ? 'text-paper' : 'text-ink';

  if (variant === 'mark') {
    return <LogoMark size={s.mark} tone={tone} className={className} />;
  }

  const content = (
    <>
      {variant !== 'wordmark' && <LogoMark size={s.mark} tone={tone} />}
      <span
        className={`font-medium tracking-[-0.02em] ${wordmarkColor} ${s.text}`}
        style={{ letterSpacing: '-0.02em' }}
      >
        FeedGenix
      </span>
    </>
  );

  if (asStatic) {
    return <span className={`inline-flex items-center gap-[10px] ${className}`}>{content}</span>;
  }

  return (
    <Link to="/" className={`inline-flex items-center gap-[10px] ${className}`}>
      {content}
    </Link>
  );
}
