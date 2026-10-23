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
            yield normalized_property(property_name), value.strip()
    part = body[start:].strip()
    if ":" in part:
        property_name, value = part.split(":", 1)
        yield normalized_property(property_name), value.strip()


def normalized_property(property_name: str) -> str:
    name = property_name.strip()
    return name if name.startswith("--") else name.lower()


def normalize_selector(selector: str) -> str:
    """Ignore optional spacing around selector lists and combinators only.

    Descendant whitespace and the contents of quoted/attribute values retain
    their meaning. Escaped punctuation is not a separator.
    """
    output = []
    quote = ""
    escaped = False
    bracket_depth = 0
    pending_space = False
    separators = ",>+~"

    for char in selector:
        if escaped:
            output.append(char)
            escaped = False
            continue
        if char == "\\":
            if pending_space and output and output[-1] not in separators:
                output.append(" ")
            pending_space = False
            output.append(char)
            escaped = True
            continue
        if quote:
            output.append(char)
            if char == quote:
                quote = ""
            continue
        if char in ("'", '"'):
            if pending_space and output and output[-1] not in separators:
                output.append(" ")
            pending_space = False
            output.append(char)
            quote = char
            continue
        if char == "[":
            bracket_depth += 1
        elif char == "]":
            bracket_depth = max(0, bracket_depth - 1)
        if char.isspace():
            pending_space = True
            continue
        if bracket_depth == 0 and char in separators:
            while output and output[-1] == " ":
                output.pop()
            output.append(char)
        else:
            if pending_space and output and output[-1] not in separators:
                output.append(" ")
            output.append(char)
        pending_space = False

    return "".join(output)


# These at-rules own declarations rather than nested selector rules.
DESCRIPTOR_AT_RULES = frozenset({
    "@font-face", "@page", "@property", "@counter-style",
    "@font-feature-values", "@font-palette-values", "@viewport", "@-ms-viewport",
})


def is_descriptor_at_rule(prelude: str) -> bool:
    keyword = prelude.split(None, 1)[0].split("(", 1)[0].lower()
    return keyword in DESCRIPTOR_AT_RULES or keyword.startswith(("@top-", "@bottom-", "@left-", "@right-"))


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
            kind = ("descriptor" if is_descriptor_at_rule(prelude) else "at") if prelude.startswith("@") else "rule"
            context = tuple(entry["name"] for entry in stack if entry["kind"] == "at")
            if kind == "rule":
                key = (context, normalize_selector(prelude))
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
            if block["kind"] in ("rule", "descriptor"):
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
    assert scan('.a,.b {color: red;} .a, .b {color: blue;}')
    assert scan('.a > .b {color: red;} .a>.b {color: blue;}')
    assert scan('.a + .b {color: red;} .a+.b {color: blue;}')
    assert scan('.a .b {color: red;} .a.b {color: blue;}') == []
    assert scan('.a[title="a, b"] {color: red;} .a[title="a,b"] {color: blue;}') == []
    assert scan('.a {--Brand-color: red; --brand-color: blue;}') == []
    assert scan('.a {COLOR: red; color: blue;}')
    assert scan('@font-face {font-display: swap; font-display: swap;}')
    assert scan('@property --primary {syntax: "<color>"; syntax: "<length>";}')
    assert scan('@media (min-width: 500px) {@font-face {font-display: swap; font-display: swap;}}')


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
