#!/usr/bin/env bash
#
# Extract all tags from the nodes, ways and relations of an OSM PBF file and write frequency
# tables: how often each tag key and each key=value pair occurs on OSM objects.
#
# Usage: scripts/production/extract-osm-tags.sh [input.pbf] [output-dir] [--nodes]
#   --nodes preserves the legacy node-only extraction and filenames.
#   input.pbf defaults to scripts/data/flensburg.osm.pbf
#
# Requires: osmium-tool (brew install osmium-tool), python3
#
# Output (into scripts/data/, gitignored):
#   <stem>-tag-keys.tsv   count <TAB> key
#   <stem>-tag-pairs.tsv  count <TAB> key <TAB> value
# Both sorted by descending count. The summary and the 25 most common keys
# are also printed to stdout.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
DATA_DIR="${ROOT_DIR}/scripts/data"
OUTPUT_DIR="${2:-${DATA_DIR}}"
SCOPE="${3:-all}"
if [[ "${SCOPE}" != "all" && "${SCOPE}" != "--nodes" ]]; then
  echo "Unknown scope: ${SCOPE}" >&2
  exit 1
fi

PBF="${1:-${DATA_DIR}/flensburg.osm.pbf}"
if [ ! -f "${PBF}" ]; then
  echo "Input not found: ${PBF}" >&2
  exit 1
fi

STEM="$(basename "${PBF}")"
STEM="${STEM%%.*}"
PREFIX="${STEM}"
if [[ "${SCOPE}" == "--nodes" ]]; then PREFIX="${STEM}-node"; fi
KEYS_TSV="${OUTPUT_DIR}/${PREFIX}-tag-keys.tsv"
PAIRS_TSV="${OUTPUT_DIR}/${PREFIX}-tag-pairs.tsv"

mkdir -p "${OUTPUT_DIR}"

echo "Reading OSM tags from ${PBF}..."
# OPL object lines start with "n", "w" or "r"; the T field holds all tags as
# key=value pairs separated by commas, with special characters %HEX%-escaped.
# Python reads the OPL stream from an osmium subprocess because the heredoc
# below already occupies python3's stdin.
python3 - "${KEYS_TSV}" "${PAIRS_TSV}" "${PBF}" "${SCOPE}" <<'PY'
import csv
import re
import subprocess
import sys
from collections import Counter

ESCAPE = re.compile(r"%([0-9A-Fa-f]+)%")


def unescape(text):
    return ESCAPE.sub(lambda m: chr(int(m.group(1), 16)), text)


keys_tsv, pairs_tsv, pbf, scope = sys.argv[1:]
key_counts = Counter()
pair_counts = Counter()
total_objects = 0
tagged_objects = 0

opl = subprocess.Popen(
    ["osmium", "cat", "-f", "opl", "--output=-", pbf],
    stdout=subprocess.PIPE,
    text=True,
)
for line in opl.stdout:
    if not line.startswith(("n",) if scope == "--nodes" else ("n", "w", "r")):
        continue
    total_objects += 1
    tag_field = ""
    for field in line.rstrip("\n").split(" "):
        if field.startswith("T"):
            tag_field = field[1:]
            break
    if not tag_field:
        continue
    tagged_objects += 1
    object_keys = set()
    for pair in tag_field.split(","):
        if "=" not in pair:
            continue
        key, value = pair.split("=", 1)
        key, value = unescape(key), unescape(value)
        object_keys.add(key)
        pair_counts[(key, value)] += 1
    key_counts.update(object_keys)

opl.wait()
if opl.returncode != 0:
    raise SystemExit(f"osmium failed with exit code {opl.returncode}")

keys_sorted = key_counts.most_common()
pairs_sorted = pair_counts.most_common()

with open(keys_tsv, "w", newline="", encoding="utf-8") as f:
    csv.writer(f, delimiter="\t", lineterminator="\n").writerows(
        (count, key) for key, count in keys_sorted
    )

with open(pairs_tsv, "w", newline="", encoding="utf-8") as f:
    csv.writer(f, delimiter="\t", lineterminator="\n").writerows(
        (count, key, value) for (key, value), count in pairs_sorted
    )

print(f"total objects:            {total_objects}")
print(f"objects with tags:        {tagged_objects}")
print(f"distinct keys:          {len(key_counts)}")
print(f"distinct key=value:     {len(pair_counts)}")
print("\ntop 25 keys:")
for key, count in keys_sorted[:25]:
    print(f"  {count:>7}  {key}")
PY

echo "Done:"
echo "  ${KEYS_TSV}"
echo "  ${PAIRS_TSV}"
