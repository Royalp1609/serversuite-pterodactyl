#!/usr/bin/env python3
"""Build serversuite.blueprint from extension/, or check it is in sync.

A .blueprint file is a plain zip of the extension directory (conf.yml at the
root). The output is deterministic (sorted entries, fixed timestamps), so
rebuilding unchanged source gives an identical file.

    python3 tools/build-blueprint.py            # rebuild serversuite.blueprint
    python3 tools/build-blueprint.py --check    # fail if it differs from extension/
    python3 tools/build-blueprint.py -o out.blueprint

--check also fails if conf.yml's version differs from composer.json's: the
backend package and the extension ship together and share a version.
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

    conf = (SRC / "conf.yml").read_text()
    m = re.search(r'^\s+version:\s*["\']?([^"\'\s]+)', conf, re.M)
    conf_version = m.group(1) if m else None
    composer_version = json.loads((ROOT / "composer.json").read_text()).get("version")
    if conf_version != composer_version:
        problems.append(f"version mismatch: conf.yml {conf_version} vs composer.json {composer_version}")
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
