import { defineConfig } from 'vite';
import { readFileSync } from 'node:fs';
// The rich text editor is a second bundle, loaded only on pages with an editor.
const version = JSON.parse(readFileSync(new URL('./package.json', import.meta.url), 'utf8')).version;
export default defineConfig({ build: { outDir: 'dist', emptyOutDir: false, minify: true,
  lib: { entry: 'resources/js/editor/index.ts', name: 'FloAdminEditorBundle', formats: ['iife'], fileName: () => `admin-editor-v${version}.js` },
} });
