#!/usr/bin/env python3
"""Build deterministic SooCool gettext catalogs from shipped PHP/JS sources.

No third-party Python packages are required. The extractor intentionally supports
only the WordPress i18n functions currently used by this plugin and fails when a
recognized call uses a dynamic message/domain that cannot be extracted safely.
"""

from __future__ import annotations

import argparse
import ast
import datetime as dt
import re
import struct
import sys
from collections import defaultdict
from pathlib import Path
from typing import Iterable

DOMAIN = "soocool-for-woocommerce"
POT_NAME = f"{DOMAIN}.pot"
PO_NAME = f"{DOMAIN}-nl_NL.po"
MO_NAME = f"{DOMAIN}-nl_NL.mo"

# function -> (kind, msgid arg, domain arg)
I18N_FUNCTIONS = {
    "__": ("single", 0, 1),
    "_e": ("single", 0, 1),
    "esc_html__": ("single", 0, 1),
    "esc_html_e": ("single", 0, 1),
    "esc_attr__": ("single", 0, 1),
    "esc_attr_e": ("single", 0, 1),
    "_n": ("plural", 0, 3),
}

# Context-aware functions need msgctxt support. Fail instead of silently dropping them
# until the catalog format is extended deliberately.
UNSUPPORTED_CONTEXT_FUNCTIONS = {"_x", "_ex", "_nx", "esc_html_x", "esc_attr_x", "_nx_noop"}

EXCLUDED_TOP_LEVEL = {
    ".git",
    ".github",
    "languages",
    "node_modules",
    "tests",
    "tools",
    "vendor",
}


def po_quote(value: str) -> str:
    """Return one valid UTF-8 PO quoted string."""
    escaped = (
        value.replace("\\", "\\\\")
        .replace('"', '\\"')
        .replace("\t", "\\t")
        .replace("\r", "\\r")
        .replace("\n", "\\n")
    )
    return f'"{escaped}"'


def decode_po_quoted(value: str) -> str:
    return ast.literal_eval(value.strip())


def parse_po(path: Path) -> tuple[dict[str, str], dict[tuple[str, str | None], str | tuple[str, ...]]]:
    """Parse the subset of PO syntax emitted by this generator and existing catalogs."""
    if not path.exists():
        return {}, {}

    entries: list[dict[str, object]] = []
    current: dict[str, object] = {}
    active: tuple[str, int | None] | None = None

    def finish() -> None:
        nonlocal current, active
        if current:
            entries.append(current)
        current = {}
        active = None

    for raw in path.read_text("utf-8").splitlines():
        line = raw.strip()
        if not line:
            finish()
            continue
        if line.startswith("#"):
            continue

        m = re.match(r"^(msgid_plural|msgid|msgstr)(?:\[(\d+)\])?\s+(\".*\")$", line)
        if m:
            key = m.group(1)
            index = int(m.group(2)) if m.group(2) is not None else None
            value = decode_po_quoted(m.group(3))
            if key == "msgstr" and index is not None:
                strings = current.setdefault("msgstr_plural", {})
                assert isinstance(strings, dict)
                strings[index] = value
                active = ("msgstr_plural", index)
            else:
                current[key] = value
                active = (key, None)
            continue

        if line.startswith('"') and active:
            value = decode_po_quoted(line)
            key, index = active
            if key == "msgstr_plural":
                strings = current.setdefault("msgstr_plural", {})
                assert isinstance(strings, dict) and index is not None
                strings[index] = str(strings.get(index, "")) + value
            else:
                current[key] = str(current.get(key, "")) + value
            continue

        raise ValueError(f"Unsupported PO syntax in {path}: {raw}")

    finish()

    headers: dict[str, str] = {}
    messages: dict[tuple[str, str | None], str | tuple[str, ...]] = {}
    for entry in entries:
        msgid = str(entry.get("msgid", ""))
        plural = entry.get("msgid_plural")
        plural_s = str(plural) if plural is not None else None
        if msgid == "":
            header_text = str(entry.get("msgstr", ""))
            for header_line in header_text.splitlines():
                if ":" in header_line:
                    name, value = header_line.split(":", 1)
                    headers[name.strip()] = value.strip()
            continue
        if plural_s is None:
            messages[(msgid, None)] = str(entry.get("msgstr", ""))
        else:
            raw_plural = entry.get("msgstr_plural", {})
            assert isinstance(raw_plural, dict)
            max_index = max(raw_plural, default=1)
            messages[(msgid, plural_s)] = tuple(str(raw_plural.get(i, "")) for i in range(max(1, max_index) + 1))
    return headers, messages


