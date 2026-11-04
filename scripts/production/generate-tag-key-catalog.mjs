#!/usr/bin/env node
// Translation text lives only in backend/tag-translations/tags.<locale>.xlf.
// Uses the project's Docker PHP runtime; --local uses a host PHP instead.
// With no input arguments, refresh OSM tag TSVs and include backend tags.
// The PHP generator writes reciprocal tag:keyRef / tag:valueRefs and
// tag:category="osm" (TSVs) or "custom" (database) on catalog units.
// Complete runs (keys TSV + --pairs + --database) also remove catalog units
// whose tags disappeared from every source, so deleted nodes clean up their
// translations. Partial runs never remove units.
// Missing tag:ignore flags default to false; admin-set flags are preserved.
import { spawnSync } from 'node:child_process'
import { existsSync } from 'node:fs'
import { randomUUID } from 'node:crypto'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const args = process.argv.slice(2)
const local = args.includes('--local')
if (local) args.splice(args.indexOf('--local'), 1)
function run(command, arguments_, cwd = root) {
  const result = spawnSync(command, arguments_, { cwd, stdio: 'inherit' })
  if (result.error) throw result.error
  if (result.status !== 0) throw new Error(`${command} exited with status ${result.status}`)
}
const copied = []
try {
  if (args.length === 0) {
    const pbf = join(root, 'scripts/data/flensburg.osm.pbf')
    if (!existsSync(pbf)) {
      console.log('Preparing the OSM source for the first sync...')
      run('bash', [join(root, 'scripts/production/prepare-flensburg.sh')])
    }
    console.log('Collecting OSM tag keys and values...')
    run('bash', [join(root, 'scripts/production/extract-osm-tags.sh'), pbf])
    args.push(
      join(root, 'scripts/data/flensburg-tag-keys.tsv'),
      '--pairs', join(root, 'scripts/data/flensburg-tag-pairs.tsv'),
      '--database',
    )
    console.log('Syncing translation catalogs, source categories and key/value references from OSM and backend tags...')
  }
  if (local) {
    run('php', [join(root, 'backend/bin/console'), 'app:tags:sync-translations', ...args])
  } else {
    const backend = join(root, 'backend')
    // Copy TSVs because the container mounts backend/, not scripts/data/.
    for (let index = 0; index < args.length; index++) {
      if ((index === 0 && !args[index].startsWith('-')) || args[index - 1] === '--pairs') {
        const destination = `/tmp/tag-translations-${randomUUID()}.tsv`
        run('docker', ['compose', 'cp', resolve(args[index]), `php:${destination}`], backend)
        copied.push(destination)
        args[index] = destination
      }
    }
    run('docker', ['compose', 'exec', '-T', 'php', 'php', 'bin/console', 'app:tags:sync-translations', ...args], backend)
  }
} catch (error) {
  console.error(error.message)
  process.exitCode = 1
} finally {
  if (copied.length > 0) {
    const cleanup = spawnSync('docker', ['compose', 'exec', '-T', 'php', 'rm', '-f', ...copied], { cwd: join(root, 'backend'), stdio: 'inherit' })
    if (cleanup.status !== 0) process.exitCode = 1
  }
}
