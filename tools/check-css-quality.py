#!/usr/bin/env python3
"""Reject redundant CSS rules and repeated declarations in SooCool sources.

Each at-rule context is checked separately: intentional responsive overrides
are not treated as duplicates of base rules or other media queries.
"""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCES = (
    "assets/build/admin-settings.css",
    "assets/build/admin-orders.css",
    "assets/frontend/checkout-delivery.css",
)


def strip_comments(source: str) -> str:
    """Blank comments without altering strings or original line numbers."""
    chars = list(source)
    index = 0
    quote = ""
    while index < len(chars):
        char = chars[index]
        if quote:
            if char == "\\":
                index += 2
                continue
            if char == quote:
                quote = ""
            index += 1
            continue
        if char in ("'", '"'):
            quote = char
            index += 1
            continue
        if char == "/" and index + 1 < len(chars) and chars[index + 1] == "*":
            end = source.find("*/", index + 2)
            if end < 0:
                raise ValueError("Unclosed CSS comment")
            for offset in range(index, end + 2):
                if chars[offset] != "\n":
                    chars[offset] = " "
            index = end + 2
            continue
        index += 1
    if quote:
        raise ValueError("Unclosed CSS string")
    return "".join(chars)


def tokens(source: str):
    """Find structural punctuation, ignoring CSS strings and function arguments."""
    quote = ""
    escaped = False
    parentheses = 0
    for index, char in enumerate(source):
        if escaped:
            escaped = False
            continue
        if char == "\\":
            escaped = True
            continue
        if quote:
            if char == quote:
                quote = ""
            continue
        if char in ("'", '"'):
            quote = char
            continue
        if char == "(":
            parentheses += 1
            continue
        if char == ")":
            parentheses = max(0, parentheses - 1)
            continue
        if parentheses == 0 and char in "{};":
            yield index, char


def declarations(body: str):
    """Return top-level property/value pairs without splitting function values."""
    start = 0
    for index, char in tokens(body):
        if char != ";":
            continue
        part = body[start:index].strip()
        start = index + 1
        if ":" in part:
            property_name, value = part.split(":", 1)
            yield property_name.strip().lower(), value.strip()
    part = body[start:].strip()
    if ":" in part:
        property_name, value = part.split(":", 1)
        yield property_name.strip().lower(), value.strip()


def scan(source: str) -> list[str]:
    clean = strip_comments(source)
    stack = []
    seen = {}
    issues = []
    start = 0
    for index, char in tokens(clean):
        if char == "{":
            prelude = " ".join(clean[start:index].split())
            if not prelude:
                issues.append(f"line {clean.count(chr(10), 0, index) + 1}: empty block selector")
            kind = "at" if prelude.startswith("@") else "rule"
            context = tuple(entry["name"] for entry in stack if entry["kind"] == "at")
            if kind == "rule":
                key = (context, prelude)
                line = clean.count("\n", 0, start) + 1
                if key in seen:
                    issues.append(f"line {line}: selector {prelude!r} repeats line {seen[key]} in the same context")
                seen[key] = line
            stack.append({"kind": kind, "name": prelude, "start": index + 1})
            start = index + 1
        elif char == "}":
            if not stack:
                issues.append(f"line {clean.count(chr(10), 0, index) + 1}: unexpected closing brace")
                start = index + 1
                continue
            block = stack.pop()
            if block["kind"] == "rule":
                properties = set()
                for name, _ in declarations(clean[block["start"]:index]):
                    if name in properties:
                        line = clean.count("\n", 0, block["start"]) + 1
                        issues.append(f"line {line}: selector {block['name']!r} repeats property {name!r}")
                    properties.add(name)
            start = index + 1
        elif not stack or stack[-1]["kind"] == "at":
            start = index + 1
    if stack:
        issues.append("Unclosed CSS rule or at-rule block")
    return issues


def self_test() -> None:
    assert scan('.a {color: red;} @media (max-width: 600px) {.a {color: blue;}}') == []
    assert scan('.a {color: red;} .a {color: blue;}')
    assert scan('@media (max-width: 600px) {.a {color: red;} .a {color: blue;}}')
    assert scan('.a {color: red; color: blue;}')
    assert scan('.a {content: "{;} /* harmless */"; background: url("data:x;y");}') == []
    assert scan('.a {color: red; /* ignore { } ; */ background: blue;}') == []


def main() -> int:
    self_test()
    failed = False
    for relative in SOURCES:
        filename = ROOT / relative
        if not filename.is_file():
            print(f"MISSING CSS: {relative}", file=sys.stderr)
            failed = True
            continue
        try:
            issues = scan(filename.read_text(encoding="utf-8"))
        except ValueError as exc:
            issues = [str(exc)]
        if issues:
            failed = True
            for issue in issues:
                print(f"{relative}: {issue}", file=sys.stderr)
        else:
            print(f"CSS structure OK: {relative}")
    return int(failed)


if __name__ == "__main__":
    raise SystemExit(main())
