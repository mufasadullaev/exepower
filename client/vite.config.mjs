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
        '/api/energy_metrics': {
          target: 'http://exepower',
          changeOrigin: true,
          secure: false
        },
        '/api/meters': {
          target: 'http://exepower',
          changeOrigin: true,
          secure: false
        },
        '/api/meter_readings': {
          target: 'http://exepower',
          changeOrigin: true,
          secure: false
        },
        '/api/meter_replacements': {
          target: 'http://exepower',
          changeOrigin: true,
          secure: false
        },
        '/api/shifts': {
          target: 'http://exepower',
          changeOrigin: true,
          secure: false
        }
      },
    },
  }
})
