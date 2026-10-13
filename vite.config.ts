import { defineConfig } from 'vite';
import { readFileSync } from 'node:fs';
const version = JSON.parse(readFileSync(new URL('./package.json', import.meta.url), 'utf8')).version;
export default defineConfig({ build: { outDir: 'dist', emptyOutDir: true,
  lib: { entry: 'resources/js/admin.ts', name: 'FloAdmin', formats: ['iife'], fileName: () => `admin-v${version}.js`, cssFileName: `admin-v${version}` },
} });
