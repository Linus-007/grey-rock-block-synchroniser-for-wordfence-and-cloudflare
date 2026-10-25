#!/usr/bin/env python3

from __future__ import annotations

import argparse
import sys
import zipfile
from pathlib import Path
from wordpress_policy import validate_metadata
from release_artifact import snapshot, read_bounded
import re


DEFAULT_PLUGIN_SLUG = "grey-rock-block-synchroniser-for-wordfence-and-cloudflare"


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Build and verify a WordPress plugin release ZIP."
    )

    parser.add_argument(
        "--source",
        default="src",
        help="Plugin source directory.",
    )

    parser.add_argument(
        "--output",
        default=(
            "dist/"
            "grey-rock-block-synchroniser-for-wordfence-and-cloudflare.zip"
        ),
        help="Release ZIP path.",
    )

    parser.add_argument(
        "--plugin-slug",
        default=DEFAULT_PLUGIN_SLUG,
        help="Top-level plugin directory name inside the ZIP.",
    )

    parser.add_argument(
        "--readme",
        default="readme.txt",
        help="WordPress.org readme copied into the plugin directory.",
    )

    return parser.parse_args()


def should_exclude(path: Path) -> bool:
    excluded_names = {
        ".DS_Store",
        "__MACOSX",
        ".git",
        ".gitignore",
    }

    return any(part in excluded_names for part in path.parts)


def build_zip(
    source: Path,
    output: Path,
    plugin_slug: str,
    readme: Path,
) -> None:
    if not source.is_dir():
        raise RuntimeError(
            f"Source directory does not exist: {source}"
        )

    if not readme.is_file():
        raise RuntimeError(
            f"WordPress readme does not exist: {readme}"
        )

    if (
        not plugin_slug
        or "/" in plugin_slug
        or "\\" in plugin_slug
    ):
        raise RuntimeError(
            f"Invalid plugin slug: {plugin_slug}"
        )

    if any(path.is_symlink() for path in source.rglob("*")):
        raise RuntimeError("Source symlinks are forbidden in release artifacts.")

    try:
        validate_metadata(
            (source / f"{DEFAULT_PLUGIN_SLUG}.php").read_text(encoding="utf-8"),
            readme.read_text(encoding="utf-8"),
        )
    except (ValueError, OSError) as error:
        raise RuntimeError(f"Invalid WordPress compatibility metadata: {error}") from error

    output.parent.mkdir(parents=True, exist_ok=True)

    if output.exists():
        output.unlink()

    with zipfile.ZipFile(
        output,
        mode="w",
        compression=zipfile.ZIP_DEFLATED,
        compresslevel=9,
    ) as archive:
        archive.writestr(f"{plugin_slug}/", "")

        for path in sorted(source.rglob("*")):
            relative_path = path.relative_to(source)

            if should_exclude(relative_path):
                continue

            archive_path = Path(plugin_slug) / relative_path
            archive_name = archive_path.as_posix()

            if path.is_dir():
                archive.writestr(f"{archive_name}/", "")
                continue

            archive.write(path, archive_name)

        archive.write(
            readme,
            f"{plugin_slug}/readme.txt",
        )


def verify_zip(
    output: Path,
    plugin_slug: str,
) -> None:
    if not output.is_file():
        raise RuntimeError(
            f"Release ZIP was not created: {output}"
        )

    if not zipfile.is_zipfile(output):
        raise RuntimeError(
            f"Release file is not a valid ZIP: {output}"
        )

    required_files = (
        f"{plugin_slug}/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.php",
        f"{plugin_slug}/uninstall.php",
        f"{plugin_slug}/readme.txt",
    )

    with zipfile.ZipFile(output, mode="r") as archive:
        corrupt_file = archive.testzip()

        if corrupt_file is not None:
            raise RuntimeError(
                f"Corrupt file detected: {corrupt_file}"
            )

        names = archive.namelist()

        for required_file in required_files:
            if required_file not in names:
                raise RuntimeError(
                    "Required plugin file is missing: "
                    f"{required_file}"
                )

        try:
            validate_metadata(
                archive.read(f"{plugin_slug}/{DEFAULT_PLUGIN_SLUG}.php").decode("utf-8"),
                archive.read(f"{plugin_slug}/readme.txt").decode("utf-8"),
            )
        except (ValueError, UnicodeError) as error:
            raise RuntimeError(f"Invalid packaged WordPress metadata: {error}") from error

        for name in names:
            if not name.startswith(f"{plugin_slug}/"):
                raise RuntimeError(
                    "Unexpected ZIP entry outside plugin directory: "
                    f"{name}"
                )

    try:
        with zipfile.ZipFile(output) as archive:
            header = archive.read(f"{plugin_slug}/{DEFAULT_PLUGIN_SLUG}.php").decode("utf-8")
        match = re.search(r"^\s*\* Version:\s*(\S+)", header, re.M)
        if match is None:
            raise ValueError("Missing package version")
        snapshot(read_bounded(output), match[1], plugin_slug)
    except (ValueError, KeyError, UnicodeError) as error:
        raise RuntimeError("Unsafe or inconsistent complete ZIP contents") from error


def print_contents(output: Path) -> None:
    with zipfile.ZipFile(output, mode="r") as archive:
        for name in archive.namelist():
            print(name)


def main() -> int:
    arguments = parse_arguments()

    source = Path(arguments.source).resolve()
    output = Path(arguments.output).resolve()
    readme = Path(arguments.readme).resolve()
    plugin_slug = arguments.plugin_slug

    try:
        build_zip(
            source,
            output,
            plugin_slug,
            readme,
        )

        verify_zip(
            output,
            plugin_slug,
        )
    except RuntimeError as error:
        print(f"ERROR: {error}", file=sys.stderr)
        return 1

    print(f"Created: {output}")
    print(f"Size: {output.stat().st_size} bytes")
    print("Contents:")
    print_contents(output)

    return 0


if __name__ == "__main__":
    sys.exit(main())
