#!/usr/bin/env python3
"""
Mock AI provider for development and end-to-end tests: no API key, no cost,
deterministic answers. Python 3.8+ standard library only.

    python tools/mock-llm/server.py --port 8099 [--log requests.jsonl]

Implements just enough of:
  - OpenAI / OpenAI-compatible:  POST /v1/chat/completions, POST /v1/embeddings, GET /v1/models
  - Azure OpenAI (classic):      POST /openai/deployments/<name>/chat/completions?api-version=...
                                 POST /openai/deployments/<name>/embeddings?api-version=...
  - Azure OpenAI (v1):           POST /openai/v1/chat/completions, POST /openai/v1/embeddings
  - Anthropic:                   POST /v1/messages, GET /v1/models

The answer depends on the output contract found in the system prompt
(analysis, rewrite, classification, distillation, article) and echoes facts
from the request (number of images, attachment names) so that tests can
check what the plugin really sent.

Error injection: put one of these markers in the ticket or the draft:
  MOCK_ERROR_429, MOCK_ERROR_500, MOCK_CONTENT_FILTER, MOCK_BAD_JSON, MOCK_SLOW

With --scripted, the open tickets of the demonstration data (VPN, printer,
access request) get written, realistic answers instead of "[mock]" texts:
for demonstrations and screenshots. They are scripted, not generated.
With --latency, every chat answer waits that many seconds, like a real model.
"""

import argparse
import hashlib
import json
import math
import re
import sys
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LOG_FILE = None
SCRIPTED = False
LATENCY = 0.0
STATE = {'429_seen': set()}
DIMENSIONS = 64


def log_request(path, body):
    if not LOG_FILE:
        return

    def shorten(value):
        if isinstance(value, str) and len(value) > 300 and re.fullmatch(r'[A-Za-z0-9+/=:;,\-.a-z]+', value[:300]):
            return value[:60] + '...(%d chars)' % len(value)
        if isinstance(value, dict):
            return {k: shorten(v) for k, v in value.items()}
        if isinstance(value, list):
            return [shorten(v) for v in value]
        return value

    with open(LOG_FILE, 'a', encoding='utf-8') as handle:
        handle.write(json.dumps({'time': time.time(), 'path': path, 'body': shorten(body)}, ensure_ascii=False) + '\n')


def embedding(text):
    """Deterministic bag-of-words vector: similar texts get similar vectors."""
    vector = [0.0] * DIMENSIONS
    for word in re.findall(r'\w{3,}', text.lower()):
        digest = hashlib.md5(word.encode('utf-8')).digest()
        vector[digest[0] % DIMENSIONS] += 1.0
    norm = math.sqrt(sum(v * v for v in vector)) or 1.0
    return [round(v / norm, 6) for v in vector]


def openai_parts(messages):
    """(system, user_text, image_count) from OpenAI messages."""
    system, texts, images = '', [], 0
    for message in messages:
        content = message.get('content')
        if message.get('role') == 'system':
            system += content if isinstance(content, str) else ''
            continue
        if isinstance(content, str):
            texts.append(content)
        elif isinstance(content, list):
            for part in content:
                if part.get('type') == 'text':
                    texts.append(part.get('text', ''))
                elif part.get('type') in ('image_url', 'input_image'):
                    images += 1
                elif part.get('type') == 'file':
                    texts.append('[native file: %s]' % part.get('file', {}).get('filename', '?'))
    return system, '\n'.join(texts), images


def anthropic_parts(body):
    system = body.get('system', '')
    if isinstance(system, list):
        system = ' '.join(b.get('text', '') for b in system if isinstance(b, dict))
    texts, images = [], 0
    for message in body.get('messages', []):
        content = message.get('content')
        if isinstance(content, str):
            texts.append(content)
            continue
        for block in content or []:
            if block.get('type') == 'text':
                texts.append(block.get('text', ''))
            elif block.get('type') == 'image':
                images += 1
            elif block.get('type') == 'document':
                texts.append('[native document]')
    return system, '\n'.join(texts), images


def section(text, title):
    match = re.search(r'## ' + re.escape(title) + r'[^\n]*\n(.*?)(?:\n## |\n</ticket>|\Z)', text, re.S)
    return match.group(1).strip() if match else ''


# --- Scripted answers (--scripted) --------------------------------------------

