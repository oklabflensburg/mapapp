import { test } from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const extractor = fileURLToPath(new URL('../production/extract-osm-tags.sh', import.meta.url))

test('extracts node, way and relation tags, including roof:levels and escaped values', () => {
  const directory = mkdtempSync(join(tmpdir(), 'osm-tag-extraction-'))
  try {
    const input = join(directory, 'sample.osm')
    writeFileSync(input, `<?xml version="1.0" encoding="UTF-8"?>
<osm version="0.6">
  <node id="1" lat="54.78" lon="9.43"><tag k="name" v="A &amp; B"/></node>
  <node id="2" lat="54.79" lon="9.43"/>
  <way id="3"><nd ref="1"/><nd ref="2"/><tag k="roof:levels" v="2"/><tag k="name" v="A &amp; B"/><tag k="note" v="a,b=c% &#9;&quot;line&#10;end"/></way>
  <relation id="4"><member type="way" ref="3" role="outer"/><tag k="type" v="multipolygon"/><tag k="roof:levels" v="2"/></relation>
</osm>`)
    const result = spawnSync('bash', [extractor, input, directory], { encoding: 'utf8' })
    assert.equal(result.status, 0, result.stderr)
    const keys = readFileSync(join(directory, 'sample-tag-keys.tsv'), 'utf8')
    const pairs = readFileSync(join(directory, 'sample-tag-pairs.tsv'), 'utf8')
    assert.match(keys, /^2\troof:levels$/m)
    assert.match(keys, /^1\ttype$/m)
    assert.match(pairs, /^2\troof:levels\t2$/m)
    assert.match(pairs, /^2\tname\tA & B$/m)
    assert.match(pairs, /^1\ttype\tmultipolygon$/m)
    assert.ok(pairs.includes('1\tnote\t"a,b=c% \t""line\nend"\n'))
    assert.match(result.stdout, /total objects:\s+4/)
    assert.match(result.stdout, /objects with tags:\s+3/)

    const legacy = spawnSync('bash', [extractor, input, directory, '--nodes'], { encoding: 'utf8' })
    assert.equal(legacy.status, 0, legacy.stderr)
    assert.equal(readFileSync(join(directory, 'sample-node-tag-keys.tsv'), 'utf8'), '1\tname\n')
    assert.equal(readFileSync(join(directory, 'sample-node-tag-pairs.tsv'), 'utf8'), '1\tname\tA & B\n')
  } finally {
    rmSync(directory, { recursive: true, force: true })
  }
})