def skip_ws(source: str, index: int) -> int:
    while index < len(source) and source[index].isspace():
        index += 1
    return index


def parse_call_args(source: str, open_paren: int) -> tuple[list[str] | None, int | None]:
    args: list[str] = []
    start = open_paren + 1
    index = start
    depth = 1
    quote: str | None = None
    escaped = False
    line_comment = False
    block_comment = False

    while index < len(source):
        ch = source[index]
        nxt = source[index + 1] if index + 1 < len(source) else ""
        if line_comment:
            if ch == "\n":
                line_comment = False
            index += 1
            continue
        if block_comment:
            if ch == "*" and nxt == "/":
                block_comment = False
                index += 2
                continue
            index += 1
            continue
        if quote:
            if escaped:
                escaped = False
                index += 1
                continue
            if ch == "\\":
                escaped = True
                index += 1
                continue
            if ch == quote:
                quote = None
            index += 1
            continue
        if ch in {"'", '"', "`"}:
            quote = ch
            index += 1
            continue
        if ch == "/" and nxt == "/":
            line_comment = True
            index += 2
            continue
        if ch == "/" and nxt == "*":
            block_comment = True
            index += 2
            continue
        if ch == "#":
            line_comment = True
            index += 1
            continue
        if ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
            if depth == 0:
                args.append(source[start:index].strip())
                return args, index
        elif ch == "," and depth == 1:
            args.append(source[start:index].strip())
            start = index + 1
        index += 1
    return None, None


def decode_source_literal(expression: str) -> str | None:
    expression = expression.strip()
    if len(expression) < 2 or expression[0] not in {"'", '"'} or expression[-1] != expression[0]:
        return None
    quote = expression[0]
    body = expression[1:-1]
    if quote == '"' and re.search(r"(?<!\\)\$[A-Za-z_{]", body):
        return None

    out: list[str] = []
    index = 0
    while index < len(body):
        ch = body[index]
        if ch != "\\" or index + 1 >= len(body):
            out.append(ch)
            index += 1
            continue
        nxt = body[index + 1]
        if quote == "'":
            if nxt in {"'", "\\"}:
                out.append(nxt)
            else:
                out.extend(("\\", nxt))
            index += 2
            continue
        escapes = {"n": "\n", "r": "\r", "t": "\t", '"': '"', "'": "'", "\\": "\\"}
        if nxt in escapes:
            out.append(escapes[nxt])
        else:
            out.extend(("\\", nxt))
        index += 2
    return "".join(out)


def iter_source_files(root: Path) -> Iterable[Path]:
    for path in sorted(root.rglob("*")):
        if not path.is_file() or path.suffix not in {".php", ".js"}:
            continue
        rel = path.relative_to(root)
        if rel.parts and rel.parts[0] in EXCLUDED_TOP_LEVEL:
            continue
        yield path


