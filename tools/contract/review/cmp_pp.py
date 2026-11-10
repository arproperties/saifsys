import json, sys, re, html, difflib
d = json.load(open(sys.argv[1], encoding='utf-8'))
m, old = d['mapping'], d['old']
fill = {'{{MINOR_REPAIR_THRESHOLD}}': '500', '{{MINOR_REPAIR_THRESHOLD_WORDS}}': 'Five Hundred',
        '{{EJARI_REGISTRATION_DAYS}}': '7', '{{EJARI_REGISTRATION_DAYS_WORDS}}': 'seven', '{{CHEQUE_ADMIN_FEE}}': '500', '{{CHEQUE_ADMIN_FEE_WORDS}}': 'Five Hundred',
        '{{MAX_OCCUPANTS}}': '4', '{{MAX_OCCUPANTS_WORDS}}': 'four', '{{OCCUPANCY_TYPE}}': 'a single family',
        '{{LANDLORD_NOTICE_EMAIL}}': 'tenantcare@ainalreempro.com',
        '{{TENANT_EMAIL}}': "[tenant's email]", '{{TENANT_PHONE}}': "[tenant's mobile]",
        '{{GUARANTOR_NAME}}': '[Guarantor name]', '{{GUARANTOR_EID}}': '[Guarantor Emirates ID]'}
def plain(t):
    for k, v in fill.items(): t = t.replace(k, v)
    t = t.replace('<br>', '\n').replace('<b>', '').replace('</b>', '')
    t = html.unescape(re.sub(r'<[^>]+>', '', t))
    return t.strip()
def esc(t): return html.escape(t).replace('\n', '<br>')
def diff(a, b):
    aw, bw = re.findall(r'\S+|\n', a), re.findall(r'\S+|\n', b)
    L, R = [], []
    for op, i1, i2, j1, j2 in difflib.SequenceMatcher(None, aw, bw, autojunk=False).get_opcodes():
        A = ' '.join(aw[i1:i2]).replace(' \n ', '\n'); B = ' '.join(bw[j1:j2]).replace(' \n ', '\n')
        if op == 'equal': L.append(esc(A)); R.append(esc(B))
        else:
            if A: L.append('<span class="del">' + esc(A) + '</span>')
            if B: R.append('<span class="add">' + esc(B) + '</span>')
    return ' '.join(L), ' '.join(R)

TAG = {'unchanged': ('No change', 'same'), 'amended': ('Amended', 'amd'), 'replaced': ('Replaced', 'rep'),
       'new': ('New', 'new'), 'deleted': ('Deleted', 'delc'), 'legal': ('Legal review', 'legal')}
def tag(kind):
    k = kind.lower()
    if k.startswith('unchanged - legal'): return TAG['legal']
    for key in ['unchanged', 'amended', 'replaced', 'new', 'deleted']:
        if k.startswith(key): return TAG[key]

