// Copies the self-contained app prototype (../app) into ./www so Capacitor
// can bundle it as the native web asset. Keeps a single source of truth.
import { cpSync, mkdirSync, existsSync, rmSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const src = resolve(__dirname, '../app');
const dest = resolve(__dirname, 'www');

if (!existsSync(src)) {
  console.error('Source app not found at', src);
  process.exit(1);
}

if (existsSync(dest)) rmSync(dest, { recursive: true, force: true });
mkdirSync(dest, { recursive: true });
cpSync(src, dest, { recursive: true });
console.log('Copied app prototype -> www/');