SCENARIOS = {
    'vpn': {
        'summary': '<p>{name} cannot connect to the VPN from a hotel since this morning: Windows shows '
                   '<strong>error 809</strong> (screenshot), and needs the shared drive for a presentation at 2 pm.</p>'
                   '<ul><li>The client log shows no answer to <code>IKE_SA_INIT</code> on UDP 500 (IKEv2/IPsec), also from a phone hotspot.</li>'
                   '<li>The attached notice NC-2026-118 says that hotel and guest networks block IPsec (UDP 500/4500) since October 12; '
                   'remote users must use the SSL VPN profile <em>Corporate-SSL</em>, available in the client since version 5.2.</li>'
                   '<li>Internal note: the gateway saw no IPsec negotiation from this account since 08:10, so the traffic is blocked before reaching us.</li></ul>',
        'strategy': '<ol><li>Ask {name} to select the profile <strong>Corporate-SSL</strong> in the VPN client and connect again: it goes through hotel networks and hotspots (TCP 443).</li>'
                    '<li>If the profile is missing, check the version of the client: below 5.2, send the update or the profile file.</li>'
                    '<li>If Corporate-SSL fails too, ask for a new client log and check the account on the SSL gateway.</li>'
                    '<li>A password was written in the ticket: ask {name} to change it once connected.</li></ol>',
        'reply': '<p>Hello {name},</p><p>Thank you for the screenshot and the log. Error 809 means that the hotel network blocks the type of VPN '
                 'connection your profile uses (IPsec). Since October 12 this is expected on hotel and guest networks.</p>'
                 '<p>Please open the VPN client, choose the profile <strong>Corporate-SSL</strong> and connect again: it works through hotel networks '
                 'and phone hotspots. If you do not see this profile, tell us the version of your VPN client (Help &gt; About) and we will send it right away.</p>'
                 '<p>For your security, please also change your password once you are connected: it was written in the ticket.</p>',
        'missing': ['Version of the VPN client (Help > About)'],
        'urgency': 4,
    },
    'printer': {
        'summary': '<p>Every page printed on the finance printer comes out blank since the toner was replaced yesterday.</p>'
                   '<p>Three similar solved tickets were caused by the sealing tape of a new toner cartridge left in place, or by a cartridge not fully seated.</p>',
        'strategy': '<ol><li>Open the printer and check that the sealing tape of the new toner was pulled out completely.</li>'
                    '<li>Reseat the cartridge until it clicks, then print a configuration page from the printer panel.</li>'
                    '<li>If the page is still blank, put the previous cartridge back to rule out a defective one, then open a warranty request with the supplier.</li></ol>',
        'reply': '<p>Hello {name},</p><p>Thank you for reporting it. A new toner cartridge often keeps a protective tape that must be pulled out before use.</p>'
                 '<p>A technician will check the finance printer this morning and keep you informed.</p>',
        'missing': [],
        'urgency': 3,
    },
    'access': {
        'summary': '<p>Request to give the new analyst access to the finance shared folder to prepare the budget. '
                   'The requester says the manager agreed, but the approval is not in the ticket.</p>',
        'strategy': '<ol><li>Get the approval of the manager of the finance department (approval in GLPI, or the e-mail attached to the ticket).</li>'
                    '<li>Add the account of the analyst to the group that has access to the folder, not to the folder itself.</li>'
                    '<li>Ask the analyst to sign out and in again, then confirm the access.</li></ol>',
        'reply': '<p>Hello {name},</p><p>We will give access to the finance folder as soon as the manager approves the request: '
                 'the manager will receive an approval request from GLPI.</p><p>Could you also tell us the name and the login of the new analyst?</p>',
        'missing': ['Name and login of the new analyst', 'Approval of the manager of the finance department'],
        'urgency': 3,
    },
}

CATEGORY_HINTS = [
    (r'vpn|809|ipsec', 'Network > VPN'),
    (r'wi-?fi|wireless', 'Network > Wi-Fi'),
    (r'print|toner', 'Hardware > Printer'),
    (r'laptop|notebook|battery', 'Hardware > Laptop'),
    (r'password|locked', 'Access > Password reset'),
    (r'access|folder|account|permission', 'Access > New account'),
    (r'e-?mail|outlook|mailbox', 'Software > E-mail'),
    (r'install|software|licen', 'Software > Installation'),
]


def scenario_of(text):
    lower = text.lower()
    if re.search(r'vpn|error 809', lower):
        return 'vpn'
    if re.search(r'printer|toner|blank pages|pages come out blank', lower):
        return 'printer'
    if re.search(r'budget folder|shared folder|access to the', lower):
        return 'access'
    return None


