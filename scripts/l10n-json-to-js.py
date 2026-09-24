#!/usr/bin/env python3
"""Write l10n/<lang>.js from l10n/<lang>.json.

The two files hold the same catalogue in two shapes: PHP reads the JSON, the
browser loads the JS through OC.L10N.register. Only the JSON is edited by hand;
keeping the JS equal by hand is the kind of bookkeeping that quietly stops being
done, and half the app then reads in English for reasons nobody can see.

TranslationCoverageTest compares the pair, so a JS left unregenerated fails the
build rather than shipping.
"""
from __future__ import annotations

import json
import pathlib
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
L10N = ROOT / "l10n"


def render(catalogue: dict, plural_form: str) -> str:
    lines = [
        f"        {json.dumps(key, ensure_ascii=False)}: {json.dumps(value, ensure_ascii=False)}"
        for key, value in catalogue.items()
    ]
    body = ",\n".join(lines)

    return (
        'OC.L10N.register(\n    "doconext_finder",\n    {\n'
        f"{body}\n"
        f'    }},\n    "{plural_form}");\n'
    )


def main() -> int:
    written = 0
    for source in sorted(L10N.glob("*.json")):
        document = json.loads(source.read_text(encoding="utf-8"))
        target = source.with_suffix(".js")
        target.write_text(
            render(document["translations"], document["pluralForm"]),
            encoding="utf-8",
        )
        print(f"{target.relative_to(ROOT)}: {len(document['translations'])} strings")
        written += 1

    if written == 0:
        print("no l10n/*.json found", file=sys.stderr)
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
