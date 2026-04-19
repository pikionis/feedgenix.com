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
  PasswordStrength,
  Separator,
  TextInput,
  scorePassword,
} from '@/components/auth/form';

const registerSchema = z.object({
  first_name: z.string().min(1, 'First name is required'),
  last_name: z.string().min(1, 'Last name is required'),
  email: z.string().email('Please enter a valid email address'),
  password: z.string().min(10, 'Password must be at least 10 characters'),
  terms: z.boolean().refine((v) => v === true, {
    message: 'You must agree to the Terms and Privacy Policy',
  }),
});

type RegisterForm = z.infer<typeof registerSchema>;

export default function Register() {
  const navigate = useNavigate();
  const registerUser = useAuthStore((s) => s.register);
  const [error, setError] = useState('');
  const [showPwd, setShowPwd] = useState(false);

  const {
    register,
    handleSubmit,
    watch,
    formState: { errors, isSubmitting },
  } = useForm<RegisterForm>({
    resolver: zodResolver(registerSchema),
    defaultValues: { terms: false },
  });

  const pwd = watch('password') ?? '';

  const onSubmit = async (data: RegisterForm) => {
    try {
      setError('');
      const name = `${data.first_name.trim()} ${data.last_name.trim()}`.trim();
      await registerUser(name, data.email, data.password, data.password);
      navigate('/dashboard');
    } catch (err: unknown) {
      const message =
        err && typeof err === 'object' && 'response' in err
          ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
          : undefined;
      setError(message || 'Registration failed. Please try again.');
    }
  };

  return (
    <AuthLayout
      title="Start free — 500 rows"
      subtitle="No credit card. Translate your first CSV in under 4 minutes."
      crumbs={{
        left: 'Create account',
        right: (
          <>
            Already have one?{' '}
            <Link to="/login" className="font-medium text-indigo hover:text-indigo-600">
              Sign in
            </Link>
          </>
        ),
      }}
      footer={<>By continuing you'll receive a 6-digit code to verify your email.</>}
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

        <div className="grid grid-cols-2 gap-3">
          <Field label="First name" error={errors.first_name?.message}>
            <TextInput
              autoComplete="given-name"
              placeholder="Maria"
              {...register('first_name')}
            />
          </Field>
          <Field label="Last name" error={errors.last_name?.message}>
            <TextInput
              autoComplete="family-name"
              placeholder="Papadopoulos"
              {...register('last_name')}
            />
          </Field>
        </div>

        <Field label="Work email" error={errors.email?.message}>
          <TextInput
            type="email"
            autoComplete="email"
            placeholder="maria@deindeal.ch"
            {...register('email')}
          />
        </Field>

        <Field
          label="Password"
          error={errors.password?.message}
          hint="Use 10+ characters with a mix of letters, numbers, and symbols."
        >
          <PasswordInput
            visible={showPwd}
            onToggle={() => setShowPwd((v) => !v)}
            placeholder="At least 10 characters"
            autoComplete="new-password"
            {...register('password')}
          />
          <PasswordStrength score={scorePassword(pwd)} />
        </Field>

        <label className="my-1.5 flex cursor-pointer items-start gap-2.5 text-[13px] text-ink-500">
          <input type="checkbox" className="mt-0.5 accent-[#4F46E5]" {...register('terms')} />
          <span>
            I agree to FeedGenix's{' '}
            <Link to="/terms" className="font-medium text-indigo hover:text-indigo-600">
              Terms of Service
            </Link>{' '}
            and{' '}
            <Link to="/privacy" className="font-medium text-indigo hover:text-indigo-600">
              Privacy Policy
            </Link>
            , and consent to receive product updates.
          </span>
        </label>
        {errors.terms && <p className="-mt-1 text-[12px] text-[#C6392A]">{errors.terms.message}</p>}

        <button
          type="submit"
          disabled={isSubmitting}
          className="mt-1 w-full rounded-lg bg-indigo py-3 text-[14px] font-medium text-white transition-colors duration-200 hover:bg-indigo-600 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {isSubmitting ? 'Creating account…' : 'Create account →'}
        </button>
      </form>
    </AuthLayout>
  );
}
