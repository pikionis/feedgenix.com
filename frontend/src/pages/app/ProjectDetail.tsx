import { useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useProjectStore } from '@/stores/projectStore';
import { Button } from '@/components/ui/button';

const statusSteps = ['draft', 'mapping', 'review', 'translating', 'completed'];

export default function ProjectDetail() {
  const { id } = useParams<{ id: string }>();
  const { currentProject, isLoading, fetchProject } = useProjectStore();

  useEffect(() => {
    if (id) fetchProject(Number(id));
  }, [id, fetchProject]);

  if (isLoading || !currentProject) {
    return <div className="text-sm text-muted-foreground">Loading project...</div>;
  }

  const currentStepIndex = statusSteps.indexOf(currentProject.status);

  return (
    <div>
      <div className="flex items-center gap-2 text-sm text-muted-foreground mb-6">
        <Link to="/projects" className="hover:text-foreground">Projects</Link>
        <span>/</span>
        <span className="text-foreground">{currentProject.name}</span>
      </div>

      <div className="flex items-center justify-between mb-8">
        <div>
          <h1 className="text-2xl font-bold mb-1">{currentProject.name}</h1>
          {currentProject.description && (
            <p className="text-muted-foreground text-sm">{currentProject.description}</p>
          )}
        </div>
        <div className="text-sm text-muted-foreground">
          {currentProject.products_count} products
        </div>
      </div>

      {/* Status pipeline */}
      <div className="flex items-center gap-2 mb-8">
        {statusSteps.map((step, i) => (
          <div key={step} className="flex items-center gap-2">
            <div
              className={`px-3 py-1 rounded-full text-xs font-medium ${
                i <= currentStepIndex
                  ? 'bg-primary text-primary-foreground'
                  : 'bg-muted text-muted-foreground'
              }`}
            >
              {step}
            </div>
            {i < statusSteps.length - 1 && (
              <div className={`w-8 h-0.5 ${i < currentStepIndex ? 'bg-primary' : 'bg-border'}`} />
            )}
          </div>
        ))}
      </div>

      {/* Sections */}
      <div className="space-y-6">
        {/* Upload section */}
        <div className="rounded-xl border border-border p-6">
          <h2 className="text-lg font-semibold mb-2">Upload</h2>
          <p className="text-sm text-muted-foreground mb-4">
            Upload a CSV file with your product data.
          </p>
          <Button variant="outline" size="sm">Upload CSV</Button>
        </div>

        {/* Mapping section */}
        <div className="rounded-xl border border-border p-6">
          <h2 className="text-lg font-semibold mb-2">Field Mapping</h2>
          <p className="text-sm text-muted-foreground mb-4">
            Review AI-generated field mappings and select which fields to translate.
          </p>
          <Button variant="outline" size="sm" disabled={currentProject.status === 'draft'}>
            Review Mapping
          </Button>
        </div>

        {/* Translation section */}
        <div className="rounded-xl border border-border p-6">
          <h2 className="text-lg font-semibold mb-2">Translation</h2>
          <p className="text-sm text-muted-foreground mb-4">
            Translate your products into target languages.
          </p>
          <div className="flex items-center gap-3">
            <Button
              variant="outline"
              size="sm"
              disabled={!['review', 'translating', 'completed'].includes(currentProject.status)}
            >
              Start Translation
            </Button>
            {currentProject.status === 'completed' && (
              <Button size="sm">Download Export</Button>
            )}
          </div>
        </div>

        {/* Project info */}
        <div className="rounded-xl border border-border p-6">
          <h2 className="text-lg font-semibold mb-4">Project Info</h2>
          <dl className="grid grid-cols-2 gap-4 text-sm">
            <div>
              <dt className="text-muted-foreground">Source Language</dt>
              <dd className="font-medium">{currentProject.source_language || 'Not set'}</dd>
            </div>
            <div>
              <dt className="text-muted-foreground">Target Languages</dt>
              <dd className="font-medium">
                {currentProject.target_languages.length > 0
                  ? currentProject.target_languages.join(', ')
                  : 'Not set'}
              </dd>
            </div>
            <div>
              <dt className="text-muted-foreground">Status</dt>
              <dd className="font-medium capitalize">{currentProject.status}</dd>
            </div>
            <div>
              <dt className="text-muted-foreground">Created</dt>
              <dd className="font-medium">
                {new Date(currentProject.created_at).toLocaleDateString()}
              </dd>
            </div>
          </dl>
        </div>
      </div>
    </div>
  );
}
