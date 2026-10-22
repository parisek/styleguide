#!/usr/bin/env python3
"""Keep the link references at the bottom of CHANGELOG.md in sync with the tags.

  changelog-links.py sync  [--version X.Y.Z] [--changelog PATH] [--tags-file PATH]
  changelog-links.py check [--changelog PATH] [--tags-file PATH]

`sync` adds the missing `[X.Y.Z]: <repo>/compare/v<previous>...vX.Y.Z` references
and points `[Unreleased]` at `compare/v<newest>...HEAD`. `--version` counts a tag
that does not exist yet: the Stamp Release workflow calls it before it tags.
It never rewrites a reference that exists. It is idempotent.

`check` fails when a `## [X.Y.Z]` heading has no reference, when a reference
does not point at a valid range, or when `[Unreleased]` does not start at the
newest tag. It changes nothing.

<previous> is the newest tag below X.Y.Z by semver, not by date and not by
heading, so a tag without a heading still ends the range before it. The lowest
tag has no previous one; its reference points at the release page.

Tags come from `git tag --list 'v*'`, or from --tags-file (one tag per line).
Exit codes: 0 clean, 1 findings or a missing input.
"""

from __future__ import annotations

import argparse
import re
import subprocess
import sys
from pathlib import Path

Version = tuple[int, int, int]

HEADING = re.compile(r"^## \[((?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*))\]", re.M)
REF_LINE = re.compile(r"^\[([^\]\n]+)\]:[ \t]+(\S+)[ \t]*$", re.M)
UNRELEASED_URL = re.compile(r"^(https://[^\s]+?)/compare/v(\d+\.\d+\.\d+)\.\.\.HEAD$")
SEMVER = r"(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)"
TAG = re.compile(rf"^v({SEMVER})$")


REPO_ROOT = Path(__file__).resolve().parent.parent


class Failure(Exception):
    pass


def parse(version: str) -> Version:
    major, minor, patch = version.split(".")
    return int(major), int(minor), int(patch)


def fmt(version: Version) -> str:
    return ".".join(str(part) for part in version)


def read_tags(tags_file: str | None) -> list[Version]:
    if tags_file is not None:
        try:
            raw = Path(tags_file).read_text(encoding="utf-8")
        except OSError as error:
            raise Failure(f"cannot read tags file {tags_file}: {error}")
    else:
        try:
            raw = subprocess.run(
                ["git", "-C", str(REPO_ROOT), "tag", "--list", "v*"],
                check=True,
                capture_output=True,
                text=True,
            ).stdout
        except (OSError, subprocess.CalledProcessError) as error:
            raise Failure(f"git tag --list failed: {error}")
    tags = []
    for line in raw.split():
        match = TAG.match(line)
        if match:  # pre-release and foreign tags are not releases
            tags.append(parse(match.group(1)))
    if not tags:
        raise Failure(
            "no vX.Y.Z tags found. In CI check out with fetch-depth: 0 so tags are present."
        )
    return sorted(set(tags))


def previous_tag(version: Version, tags: list[Version]) -> Version | None:
    lower = [tag for tag in tags if tag < version]
    return max(lower) if lower else None


def release_url(repo: str, version: Version, tags: list[Version]) -> str:
    previous = previous_tag(version, tags)
    if previous is None:
        return f"{repo}/releases/tag/v{fmt(version)}"
    return f"{repo}/compare/v{fmt(previous)}...v{fmt(version)}"


def unreleased_ref(text: str) -> tuple[str, Version]:
    """Return the repo URL and the tag `[Unreleased]` starts at."""
    refs = {m.group(1): m.group(2) for m in REF_LINE.finditer(text)}
    if "Unreleased" not in refs:
        raise Failure(
            "the reference block is missing: no `[Unreleased]: <repo>/compare/vX.Y.Z...HEAD` line."
        )
    url = UNRELEASED_URL.match(refs["Unreleased"])
    if url is None:
        raise Failure(
            f"`[Unreleased]` is not `<repo>/compare/vX.Y.Z...HEAD`: {refs['Unreleased']}"
        )
    return url.group(1), parse(url.group(2))


def headings(text: str) -> list[Version]:
    found = [parse(m.group(1)) for m in HEADING.finditer(text)]
    if not found:
        raise Failure("CHANGELOG.md has no `## [X.Y.Z]` heading.")
    if len(set(found)) != len(found):
        raise Failure("CHANGELOG.md has a duplicate `## [X.Y.Z]` heading.")
    return found


