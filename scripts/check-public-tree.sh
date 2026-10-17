#!/usr/bin/env bash
# Standalone structure and hygiene gate of the public BladeCP WordPress
# Migrator repository. Runs from any directory, in CI and by hand:
#
#   bash scripts/check-public-tree.sh
#
# Needs find, grep, php and python3. Exit status 1 on any finding.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

for command in find grep php python3; do
	command -v "$command" >/dev/null 2>&1 || {
		echo "error: $command is required" >&2
		exit 2
	}
done

required=(
	LICENSE README.md README.pt-BR.md README.es.md CHANGELOG.md SECURITY.md CONTRIBUTING.md
	bladecp-migrator.php uninstall.php readme.txt
	includes/class-plugin.php includes/LICENSE-lucide.txt
	assets/css/admin.css assets/js/admin.js assets/fonts/OFL.txt
	languages/bladecp-migrator.pot
	docs/installation.md docs/configuration.md docs/compatibility.md
	docs/troubleshooting.md docs/release-process.md docs/repository-governance.md
	assets/readme/README.md
	.wordpress-org/README.md
	.github/workflows/ci.yml .github/pull_request_template.md
)
for path in "${required[@]}"; do
	[ -f "$path" ] || { echo "error: required public file is missing: $path" >&2; exit 1; }
done

# README artwork: the brand marks plus, for each language, a wide and a compact
# hero and architecture diagram. GitHub shows them through <img>, where scripts
# never run; the public tree keeps them free of active content and external
# references all the same.
artwork=(assets/readme/logo-dark.svg assets/readme/logo-light.svg)
for language in en pt-BR es; do
	for name in hero hero-compact architecture architecture-compact; do
		artwork+=("assets/readme/$name.$language.svg")
	done
done
for path in "${artwork[@]}"; do
	[ -f "$path" ] || { echo "error: required README artwork is missing: $path" >&2; exit 1; }
done
actual_artwork="$(find assets/readme -maxdepth 1 -type f -name '*.svg' -print | sed 's#^\./##' | LC_ALL=C sort)"
expected_artwork="$(printf '%s\n' "${artwork[@]}" | LC_ALL=C sort)"
if [ "$actual_artwork" != "$expected_artwork" ]; then
	echo "error: assets/readme must contain exactly the approved README artwork" >&2
	printf 'expected:\n%s\nactual:\n%s\n' "$expected_artwork" "$actual_artwork" >&2
	exit 1