def scan_source_file(root: Path, path: Path) -> list[tuple[tuple[str, str | None], tuple[str, int]]]:
    source = path.read_text("utf-8")
    relative = path.relative_to(root).as_posix()
    found: list[tuple[tuple[str, str | None], tuple[str, int]]] = []
    index = 0
    state = "normal"
    quote: str | None = None
    escaped = False

    names = sorted(set(I18N_FUNCTIONS) | UNSUPPORTED_CONTEXT_FUNCTIONS, key=len, reverse=True)
    while index < len(source):
        ch = source[index]
        nxt = source[index + 1] if index + 1 < len(source) else ""
        if state == "line":
            if ch == "\n":
                state = "normal"
            index += 1
            continue
        if state == "block":
            if ch == "*" and nxt == "/":
                state = "normal"
                index += 2
                continue
            index += 1
            continue
        if state == "string":
            if escaped:
                escaped = False
                index += 1
                continue
            if ch == "\\":
                escaped = True
                index += 1
                continue
            if ch == quote:
                state = "normal"
                quote = None
            index += 1
            continue

        if ch in {"'", '"', "`"}:
            state = "string"
            quote = ch
            index += 1
            continue
        if ch == "/" and nxt == "/":
            state = "line"
            index += 2
            continue
        if ch == "/" and nxt == "*":
            state = "block"
            index += 2
            continue
        if path.suffix == ".php" and ch == "#":
            state = "line"
            index += 1
            continue

        matched: tuple[str, int] | None = None
        for function in names:
            if not source.startswith(function, index):
                continue
            before = source[index - 1] if index else ""
            after_pos = index + len(function)
            after = source[after_pos] if after_pos < len(source) else ""
            if (before and (before.isalnum() or before == "_")) or (after and (after.isalnum() or after == "_")):
                continue
            open_paren = skip_ws(source, after_pos)
            if open_paren < len(source) and source[open_paren] == "(":
                matched = (function, open_paren)
                break
        if not matched:
            index += 1
            continue

        function, open_paren = matched
        args, end = parse_call_args(source, open_paren)
        line = source.count("\n", 0, index) + 1
        if args is None or end is None:
            raise ValueError(f"Unclosed {function}() call at {relative}:{line}")

        if function in UNSUPPORTED_CONTEXT_FUNCTIONS:
            raise ValueError(f"Context-aware i18n function {function}() needs extractor support at {relative}:{line}")

        kind, msg_index, domain_index = I18N_FUNCTIONS[function]
        if len(args) <= domain_index:
            raise ValueError(f"Incomplete {function}() call at {relative}:{line}")
        domain = decode_source_literal(args[domain_index])
        if domain == DOMAIN:
            if kind == "single":
                msgid = decode_source_literal(args[msg_index])
                if msgid is None:
                    raise ValueError(f"Dynamic translatable string in {function}() at {relative}:{line}")
                found.append(((msgid, None), (relative, line)))
            else:
                singular = decode_source_literal(args[0])
                plural = decode_source_literal(args[1]) if len(args) > 1 else None
                if singular is None or plural is None:
                    raise ValueError(f"Dynamic plural string in {function}() at {relative}:{line}")
                found.append(((singular, plural), (relative, line)))
        elif domain is None:
            raise ValueError(f"Dynamic text domain in {function}() at {relative}:{line}")

        index = end + 1
    return found


def extract_messages(root: Path) -> dict[tuple[str, str | None], list[tuple[str, int]]]:
    messages: dict[tuple[str, str | None], list[tuple[str, int]]] = defaultdict(list)
    for path in iter_source_files(root):
        for key, location in scan_source_file(root, path):
            messages[key].append(location)
    return {key: sorted(set(locations)) for key, locations in messages.items()}


def plugin_version(root: Path) -> str:
    text = (root / f"{DOMAIN}.php").read_text("utf-8")
    match = re.search(r"^\s*\*\s*Version:\s*([^\s]+)\s*$", text, re.MULTILINE)
    if not match:
        raise ValueError("Plugin Version header not found")
    return match.group(1)


def header_lines(root: Path, language: str, existing: dict[str, str], stamp: str | None) -> list[str]:
    version = plugin_version(root)
    creation = stamp or existing.get("POT-Creation-Date") or "1970-01-01 00:00+0000"
    revision = stamp or existing.get("PO-Revision-Date") or creation
    return [
        f"Project-Id-Version: SooCool for WooCommerce {version}",
        f"Report-Msgid-Bugs-To: {existing.get('Report-Msgid-Bugs-To', 'security@webactueel.nl')}",
        f"POT-Creation-Date: {creation}",
        f"PO-Revision-Date: {revision}",
        f"Last-Translator: {existing.get('Last-Translator', 'Webactueel')}",
        f"Language-Team: {existing.get('Language-Team', 'Dutch')}",
        f"Language: {language}",
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
        "Content-Transfer-Encoding: 8bit",
        "Plural-Forms: nplurals=2; plural=(n != 1);",
        "X-Generator: tools/build-translations.py",
        f"X-Domain: {DOMAIN}",
    ]


def render_catalog(
    root: Path,
    messages: dict[tuple[str, str | None], list[tuple[str, int]]],
    translations: dict[tuple[str, str | None], str | tuple[str, ...]],
    language: str,
    headers: dict[str, str],
    stamp: str | None,
    template: bool,
) -> bytes:
    output: list[str] = ["msgid \"\"", "msgstr \"\""]
    for line in header_lines(root, language, headers, stamp):
        output.append(po_quote(line + "\n"))
    output.extend(("", ""))

    for key in sorted(messages, key=lambda item: (item[0].casefold(), item[0], item[1] or "")):
        msgid, plural = key
        locations = messages[key]
        output.append("#: " + " ".join(f"{path}:{line}" for path, line in locations))
        output.append("msgid " + po_quote(msgid))
        if plural is None:
            if template:
                translated = ""
            else:
                raw = translations.get(key, "")
                translated = raw if isinstance(raw, str) else (raw[0] if raw else "")
            output.append("msgstr " + po_quote(translated))
        else:
            output.append("msgid_plural " + po_quote(plural))
            if template:
                strings = ("", "")
            else:
                raw = translations.get(key, ("", ""))
                if isinstance(raw, str):
                    strings = (raw, "")
                else:
                    strings = tuple(raw) + ("", "")
            output.append("msgstr[0] " + po_quote(strings[0]))
            output.append("msgstr[1] " + po_quote(strings[1]))
        output.extend(("", ""))
    return ("\n".join(output).rstrip() + "\n").encode("utf-8")