def pick_category(text):
    """(id, name) of the candidate category matching the ticket, from the list sent by the plugin."""
    candidates = re.findall(r'^- \[id (\d+)\] ([^(\n]+?) \(', section(text, 'CANDIDATE CATEGORIES'), re.M)
    ticket = text.split('## CANDIDATE CATEGORIES')[0]
    for pattern, name in CATEGORY_HINTS:
        if re.search(pattern, ticket, re.I):
            for category_id, label in candidates:
                if label.strip() == name:
                    return int(category_id), name
    return (int(candidates[0][0]), candidates[0][1].strip()) if candidates else (None, '')


def scripted_answer(system, text, title, first_name):
    name = first_name or 'there'
    if '"summary_html"' in system:
        key = scenario_of(title + '\n' + text)
        if key is None:
            return None
        scenario = SCENARIOS[key]
        return {
            'summary_html': scenario['summary'].format(name=name),
            'strategy_html': scenario['strategy'].format(name=name),
            'reply_html': scenario['reply'].format(name=name),
            'missing_information': scenario['missing'],
        }
    if '"type": 1 for an incident' in system:
        category_id, category = pick_category(text)
        request = bool(re.search(r'access|folder|account|new user|install', title, re.I))
        if '"reusable"' in system:
            problem = re.sub(r'\s+', ' ', section(text, 'TICKET') or title)[:160]
            return {
                'summary': 'Recurring case: ' + problem.rstrip('.') + '.',
                'resolution': '1. Confirm the symptom with the requester. 2. Apply the documented fix of the category. 3. Confirm with the requester and close.',
                'type': 2 if request else 1,
                'category_id': category_id,
                'keywords': [w for w in re.findall(r'[a-z]{4,}', title.lower())][:6],
                'reusable': True,
            }
        key = scenario_of(title)
        return {
            'type': 2 if request else 1,
            'category_id': category_id,
            'confidence': 0.86,
            'justification': 'The solved tickets of "%s" describe the same need, and the ticket asks for it explicitly.' % category if category else 'Closest category among the candidates.',
            'urgency': SCENARIOS[key]['urgency'] if key else 3,
        }
    if '"content_html"' in system:
        if re.search(r'folder|access', title, re.I):
            return {
                'title': 'Give a new employee access to a department shared folder',
                'content_html': '<h4>Symptoms</h4><p>A new employee cannot open the shared folder of the department.</p>'
                                '<h4>Cause</h4><p>Access to the folders is granted through groups: the account is not yet a member of the group of the department.</p>'
                                '<h4>Solution</h4><ol><li>Check that the manager of the department approved the request.</li>'
                                '<li>Add the account to the group of the folder (for example FINANCE-READ or FINANCE-WRITE).</li>'
                                '<li>Ask the user to sign out and in again, then open the folder.</li></ol>'
                                '<h4>Notes</h4><p>Never give access to the folder itself: groups keep the permissions consistent when people change jobs.</p>',
            }
        return {
            'title': 'How to solve: ' + title,
            'content_html': '<h4>Symptoms</h4><p>' + title + '.</p><h4>Solution</h4><ol><li>Check the configuration.</li>'
                            '<li>Apply the fix described in the solution.</li><li>Confirm with the requester.</li></ol>',
        }
    if '"reply_html"' in system:
        draft = re.search(r'<draft>\s*(.*?)\s*</draft>', text, re.S)
        draft = draft.group(1).strip() if draft else ''
        if re.search(r'corporate-?ssl|profile|vpn', draft, re.I):
            return {'reply_html': '<p>Hello %s,</p><p>Thank you for your patience. Please select the VPN profile <strong>Corporate-SSL</strong> '
                                  'in the client and connect again: unlike the usual profile, it works from hotel networks and phone hotspots.</p>'
                                  '<p>If the connection still fails, reply to this message with a screenshot of the error and we will call you.</p>' % name}
        sentences = [s.strip() for s in re.split(r'(?<=[.!?])\s+', re.sub(r'\s+', ' ', draft)) if s.strip()]
        body = ' '.join(s[0].upper() + s[1:] for s in sentences) or draft
        return {'reply_html': '<p>Hello %s,</p><p>%s</p>' % (name, body.replace('<', '&lt;'))}
    return None