fi
if grep -InE '<(script|foreignObject|image|iframe|embed|object)[[:space:]>/]|[[:space:]]on[a-z]+[[:space:]]*=|(href|src)[[:space:]]*=[[:space:]]*["'\''](https?:|data:|//)|url\([[:space:]]*["'\'']?(https?:|data:|//)|@import|<!(DOCTYPE|ENTITY)' "${artwork[@]}"; then
	echo "error: README artwork must not contain scripts, embedded content or external references" >&2
	exit 1
fi

if find . -path ./.git -prune -o -type l -print | grep -q .; then
	echo "error: symbolic links are not allowed in the public tree" >&2
	find . -path ./.git -prune -o -type l -print >&2
	exit 1
fi

# The project never uses the em dash, in code or in text.
if grep -RIl --exclude-dir=.git $'\xe2\x80\x94' . >/dev/null; then
	echo "error: em dash found in:" >&2
	grep -RIl --exclude-dir=.git $'\xe2\x80\x94' . >&2
	exit 1
fi

if grep -RIn --exclude-dir=.git --include='*.md' --include='*.txt' -E \
	'(^|[^[:alnum:]_])(DRAFT|TODO|FIXME|TBD)([^[:alnum:]_]|$)' .; then
	echo "error: editorial markers are not allowed in public documentation" >&2
	exit 1
fi

# Public material only points to the production BladeCP services. The
# one-character classes keep this script from matching itself.
if grep -RIniE --exclude-dir=.git \
	'(api\.bladecp\.clou[d]|san[d]box|value[h]ost|ulis[s]es|epic[w]ay|wordpress\.tes[t]|wordpress-dem[o])' .; then
	echo "error: the public tree contains a non-production, third-party or personal reference" >&2
	exit 1
fi

# The endpoint override constants exist for internal testing only.
if grep -RIn --exclude-dir=.git --include='*.md' --include='*.txt' -E 'BLADECP_MIGRATOR_(API|HUB)_UR[L]' .; then
	echo "error: public documentation must not describe the endpoint override constants" >&2
	exit 1
fi

if grep -RInE --exclude-dir=.git \
	'bcp_[a-z]+_[A-Za-z0-9_]{20,}|whsec_[A-Za-z0-9]{16,}|-----BEGIN [A-Z ]*PRIVATE KE[Y]-----|Authorization[[:space:]]*[:=][[:space:]]*(Bearer|Basic)[[:space:]]+[A-Za-z0-9._-]{8,}' .; then
	echo "error: credential-like value found in the public tree" >&2
	exit 1
fi

while IFS= read -r -d '' file; do
	php -l "$file" >/dev/null || { echo "error: PHP syntax error in $file" >&2; exit 1; }
done < <(find . -path ./.git -prune -o -name '*.php' -print0)

python3 - "$ROOT" <<'PY'
import os
import re
import struct
import sys

root = os.path.abspath(sys.argv[1])
problems = []


def read(path):
    with open(os.path.join(root, path), encoding="utf-8") as f:
        return f.read()


# -- local Markdown links ------------------------------------------------------
link = re.compile(r"!?\[(?:[^\]]*)\]\(\s*<?([^)\s>]+)>?(?:\s+\"[^\"]*\")?\s*\)")
for base, dirs, names in os.walk(root):
    dirs[:] = [d for d in dirs if d != ".git"]
    for name in names:
        if not name.lower().endswith(".md"):
            continue
        path = os.path.join(base, name)
        for match in link.finditer(open(path, encoding="utf-8").read()):
            target = match.group(1)
            if target.startswith(("#", "http://", "https://", "mailto:")):
                continue
            target = target.split("#", 1)[0]
            candidate = os.path.normpath(os.path.join(os.path.dirname(path), target))
            if not candidate.startswith(root + os.sep) or not os.path.exists(candidate):
                problems.append(f"broken local link: {os.path.relpath(path, root)} -> {match.group(1)}")

# -- plugin header, readme.txt and versions ------------------------------------
main = read("bladecp-migrator.php")
block = re.search(r"/\*\*(.*?)\*/", main, re.S)
headers = {}
for line in (block.group(1) if block else "").splitlines():
    m = re.match(r"^\s*\*\s*([A-Za-z][A-Za-z ]+?):\s*(.+?)\s*$", line)
    if m:
        headers[m.group(1)] = m.group(2)
constant = re.search(r"define\( 'BLADECP_MIGRATOR_VERSION', '([^']+)' \);", main)
version = headers.get("Version", "")

readme = read("readme.txt").splitlines()
name = re.match(r"^===\s*(.+?)\s*===$", readme[0] if readme else "")
fields = {}
i = 1
while i < len(readme) and readme[i].strip():
    m = re.match(r"^([A-Za-z][A-Za-z ]+?):\s*(.*?)\s*$", readme[i])
    if m:
        fields[m.group(1).lower()] = m.group(2)
    i += 1
short = []
while i < len(readme) and not readme[i].startswith("=="):
    if readme[i].strip():
        short.append(readme[i].strip())
    i += 1
short = " ".join(short)
sections = {}
current = None
for line in readme[i:]:
    m = re.match(r"^==\s*(.+?)\s*==$", line)
    if m:
        current = m.group(1).lower()
        sections[current] = []
    elif current:
        sections[current].append(line)

if not name or name.group(1) != headers.get("Plugin Name"):
    problems.append("readme.txt name differs from the Plugin Name header")
if not constant or constant.group(1) != version:
    problems.append("BLADECP_MIGRATOR_VERSION differs from the Version header")
if fields.get("stable tag") != version:
    problems.append("readme.txt Stable tag differs from the Version header")
if not re.search(r"^## \[" + re.escape(version) + r"\] - \d{4}-\d{2}-\d{2}$", read("CHANGELOG.md"), re.M):
    problems.append(f"CHANGELOG.md has no dated entry for {version}")
if not any(re.match(r"^=\s*" + re.escape(version) + r"\s*=$", l) for l in sections.get("changelog", [])):
    problems.append(f"readme.txt changelog has no entry for {version}")
for field, value in (("License", "MIT"), ("License URI", "https://opensource.org/licenses/MIT"),
                     ("Text Domain", "bladecp-migrator"), ("Domain Path", "/languages")):
    if headers.get(field) != value:
        problems.append(f"plugin header {field} must be {value}")
if fields.get("license") != headers.get("License") or fields.get("license uri") != headers.get("License URI"):
    problems.append("readme.txt and the plugin header must declare the same license")
for field, header in (("requires at least", "Requires at least"), ("requires php", "Requires PHP")):
    if fields.get(field) != headers.get(header):
        problems.append(f"readme.txt {header} differs from the plugin header")
if not re.fullmatch(r"\d+\.\d+", fields.get("tested up to", "")):
    problems.append("readme.txt Tested up to must be a major version such as 7.1")
tags = [t for t in (x.strip() for x in fields.get("tags", "").split(",")) if t]
if not 1 <= len(tags) <= 5:
    problems.append("readme.txt needs 1 to 5 tags")
if not short or len(short) > 150:
    problems.append(f"readme.txt short description must have 1 to 150 characters ({len(short)})")
if len(headers.get("Description", "")) >= 140:
    problems.append("the plugin Description header must have fewer than 140 characters")
for section in ("description", "external services", "installation", "frequently asked questions", "changelog"):
    if section not in sections:
        problems.append(f"readme.txt has no '{section}' section")
if not read("LICENSE").startswith("MIT License"):
    problems.append("LICENSE must be the MIT License")

# -- WordPress.org directory images --------------------------------------------
locale = r"(?:-(?:rtl|[a-z]{2,3}(?:_[A-Z]{2})?))?"
rules = (
    (r"banner-772x250" + locale + r"\.(png|jpg)", (772, 250)),
    (r"banner-1544x500" + locale + r"\.(png|jpg)", (1544, 500)),
    (r"icon-128x128\.(png|jpg|gif)", (128, 128)),
    (r"icon-256x256\.(png|jpg|gif)", (256, 256)),
    (r"icon\.svg", None),
    (r"screenshot-[1-9][0-9]*(?:-[a-z]{2,3}(?:_[A-Z]{2})?)?\.(png|jpg)", None),
)


def size_and_metadata(data):
    if data.startswith(b"\x89PNG\r\n\x1a\n"):
        offset, size, meta = 8, None, False
        while offset + 8 <= len(data):
            length, kind = struct.unpack(">I4s", data[offset:offset + 8])
            if kind == b"IHDR":
                size = struct.unpack(">II", data[offset + 8:offset + 16])
            if kind in (b"tEXt", b"zTXt", b"iTXt", b"eXIf", b"tIME"):
                meta = True
            offset += 12 + length
            if kind == b"IEND":
                break
        return size, meta
    if data.startswith(b"\xff\xd8"):
        offset, size, meta = 2, None, False
        while offset + 4 <= len(data) and data[offset] == 0xFF:
            marker = data[offset + 1]
            if marker in (0xD8, 0x01) or 0xD0 <= marker <= 0xD7:
                offset += 2
                continue
            length = struct.unpack(">H", data[offset + 2:offset + 4])[0]
            if marker in (0xE1, 0xED, 0xFE):
                meta = True
            if marker in (0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF):
                height, width = struct.unpack(">HH", data[offset + 5:offset + 9])
                size = (width, height)
            if marker == 0xDA:
                break
            offset += 2 + length
        return size, meta
    if data[:6] in (b"GIF87a", b"GIF89a"):
        return struct.unpack("<HH", data[6:10]), False
    return None, False


images = os.path.join(root, ".wordpress-org")
shots = []
for name in sorted(os.listdir(images)):
    if name == "README.md":
        continue
    path = os.path.join(images, name)
    rule = next((r for r in rules if re.fullmatch(r[0], name)), None)
    if rule is None or not os.path.isfile(path):
        problems.append(f".wordpress-org/{name}: not a WordPress.org directory image name")
        continue
    data = open(path, "rb").read()
    if name.endswith(".svg"):
        if re.search(rb"<script|<foreignObject|\son[a-z]+=|<!DOCTYPE|<!ENTITY", data, re.I):
            problems.append(f".wordpress-org/{name}: SVG with scripts, events or a DTD")
        continue
    size, meta = size_and_metadata(data)
    if size is None:
        problems.append(f".wordpress-org/{name}: unreadable image")
    elif rule[1] is not None and tuple(size) != rule[1]:
        problems.append(f".wordpress-org/{name}: {size[0]}x{size[1]} pixels, expected {rule[1][0]}x{rule[1][1]}")
    if meta:
        problems.append(f".wordpress-org/{name}: EXIF, XMP, IPTC, text, comment or time metadata")
    m = re.fullmatch(r"screenshot-([1-9][0-9]*)\.(png|jpg)", name)
    if m:
        shots.append(int(m.group(1)))
captions = [l for l in sections.get("screenshots", []) if re.match(r"^\s*\d+\.\s+\S", l)]
if sorted(shots) != list(range(1, len(shots) + 1)) or len(captions) != len(shots):
    problems.append(f"{len(shots)} screenshot(s) must be numbered from 1 and match the {len(captions)} caption(s) of readme.txt")

if problems:
    for problem in problems:
        print(f"error: {problem}", file=sys.stderr)
    raise SystemExit(1)
PY

echo "Public tree structure and hygiene checks passed."
