#!/usr/bin/env python3
# Validate merchant-web.html structure with the stdlib html.parser.
# Checks: well-formed parse, balanced tags, required containers present,
# links admin.css, loads api.js, contains the 5 screens + login gate.
import sys
import os
from html.parser import HTMLParser

HTML_PATH = os.path.join(os.path.dirname(__file__), '..', 'app', 'merchant-web.html')

VOID = {'area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'}

class StructChecker(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.stack = []
        self.errors = []
        self.void_self_closed = 0
        self.tags = {}
        self.in_script = False
        self.script_depth = 0

    def handle_starttag(self, tag, attrs):
        self.tags[tag] = self.tags.get(tag, 0) + 1
        if tag in VOID:
            return
        if tag == 'script':
            # scripts may contain '</script>' inside strings; track depth
            self.script_depth += 1
            self.in_script = True
        self.stack.append(tag)

    def handle_startendtag(self, tag, attrs):
        # self-closing like <meta ... /> or <input ... />
        self.tags[tag] = self.tags.get(tag, 0) + 1
        if tag not in VOID:
            self.void_self_closed += 1

    def handle_endtag(self, tag):
        if tag in VOID:
            return
        if tag == 'script':
            self.script_depth -= 1
            if self.script_depth <= 0:
                self.in_script = False
            # script end: pop the script start
            if self.stack and self.stack[-1] == 'script':
                self.stack.pop()
            return
        # pop matching
        if tag in self.stack:
            # pop until we find it (tolerant of minor nesting issues, but record mismatch)
            while self.stack and self.stack[-1] != tag:
                self.errors.append('unclosed <%s> before </%s>' % (self.stack[-1], tag))
                self.stack.pop()
            if self.stack:
                self.stack.pop()
        else:
            self.errors.append('stray </%s> with no open' % tag)

def main():
    with open(os.path.abspath(HTML_PATH), 'r', encoding='utf-8') as f:
        html = f.read()

    # crude script-content guard: ensure every <script ...> has a matching </script>
    opens = html.count('<script')
    closes = html.count('</script>')
    script_balance = (opens == closes)

    p = StructChecker()
    try:
        p.parse = True
        p.feed(html)
    except Exception as e:
        print('PARSE_EXCEPTION: %s' % e)
        sys.exit(1)

    leftover = p.stack
    # ignore script-related leftover if script_depth tracked
    print('html length: %d chars' % len(html))
    print('script open/close tags: %d / %d  (balanced=%s)' % (opens, closes, script_balance))
    print('unclosed at EOF: %s' % (leftover if leftover else 'none'))
    print('structural mismatches: %d' % len(p.errors))
    for e in p.errors[:20]:
        print('  - ' + e)

    # Required structural pieces
    required = {
        'login gate (#login)': 'id="login"' in html,
        'app shell (#app)': 'id="app"' in html,
        'links admin.css': 'href="admin.css"' in html,
        'loads api.js': 'src="api.js"' in html,
        'sidebar nav': 'class="sidebar"' in html,
        'topbar': 'class="topbar"' in html,
        'dashboard page': 'id="page-dashboard"' in html,
        'products page': 'id="page-products"' in html,
        'orders page': 'id="page-orders"' in html,
        'settlement page': 'id="page-settlement"' in html,
        'settings page': 'id="page-settings"' in html,
        'drawer': 'id="drawer"' in html,
        'toast wrap': 'id="toastWrap"' in html,
        'lang pill': 'id="langPill"' in html,
        'theme btn': 'id="themeBtn"' in html,
    }
    print('\n--- required pieces ---')
    all_present = True
    for k, v in required.items():
        print(('  OK  ' if v else '  MISS') + ' ' + k)
        if not v: all_present = False

    ok = script_balance and not leftover and not p.errors and all_present
    print('\nSTRUCTURE_OK' if ok else 'STRUCTURE_FAIL')
    sys.exit(0 if ok else 2)

if __name__ == '__main__':
    main()
