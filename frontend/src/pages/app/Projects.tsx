import { useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useProjectStore } from '@/stores/projectStore';
import { Button } from '@/components/ui/button';

const statusColors: Record<string, string> = {
  draft: 'bg-muted text-muted-foreground',
  mapping: 'bg-yellow-100 text-yellow-800',
  review: 'bg-blue-100 text-blue-800',
  translating: 'bg-purple-100 text-purple-800',
  completed: 'bg-green-100 text-green-800',
  error: 'bg-red-100 text-red-800',
};

export default function Projects() {
  const { projects, isLoading, fetchProjects } = useProjectStore();

  useEffect(() => {
    fetchProjects();
  }, [fetchProjects]);

  return (
    <div>
      <div className="flex items-center justify-between mb-8">
        <div>
          <h1 className="text-2xl font-bold mb-1">Projects</h1>
          <p className="text-muted-foreground text-sm">Manage your product feed projects.</p>
        </div>
        <Link to="/projects/new">
          <Button>New Project</Button>
        </Link>
      </div>

      {isLoading ? (
        <div className="text-sm text-muted-foreground">Loading projects...</div>
      ) : projects.length === 0 ? (
        <div className="rounded-xl border border-border p-12 text-center">
          <h3 className="text-lg font-semibold mb-2">No projects yet</h3>
          <p className="text-sm text-muted-foreground mb-4">
            Create your first project to start translating product feeds.
          </p>
          <Link to="/projects/new">
            <Button>Create Project</Button>
          </Link>
        </div>
      ) : (
        <div className="grid gap-4">
          {projects.map((project) => (
            <Link
              key={project.id}
              to={`/projects/${project.id}`}
              className="block rounded-xl border border-border p-5 hover:border-primary/50 transition-colors"
            >
              <div className="flex items-center justify-between">
                <div>
                  <h3 className="font-semibold">{project.name}</h3>
                  {project.description && (
                    <p className="text-sm text-muted-foreground mt-1">{project.description}</p>
                  )}
                </div>
                <div className="flex items-center gap-3">
                  <span className="text-sm text-muted-foreground">
                    {project.products_count} products
                  </span>
                  <span
                    className={`text-xs font-medium px-2 py-0.5 rounded-full ${
                      statusColors[project.status] || statusColors.draft
                    }`}
                  >
                    {project.status}
                  </span>
                </div>
              </div>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
