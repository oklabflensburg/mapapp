#!/usr/bin/env bash
#
# Extract all tags from the nodes of an OSM PBF file and write frequency
# tables: how often each tag key and each key=value pair occurs on nodes.
#
# Usage: scripts/extract-node-tags.sh [input.pbf]
#   input.pbf defaults to scripts/data/flensburg.osm.pbf
#
# Requires: osmium-tool (brew install osmium-tool), python3
#
# Output (into scripts/data/, gitignored):
#   <stem>-node-tag-keys.tsv   count <TAB> key
#   <stem>-node-tag-pairs.tsv  count <TAB> key <TAB> value
# Both sorted by descending count. The summary and the 25 most common keys
# are also printed to stdout.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DATA_DIR="${ROOT_DIR}/scripts/data"

PBF="${1:-${DATA_DIR}/flensburg.osm.pbf}"
if [ ! -f "${PBF}" ]; then
  echo "Input not found: ${PBF}" >&2
  exit 1
fi

STEM="$(basename "${PBF%%.*}")"
KEYS_TSV="${DATA_DIR}/${STEM}-node-tag-keys.tsv"
PAIRS_TSV="${DATA_DIR}/${STEM}-node-tag-pairs.tsv"

mkdir -p "${DATA_DIR}"

echo "Reading nodes from ${PBF}..."
# OPL node lines start with "n"; the T field holds all tags as
# key=value pairs separated by commas, with special characters %XX-escaped.
# Python reads the OPL stream from an osmium subprocess because the heredoc
# below already occupies python3's stdin.
python3 - "${KEYS_TSV}" "${PAIRS_TSV}" "${PBF}" <<'PY'
import re
import subprocess
import sys
from collections import Counter

ESCAPE = re.compile("%([0-9A-Fa-f]{2})")


def unescape(text):
    return ESCAPE.sub(lambda m: chr(int(m.group(1), 16)), text)


keys_tsv, pairs_tsv, pbf = sys.argv[1], sys.argv[2], sys.argv[3]
key_counts = Counter()
pair_counts = Counter()
total_nodes = 0
tagged_nodes = 0

opl = subprocess.Popen(
    ["osmium", "cat", "-f", "opl", "--output=-", pbf],
    stdout=subprocess.PIPE,
    text=True,
)
for line in opl.stdout:
    if not line.startswith("n"):
        continue
    total_nodes += 1
    tag_field = ""
    for field in line.split(" "):
        if field.startswith("T"):
            tag_field = field[1:]
            break
    if not tag_field:
        continue
    tagged_nodes += 1
    node_keys = set()
    for pair in tag_field.split(","):
        if "=" not in pair:
            continue
        key, value = pair.split("=", 1)
        key, value = unescape(key), unescape(value)
        node_keys.add(key)
        pair_counts[(key, value)] += 1
    key_counts.update(node_keys)

opl.wait()
if opl.returncode != 0:
    raise SystemExit(f"osmium failed with exit code {opl.returncode}")

keys_sorted = key_counts.most_common()
pairs_sorted = pair_counts.most_common()

with open(keys_tsv, "w") as f:
    f.writelines(f"{count}\t{key}\n" for key, count in keys_sorted)

with open(pairs_tsv, "w") as f:
    f.writelines(f"{count}\t{key}\t{value}\n" for (key, value), count in pairs_sorted)

print(f"total nodes:            {total_nodes}")
print(f"nodes with tags:        {tagged_nodes}")
print(f"distinct keys:          {len(key_counts)}")
print(f"distinct key=value:     {len(pair_counts)}")
print("\ntop 25 keys:")
for key, count in keys_sorted[:25]:
    print(f"  {count:>7}  {key}")
PY

echo "Done:"
echo "  ${KEYS_TSV}"
echo "  ${PAIRS_TSV}"
