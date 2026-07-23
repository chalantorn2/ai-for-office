import { spawn } from 'node:child_process'
import net from 'node:net'
import path from 'node:path'
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

const PHP_HOST = 'localhost'
const PHP_PORT = 8000

function isPortTaken(host, port) {
  return new Promise((resolve) => {
    const socket = net
      .connect({ host, port })
      .on('connect', () => {
        socket.destroy()
        resolve(true)
      })
      .on('error', () => resolve(false))
    socket.setTimeout(500, () => {
      socket.destroy()
      resolve(false)
    })
  })
}

// Starts the PHP backend alongside `vite` so `bun dev` is the only command needed.
function phpBackend() {
  let child = null

  const stop = () => {
    if (child && !child.killed) child.kill()
    child = null
  }

  return {
    name: 'php-backend',
    apply: 'serve',
    async configureServer(server) {
      if (await isPortTaken(PHP_HOST, PHP_PORT)) {
        server.config.logger.info(
          `[php] reusing server already listening on ${PHP_HOST}:${PHP_PORT}`,
        )
        return
      }

      // No `shell`, so the handle is php itself and kill() cannot orphan it.
      child = spawn(
        'php',
        ['-S', `${PHP_HOST}:${PHP_PORT}`, '-t', import.meta.dirname],
        { cwd: import.meta.dirname },
      )

      child.on('error', (err) => {
        server.config.logger.error(`[php] failed to start: ${err.message}`)
        child = null
      })
      // php -S logs requests and errors on stderr.
      child.stderr.on('data', (data) => process.stderr.write(`[php] ${data}`))
      child.stdout.on('data', (data) => process.stdout.write(`[php] ${data}`))

      server.httpServer?.once('listening', () =>
        server.config.logger.info(`  ➜  PHP:     http://${PHP_HOST}:${PHP_PORT}/`),
      )

      process.once('exit', stop)
      process.once('SIGINT', stop)
      process.once('SIGTERM', stop)
    },
    closeBundle: stop,
    buildEnd: stop,
  }
}

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss(), phpBackend()],
  resolve: {
    alias: {
      '@': path.resolve(import.meta.dirname, './src'),
    },
  },
  server: {
    proxy: {
      // The PHP backend runs separately in dev (php -S localhost:8000 -t .).
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
})
