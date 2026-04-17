import { Link } from 'react-router-dom';

interface LogoProps {
  variant?: 'default' | 'mark' | 'wordmark';
  size?: 'sm' | 'md' | 'lg';
  className?: string;
}

const sizeMap = {
  sm: { mark: 20, text: 'text-base' },
  md: { mark: 24, text: 'text-lg' },
  lg: { mark: 32, text: 'text-2xl' },
};

export function LogoMark({ size = 24, className = '' }: { size?: number; className?: string }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 32 32"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      className={className}
      aria-hidden="true"
    >
      <rect width="32" height="32" rx="8" fill="hsl(var(--primary))" />
      <path
        d="M10 9h12M10 16h8M10 23h12"
        stroke="white"
        strokeWidth="2.5"
        strokeLinecap="round"
      />
      <circle cx="24" cy="16" r="2" fill="white" />
    </svg>
  );
}

export default function Logo({ variant = 'default', size = 'md', className = '' }: LogoProps) {
  const s = sizeMap[size];

  if (variant === 'mark') {
    return <LogoMark size={s.mark} className={className} />;
  }

  return (
    <Link to="/" className={`inline-flex items-center gap-2 group ${className}`}>
      {variant !== 'wordmark' && <LogoMark size={s.mark} />}
      <span className={`font-heading font-bold tracking-tight text-foreground ${s.text}`}>
        FeedGenix
      </span>
    </Link>
  );
}