def answer_for(system, text, images):
    """JSON answer matching the output contract described in the system prompt."""
    title = re.search(r'Title: (.*)', text)
    title = title.group(1).strip() if title else 'the ticket'
    first_name = re.search(r'Use "([^"]+)" as the requester', text) or re.search(r"Requester's first name \(for the greeting\): (.+)", text)
    first_name = first_name.group(1).strip() if first_name else ''
    if SCRIPTED:
        scripted = scripted_answer(system, text, title, first_name)
        if scripted is not None:
            return scripted
    image_names = re.findall(r'^\d+\. (.+)$', section(text, 'ATTACHED IMAGES'), re.M)
    documents = re.findall(r'^### (.+)$', section(text, 'ATTACHED DOCUMENTS'), re.M)
    similar = len(re.findall(r'^Case \d+', text, re.M))

    if '"summary_html"' in system:
        facts = '<ul><li>Images received: %d%s</li><li>Documents read: %s</li><li>Similar solved tickets: %d</li></ul>' % (
            images, (' (' + ', '.join(image_names) + ')') if image_names else '',
            ', '.join(documents) if documents else 'none', similar)
        greeting = ('Hello %s,' % first_name) if first_name else 'Hello,'
        return {
            'summary_html': '<p>[mock] Summary of "%s".</p>%s' % (title, facts),
            'strategy_html': '<ol><li>[mock] Check the information of "%s".</li><li>Reproduce the problem.</li><li>Escalate to level 2 if it persists.</li></ol>' % title,
            'reply_html': '<p>%s</p><p>[mock] We are working on your ticket "%s" and will update you shortly.</p><p>Best regards,</p>' % (greeting, title),
            'missing_information': ['[mock] Screenshot of the error'] if images == 0 else [],
        }
    if '"type": 1 for an incident' in system or '"type": 1 for an incident (something' in system:
        candidates = re.findall(r'^\s*-?\s*\[?id[ =:]*(\d+)\]?', text, re.M) or re.findall(r'#(\d+)\b', section(text, 'CANDIDATE CATEGORIES'))
        if '"reusable"' in system:
            return {
                'summary': '[mock] Generic summary of "%s".' % title,
                'resolution': '[mock] 1. Check the configuration. 2. Apply the fix. 3. Confirm with the requester.',
                'type': 2 if re.search(r'request|access|acesso|solicit', text, re.I) else 1,
                'category_id': int(candidates[0]) if candidates else None,
                'keywords': ['mock', 'test'],
                'reusable': True,
            }
        return {
            'type': 2 if re.search(r'request|access|acesso|solicit', text, re.I) else 1,
            'category_id': int(candidates[0]) if candidates else None,
            'confidence': 0.77,
            'justification': '[mock] Chosen from the candidate list.',
            'urgency': 3,
        }
    if '"content_html"' in system:
        return {
            'title': '[mock] How to solve: %s' % title,
            'content_html': '<h4>Symptoms</h4><p>[mock]</p><h4>Solution</h4><ol><li>Step one</li><li>Step two</li></ol>',
        }
    if '"reply_html"' in system:
        draft = re.search(r'<draft>\s*(.*?)\s*</draft>', text, re.S)
        draft = draft.group(1).strip() if draft else text[:200]
        mode = 'rewrite'
        for name in ('formal', 'friendly', 'shorter', 'Correct only', 'Translate'):
            if name.lower() in system.lower():
                mode = name.split()[0].lower()
                break
        greeting = ('Hello %s,' % first_name) if first_name else 'Hello,'
        return {'reply_html': '<p>%s</p><p>[mock %s] %s</p>' % (greeting, mode, draft.replace('<', '&lt;'))}
    return None