def header_text(lines: list[str]) -> str:
    return "".join(line + "\n" for line in lines)


def compile_mo(
    root: Path,
    messages: dict[tuple[str, str | None], list[tuple[str, int]]],
    translations: dict[tuple[str, str | None], str | tuple[str, ...]],
    headers: dict[str, str],
    stamp: str | None,
) -> bytes:
    catalog: dict[str, str] = {"": header_text(header_lines(root, "nl_NL", headers, stamp))}
    untranslated: list[str] = []
    for key in sorted(messages):
        msgid, plural = key
        raw = translations.get(key)
        if plural is None:
            translated = raw if isinstance(raw, str) else ""
            if not translated:
                untranslated.append(msgid)
            catalog[msgid] = translated
        else:
            strings = raw if isinstance(raw, tuple) else ("", "")
            strings = tuple(strings) + ("", "")
            if not strings[0] or not strings[1]:
                untranslated.append(msgid)
            catalog[msgid + "\x00" + plural] = strings[0] + "\x00" + strings[1]
    if untranslated:
        preview = ", ".join(repr(item) for item in untranslated[:5])
        raise ValueError(f"Dutch catalog has {len(untranslated)} untranslated message(s): {preview}")

    ids = sorted(catalog)
    encoded_ids = [item.encode("utf-8") for item in ids]
    encoded_strs = [catalog[item].encode("utf-8") for item in ids]
    count = len(ids)
    key_index_offset = 7 * 4
    value_index_offset = key_index_offset + count * 8
    key_data_offset = value_index_offset + count * 8
    key_blob = b""
    key_table = []
    for value in encoded_ids:
        key_table.append((len(value), key_data_offset + len(key_blob)))
        key_blob += value + b"\x00"
    value_data_offset = key_data_offset + len(key_blob)
    value_blob = b""
    value_table = []
    for value in encoded_strs:
        value_table.append((len(value), value_data_offset + len(value_blob)))
        value_blob += value + b"\x00"

    out = bytearray()
    out += struct.pack("<7I", 0x950412DE, 0, count, key_index_offset, value_index_offset, 0, 0)
    for length, offset in key_table:
        out += struct.pack("<2I", length, offset)
    for length, offset in value_table:
        out += struct.pack("<2I", length, offset)
    out += key_blob
    out += value_blob
    return bytes(out)


def build(root: Path, stamp: str | None) -> dict[str, bytes]:
    lang = root / "languages"
    pot_headers, _ = parse_po(lang / POT_NAME)
    po_headers, translations = parse_po(lang / PO_NAME)
    messages = extract_messages(root)
    if not messages:
        raise ValueError("No translatable messages found")
    pot = render_catalog(root, messages, {}, "", pot_headers, stamp, template=True)
    po = render_catalog(root, messages, translations, "nl_NL", po_headers, stamp, template=False)
    mo = compile_mo(root, messages, translations, po_headers, stamp)
    return {POT_NAME: pot, PO_NAME: po, MO_NAME: mo}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true", help="Fail if committed catalogs differ from generated output")
    parser.add_argument("--stamp", action="store_true", help="Refresh POT/PO timestamps to the current UTC minute")
    args = parser.parse_args()

    root = Path(__file__).resolve().parents[1]
    stamp = dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%d %H:%M+0000") if args.stamp else None
    generated = build(root, stamp)
    lang = root / "languages"

    changed: list[str] = []
    for name, data in generated.items():
        path = lang / name
        current = path.read_bytes() if path.exists() else b""
        if current != data:
            changed.append(name)
            if not args.check:
                path.write_bytes(data)

    if args.check and changed:
        print("Translation catalogs are stale: " + ", ".join(changed), file=sys.stderr)
        return 1
    if args.check:
        print(f"Translation catalogs are current ({len(extract_messages(root))} messages).")
    else:
        print(f"Generated {len(extract_messages(root))} messages: {', '.join(generated)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
