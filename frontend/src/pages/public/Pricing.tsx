import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';

const tiers = [
  {
    name: 'Free',
    price: '0',
    period: 'forever',
    description: 'Try FeedGenix with up to 100 products.',
    features: [
      '100 products / month',
      '1 target language',
      '1 team member',
      'CSV upload & export',
      'Basic field mapping',
    ],
    cta: 'Get Started Free',
    highlighted: false,
  },
  {
    name: 'Starter',
    price: '29',
    period: '/month',
    description: 'For small sellers expanding into new markets.',
    features: [
      '1,000 products / month',
      '3 target languages',
      '2 team members',
      'Smart AI mapping',
      'XLSX export',
      'Change detection',
    ],
    cta: 'Start Free Trial',
    highlighted: false,
  },
  {
    name: 'Pro',
    price: '79',
    period: '/month',
    description: 'For growing businesses with large catalogs.',
    features: [
      '10,000 products / month',
      '10 target languages',
      '5 team members',
      'All export formats',
      'Continuous sync',
      'API access',
      'Priority support',
    ],
    cta: 'Start Free Trial',
    highlighted: true,
  },
  {
    name: 'Enterprise',
    price: '199',
    period: '/month',
    description: 'Unlimited everything for large operations.',
    features: [
      'Unlimited products',
      'Unlimited languages',
      'Unlimited team members',
      'All features included',
      'Dedicated support',
      'Custom integrations',
    ],
    cta: 'Contact Sales',
    highlighted: false,
  },
];

export default function Pricing() {
  return (
    <div className="min-h-screen bg-background">
      {/* Header */}
      <header className="border-b border-border">
        <div className="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between">
          <Link to="/" className="text-2xl font-bold text-primary">FeedGenix</Link>
          <nav className="flex items-center gap-6">
            <Link to="/login">
              <Button variant="outline" size="sm">Sign in</Button>
            </Link>
            <Link to="/register">
              <Button size="sm">Get Started Free</Button>
            </Link>
          </nav>
        </div>
      </header>

      {/* Pricing */}
      <section className="max-w-6xl mx-auto px-6 py-16">
        <h1 className="text-4xl font-bold text-center mb-4">Simple, Transparent Pricing</h1>
        <p className="text-lg text-muted-foreground text-center mb-12">
          Start free, upgrade when you need more.
        </p>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          {tiers.map((tier) => (
            <div
              key={tier.name}
              className={`rounded-xl border p-6 flex flex-col ${
                tier.highlighted
                  ? 'border-primary ring-2 ring-primary/20'
                  : 'border-border'
              }`}
            >
              {tier.highlighted && (
                <div className="text-xs font-medium text-primary mb-2">Most Popular</div>
              )}
              <h3 className="text-lg font-semibold mb-1">{tier.name}</h3>
              <div className="flex items-baseline gap-1 mb-2">
                <span className="text-3xl font-bold">&euro;{tier.price}</span>
                <span className="text-sm text-muted-foreground">{tier.period}</span>
              </div>
              <p className="text-sm text-muted-foreground mb-6">{tier.description}</p>
              <ul className="space-y-2 mb-8 flex-1">
                {tier.features.map((feature) => (
                  <li key={feature} className="text-sm flex items-start gap-2">
                    <span className="text-primary mt-0.5">&#10003;</span>
                    {feature}
                  </li>
                ))}
              </ul>
              <Link to="/register">
                <Button
                  variant={tier.highlighted ? 'default' : 'outline'}
                  className="w-full"
                >
                  {tier.cta}
                </Button>
              </Link>
            </div>
          ))}
        </div>
      </section>
    </div>
  );
}
