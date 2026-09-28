// Serves build/client the way nginx does in production: static files first, the pre-rendered "/"
// from index.html, every other app path from __spa-fallback.html, and the API paths proxied to
// Laravel on the same origin. Used by Playwright and by `npm run serve`.
import { createReadStream, existsSync, statSync } from 'node:fs'
import { createServer, request } from 'node:http'
import { extname, join, normalize, resolve, sep } from 'node:path'

const args = Object.fromEntries(process.argv.slice(2).map((arg) => arg.replace(/^--/, '').split('=')))
const port = Number(args.port ?? 4173)
const backend = new URL(process.env.FEEDGENIX_BACKEND_URL ?? 'http://127.0.0.1:8000')
const root = resolve('build/client')
const proxied = ['/api/', '/sanctum/', '/auth/']
const types = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.json': 'application/json',
  '.woff2': 'font/woff2',
  '.woff': 'font/woff',
  '.png': 'image/png',
  '.ico': 'image/x-icon',
  '.txt': 'text/plain; charset=utf-8',
}

function proxy(req, res) {
  const upstream = request(
    { hostname: backend.hostname, port: backend.port, path: req.url, method: req.method, headers: req.headers },
    (answer) => {
      res.writeHead(answer.statusCode ?? 502, answer.headers)
      answer.pipe(res)
    },
  )
  upstream.on('error', () => res.writeHead(502).end('Bad gateway'))
  req.pipe(upstream)
}

/** The file to serve for a path, or null when the path escapes build/client. */
function fileFor(pathname) {
  const target = normalize(join(root, pathname))
  if (target !== root && !target.startsWith(root + sep)) return null
  if (existsSync(target) && statSync(target).isFile()) return target
  return join(root, pathname === '/' ? 'index.html' : '__spa-fallback.html')
}

createServer((req, res) => {
  let pathname
  try {
    pathname = decodeURIComponent(new URL(req.url ?? '/', 'http://localhost').pathname)
  } catch {
    return res.writeHead(400).end('Bad request')
  }

  if (pathname === '/up' || proxied.some((prefix) => pathname.startsWith(prefix))) return proxy(req, res)

  const file = fileFor(pathname)
  if (!file || !existsSync(file)) return res.writeHead(404).end('Not found')

  res.writeHead(200, { 'content-type': types[extname(file)] ?? 'application/octet-stream' })
  createReadStream(file).pipe(res)
}).listen(port, '127.0.0.1', () => {
  console.log(`FeedGenix build on http://127.0.0.1:${port} (API → ${backend.origin})`)
})
