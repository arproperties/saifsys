# -*- coding: utf-8 -*-
import re, html, difflib, sys
def load(p):
    h = open(p, encoding='utf-8').read()
    cl = {}
    for b in h.split('<tr class="clause-row">')[1:]:
        en = re.search(r'<td class="num-en">(\d+)\.</td>\s*<td class="txt-en">(.*?)</td>', b, re.S)
        ar = re.search(r'<td class="txt-ar" dir="rtl">(.*?)</td>', b, re.S)
        cl[int(en.group(1))] = (en.group(2).strip(), ar.group(1).strip() if ar else '')
    det = re.findall(r'<td class="label">(.*?)</td>\s*<td class="value">(.*?)</td>\s*<td class="label-ar" dir="rtl">(.*?)</td>', h, re.S)
    return h, cl, det
def plain(t): return html.unescape(re.sub(r'<[^>]+>', ' ', t.replace('<br>', '\n'))).replace(' \n ', '\n').strip()
def norm(t): return re.sub(r'(\w)\(', r'\1 (', t).split()
def esc(t): return html.escape(t).replace('\n', '<br>')
hp, pp, det = load(sys.argv[1]); ha, ay, _ = load(sys.argv[2])
rows = []; ndiff = 0
for n in sorted(pp):
    en, ar = plain(pp[n][0]), plain(pp[n][1])
    aen = plain(ay[n][0]) if n in ay else ''
    if norm(aen) == norm(en):
        tag = '<span class="same">Same as Ayla</span>'; en_h = esc(en)
    else:
        ndiff += 1
        a, b = aen.split(), en.split(); out = []
        for op, i1, i2, j1, j2 in difflib.SequenceMatcher(None, a, b, autojunk=False).get_opcodes():
            if op == 'equal': out.append(esc(' '.join(b[j1:j2])))
            elif j2 > j1: out.append('<span class="pp">' + esc(' '.join(b[j1:j2])) + '</span>')
        en_h = ' '.join(out); tag = '<span class="diff">Different from Ayla</span><br><span class="small">Park Place wording highlighted</span>'
    rows.append(f'<tr><td class="no">{n}</td><td class="en">{en_h}</td><td class="ar" dir="rtl">{esc(ar)}</td><td class="tag">{tag}</td></tr>')
drows = ''.join(f'<tr><td>{esc(plain(l))}</td><td>{esc(plain(v))}</td><td dir="rtl" class="ar">{esc(plain(a))}</td></tr>' for l, v, a in det)
tail = re.search(r'<div class="remarks-section">(.*?)</body>', hp, re.S).group(1)
tail_txt = esc(re.sub(r'\n\s*\n+', '\n', plain(tail)))
css = '''body{font-family:dejavusans;font-size:8.5px}
h1{font-size:15px;text-align:center;margin:0 0 3px}.sub{text-align:center;font-size:9px;margin-bottom:8px}
.box{border:1px solid #888;padding:6px;margin-bottom:8px;font-size:9px;line-height:1.5}
table{width:100%;border-collapse:collapse;margin-bottom:10px}
th{background:#2f3b4c;color:#fff;padding:4px;border:1px solid #2f3b4c}
td{border:1px solid #999;padding:5px;vertical-align:top;line-height:1.45}
td.no{width:5%;text-align:center;font-weight:bold}td.en{width:42%}td.ar{width:42%;text-align:right;font-size:9.5px}td.tag{width:11%;text-align:center;font-size:8px}
.same{color:#777}.diff{color:#a30000;font-weight:bold}.pp{background:#ffe9a8}.small{font-size:7px;color:#555}
h2{font-size:11px;margin:8px 0 4px}'''
out = f'''<html><head><style>{css}</style></head><body>
<h1>Park Place Tenancy Contract – current template (for review)</h1>
<div class="sub">"2026 Tanacey Contract PP" · Ain Al Reem Properties L.L.C. · exported from the system 9 November 2026 · {len(pp)} clauses</div>
<div class="box"><b>About this file</b><br>
• This is the Park Place contract exactly as the system prints it today. Nothing has been changed.<br>
• Words in {{{{...}}}} are filled in from the lease when the contract is printed.<br>
• The last column compares each clause with the current Ayla contract ("2026 Tanacey Contract", before v2): all {len(pp)} clauses have the same English and Arabic as the old Ayla contract (only clause 14 differs slightly: a missing space "three(3)" in English, and «ثلاث» instead of «ثلاثة» in Arabic). The one real difference is the <b>Rent line on page 1</b>: Park Place prints rent + chiller fees = total.<br>
• The Ayla contract has since been revised (v2: Law 26/2007 review, Annexes A–D). The same review points may apply here.</div>
<h2>Page 1 – contract details</h2>
<table><tr><th>Label</th><th>Value printed</th><th>Arabic label</th></tr>{drows}</table>
<h2>Conditions</h2>
<table><thead><tr><th>No.</th><th>English</th><th>Arabic</th><th>vs Ayla</th></tr></thead><tbody>{''.join(rows)}</tbody></table>
<h2>After the clauses (remarks and signatures)</h2>
<div class="box">{tail_txt}</div>
</body></html>'''
open(sys.argv[3], 'w', encoding='utf-8').write(out)
print('clauses', len(pp), 'different from Ayla:', ndiff, [n for n in sorted(pp) if norm(plain(pp[n][0])) != norm(plain(ay.get(n, ('', ''))[0]))])
