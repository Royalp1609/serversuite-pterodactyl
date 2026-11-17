#!/usr/bin/env python3
"""Build serversuite.blueprint from extension/, or check it is in sync.

A .blueprint file is a plain zip of the extension directory (conf.yml at the
root). The output is deterministic (sorted entries, fixed timestamps), so
rebuilding unchanged source gives an identical file.

    python3 tools/build-blueprint.py            # rebuild serversuite.blueprint
    python3 tools/build-blueprint.py --check    # fail if it differs from extension/
    python3 tools/build-blueprint.py -o out.blueprint

--check also fails if conf.yml's version differs from composer.json's (the
backend package and the extension ship together and share a version), if a
field Blueprint requires is empty, if conf.yml / Components.yml point at
files that don't exist, or if a source file contains a token Blueprint would
replace at install time (e.g. {name} in JSX).
"""
import argparse
import json
import re
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "extension"
DEFAULT_OUT = ROOT / "serversuite.blueprint"
EPOCH = (1980, 1, 1, 0, 0, 0)


def source_files() -> dict[str, bytes]:
    return {p.relative_to(SRC).as_posix(): p.read_bytes()
            for p in sorted(SRC.rglob("*")) if p.is_file()}


def build(out: Path) -> None:
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for name, data in source_files().items():
            info = zipfile.ZipInfo(name, EPOCH)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            z.writestr(info, data)
    print(f"wrote {out.relative_to(ROOT) if out.is_relative_to(ROOT) else out}")


def check(path: Path) -> list[str]:
    problems = []
    with zipfile.ZipFile(path) as z:
        packed = {i.filename: z.read(i) for i in z.infolist() if not i.is_dir()}
    src = source_files()
    for name in sorted(src.keys() - packed.keys()):
        problems.append(f"in extension/ but not in {path.name}: {name}")
    for name in sorted(packed.keys() - src.keys()):
        problems.append(f"in {path.name} but not in extension/: {name}")
    for name in sorted(src.keys() & packed.keys()):
        if src[name] != packed[name]:
            problems.append(f"differs from extension/: {name}")

    conf = parse_conf((SRC / "conf.yml").read_text())
    composer_version = json.loads((ROOT / "composer.json").read_text()).get("version")
    if conf.get("info.version") != composer_version:
        problems.append(f"version mismatch: conf.yml {conf.get('info.version')} vs composer.json {composer_version}")
    problems += check_conf(conf)
    problems += check_placeholders()
    problems += check_blade_php()
    return problems


# Blueprint replaces these tokens in extension files at install time
# (scripts/commands/extensions/install.sh). In JSX, {name} or {version} would
# be an ordinary expression, so a collision silently changes the code.
PLACEHOLDER = re.compile(r"\{(appcontext|author|engine|fs|identifier|is_target|mode|name|random|root|"
                         r"target|timestamp|version|viewcontext|webroot)[}!^/]")


def check_placeholders() -> list[str]:
    problems = []
    for f in sorted(SRC.rglob("*")):
        if f.is_file() and f.suffix in (".php", ".tsx", ".ts", ".js", ".css", ".yml"):
            for n, line in enumerate(f.read_text(errors="replace").splitlines(), 1):
                m = PLACEHOLDER.search(line)
                if m:
                    problems.append(f"{f.relative_to(ROOT)}:{n}: '{m.group(0)}' is a Blueprint placeholder "
                                    "and would be replaced at install time")
    return problems


def parse_conf(text: str) -> dict[str, str]:
    """Flatten Blueprint's two-level conf.yml into {'section.key': value}."""
    out, section = {}, None
    for line in text.splitlines():
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        m = re.match(r"^(\w+):\s*$", line)
        if m:
            section = m.group(1)
            continue
        m = re.match(r"^\s+(\w+):\s*(.*?)\s*$", line)
        if m and section:
            out[f"{section}.{m.group(1)}"] = m.group(2).strip("'\"")
    return out


# conf.yml fields Blueprint's install.sh treats as fatal when empty.
REQUIRED = ["info.name", "info.identifier", "info.description", "info.version", "info.target", "admin.view"]
# conf.yml fields that name a file or directory inside the extension.
PATHS = ["info.icon", "admin.view", "admin.controller", "admin.css", "admin.wrapper", "dashboard.css",
         "dashboard.wrapper", "dashboard.components", "requests.app", "requests.views",
         "requests.routers.web", "database.migrations"]


def check_blade_php() -> list[str]:
    """A Blade view must not mix inline @php(...) with @php ... @endphp blocks.

    Blade compiles @php ... @endphp blocks first with a lazy match from any
    "@php" to the next "@endphp", so an inline @php($x = ...) earlier in the file
    swallows everything up to the next block's @endphp as PHP: the view fails to
    compile ("unexpected end of file") and the page is a 500. That broke the
    admin page from 0.17.8 to 0.17.10.
    """
    problems = []
    for f in sorted((ROOT / "extension").rglob("*.blade.php")):
        text = f.read_text(encoding="utf-8")
        if "@endphp" not in text:
            continue
        for n, line in enumerate(text.splitlines(), 1):
            if re.search(r"(?<!@)@php\s*\(", line):
                problems.append(f"{f.relative_to(ROOT)}:{n}: inline @php(...) in a view that also has @php ... @endphp "
                                "blocks; Blade pairs it with the next @endphp. Use @php ... @endphp here too.")
    return problems


def check_conf(conf: dict[str, str]) -> list[str]:
    problems = [f"conf.yml: '{k}' is required by Blueprint" for k in REQUIRED if not conf.get(k)]
    if not re.fullmatch(r"(beta|alpha)-\d{4}-\d{2}", conf.get("info.target", "")):
        problems.append(f"conf.yml: info.target '{conf.get('info.target')}' isn't a Blueprint release tag")
    # parse_conf only keeps two levels, so requests.routers.web is read from the raw file
    raw = (SRC / "conf.yml").read_text()
    m = re.search(r"^\s+web:\s*['\"]?([^'\"\s]*)", raw, re.M)
    conf = {**conf, "requests.routers.web": m.group(1) if m else ""}
    for k in PATHS:
        if conf.get(k) and not (SRC / conf[k]).exists():
            problems.append(f"conf.yml: {k} '{conf[k]}' doesn't exist in extension/")

    components = conf.get("dashboard.components")
    comp_file = SRC / components / "Components.yml" if components else None
    if comp_file and comp_file.exists():
        for c in re.findall(r"Component:\s*['\"]([^'\"]+)['\"]", comp_file.read_text()):
            if not any((SRC / components / f"{c}{ext}").exists() for ext in (".tsx", ".ts", ".jsx", ".js")):
                problems.append(f"Components.yml: component '{c}' has no source file")
    return problems


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--check", action="store_true", help="verify the committed package instead of building")
    ap.add_argument("-o", "--output", type=Path, default=DEFAULT_OUT)
    args = ap.parse_args()

    if args.check:
        problems = check(args.output)
        for p in problems:
            print(f"::error::{p}")
        if problems:
            print("Run: python3 tools/build-blueprint.py, then commit serversuite.blueprint")
            return 1
        print(f"{args.output.name} matches extension/")
        return 0

    build(args.output)
    return 0


if __name__ == "__main__":
    sys.exit(main())