def table(with_g):
    rows = []; n = 0
    seq = [x for x in m if 'deleted' not in x['kind']]
    for x in seq:
        is_g = 'Guarantor' in x['kind']
        if is_g and not with_g: continue
        if not is_g: n += 1
        o = x['old']
        lab, cls = tag(x['kind'])
        new_t = plain(x['en'])
        if o is None:
            left, right = '<span class="muted">— (not in old contract)</span>', '<span class="add">' + esc(new_t) + '</span>'
            oldno = '—'
        elif cls in ('same', 'legal'):
            left = esc(plain(old[str(o)]['en'])); right = '<span class="muted">Same text as old.</span>'
            if cls == 'legal': right += '<br><span class="note">Kept, but marked for legal review (can be switched off).</span>'
            oldno = str(o)
        elif o == 15:
            l, r = diff(plain(old['15']['en']), new_t)
            left = '<b>Old 15:</b> ' + esc(plain(old['15']['en'])) + '<br><br><b>Old 18:</b> ' + esc(plain(old['18']['en']))
            left = '<span class="del">' + left + '</span>'
            right = '<span class="add">' + esc(new_t) + '</span>'
            oldno = '15 + 18'
            lab = 'Replaced (15 + 18 merged)'
        elif cls == 'rep':
            left = '<span class="del">' + esc(plain(old[str(o)]['en'])) + '</span>'
            right = '<span class="add">' + esc(new_t) + '</span>'
            oldno = str(o)
        else:
            left, right = diff(plain(old[str(o)]['en']), new_t)
            oldno = str(o)
        newno = f'{n+1}*' if is_g else str(n)
        if is_g: lab = 'New<br><span style="font-weight:normal;color:#555">printed only when the lease has a guarantor</span>'
        rows.append(f'<tr class="{cls}"><td class="no">{oldno}</td><td class="txt">{left}</td><td class="no">{newno}</td><td class="txt">{right}</td><td class="tag {cls}t">{lab}</td></tr>')
        if o == 17:
            rows.append('<tr class="delc"><td class="no">18</td><td class="txt"><span class="del">' + esc(plain(old['18']['en'])) +
                        '</span></td><td class="no">—</td><td class="txt"><span class="muted">Removed. Merged into new clause 15.</span></td><td class="tag delct">Deleted</td></tr>')
    return '\n'.join(rows), n

LEG = '<div class="legend">Page 1 Rent line is the same in old and new: AED rent + Chiller Fees AED x = AED total. &nbsp;&nbsp; <span class="del">red, crossed out</span> = removed from old &nbsp;&nbsp; <span class="add">green</span> = added in new &nbsp;&nbsp; Figures shown with the default values (AED 500, 7 days, 4 persons, a single family).</div>'
def page(title, sub, with_g):
    body, n = table(with_g)
    return f'''<div class="title">{title}</div><div class="sub">{sub} &middot; {n} clauses in the new contract (old had 31)</div>{LEG}
<table class="cmp"><thead><tr><th class="no">Old<br>No.</th><th class="txt">OLD contract (2026 Tanacey Contract PP)</th><th class="no">New<br>No.</th><th class="txt">NEW contract (PP v2)</th><th class="tagh">Change</th></tr></thead>
<tbody>{body}</tbody></table>'''

css = '''body{font-family:dejavusans;font-size:8px;color:#000}
.title{font-size:14px;font-weight:bold;text-align:center}
.sub{font-size:9px;text-align:center;margin:3px 0 8px}
.legend{font-size:8.5px;margin-bottom:8px;text-align:center}
table.cmp{width:100%;border-collapse:collapse}
.cmp th{background:#2f3b4c;color:#fff;padding:4px;font-size:8.5px;border:1px solid #2f3b4c;text-align:left}
.cmp td{border:1px solid #999;padding:4px;vertical-align:top;line-height:1.35}
.cmp td.txt,.cmp th.txt{width:41%}
.cmp td.no,.cmp th.no{width:4%;text-align:center;font-weight:bold}
.cmp th.tagh,.cmp td.tag{width:10%;text-align:center}
.del{background:#fde2e2;color:#a30000;text-decoration:line-through}
.add{background:#dff5e1;color:#0b5d1e}
.muted{color:#777;font-style:italic}
.note{color:#8a5a00;font-size:7.5px}
.tag{font-weight:bold;font-size:7.5px}
.samet{color:#777}.amdt{color:#b06d00}.rept{color:#a30000}.newt{color:#0b5d1e}.delct{color:#a30000}.legalt{color:#8a5a00}
tr.same td{background:#fafafa}'''
legend = '<div class="legend"><span class="del">red, crossed out</span> = removed from old &nbsp;&nbsp; <span class="add">green</span> = added in new &nbsp;&nbsp; Amounts shown with default values (AED 500, 7 days, 4 persons, a single family).</div>'
out = f'''<html><head><style>{css}</style></head><body>
{page("Park Place Contract - Old vs New (PP v2)", "One contract. 30* = guarantor clause, printed only when the lease has a guarantor (then the clauses after it move down by one)", True)}
</body></html>'''
open(sys.argv[2], 'w', encoding='utf-8').write(out)
