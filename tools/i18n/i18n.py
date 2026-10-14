#!/usr/bin/env python3
"""
Translation tooling without GNU gettext (pure Python 3.8+ standard library).

  python tools/i18n/i18n.py extract   # scans PHP/JS/Twig -> locales/<domain>.pot
  python tools/i18n/i18n.py update    # merges the .pot into every locales/*.po
  python tools/i18n/i18n.py compile   # compiles every locales/*.po -> .mo
  python tools/i18n/i18n.py check     # reports untranslated / fuzzy entries

Recognized calls (domain must be the plugin key):
  __('text', 'domain')            _x('context', 'text', 'domain')
  _n('one', 'many', $n, 'domain') _nx('context', 'one', 'many', $n, 'domain')
  __s / _sx / _sn variants are recognized as well.
"""

import os
import re
import struct
import sys
from datetime import datetime, timezone

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
DOMAIN = 'ticketassistant'
LOCALES = os.path.join(ROOT, 'locales')
SCAN_DIRS = ['src', 'front', 'ajax', 'public', 'templates', 'inc']
SCAN_FILES = ['setup.php', 'hook.php']
EXTENSIONS = ('.php', '.js', '.twig')

STRING = r"""(?:'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*")"""
SEP = r'\s*,\s*'
CALLS = [
    # (regex, kind)
    # STRING has no capturing group: the quote of the domain is the last group
    (re.compile(r'\b(?:__|__s)\(\s*(' + STRING + ')' + SEP + r'([\'"])' + DOMAIN + r'\2\s*\)'), 'simple'),
    (re.compile(r'\b(?:_x|_sx)\(\s*(' + STRING + ')' + SEP + '(' + STRING + ')' + SEP + r'([\'"])' + DOMAIN + r'\3\s*\)'), 'context'),
    (re.compile(r'\b(?:_n|_sn)\(\s*(' + STRING + ')' + SEP + '(' + STRING + ')' + SEP + r'[^,()]+(?:\([^()]*\))?' + SEP + r'([\'"])' + DOMAIN + r'\3\s*\)'), 'plural'),
    (re.compile(r'\b_nx\(\s*(' + STRING + ')' + SEP + '(' + STRING + ')' + SEP + '(' + STRING + ')' + SEP + r'[^,()]+(?:\([^()]*\))?' + SEP + r'([\'"])' + DOMAIN + r'\4\s*\)'), 'context_plural'),
]


def php_unquote(literal):
    """Decodes a PHP/JS single or double quoted literal."""
    quote, body = literal[0], literal[1:-1]
    if quote == "'":
        return re.sub(r"\\([\\'])", r'\1', body)
    mapping = {'n': '\n', 't': '\t', 'r': '\r', '"': '"', '\\': '\\', '$': '$', "'": "'"}
    return re.sub(r'\\(.)', lambda m: mapping.get(m.group(1), '\\' + m.group(1)), body)


def po_escape(text):
    return (text.replace('\\', '\\\\').replace('"', '\\"')
            .replace('\n', '\\n').replace('\t', '\\t').replace('\r', '\\r'))


def po_unescape(text):
    out, i = [], 0
    mapping = {'n': '\n', 't': '\t', 'r': '\r', '"': '"', '\\': '\\', 'a': '\a', 'b': '\b', 'f': '\f', 'v': '\v'}
    while i < len(text):
        char = text[i]
        if char == '\\' and i + 1 < len(text):
            out.append(mapping.get(text[i + 1], text[i + 1]))
            i += 2
        else:
            out.append(char)
            i += 1
    return ''.join(out)


# ---------------------------------------------------------------------------
# Extraction
# ---------------------------------------------------------------------------

def iter_sources():
    for name in SCAN_FILES:
        path = os.path.join(ROOT, name)
        if os.path.isfile(path):
            yield path
    for directory in SCAN_DIRS:
        base = os.path.join(ROOT, directory)
        for dirpath, _, files in os.walk(base):
            for file in sorted(files):
                if file.endswith(EXTENSIONS) and not file.endswith('.min.js'):
                    yield os.path.join(dirpath, file)


