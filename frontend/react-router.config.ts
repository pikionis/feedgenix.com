import type { Config } from '@react-router/dev/config'

export default {
  appDirectory: 'src',
  // No runtime server: nginx serves the build. Marketing pages are pre-rendered for SEO and
  // every other path (the app) hydrates from build/client/__spa-fallback.html.
  ssr: false,
  prerender: ['/'],
} satisfies Config