def sync(text: str, tags: list[Version]) -> str:
    repo, _ = unreleased_ref(text)
    newest = max(tags)
    text = re.sub(
        r"^\[Unreleased\]:[ \t]+\S+[ \t]*$",
        f"[Unreleased]: {repo}/compare/v{fmt(newest)}...HEAD",
        text,
        count=1,
        flags=re.M,
    )

    present = {m.group(1) for m in REF_LINE.finditer(text)}
    missing = [v for v in headings(text) if fmt(v) not in present]
    lines = text.split("\n")
    # Highest first: each insert goes above the first lower reference, so the
    # new block stays newest-first without touching the order that exists.
    for version in sorted(missing, reverse=True):
        if version not in tags:
            raise Failure(
                f"heading [{fmt(version)}] has no tag v{fmt(version)}; tag it, or pass --version for the release in flight."
            )
        ref = f"[{fmt(version)}]: {release_url(repo, version, tags)}"
        at = None
        last = None
        for index, line in enumerate(lines):
            match = REF_LINE.match(line)
            if match is None:
                continue
            last = index
            label = match.group(1)
            if label != "Unreleased" and re.fullmatch(r"\d+\.\d+\.\d+", label):
                if parse(label) < version and at is None:
                    at = index
        if at is None:
            if last is None:  # unreleased_ref() already proved a ref exists
                raise Failure("the reference block vanished while inserting.")
            at = last + 1
        lines.insert(at, ref)
    return "\n".join(lines)


def check(text: str, tags: list[Version]) -> list[str]:
    findings: list[str] = []
    try:
        repo, start = unreleased_ref(text)
    except Failure as error:
        return [str(error)]

    newest = max(tags)
    if start != newest:
        findings.append(
            f"[Unreleased] starts at v{fmt(start)} but the newest tag is v{fmt(newest)}."
        )

    refs: dict[str, str] = {}
    for match in REF_LINE.finditer(text):
        label = match.group(1)
        if label in refs:
            findings.append(f"[{label}]: defined twice.")
        refs[label] = match.group(2)

    for version in headings(text):
        label = fmt(version)
        if version not in tags:
            findings.append(f"[{label}]: heading has no tag v{label}.")
            continue
        url = refs.get(label)
        if url is None:
            findings.append(f"[{label}]: heading has no link reference.")
            continue
        compare = re.fullmatch(
            re.escape(repo) + r"/compare/v(\d+\.\d+\.\d+)\.\.\.v(\d+\.\d+\.\d+)", url
        )
        if compare is not None:
            low, high = parse(compare.group(1)), parse(compare.group(2))
            if high != version or low != previous_tag(version, tags):
                findings.append(f"[{label}]: invalid range {url}")
        elif previous_tag(version, tags) is None and url == release_url(repo, version, tags):
            continue
        else:
            findings.append(f"[{label}]: not a compare link for this version: {url}")
    return findings


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("command", choices=("sync", "check"))
    parser.add_argument("--changelog", default=None)
    parser.add_argument("--tags-file", default=None)
    parser.add_argument("--version", default=None)
    args = parser.parse_args()

    path = Path(args.changelog) if args.changelog else REPO_ROOT / "CHANGELOG.md"
    try:
        text = path.read_text(encoding="utf-8")
        tags = read_tags(args.tags_file)
        if args.command == "sync":
            if args.version is not None:
                if not re.fullmatch(SEMVER, args.version):
                    raise Failure(f"--version must be X.Y.Z, got: {args.version}")
                tags = sorted(set(tags) | {parse(args.version)})
            new = sync(text, tags)
            if args.version is not None and parse(args.version) not in headings(new):
                raise Failure(f"no `## [{args.version}]` heading: the stamp step did not run or failed.")
            findings = check(new, tags)
            if findings:
                raise Failure("result fails the check, nothing written:\n  " + "\n  ".join(findings))
            if new != text:
                path.write_text(new, encoding="utf-8")
            print(f"{path.name}: references in sync with {len(tags)} tag(s).")
            return 0
        if args.version is not None:
            raise Failure("--version belongs to `sync`.")
        findings = check(text, tags)
    except (Failure, OSError) as error:
        print(f"ERROR {error}", file=sys.stderr)
        return 1

    if findings:
        print(f"{path.name} — {len(findings)} finding(s):", file=sys.stderr)
        for finding in findings:
            print(f"  {finding}", file=sys.stderr)
        print("Fix the references, or run: python3 scripts/changelog-links.py sync", file=sys.stderr)
        return 1
    print(f"{path.name} links OK — {len(headings(text))} heading(s), newest tag v{fmt(max(tags))}.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
