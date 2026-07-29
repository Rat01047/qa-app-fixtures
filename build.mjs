/*
 * Dependency-free "asset build" for the PHP fixture.
 *
 * The Node install step runs whenever package.json exists — even for a plain
 * PHP site — and is followed by the site's configured build command. This
 * writes a stamp that /?health reads back, so "the Node build step ran on the
 * server" becomes an observable fact rather than an assumption.
 *
 * No dependencies on purpose: the point is to test the build plumbing, not a
 * bundler.
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { hostname } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const outDir = join(dirname(fileURLToPath(import.meta.url)), 'public', 'assets');

mkdirSync(outDir, { recursive: true });

writeFileSync(
    join(outDir, 'build-stamp.json'),
    JSON.stringify(
        {
            built_at: new Date().toISOString(),
            node_version: process.version,
            hostname: hostname(),
        },
        null,
        2,
    ),
);

console.log('Wrote public/assets/build-stamp.json');
