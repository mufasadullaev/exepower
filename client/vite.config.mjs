import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'node:path'
import autoprefixer from 'autoprefixer'

export default defineConfig(() => {
  return {
    base: './',
    build: {
      outDir: 'build',
    },
    css: {
      postcss: {
        plugins: [
          autoprefixer({}), // add options if needed
        ],
      },
    },
    esbuild: {
      loader: 'jsx',
      include: /src\/.*\.jsx?$/,
      exclude: [],
    },
    optimizeDeps: {
      force: true,
      esbuildOptions: {
        loader: {
          '.js': 'jsx',
        },
      },
    },
    plugins: [react()],
    resolve: {
      alias: [
        {
          find: 'src/',
          replacement: `${path.resolve(__dirname, 'src')}/`,
        },
      ],
      extensions: ['.mjs', '.js', '.ts', '.jsx', '.tsx', '.json', '.scss'],
    },
    server: {
      port: 3000,
      proxy: {
        // https://vitejs.dev/config/server-options.html
        '/api': {
          target: 'http://exepower',
          changeOrigin: true,
          secure: false,
          rewrite: (path) => path
        },
        // Add specific endpoints to ensure proper handling
        '/api/equipment-events': {
          target: 'http://exepower',
          changeOrigin: true,
        },
        '/api/equipment-stats': {
          target: 'http://exepower',
          changeOrigin: true,
        },
        '/api/functions': {
          target: 'http://exepower',
          changeOrigin: true,
        },
        '/api/shifts': {
          target: 'http://exepower',
          changeOrigin: true,
        },
      },
    },
  }
})
