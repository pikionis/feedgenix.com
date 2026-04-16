import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';

export default function Landing() {
  return (
    <div className="min-h-screen bg-background">
      {/* Header */}
      <header className="border-b border-border">
        <div className="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between">
          <Link to="/" className="text-2xl font-bold text-primary">FeedGenix</Link>
          <nav className="flex items-center gap-6">
            <Link to="/pricing" className="text-sm text-muted-foreground hover:text-foreground transition-colors">
              Pricing
            </Link>
            <Link to="/login">
              <Button variant="outline" size="sm">Sign in</Button>
            </Link>
            <Link to="/register">
              <Button size="sm">Get Started Free</Button>
            </Link>
          </nav>
        </div>
      </header>

      {/* Hero */}
      <section className="max-w-6xl mx-auto px-6 py-24 text-center">
        <h1 className="text-5xl font-bold tracking-tight text-foreground mb-6">
          AI-Powered Feed Intelligence
        </h1>
        <p className="text-xl text-muted-foreground max-w-2xl mx-auto mb-10">
          Translate, optimize, and enrich your product feeds for every marketplace.
          Upload a CSV, let AI map your fields, and get perfectly translated feeds in minutes.
        </p>
        <div className="flex items-center justify-center gap-4">
          <Link to="/register">
            <Button size="lg">Get Started Free</Button>
          </Link>
          <Link to="/pricing">
            <Button variant="outline" size="lg">View Pricing</Button>
          </Link>
        </div>
      </section>

      {/* Features */}
      <section className="max-w-6xl mx-auto px-6 py-16">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
          <div className="p-6 rounded-xl border border-border">
            <div className="w-10 h-10 rounded-lg bg-primary/10 text-primary flex items-center justify-center mb-4 font-bold">
              AI
            </div>
            <h3 className="text-lg font-semibold mb-2">Smart Field Mapping</h3>
            <p className="text-sm text-muted-foreground">
              AI automatically detects and maps your CSV columns to standard product fields with confidence scores.
            </p>
          </div>
          <div className="p-6 rounded-xl border border-border">
            <div className="w-10 h-10 rounded-lg bg-primary/10 text-primary flex items-center justify-center mb-4 font-bold">
              Tx
            </div>
            <h3 className="text-lg font-semibold mb-2">Bulk Translation</h3>
            <p className="text-sm text-muted-foreground">
              Translate thousands of products into multiple languages using intelligent provider routing and caching.
            </p>
          </div>
          <div className="p-6 rounded-xl border border-border">
            <div className="w-10 h-10 rounded-lg bg-primary/10 text-primary flex items-center justify-center mb-4 font-bold">
              QA
            </div>
            <h3 className="text-lg font-semibold mb-2">Golden Rule Protection</h3>
            <p className="text-sm text-muted-foreground">
              Prices, stock levels, barcodes, and SKUs are never modified. Your critical data stays safe.
            </p>
          </div>
        </div>
      </section>

      {/* Footer */}
      <footer className="border-t border-border mt-16">
        <div className="max-w-6xl mx-auto px-6 py-8 text-center text-sm text-muted-foreground">
          &copy; {new Date().getFullYear()} FeedGenix. All rights reserved.
        </div>
      </footer>
    </div>
  );
}
