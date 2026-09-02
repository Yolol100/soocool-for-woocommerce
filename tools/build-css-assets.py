#!/usr/bin/env python3
"""Build deterministic SooCool CSS minified assets without external packages.

The minifier is deliberately conservative: it removes ordinary CSS comments and
whitespace only where CSS token boundaries make that safe. It does not rewrite
values, selectors, identifiers, colors, calc() expressions or custom properties.
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PAIRS = (
    (
        Path("assets/build/admin-settings.css"),
        Path("assets/build/admin-settings.min.css"),
    ),
)

# Whitespace after these characters is not required to keep tokens separate.
SPACE_NOT_NEEDED_AFTER = frozenset("{:;,>")
# Whitespace before these characters is not required to keep tokens separate.
# A colon is intentionally excluded because `.parent :where(...)` differs from
# `.parent:where(...)`.
SPACE_NOT_NEEDED_BEFORE = frozenset("{};,>")


def needs_space(previous: str, current: str) -> bool:
    if not previous or not current:
        return False
    if previous in SPACE_NOT_NEEDED_AFTER:
        return False
    if current in SPACE_NOT_NEEDED_BEFORE:
        return False
    return True


def minify_css(source: str) -> str:
    """Return conservative CSS minification while preserving token boundaries."""
    output: list[str] = []
    index = 0
    pending_space = False
    length = len(source)

    while index < length:
        char = source[index]
        next_char = source[index + 1] if index + 1 < length else ""

        if char.isspace():
            pending_space = True
            index += 1
            continue

        if char == "/" and next_char == "*":
            comment_end = source.find("*/", index + 2)
            if comment_end < 0:
                raise ValueError("Unclosed CSS comment")

            # Keep license/important comments exactly; remove ordinary comments.
            if index + 2 < length and source[index + 2] == "!":
                if pending_space and needs_space(output[-1] if output else "", "/"):
                    output.append(" ")
                output.append(source[index : comment_end + 2])
                pending_space = False
            index = comment_end + 2
            continue

        if char in {"'", '"'}:
            if pending_space and needs_space(output[-1] if output else "", char):
                output.append(" ")
            pending_space = False
            quote = char
            output.append(char)
            index += 1
            while index < length:
                string_char = source[index]
                output.append(string_char)
                if string_char == "\\":
                    index += 1
                    if index < length:
                        output.append(source[index])
                    index += 1
                    continue
                index += 1
                if string_char == quote:
                    break
            else:
                raise ValueError("Unclosed CSS string")
            continue

        if pending_space and needs_space(output[-1] if output else "", char):
            output.append(" ")
        pending_space = False

        # Preserve escaped characters outside strings as one token so an escaped
        # quote, slash or punctuation mark cannot change parser state.
        if char == "\\" and index + 1 < length:
            output.append(char)
            output.append(source[index + 1])
            index += 2
            continue

        output.append(char)
        index += 1

    result = "".join(output).strip()
    return result + ("\n" if result else "")


def run_self_tests() -> None:
    cases = {
        ".a :where(.b) { color: red; }": ".a :where(.b){color:red;}",
        ".x { width: calc(100% - 2px); }": ".x{width:calc(100% - 2px);}",
        ".x { --tokens: 1  2; }": ".x{--tokens:1 2;}",
        ".a, .b > .c { margin: 0; }": ".a,.b>.c{margin:0;}",
        ".x { content: \"a b/*keep*/\"; }": '.x{content:"a b/*keep*/";}',
        ".x { /* remove */ color: red; }": ".x{color:red;}",
        "a/**/b { color: red; }": "ab{color:red;}",
    }
    for source, expected in cases.items():
        actual = minify_css(source).rstrip("\n")
        if actual != expected:
            raise AssertionError(f"CSS minifier self-test failed: {source!r} -> {actual!r}, expected {expected!r}")


def build_pair(source_relative: Path, output_relative: Path) -> tuple[bytes, bytes]:
    source_path = ROOT / source_relative
    output_path = ROOT / output_relative
    if not source_path.is_file():
        raise FileNotFoundError(f"Missing CSS source: {source_relative.as_posix()}")

    source_bytes = source_path.read_bytes()
    source_text = source_bytes.decode("utf-8")
    minified_bytes = minify_css(source_text).encode("utf-8")

    if len(minified_bytes) >= len(source_bytes):
        raise ValueError(
            f"Minified CSS is not smaller for {source_relative.as_posix()}: "
            f"{len(minified_bytes)} >= {len(source_bytes)} bytes"
        )

    return source_bytes, minified_bytes


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--check", action="store_true", help="Fail if generated CSS assets are stale.")
    mode.add_argument("--write", action="store_true", help="Write generated CSS assets.")
    args = parser.parse_args()

    run_self_tests()
    stale: list[str] = []

    for source_relative, output_relative in PAIRS:
        source_bytes, minified_bytes = build_pair(source_relative, output_relative)
        output_path = ROOT / output_relative

        if args.write:
            output_path.write_bytes(minified_bytes)
        else:
            current = output_path.read_bytes() if output_path.is_file() else b""
            if current != minified_bytes:
                stale.append(output_relative.as_posix())

        reduction = 100.0 * (1.0 - (len(minified_bytes) / len(source_bytes)))
        print(
            f"{source_relative.as_posix()} -> {output_relative.as_posix()}: "
            f"{len(source_bytes)} -> {len(minified_bytes)} bytes ({reduction:.1f}% smaller)"
        )

    if stale:
        print("Stale CSS assets: " + ", ".join(stale), file=sys.stderr)
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
