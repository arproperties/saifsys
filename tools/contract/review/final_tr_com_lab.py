# -*- coding: utf-8 -*-
"""Translator files for the commercial and labour v2 contracts (read straight from the template HTML).

    python3 -I final_tr_com_lab.py <templates_dir> <data_dir> <out_com.html> <out_lab.html>

Rows: grey = existing Arabic kept, yellow = Arabic draft from the residential v2 work (check it),
red = new or changed English (translate). Part 1 is the full first contract; later parts list only
the rows that differ from it.
"""
import json, re, sys, html, os

SINGLE = len(sys.argv) > 1 and sys.argv[1] == 'single'
if SINGLE:
    # python3 -I final_tr_com_lab.py single <templates_dir> <data_dir> <template_code> <out.html> "<title>"
    TPL, DATA, SINGLE_CODE, SINGLE_OUT, SINGLE_TITLE = sys.argv[2:7]
else:
    TPL, DATA, OUT_COM, OUT_LAB = sys.argv[1:5]
ar_draft = json.load(open(os.path.join(DATA, 'ar_draft.json'), encoding='utf-8'))

S_KEEP = ('Unchanged', 'Existing Arabic. No action needed.', 'keep')
S_CHECK = ('Check draft', 'Same English as the residential v2. Arabic draft prepared there; please check and correct.', 'check')
S_NEW = ('TRANSLATE', 'New English. Please translate.', 're')
S_GUIDE = ('TRANSLATE', 'English changed. Please translate the new English. The old Arabic is shown only as a guide.', 're')
S_PART = ('', '', 'part')


def read(name):
    return open(os.path.join(TPL, name + '.html'), encoding='utf-8').read()


def show_flags(t):
    """Make the {{#if}} parts readable for the translator."""
    lab = {
        'USE_OFFICE': ('[Office:] ', ' [Shop / Food Court:] '),
        'USE_FOOD_COURT': ('[Food Court only:] ', ' [Otherwise:] '),
        'HAS_CHILLER': ('[If the Landlord bills chiller:] ', ' [If not:] '),
    }
    def f(m):
        name, body = m.group(1), m.group(2)
        a, b = (body.split('{{else}}', 1) + [''])[:2]
        on, off = lab.get(name, ('[' + name + ':] ', ' [else:] '))
        out = ''
        if a.strip():
            out += '«' + on.strip() + '» ' + a.strip()
        if b.strip():
            out += (' ' if out else '') + '«' + off.strip() + '» ' + b.strip()
        return ' ' + out + ' '
    return re.sub(r'\{\{#if\s+([A-Z_]+)\}\}(.*?)\{\{/if\}\}', f, t, flags=re.S)


def plain(t):
    t = t.replace('&nbsp;', ' ').replace('<br>', '\n')
    t = re.sub(r'<(?!/?b>)[^>]+>', '', t)
    t = re.sub(r'[ \t]+', ' ', html.unescape(t))
    return t.strip()


def clause_rows(src):
    """[(label, en_raw, ar_cell_raw)] in order; row-level {{#if}} blocks give '*' (guarantor) or 'F' (food court)."""
    body = src[src.find('<table class="clauses-table"'):src.find('<div class="remarks-section">')]
    # mark row-level blocks
    rows = []
    n = 0
    pos = 0
    block = None
    tokens = re.finditer(r'\{\{#if\s+([A-Z_]+)\}\}\s*<tr class="clause-row">|<tr class="clause-row">|\{\{/if\}\}\s*(?=\n)', body)
    for m in tokens:
        tok = m.group(0)
        if tok.startswith('{{/if'):
            block = None
            continue
        if tok.startswith('{{#if'):
            block = m.group(1)
        end = body.find('</tr>\n', body.find('class="clause-col-ar"', m.end()))
        chunk = body[m.end():end]
        en = re.search(r'<td class="txt-en">(.*?)</td>', chunk, re.S).group(1)
        ar = re.search(r'<td class="txt-ar" dir="rtl">(.*?)</td>', chunk, re.S).group(1)
        if block == 'HAS_GUARANTOR':
            label = str(n + 1) + '*'
        elif block == 'USE_FOOD_COURT':
            label = str(n + 1) + 'F'
        else:
            n += 1
            label = str(n)
        rows.append((label, en, ar))
    return rows