def extract():
    entries = {}  # (ctx, msgid) -> {'plural': str|None, 'refs': [..]}
    for path in iter_sources():
        with open(path, encoding='utf-8') as handle:
            source = handle.read()
        rel = os.path.relpath(path, ROOT).replace(os.sep, '/')
        for regex, kind in CALLS:
            for match in regex.finditer(source):
                line = source.count('\n', 0, match.start()) + 1
                groups = match.groups()
                ctx, plural = None, None
                if kind == 'simple':
                    msgid = php_unquote(groups[0])
                elif kind == 'context':
                    ctx, msgid = php_unquote(groups[0]), php_unquote(groups[1])
                elif kind == 'plural':
                    msgid, plural = php_unquote(groups[0]), php_unquote(groups[1])
                else:
                    ctx, msgid, plural = php_unquote(groups[0]), php_unquote(groups[1]), php_unquote(groups[2])
                key = (ctx, msgid)
                entry = entries.setdefault(key, {'plural': plural, 'refs': []})
                if plural and not entry['plural']:
                    entry['plural'] = plural
                entry['refs'].append('%s:%d' % (rel, line))

    os.makedirs(LOCALES, exist_ok=True)
    pot = os.path.join(LOCALES, DOMAIN + '.pot')
    now = datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M+0000')
    with open(pot, 'w', encoding='utf-8', newline='\n') as out:
        out.write('# Translation template of the %s GLPI plugin.\n' % DOMAIN)
        out.write('# This file is distributed under the same license as the plugin (GPL-2.0-or-later).\n')
        out.write('msgid ""\nmsgstr ""\n')
        out.write('"Project-Id-Version: %s\\n"\n' % DOMAIN)
        out.write('"POT-Creation-Date: %s\\n"\n' % now)
        out.write('"MIME-Version: 1.0\\n"\n"Content-Type: text/plain; charset=UTF-8\\n"\n')
        out.write('"Content-Transfer-Encoding: 8bit\\n"\n"Plural-Forms: nplurals=INTEGER; plural=EXPRESSION;\\n"\n\n')
        for (ctx, msgid), entry in sorted(entries.items(), key=lambda kv: (kv[1]['refs'][0], kv[0][1])):
            for ref in entry['refs'][:5]:
                out.write('#: %s\n' % ref)
            if ctx is not None:
                out.write('msgctxt "%s"\n' % po_escape(ctx))
            out.write('msgid "%s"\n' % po_escape(msgid))
            if entry['plural']:
                out.write('msgid_plural "%s"\n' % po_escape(entry['plural']))
                out.write('msgstr[0] ""\nmsgstr[1] ""\n\n')
            else:
                out.write('msgstr ""\n\n')
    print('%d strings -> %s' % (len(entries), os.path.relpath(pot, ROOT)))
    return entries


# ---------------------------------------------------------------------------
# PO parsing / writing
# ---------------------------------------------------------------------------

def parse_po(path):
    """Returns (header_msgstr, entries) where entries is an ordered list of dicts."""
    entries, current, last = [], None, None

    def flush():
        if current is not None and ('msgid' in current):
            entries.append(current)

    with open(path, encoding='utf-8') as handle:
        lines = handle.read().splitlines()

    for raw in lines + ['']:
        line = raw.strip()
        if line == '':
            flush()
            current, last = None, None
            continue
        if current is None:
            current = {'comments': [], 'flags': [], 'msgstr': {}}
        if line.startswith('#,'):
            current['flags'] += [f.strip() for f in line[2:].split(',')]
            continue
        if line.startswith('#~'):
            current['obsolete'] = True
            continue
        if line.startswith('#'):
            current['comments'].append(raw)
            continue
        match = re.match(r'^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+"(.*)"$', line)
        if match:
            keyword, index, value = match.group(1), match.group(2), po_unescape(match.group(3))
            if keyword.startswith('msgstr'):
                last = ('msgstr', int(index) if index is not None else 0)
                current['msgstr'][last[1]] = value
            else:
                last = (keyword, None)
                current[keyword] = value
            continue
        match = re.match(r'^"(.*)"$', line)
        if match and last is not None:
            value = po_unescape(match.group(1))
            if last[0] == 'msgstr':
                current['msgstr'][last[1]] += value
            else:
                current[last[0]] += value
    entries = [e for e in entries if not e.get('obsolete')]
    header = ''
    body = []
    for entry in entries:
        if entry.get('msgid') == '' and 'msgctxt' not in entry:
            header = entry['msgstr'].get(0, '')
        else:
            body.append(entry)
    return header, body


def write_po(path, header, entries):
    with open(path, 'w', encoding='utf-8', newline='\n') as out:
        out.write('msgid ""\nmsgstr ""\n')
        for line in header.split('\n'):
            if line:
                out.write('"%s\\n"\n' % po_escape(line))
        out.write('\n')
        for entry in entries:
            for comment in entry.get('comments', []):
                out.write(comment + '\n')
            flags = [f for f in entry.get('flags', []) if f]
            if flags:
                out.write('#, %s\n' % ', '.join(flags))
            if entry.get('msgctxt') is not None:
                out.write('msgctxt "%s"\n' % po_escape(entry['msgctxt']))
            out.write('msgid "%s"\n' % po_escape(entry['msgid']))
            if entry.get('msgid_plural') is not None:
                out.write('msgid_plural "%s"\n' % po_escape(entry['msgid_plural']))
                for index in sorted(entry['msgstr']):
                    out.write('msgstr[%d] "%s"\n' % (index, po_escape(entry['msgstr'][index])))
            else:
                out.write('msgstr "%s"\n' % po_escape(entry['msgstr'].get(0, '')))
            out.write('\n')