class Handler(BaseHTTPRequestHandler):
    server_version = 'MockLLM/1.0'

    def log_message(self, fmt, *args):
        sys.stderr.write('[mock-llm] ' + (fmt % args) + '\n')

    def send_json(self, status, payload, headers=None):
        data = json.dumps(payload, ensure_ascii=False).encode('utf-8')
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(data)))
        for key, value in (headers or {}).items():
            self.send_header(key, value)
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        if self.path.rstrip('/').endswith('/models') or '/models?' in self.path:
            self.send_json(200, {'object': 'list', 'data': [
                {'id': 'mock-vision-1', 'object': 'model'},
                {'id': 'mock-mini-1', 'object': 'model'},
                {'id': 'mock-embed-1', 'object': 'model'},
            ]})
            return
        self.send_json(404, {'error': {'message': 'Not found: ' + self.path}})

    def do_POST(self):
        length = int(self.headers.get('Content-Length') or 0)
        raw = self.rfile.read(length) if length else b''
        try:
            body = json.loads(raw.decode('utf-8') or '{}')
        except ValueError:
            self.send_json(400, {'error': {'message': 'Invalid JSON body'}})
            return
        log_request(self.path, body)
        path = self.path.split('?')[0]

        if path.endswith('/embeddings'):
            inputs = body.get('input', [])
            inputs = [inputs] if isinstance(inputs, str) else inputs
            self.send_json(200, {
                'object': 'list',
                'data': [{'object': 'embedding', 'index': i, 'embedding': embedding(t)} for i, t in enumerate(inputs)],
                'model': body.get('model', 'mock-embed-1'),
                'usage': {'prompt_tokens': sum(len(t) // 4 for t in inputs), 'total_tokens': sum(len(t) // 4 for t in inputs)},
            })
            return

        anthropic = path.endswith('/v1/messages')
        if anthropic:
            system, text, images = anthropic_parts(body)
        elif path.endswith('/chat/completions'):
            system, text, images = openai_parts(body.get('messages', []))
        else:
            self.send_json(404, {'error': {'message': 'Not found: ' + path}})
            return

        if LATENCY > 0:
            time.sleep(LATENCY)
        if 'MOCK_SLOW' in text:
            time.sleep(5)
        if 'MOCK_ERROR_500' in text:
            self.send_json(500, {'error': {'message': 'Mock internal error'}})
            return
        if 'MOCK_ERROR_429' in text:
            key = hashlib.md5(text.encode('utf-8')).hexdigest()
            if key not in STATE['429_seen']:
                STATE['429_seen'].add(key)  # first attempt fails, the retry succeeds
                self.send_json(429, {'error': {'message': 'Mock rate limit', 'type': 'rate_limit_error'}}, {'Retry-After': '1'})
                return
        if 'MOCK_CONTENT_FILTER' in text:
            self.send_json(400, {'error': {'code': 'content_filter', 'message': 'The response was filtered (mock content_filter)'}})
            return

        answer = answer_for(system, text, images)
        content = json.dumps(answer, ensure_ascii=False) if answer is not None else 'pong'
        if 'MOCK_BAD_JSON' in text:
            content = 'this is not json'
        prompt_tokens = (len(system) + len(text)) // 4 + images * 800
        completion_tokens = len(content) // 4

        if anthropic:
            self.send_json(200, {
                'id': 'msg_mock', 'type': 'message', 'role': 'assistant', 'model': body.get('model', 'mock'),
                'content': [{'type': 'text', 'text': content}],
                'stop_reason': 'end_turn',
                'usage': {'input_tokens': prompt_tokens, 'output_tokens': completion_tokens},
            })
            return
        self.send_json(200, {
            'id': 'chatcmpl-mock', 'object': 'chat.completion', 'created': int(time.time()),
            'model': body.get('model', 'mock-vision-1'),
            'choices': [{'index': 0, 'message': {'role': 'assistant', 'content': content}, 'finish_reason': 'stop'}],
            'usage': {
                'prompt_tokens': prompt_tokens,
                'completion_tokens': completion_tokens,
                'total_tokens': prompt_tokens + completion_tokens,
                'completion_tokens_details': {'reasoning_tokens': completion_tokens // 2},
            },
        })


def main():
    global LOG_FILE, SCRIPTED, LATENCY
    parser = argparse.ArgumentParser(description='Mock AI provider')
    parser.add_argument('--host', default='0.0.0.0')
    parser.add_argument('--port', type=int, default=8099)
    parser.add_argument('--log', default=None, help='JSONL file receiving every request (images shortened)')
    parser.add_argument('--scripted', action='store_true', help='written, realistic answers for the demonstration tickets')
    parser.add_argument('--latency', type=float, default=0.0, metavar='SECONDS', help='delay before every chat answer')
    args = parser.parse_args()
    LOG_FILE = args.log
    SCRIPTED = args.scripted
    LATENCY = max(0.0, args.latency)
    server = ThreadingHTTPServer((args.host, args.port), Handler)
    print('Mock AI provider listening on http://%s:%d' % (args.host, args.port), flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass


if __name__ == '__main__':
    main()
