import { useNavigate, Link } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { useProjectStore } from '@/stores/projectStore';
import PageHeader from '@/components/app/PageHeader';
import { Field, TextInput } from '@/components/auth/form';

const LANGUAGES = [
  { code: 'en', label: 'English' },
  { code: 'de', label: 'German' },
  { code: 'fr', label: 'French' },
  { code: 'it', label: 'Italian' },
  { code: 'es', label: 'Spanish' },
  { code: 'nl', label: 'Dutch' },
  { code: 'pt', label: 'Portuguese' },
  { code: 'pl', label: 'Polish' },
  { code: 'el', label: 'Greek' },
  { code: 'sv', label: 'Swedish' },
  { code: 'da', label: 'Danish' },
];

const projectSchema = z.object({
  name: z.string().min(1, 'Feed name is required').max(255),
  description: z.string().max(1000).optional(),
  source_language: z.string().min(1, 'Pick the source language'),
  target_languages: z.array(z.string()).min(1, 'Pick at least one target language'),
});

type ProjectForm = z.infer<typeof projectSchema>;

export default function NewProject() {
  const navigate = useNavigate();
  const createProject = useProjectStore((s) => s.createProject);
  const [error, setError] = useState('');

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors, isSubmitting },
  } = useForm<ProjectForm>({
    resolver: zodResolver(projectSchema),
    defaultValues: { target_languages: [] },
  });

  const selectedTargets = watch('target_languages') ?? [];
  const sourceLanguage = watch('source_language');

  const toggleTarget = (code: string) => {
    const updated = selectedTargets.includes(code)
      ? selectedTargets.filter((c) => c !== code)
      : [...selectedTargets, code];
    setValue('target_languages', updated, { shouldValidate: true });
  };

  const onSubmit = async (data: ProjectForm) => {
    try {
      setError('');
      const project = await createProject(data);
      navigate(`/projects/${project.id}`);
    } catch (err: unknown) {
      const message =
        err && typeof err === 'object' && 'response' in err
          ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
          : undefined;
      setError(message ?? 'Failed to create the feed. Please try again.');
    }
  };

  return (
    <div className="mx-auto max-w-2xl">
      <nav aria-label="Breadcrumb" className="mb-3 flex items-center gap-2 text-[12px] text-ink-500">
        <Link to="/projects" className="hover:text-ink">Feeds</Link>
        <span aria-hidden>/</span>
        <span className="text-ink">New feed</span>
      </nav>

      <PageHeader
        title="Create a new feed"
        subtitle="Upload comes next — first we set the language plan."
      />

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
        {error && (
          <div
            role="alert"
            className="rounded-lg border-[0.5px] border-[#C6392A]/30 bg-[#FCE5E1] px-3 py-2.5 text-[13px] text-[#C6392A]"
          >
            {error}
          </div>
        )}

        <Field label="Feed name" error={errors.name?.message}>
          <TextInput
            placeholder="e.g., Autumn/winter 2026"
            autoComplete="off"
            {...register('name')}
          />
        </Field>

        <Field label="Description (optional)">
          <textarea
            rows={3}
            placeholder="Short note — channel, region, anything the next operator should know."
            className="fg-input resize-none"
            {...register('description')}
          />
        </Field>

        <Field label="Source language" error={errors.source_language?.message}>
          <select
            className="fg-input"
            {...register('source_language')}
            defaultValue=""
          >
            <option value="" disabled>
              Select the language the source CSV is in…
            </option>
            {LANGUAGES.map((lang) => (
              <option key={lang.code} value={lang.code}>
                {lang.label}
              </option>
            ))}
          </select>
        </Field>

        <Field
          label="Target languages"
          error={errors.target_languages?.message}
          hint="Tap the languages you want to ship — you can change these later."
        >
          <div className="flex flex-wrap gap-2">
            {LANGUAGES.filter((l) => l.code !== sourceLanguage).map((lang) => {
              const isSelected = selectedTargets.includes(lang.code);
              return (
                <button
                  key={lang.code}
                  type="button"
                  aria-pressed={isSelected}
                  onClick={() => toggleTarget(lang.code)}
                  className={[
                    'rounded-full px-3 py-1.5 text-[13px] font-medium transition-colors duration-200',
                    isSelected
                      ? 'bg-indigo text-white'
                      : 'border-[0.5px] border-ink/14 bg-paper text-ink hover:bg-mist',
                  ].join(' ')}
                >
                  {lang.label}
                </button>
              );
            })}
          </div>
        </Field>

        <div className="flex items-center gap-2 pt-2">
          <button
            type="submit"
            disabled={isSubmitting}
            className="rounded-lg bg-indigo px-4 py-2.25 text-[14px] font-medium text-white transition-colors duration-200 hover:bg-indigo-600 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {isSubmitting ? 'Creating…' : 'Create feed →'}
          </button>
          <Link
            to="/projects"
            className="rounded-lg border-[0.5px] border-ink/14 bg-paper px-4 py-2.25 text-[14px] font-medium text-ink transition-colors duration-200 hover:bg-mist"
          >
            Cancel
          </Link>
        </div>
      </form>
    </div>
  );
}