def update():
    pot = os.path.join(LOCALES, DOMAIN + '.pot')
    if not os.path.isfile(pot):
        extract()
    _, template = parse_po(pot)
    for file in sorted(os.listdir(LOCALES)):
        if not file.endswith('.po'):
            continue
        path = os.path.join(LOCALES, file)
        header, existing = parse_po(path)
        known = {(e.get('msgctxt'), e['msgid']): e for e in existing}
        merged, added = [], 0
        for entry in template:
            key = (entry.get('msgctxt'), entry['msgid'])
            new = {
                'comments': [c for c in entry.get('comments', []) if c.startswith('#:')],
                'flags': [],
                'msgid': entry['msgid'],
                'msgstr': {0: ''},
            }
            if entry.get('msgctxt') is not None:
                new['msgctxt'] = entry['msgctxt']
            if entry.get('msgid_plural') is not None:
                new['msgid_plural'] = entry['msgid_plural']
                new['msgstr'] = {0: '', 1: ''}
            if key in known:
                old = known[key]
                new['msgstr'] = dict(old['msgstr']) or new['msgstr']
                new['flags'] = [f for f in old.get('flags', []) if f == 'fuzzy']
            else:
                added += 1
            merged.append(new)
        write_po(path, header, merged)
        print('%s: %d entries (%d new)' % (file, len(merged), added))


# ---------------------------------------------------------------------------
# MO compilation (GNU gettext binary format, little endian, no hash table)
# ---------------------------------------------------------------------------

def compile_mo(po_path, mo_path):
    header, entries = parse_po(po_path)
    messages = {b'': header.encode('utf-8')}
    for entry in entries:
        if 'fuzzy' in entry.get('flags', []):
            continue
        msgstrs = [entry['msgstr'][k] for k in sorted(entry['msgstr'])]
        if not any(msgstrs):
            continue  # untranslated: gettext falls back to the source string
        msgid = entry['msgid']
        if entry.get('msgctxt') is not None:
            msgid = entry['msgctxt'] + '\x04' + msgid
        if entry.get('msgid_plural') is not None:
            key = (msgid + '\x00' + entry['msgid_plural']).encode('utf-8')
            value = '\x00'.join(msgstrs).encode('utf-8')
        else:
            key = msgid.encode('utf-8')
            value = msgstrs[0].encode('utf-8')
        messages[key] = value

    keys = sorted(messages.keys())
    ids, strs, offsets = b'', b'', []
    for key in keys:
        offsets.append((len(ids), len(key), len(strs), len(messages[key])))
        ids += key + b'\x00'
        strs += messages[key] + b'\x00'

    count = len(keys)
    keystart = 7 * 4 + 16 * count
    valuestart = keystart + len(ids)
    koffsets, voffsets = [], []
    for o1, l1, o2, l2 in offsets:
        koffsets += [l1, o1 + keystart]
        voffsets += [l2, o2 + valuestart]

    output = struct.pack('<Iiiiiii', 0x950412de, 0, count, 7 * 4, 7 * 4 + count * 8, 0, 0)
    output += struct.pack('<%di' % len(koffsets), *koffsets)
    output += struct.pack('<%di' % len(voffsets), *voffsets)
    output += ids + strs
    with open(mo_path, 'wb') as handle:
        handle.write(output)
    return count - 1


def compile_all():
    for file in sorted(os.listdir(LOCALES)):
        if file.endswith('.po'):
            po = os.path.join(LOCALES, file)
            mo = po[:-3] + '.mo'
            count = compile_mo(po, mo)
            print('%s -> %s (%d messages)' % (file, os.path.basename(mo), count))


def check():
    _, template = parse_po(os.path.join(LOCALES, DOMAIN + '.pot'))
    wanted = {(e.get('msgctxt'), e['msgid']) for e in template}
    status = 0
    for file in sorted(os.listdir(LOCALES)):
        if not file.endswith('.po'):
            continue
        _, entries = parse_po(os.path.join(LOCALES, file))
        have = {(e.get('msgctxt'), e['msgid']): e for e in entries}
        missing = [k for k in wanted if k not in have or not any(have[k]['msgstr'].values())]
        fuzzy = [k for k, e in have.items() if 'fuzzy' in e.get('flags', [])]
        print('%s: %d/%d translated, %d fuzzy' % (file, len(wanted) - len(missing), len(wanted), len(fuzzy)))
        for ctx, msgid in sorted(missing, key=lambda k: k[1])[:50]:
            print('   untranslated: %s%s' % ('[%s] ' % ctx if ctx else '', msgid))
        if missing or fuzzy:
            status = 1
    return status


if __name__ == '__main__':
    command = sys.argv[1] if len(sys.argv) > 1 else 'compile'
    if command == 'extract':
        extract()
    elif command == 'update':
        extract()
        update()
    elif command == 'compile':
        compile_all()
    elif command == 'check':
        sys.exit(check())
    else:
        print(__doc__)
        sys.exit(2)