# Residential v2: English -> Arabic draft key (clauses whose draft still matches the English).
RES_KEY = {1: '1', 4: '4', 5: '5', 12: '12', 13: '13', 15: '15', 18: '19', 20: '21', 26: '27', 27: 'rent', 29: 'keys', 31: '29'}
res_drafts = {}
for label, en, ar in clause_rows(read('tenancy_contract_v2')):
    k = 'guar' if label.endswith('*') else RES_KEY.get(int(label))
    if k:
        res_drafts[plain(en)] = ar_draft[k]


def build(code):
    out = []
    for label, en, ar in clause_rows(read(code)):
        en_p = plain(show_flags(en))
        if k := ('guar' if label.endswith('*') else None):
            en_p = en_p.replace('as principal obligor,', 'as principal obligor, jointly and severally with the Tenant,')
        if 'TRANSLATION PENDING' not in ar:
            out.append((label, en_p, plain(re.sub(r'<!--.*?-->', '', ar, flags=re.S)), S_KEEP))
            continue
        draft = res_drafts.get(plain(en))
        if label.endswith('*'):
            draft = ar_draft['guar']
        if draft:
            out.append((label, en_p, draft, S_CHECK))
            continue
        guide = re.search(r'<!-- OLD ARABIC \(([^)]*)\), kept for the translator:\n(.*?)\n-->', ar, re.S)
        if guide and 'different subject' not in guide.group(1) and guide.group(1) != 'unused' and 'v1' not in guide.group(1):
            out.append((label, en_p, '(old Arabic, guide only)\n' + guide.group(2).strip(), S_GUIDE))
        else:
            out.append((label, en_p, '', S_NEW))
    return out


def page1(code):
    src = read(code)
    rows = []
    for lab in re.findall(r'<td class="label">([^<]+)</td>\s*<td class="value">[^\n]*</td>\s*<td class="label-ar" dir="rtl"><span class="ar-pending"', src):
        rows.append(('Page 1', 'Label: ' + lab, '', ('TRANSLATE', 'New page-1 label.', 're')))
    return rows


def diff_rows(base, other):
    seen = {r[1] for r in base}
    return [r for r in other if r[1] not in seen]


