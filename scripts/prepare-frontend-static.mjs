#!/usr/bin/env node
// Prepare an already compiled frontend for static directory-index confirmation URLs.
// Usage: node scripts/prepare-frontend-static.mjs frontend/dist [--check]
import { mkdir, readFile, writeFile, stat } from 'node:fs/promises';
import { resolve, dirname, sep } from 'node:path';

const args = process.argv.slice(2);
const check = args.includes('--check');
const positional = args.filter(value => value !== '--check');
if (positional.length > 1 || positional.some(value => value.startsWith('--'))) {
  throw new Error('Usage: prepare-frontend-static.mjs [DIST_DIRECTORY] [--check]');
}
const root = resolve(positional[0] || 'frontend/dist');
const entry = resolve(root, 'index.html');
const html = await readFile(entry, 'utf8');
if (!/<html[\s>]/i.test(html)) throw new Error('Build output must contain an HTML document.');
const assets = [
  ...Array.from(html.matchAll(/<script\b[^>]*\bsrc=["']([^"']+)["']/gi), match => match[1]),
  ...Array.from(html.matchAll(/<link\b(?=[^>]*\brel=["']stylesheet["'])[^>]*\bhref=["']([^"']+)["']/gi), match => match[1]),
];
for (const asset of assets) {
  if (!asset.startsWith('/') || asset.startsWith('//') || asset.startsWith('/console/')) {
    throw new Error('Build JavaScript/CSS must use root-relative local asset paths (Vite base: /).');
  }
  const path = decodeURIComponent(new URL(asset, 'https://carlink.samsam123.name.my').pathname);
  if (/\.(?:tsx?|jsx)(?:$|[?#])/i.test(path)) throw new Error('Compile source scripts before packaging.');
  const target = resolve(root, path.replace(/^\/+/, ''));
  if (!target.startsWith(root + sep) || !(await stat(target)).isFile()) {
    throw new Error('A referenced build asset is missing or outside the build directory.');
  }
}
for (const alias of ['auth/confirm/index.html', 'invitations/confirm/index.html']) {
  const destination = resolve(root, alias);
  if (check) {
    if (await readFile(destination, 'utf8') !== html) {
      throw new Error('Confirmation HTML differs from the current compiled entry point.');
    }
  } else {
    await mkdir(dirname(destination), { recursive: true });
    await writeFile(destination, html, 'utf8');
  }
}
console.log(`${check ? 'Verified' : 'Prepared'} static frontend: ${root}; 2 confirmation pages; ${assets.length} local script/style references.`);
