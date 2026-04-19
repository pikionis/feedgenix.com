import { Link, useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { useAuthStore } from '@/stores/authStore';
import AuthLayout from '@/components/layout/AuthLayout';
import {
  Field,
  OAuthButton,
  PasswordInput,
  Separator,
  TextInput,
} from '@/components/auth/form';

const loginSchema = z.object({
  email: z.string().email('Please enter a valid email address'),
  password: z.string().min(1, 'Password is required'),
});

type LoginForm = z.infer<typeof loginSchema>;

export default function Login() {
  const navigate = useNavigate();
  const login = useAuthStore((s) => s.login);
  const [error, setError] = useState('');
  const [showPwd, setShowPwd] = useState(false);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginForm>({ resolver: zodResolver(loginSchema) });

  const onSubmit = async (data: LoginForm) => {
    try {
      setError('');
      await login(data.email, data.password);
      navigate('/dashboard');
    } catch (err: unknown) {
      const message =
        err && typeof err === 'object' && 'response' in err
          ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
          : undefined;
      setError(message || 'Invalid credentials. Please try again.');
    }
  };

  return (
    <AuthLayout
      title="Welcome back"
      subtitle="Sign in to your FeedGenix workspace."
      crumbs={{
        left: 'Sign in',
        right: (
          <>
            New here?{' '}
            <Link to="/register" className="font-medium text-indigo hover:text-indigo-600">
              Create an account
            </Link>
          </>
        ),
      }}
      footer={
        <>
          Having trouble?{' '}
          <a
            href="mailto:support@feedgenix.com"
            className="font-medium text-indigo hover:text-indigo-600"
          >
            Email support
          </a>{' '}
          ·{' '}
          <Link to="/sso" className="font-medium text-indigo hover:text-indigo-600">
            Use SSO
          </Link>
        </>
      }
    >
      <div className="mb-4.5 grid grid-cols-2 gap-2.5">
        <OAuthButton provider="google" label="Continue with Google" />
        <OAuthButton provider="apple" label="Continue with Apple" />
      </div>
      <Separator />

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-3.5">
        {error && (
          <div className="rounded-lg border-[0.5px] border-[#C6392A]/30 bg-[#FCE5E1] px-3 py-2.5 text-[13px] text-[#C6392A]">
            {error}
          </div>
        )}

        <Field label="Work email" error={errors.email?.message}>
          <TextInput
            type="email"
            autoComplete="email"
            placeholder="you@company.com"
            {...register('email')}
          />
        </Field>

        <Field
          label={
            <div className="flex items-center justify-between">
              <span>Password</span>
              <Link
                to="/forgot-password"
                className="text-[12px] font-medium text-indigo hover:text-indigo-600"
              >
                Forgot?
              </Link>
            </div>
          }
          error={errors.password?.message}
        >
          <PasswordInput
            visible={showPwd}
            onToggle={() => setShowPwd((v) => !v)}
            placeholder="••••••••••"
            autoComplete="current-password"
            {...register('password')}
          />
        </Field>

        <label className="my-1.5 flex cursor-pointer items-start gap-2.5 text-[13px] text-ink-500">
          <input type="checkbox" defaultChecked className="mt-0.5 accent-[#4F46E5]" />
          <span>Keep me signed in on this device for 30 days</span>
        </label>

        <button
          type="submit"
          disabled={isSubmitting}
          className="mt-2 w-full rounded-lg bg-indigo py-3 text-[14px] font-medium text-white transition-colors duration-200 hover:bg-indigo-600 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {isSubmitting ? 'Signing in…' : 'Sign in'}
        </button>
      </form>
    </AuthLayout>
  );
}