annex = ar_draft['annex']
COMMON_EXTRA = [
    ('Title', '', '«وثيقة ايجار» (current Arabic title)', ('Check', 'Please give the Arabic title for this contract type (English title above).', 'check')),
    ('Page 1', 'Terms of Payment value: {{PAYMENT_SUMMARY}}  (prints e.g. "4 instalments as per Annex A")', '', ('TRANSLATE', 'Value printed under «اقساط الدفع».', 're')),
    ('Sign page', "Guarantor's Signature:  (printed only when a guarantor is on the lease)", '', ('TRANSLATE', 'New label.', 're')),
    ('Annex A', annex[0][0] + '\nNote: "The cheque dates above are the due dates of the respective instalments (Clause 12)."', annex[0][1], ('Check draft', 'Title drafted; please also translate the note.', 'check')),
    ('Annex C', annex[2][0] + '\nSection titles: Key return date and time · Final meter readings · Room-by-room condition / Change since move-in · Keys and access cards returned · Tenant / Landlord\'s representative / Witness', annex[2][1], ('Check draft', 'Title drafted; please translate the section titles.', 'check')),
    ('Annex D', annex[3][0] + '\nColumns: Item · Unit · Rate (AED). Item and category names as listed in the annex.', annex[3][1], ('Check draft', 'Heading drafted; please translate the column and item names.', 'check')),
]
COM_EXTRA = [
    ('Title', 'COMMERCIAL TENANCY CONTRACT', '', ('TRANSLATE', 'Contract title.', 're')),
    ('Page 1', 'Values of "Use of Premises": Shop · Office · Food Court\nValues of "Fit-out Period": "None" · "{{FIT_OUT_DAYS}} days from the start date"', '', ('TRANSLATE', 'Printed values.', 're')),
    ('Annex A', 'Second note (commercial): "Annual rent AED {{ANNUAL_RENT}} + VAT AED {{RENT_VAT_AMOUNT}} = AED {{GROSS_ANNUAL_RENT_INCL_RENT_VAT}}. The amounts above include VAT as set on the lease."', '', ('TRANSLATE', 'New note.', 're')),
    ('Annex B', annex[1][0] + '\nSection titles: Handover date and time · Meter readings · Room-by-room condition · Non-original fittings / Tenant accepts maintenance · Keys and access cards / Replacement cost (AED) · Tenant / Landlord\'s representative / Witness\nArea names: Shopfront / entrance · Glass and shutters · Main area · Ceiling and lights · Walls and floor · Air-conditioning · Pantry / kitchen · Toilet · Store / back area', annex[1][1], ('Check draft', 'Title drafted; please translate the section titles and area names (no Occupants section in commercial).', 'check')),
]
LAB_EXTRA = [
    ('Title', 'LABOUR ACCOMMODATION TENANCY CONTRACT', '', ('TRANSLATE', 'Contract title.', 're')),
    ('Page 1', 'Value of "Premises No (DEWA)": "As per Annex E"\nValue of "Rooms": "{{ROOM_COUNT}} room(s), listed in Annex E"', '', ('TRANSLATE', 'Printed values.', 're')),
    ('Annex B', annex[1][0] + '\nSection titles: Handover date and time · Meter readings · Room-by-room condition · Non-original fittings / Tenant accepts maintenance · Keys and access cards / Replacement cost (AED) · Tenant / Landlord\'s representative / Witness\nItem names: Room doors and locks · Beds and mattresses · Lockers / cupboards · Air-conditioning · Lights and sockets · Walls, floor and ceiling · Windows · Toilets and showers · Corridors and stairs', annex[1][1], ('Check draft', 'Title drafted; please translate the section titles and item names.', 'check')),
    ('Annex E', 'Annex E - List of Rooms\nColumns: Room · DEWA premises no. · "Total rooms: {{ROOM_COUNT}}"\nSub-title: "{{ROOM_COUNT}} room(s), up to {{PERSONS_PER_ROOM}} persons per room"', '', ('TRANSLATE', 'New annex.', 're')),
]


def part(title):
    return ('', title, '', S_PART)


def esc(t):
    return html.escape(t).replace('&lt;b&gt;', '<b>').replace('&lt;/b&gt;', '</b>').replace('\n', '<br>')


COLOR = {'keep': '#eeeeee', 'check': '#fff3cd', 're': '#f8d7da', 'part': '#cfe2ff'}


def write(path, title, intro, rows):
    n = {k: sum(1 for r in rows if r[3][2] == k) for k in ('keep', 'check', 're')}
    body = ''
    for num, en, a, st in rows:
        if st[2] == 'part':
            body += f'<tr style="background:{COLOR["part"]}"><td class="no">&nbsp;</td><td class="en"><b>{esc(en)}</b></td><td class="ar" dir="rtl" align="right">&nbsp;</td><td class="st">&nbsp;</td></tr>'
            continue
        body += (f'<tr style="background:{COLOR[st[2]]}"><td class="no">{esc(num)}</td><td class="en">{esc(en)}</td>'
                 f'<td class="ar" dir="rtl" align="right">{esc(a) if a else "&nbsp;"}</td><td class="st"><b>{esc(st[0])}</b><br>{esc(st[1])}</td></tr>')
    out = f'''<html><head><meta charset="utf-8"><title>{html.escape(title)}</title><style>
body{{font-family:dejavusans, Arial, sans-serif;font-size:9px}}
h1{{font-size:15px;text-align:center;margin:0 0 4px}}
.sub{{text-align:center;font-size:9px;margin-bottom:8px}}
.box{{border:1px solid #888;padding:6px;margin-bottom:8px;font-size:9px;line-height:1.5}}
table{{width:100%;border-collapse:collapse}}
th{{background:#2f3b4c;color:#fff;padding:4px;border:1px solid #2f3b4c}}
td{{border:1px solid #999;padding:5px;vertical-align:top;line-height:1.45}}
td.no{{width:6%;text-align:center;font-weight:bold}} td.en{{width:38%}} td.ar{{width:38%;text-align:right;font-size:10px}} td.st{{width:18%;font-size:8px}}
</style></head><body>
<h1>{html.escape(title)}</h1>
<div class="sub">Ain Al Reem Properties L.L.C. · prepared 10 November 2026 · the Arabic text prevails, so every row must be correct</div>
<div class="box"><b>How to use this file</b><br>
• <b>Grey – Unchanged ({n['keep']}):</b> existing Arabic from the current contract. No action.<br>
• <b>Yellow – Check draft ({n['check']}):</b> Arabic draft prepared. Please check and correct it, or fill in where empty.<br>
• <b>Red – Translate ({n['re']}):</b> new or changed English. Please translate.<br>
• <b>Blue rows</b> start a part. {intro}<br>
• Keep every <b>{{{{...}}}}</b> marker exactly as it is (the system fills them in).<br>
• Text in «[...]» shows wording printed only in some cases, e.g. «[Office:]» = printed only for an office. Please translate each version.<br>
• Clause numbers are as printed without a guarantor. <b>*</b> = guarantor clause, printed only when there is a guarantor; <b>F</b> = printed only for a food court. Later clauses then move down by one.</div>
<table><thead><tr><th>No.</th><th>English (final)</th><th>Arabic</th><th>Action</th></tr></thead><tbody>{body}</tbody></table>
</body></html>'''
    open(path, 'w', encoding='utf-8').write(out)
    print(os.path.basename(path), len(rows), 'rows', n)


