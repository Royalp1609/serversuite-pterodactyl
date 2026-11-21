#!/usr/bin/env python3
"""Build the release files for a version tag, and optionally publish them.

    python3 tools/release.py v0.17.0             # build into dist/ only
    python3 tools/release.py v0.17.0 --publish   # also create the GitHub release
    python3 tools/release.py v0.17.0 --publish --draft   # as a draft (testing)

Files (into dist/):
  serversuite.blueprint                  Blueprint extension (from extension/)
  serversuite-pterodactyl-backend-v<version>.zip   Composer package; composer.json at
                                         the root, so it unzips straight into
                                         the panel's packages/serversuite/

The tag must be v<version> with <version> equal to composer.json's (and so
conf.yml's, which tools/build-blueprint.py --check enforces).

--publish needs GITHUB_TOKEN, GITHUB_API_URL and GITHUB_REPOSITORY (set by
GitHub Actions). It fails if a release for the tag already exists. The change
list comes from GitHub's generated release notes (merged pull requests since
the previous release).

A security release: tag it annotated with a message starting "Security release"
(git tag -a v1.2.3 -m "Security release: ..."). The notes then start with a
"Security release" line, which panels show as a security update notice.
"""
import json
import mimetypes
import os
import subprocess
import sys
import urllib.request
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DIST = ROOT / "dist"
# Zip entry timestamps = the release commit's time (see stamp()). Not a fixed
# date: when an update is unzipped over the previous release, files that kept
# the same fixed date as before made PHP's opcache treat changed code as
# unchanged, and the panel kept running the old version (2026-10-20).
# What the panel needs from this repo; tests, CI and the extension source stay out.
BACKEND = ["composer.json", "LICENSE", "src", "config", "database", "resources", "routes", "lang", "INSTALL.md", "PROVIDERS.md"]


def stamp(tag: str) -> tuple:
    """The tagged commit's UTC time as a zip timestamp: reproducible per release, new for every release."""
    import time
    ts = int(git("log", "-1", "--format=%ct", tag if tag else "HEAD"))
    t = time.gmtime(ts)
    return (max(1980, t.tm_year), t.tm_mon, t.tm_mday, t.tm_hour, t.tm_min, t.tm_sec - t.tm_sec % 2)


def git(*args: str) -> str:
    return subprocess.run(["git", *args], cwd=ROOT, check=True, capture_output=True, text=True).stdout.strip()


def zip_paths(out: Path, paths: list[str]) -> None:
    files = []
    for p in paths:
        path = ROOT / p
        files += [path] if path.is_file() else sorted(f for f in path.rglob("*") if f.is_file())
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for f in files:
            info = zipfile.ZipInfo(f.relative_to(ROOT).as_posix(), STAMP)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            z.writestr(info, f.read_bytes())


def is_security(tag: str) -> bool:
    """A security release: its annotated tag message starts with "Security release"
    (git tag -a v1.2.3 -m "Security release: ..."). Panels read the matching line
    in the notes and show the update notice as a security one (#12)."""
    if git("tag", "--list", "--format=%(objecttype)", tag).strip() != "tag":
        return False  # lightweight tag (or none): no message of its own
    return git("tag", "--list", "--format=%(contents)", tag).strip().lower().startswith("security release")


def notes(version: str, security: bool = False) -> str:
    """Our part of the release body; GitHub appends the generated change list."""
    return "\n".join(([
        "**Security release.** Upgrade soon: this release fixes a security problem.", "",
    ] if security else []) + [
        f"Server Suite {version} for Pterodactyl.", "",
        "## Install / upgrade",
        "Download both files below and follow `INSTALL.md` (in the backend zip and the repo): replace "
        "`packages/serversuite/` with the backend zip, "
        "`composer require \"pterodactyl-serversuite/serversuite:*@dev\"`, migrate, then "
        "`blueprint -install serversuite`. After a panel upgrade, use the \"After a Pterodactyl panel "
        "upgrade\" section.", "",
        "Docs: https://royalp1609.github.io/serversuite-site/", "",
    ])


def api(method: str, url: str, token: str, data: bytes | None = None, ctype: str = "application/json") -> dict:
    req = urllib.request.Request(url, data=data, method=method, headers={
        "Authorization": f"Bearer {token}", "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28", "Content-Type": ctype})
    with urllib.request.urlopen(req) as r:
        return json.load(r)


def publish(tag: str, version: str, files: list[Path], draft: bool = False, security: bool = False) -> None:
    token = os.environ["GITHUB_TOKEN"]
    repo = os.environ["GITHUB_REPOSITORY"]
    base = f"{os.environ.get('GITHUB_API_URL', 'https://api.github.com')}/repos/{repo}"
    rel = api("POST", f"{base}/releases", token, json.dumps({
        "tag_name": tag, "name": f"Server Suite {version}", "body": notes(version, security),
        "generate_release_notes": True, "prerelease": "-" in version, "draft": draft,
    }).encode())
    upload = rel["upload_url"].split("{", 1)[0]
    for f in files:
        ctype = mimetypes.guess_type(f.name)[0] or "application/octet-stream"
        api("POST", f"{upload}?name={f.name}", token, f.read_bytes(), ctype)
        print(f"attached {f.name}")
    print(f"published {rel['html_url']}")


def main() -> int:
    if len(sys.argv) < 2:
        print(__doc__)
        return 2
    tag, do_publish, draft = sys.argv[1], "--publish" in sys.argv[2:], "--draft" in sys.argv[2:]
    global STAMP
    STAMP = stamp(tag if tag in git("tag", "--list", tag).splitlines() else "HEAD")
    version = json.loads((ROOT / "composer.json").read_text())["version"]
    if tag != f"v{version}":
        print(f"::error::tag {tag} doesn't match composer.json version {version} (expected v{version})")
        return 1

    if subprocess.run([sys.executable, str(ROOT / "tools/build-blueprint.py"), "--check"], cwd=ROOT).returncode:
        return 1

    DIST.mkdir(exist_ok=True)
    blueprint = DIST / "serversuite.blueprint"
    subprocess.run([sys.executable, str(ROOT / "tools/build-blueprint.py"), "-o", str(blueprint)], cwd=ROOT, check=True)
    backend = DIST / f"serversuite-pterodactyl-backend-v{version}.zip"
    zip_paths(backend, BACKEND)
    print(f"wrote {backend.relative_to(ROOT)}")

    if do_publish:
        publish(tag, version, [blueprint, backend], draft, is_security(tag))
    return 0


if __name__ == "__main__":
    sys.exit(main())
