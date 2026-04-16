import { useNavigate, Link } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { useProjectStore } from '@/stores/projectStore';
import { Button } from '@/components/ui/button';

const languages = [
  { code: 'en', label: 'English' },
  { code: 'de', label: 'German' },
  { code: 'fr', label: 'French' },
  { code: 'es', label: 'Spanish' },
  { code: 'it', label: 'Italian' },
  { code: 'nl', label: 'Dutch' },
  { code: 'pt', label: 'Portuguese' },
  { code: 'pl', label: 'Polish' },
  { code: 'sv', label: 'Swedish' },
  { code: 'da', label: 'Danish' },
];

const projectSchema = z.object({
  name: z.string().min(1, 'Project name is required').max(255),
  description: z.string().max(1000).optional(),
  source_language: z.string().min(1, 'Please select a source language'),
  target_languages: z.array(z.string()).min(1, 'Select at least one target language'),
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
    defaultValues: {
      target_languages: [],
    },
  });

  const selectedTargets = watch('target_languages');
  const sourceLanguage = watch('source_language');

  const toggleTarget = (code: string) => {
    const current = selectedTargets || [];
    const updated = current.includes(code)
      ? current.filter((c) => c !== code)
      : [...current, code];
    setValue('target_languages', updated, { shouldValidate: true });
  };

  const onSubmit = async (data: ProjectForm) => {
    try {
      setError('');
      const project = await createProject(data);
      navigate(`/projects/${project.id}`);
    } catch {
      setError('Failed to create project. Please try again.');
    }
  };

  return (
    <div className="max-w-2xl">
      <div className="flex items-center gap-2 text-sm text-muted-foreground mb-6">
        <Link to="/projects" className="hover:text-foreground">Projects</Link>
        <span>/</span>
        <span className="text-foreground">New Project</span>
      </div>

      <h1 className="text-2xl font-bold mb-6">Create New Project</h1>

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
        {error && (
          <div className="p-3 rounded-lg bg-destructive/10 text-destructive text-sm">
            {error}
          </div>
        )}

        <div>
          <label htmlFor="name" className="block text-sm font-medium mb-1.5">
            Project Name
          </label>
          <input
            id="name"
            type="text"
            placeholder="e.g., Summer Collection DE+FR"
            {...register('name')}
            className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-primary/50 focus:border-primary"
          />
          {errors.name && (
            <p className="text-xs text-destructive mt-1">{errors.name.message}</p>
          )}
        </div>

        <div>
          <label htmlFor="description" className="block text-sm font-medium mb-1.5">
            Description (optional)
          </label>
          <textarea
            id="description"
            rows={3}
            placeholder="Brief description of this project..."
            {...register('description')}
            className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-primary/50 focus:border-primary resize-none"
          />
        </div>

        <div>
          <label htmlFor="source_language" className="block text-sm font-medium mb-1.5">
            Source Language
          </label>
          <select
            id="source_language"
            {...register('source_language')}
            className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-primary/50 focus:border-primary"
          >
            <option value="">Select source language...</option>
            {languages.map((lang) => (
              <option key={lang.code} value={lang.code}>
                {lang.label}
              </option>
            ))}
          </select>
          {errors.source_language && (
            <p className="text-xs text-destructive mt-1">{errors.source_language.message}</p>
          )}
        </div>

        <div>
          <label className="block text-sm font-medium mb-1.5">Target Languages</label>
          <div className="flex flex-wrap gap-2">
            {languages
              .filter((lang) => lang.code !== sourceLanguage)
              .map((lang) => {
                const isSelected = selectedTargets?.includes(lang.code);
                return (
                  <button
                    key={lang.code}
                    type="button"
                    onClick={() => toggleTarget(lang.code)}
                    className={`px-3 py-1.5 rounded-lg text-sm border transition-colors ${
                      isSelected
                        ? 'border-primary bg-primary/10 text-primary'
                        : 'border-border text-muted-foreground hover:border-primary/50'
                    }`}
                  >
                    {lang.label}
                  </button>
                );
              })}
          </div>
          {errors.target_languages && (
            <p className="text-xs text-destructive mt-1">{errors.target_languages.message}</p>
          )}
        </div>

        <div className="flex items-center gap-3 pt-4">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Creating...' : 'Create Project'}
          </Button>
          <Link to="/projects">
            <Button variant="outline" type="button">Cancel</Button>
          </Link>
        </div>
      </form>
    </div>
  );
}
