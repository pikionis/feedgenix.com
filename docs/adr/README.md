# Architecture decision records

One record per decision that is expensive to reverse. Accepted records are not edited; a later
record supersedes them.

| # | Decision |
|---|---|
| [0001](0001-repository-layout-and-stack.md) | Repository layout and stack |
| [0002](0002-tenancy-and-row-level-security.md) | Tenancy and PostgreSQL row-level security |
| [0003](0003-canonical-schema-product-texts-bcp47.md) | Canonical schema, `product_texts` and BCP-47 languages |
| [0004](0004-ai-gateway.md) | The AI gateway |
| [0005](0005-metering-credits-and-pricing.md) | Metering, credits and pricing |
| [0006](0006-pipeline-work-units-and-reconciler.md) | Pipeline, work units, slots and the reconciler |
| [0007](0007-export-and-feed-serving.md) | Export profiles and feed serving |
| [0008](0008-stripe-billing.md) | Stripe billing |
| [0009](0009-hosting-deploys-and-backups.md) | Hosting, deploys and backups |
| [0010](0010-test-policy.md) | Test policy |
| [0011](0011-sandboxed-units-and-deploy-receiver.md) | Sandboxed services and a forced-command deploy receiver (refines 0009) |
| [0012](0012-reading-feeds.md) | Reading feeds: recognition by content, UTF-8, formats, never partial |
| [0013](0013-fetching-feed-urls.md) | Fetching feed URLs: the SSRF policy |
| [0014](0014-feed-size-per-plan.md) | Feed size per plan, within a server ceiling |
| [0015](0015-unfinished-packages-and-php-modules.md) | Unfinished dpkg packages and PHP modules on a shared host (refines 0011) |