if SINGLE:
    # One full contract on its own (commercial codes only).
    rows = [part('Full contract')] + COM_EXTRA[:1] + page1(SINGLE_CODE) + COM_EXTRA[1:2] + build(SINGLE_CODE) + COMMON_EXTRA[1:4] + COM_EXTRA[2:] + COMMON_EXTRA[4:]
    write(SINGLE_OUT, SINGLE_TITLE, 'This file is the full contract.', rows)
    sys.exit(0)

# ---- Commercial: Ayla full, then the other three: only what differs ----
ayla = build('tenancy_contract_com_v2')
rows = [part('PART 1 – Ayla Commercial (shop / office / food court): full contract')] + COM_EXTRA[:1] + page1('tenancy_contract_com_v2') + COM_EXTRA[1:2] + ayla + COMMON_EXTRA[1:4] + COM_EXTRA[2:] + COMMON_EXTRA[4:]
for code, name in [('tenancy_contract_pp_com_v2', 'Park Place Commercial'), ('tenancy_contract_gc_com_v2', 'Gents Camp Commercial'),
                   ('tenancy_contract_lc_com_v2', 'Ladies Camp Commercial')]:
    extra = [r for r in page1(code) if r[1] not in {x[1] for x in page1('tenancy_contract_com_v2')}]
    rows += [part('PART – ' + name + ': only the rows that differ from Ayla Commercial (all other rows are the same as Part 1)')] + extra + diff_rows(ayla, build(code))
write(OUT_COM, 'Commercial Contracts v2 (Ayla, Park Place, Gents Camp, Ladies Camp) – for translation',
      'Part 1 is the full Ayla Commercial contract. The other parts list only the rows that are different in that building.', rows)

# ---- Labour: Gents full, then Ladies differences ----
gents = build('tenancy_contract_gc_lab_v2')
rows = [part('PART 1 – Gents Camp Labour Accommodation: full contract')] + LAB_EXTRA[:1] + page1('tenancy_contract_gc_lab_v2') + LAB_EXTRA[1:2] + gents + COMMON_EXTRA[1:4] + LAB_EXTRA[2:] + COMMON_EXTRA[4:]
rows += [part('PART 2 – Ladies Camp Labour Accommodation: only the rows that differ from Gents Camp (all other rows are the same as Part 1)')] + diff_rows(gents, build('tenancy_contract_lc_lab_v2'))
write(OUT_LAB, 'Labour Accommodation Contracts v2 (Gents Camp, Ladies Camp) – for translation',
      'Part 1 is the full Gents Camp contract. Part 2 lists only the rows that are different for the Ladies Camp.', rows)
