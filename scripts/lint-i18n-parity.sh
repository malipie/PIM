#!/usr/bin/env bash
# GOLIVE #2188 — i18n key-parity guard (pl.json ↔ en.json).
#
# `fallbackLng='pl'` masks missing EN keys at runtime: an EN user silently
# gets the Polish string, so gaps are invisible in manual testing (48 keys
# had drifted before this guard). Parity must be checked by script, not by
# eye — and it must be PLURAL-AWARE: Polish uses the `_few` / `_many` CLDR
# categories that English never selects, so a `_few`/`_many` key is NOT a
# gap as long as the EN side covers the same base key with `_one` + `_other`.
#
# Symmetric: keys present in en.json but absent from pl.json fail too.
set -euo pipefail

cd "$(dirname "$0")/.."

python3 - <<'PY'
import json
import re
import sys

def flat(d, prefix=''):
    out = {}
    for k, v in d.items():
        key = f'{prefix}.{k}' if prefix else k
        if isinstance(v, dict):
            out.update(flat(v, key))
        else:
            out[key] = v
    return out

pl = flat(json.load(open('apps/admin/src/locales/pl.json')))
en = flat(json.load(open('apps/admin/src/locales/en.json')))

PLURAL = re.compile(r'_(zero|one|two|few|many|other)$')

def gaps(source, target, target_name):
    problems = []
    for key in sorted(set(source) - set(target)):
        m = PLURAL.search(key)
        if m:
            base = PLURAL.sub('', key)
            # A plural category the target language never selects is fine as
            # long as the target covers the same base key completely in ITS
            # plural system: `one + other` (English-style) or
            # `one + few + many` (Polish-style — `other` only fires for
            # fractions, which count-based UI strings never produce).
            en_style = f'{base}_one' in target and f'{base}_other' in target
            pl_style = all(f'{base}_{c}' in target for c in ('one', 'few', 'many'))
            if en_style or pl_style:
                continue
        problems.append(f'  {key} (missing in {target_name})')
    return problems

problems = gaps(pl, en, 'en.json') + gaps(en, pl, 'pl.json')
if problems:
    print(f'lint-i18n-parity: {len(problems)} translation key gap(s):', file=sys.stderr)
    print('\n'.join(problems), file=sys.stderr)
    print('Add the missing translations (plural gaps need _one + _other on the target side).', file=sys.stderr)
    sys.exit(1)

print(f'lint-i18n-parity: pl={len(pl)} keys, en={len(en)} keys — parity OK (plural-aware).')
PY
