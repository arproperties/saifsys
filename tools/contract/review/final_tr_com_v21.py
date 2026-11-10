# -*- coding: utf-8 -*-
"""Translator file (HTML for the PDF) and docs/contract/commercial_translation_sheet.md for Ayla Commercial v2.1.

    python3 -I final_tr_com_v21.py <repo_root> <out.html>

Numbering is as in the v2.1 master: every conditional clause counted ([R] renewal fee, [F] food court,
[G] guarantor). {{REF:key}} references are shown with those numbers.
"""
import re, sys, os, json, html

ROOT, OUT = sys.argv[1:3]
T = os.path.join(ROOT, 'modules/realestate/contract_templates')
src = open(os.path.join(T, 'tenancy_contract_com_v2.html'), encoding='utf-8').read()
res = open(os.path.join(T, 'tenancy_contract_v2.html'), encoding='utf-8').read()
ar_draft = json.load(open(os.path.join(ROOT, 'tools/contract/review/data/ar_draft.json'), encoding='utf-8'))

body = src[src.find('<table class="clauses-table"'):src.find('<div class="signature-section">')]
keys = re.findall(r'\{\{CLAUSE_NO:([a-z0-9_]+)\}\}', body)
num = {k: i + 1 for i, k in enumerate(keys)}
flags = {}
for fl, tag in [('INCLUDE_RENEWAL_FEE', 'R'), ('USE_FOOD_COURT', 'F'), ('HAS_GUARANTOR', 'G')]:
    for m in re.finditer(r'\{\{#if ' + fl + r'\}\}\s*<tr class="clause-row">.*?\{\{CLAUSE_NO:([a-z0-9_]+)\}\}', body, re.S):
        flags[m.group(1)] = tag


def readable(t):
    t = re.sub(r'\{\{REF:([a-z0-9_]+)\}\}', lambda m: str(num[m.group(1)]), t)
    t = t.replace('{{#if USE_OFFICE}}{{else}}', '«[Shop / Food Court:]» ')
    t = t.replace('{{#if USE_OFFICE}}', '«[Office:]» ').replace('{{else}}', ' «[Shop / Food Court:]» ')
    t = t.replace('{{#if USE_FOOD_COURT}}', ' «[Food Court only:]» ').replace('{{/if}}', '')
    t = t.replace('&nbsp;', ' ').replace('<br>', '\n')
    t = re.sub(r'<(?!/?b>)[^>]+>', '', t)
    return re.sub(r'[ \t]+', ' ', html.unescape(t)).strip()


def plain(t):
    return re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', '', t.replace('<br>', ' ')))).strip()


# residential v2 English -> Arabic draft (only drafts still matching their English)
RES_KEY = {1: '1', 4: '4', 5: '5', 12: '12', 13: '13', 15: '15', 18: '19', 20: '21', 26: '27', 27: 'rent', 29: 'keys', 31: '29'}
res_rows = re.findall(r'<td class="txt-en">(.*?)</td>', res[res.find('<table class="clauses-table"'):], re.S)
res_drafts = {}
for i, en in enumerate(res_rows[:34], 1):
    if i in RES_KEY:
        res_drafts[plain(en)] = ar_draft[RES_KEY[i]]

rows = []
for m in re.finditer(r'\{\{CLAUSE_NO:([a-z0-9_]+)\}\}\.</td>\s*<td class="txt-en">(.*?)</td>.*?<td class="txt-ar" dir="rtl">(.*?)</td>', body, re.S):
    k, en, ar = m.groups()
    label = str(num[k]) + (' [' + flags[k] + ']' if k in flags else '')
    en_plain = re.sub(r'^<b>[^<]*</b>\s*', '', en)
    draft = res_drafts.get(plain(en_plain))
    guide = re.search(r'<!-- OLD ARABIC \([^)]*\), guide only:\n(.*?)\n-->', ar, re.S)
    if draft:
        rows.append((label, readable(en), draft, ('CHECK DRAFT', 'Same English as residential v2; Arabic draft from there. Please check.', 'check')))
    elif guide:
        rows.append((label, readable(en), '(old Arabic, guide only)\n' + guide.group(1).strip(), ('TRANSLATE', 'English changed. Old Arabic shown only as a guide.', 're')))
    else:
        rows.append((label, readable(en), '', ('TRANSLATE', 'New English.', 're')))

p1 = []
box = src[src.find('<table class="contract-details"'):src.find('</table>', src.find('<table class="contract-details"'))]
for tr in re.findall(r'<tr>(.*?)</tr>', box, re.S):
    lab = re.search(r'<td class="label">(.*?)</td>', tr, re.S).group(1)
    val = re.search(r'<td class="value">(.*?)</td>', tr, re.S).group(1)
    arl = re.search(r'<td class="label-ar" dir="rtl">(.*?)</td>', tr, re.S).group(1)
    if 'ar-pending' in arl:
        p1.append(('Contract details', 'Label: ' + lab + '\nPrints: ' + readable(val), '', ('TRANSLATE', 'New label on the first page (contract details box).', 're')))
    else:
        p1.append(('Contract details', 'Label: ' + lab + '\nPrints: ' + readable(val), arl.strip(), ('CHECK', 'Existing Arabic label. Check it still fits the English label.', 'check')))
p1.append(('Contract details', 'Printed values: "None — included in the rent" · "None, unless agreed by the Landlord in writing" · "None" · "{{FIT_OUT_DAYS}} days from the start date" · "(refundable under Clause 30)" · Shop · Office · Food Court', '',
           ('TRANSLATE', 'Values that can print in the box.', 're')))
annex = ar_draft['annex']
tail = [
    ('Title', 'COMMERCIAL TENANCY CONTRACT', '«وثيقة ايجار» (current)', ('TRANSLATE', 'Contract title.', 're')),
    ('Signature page', "I undertake to act in accordance with this Contract and its Conditions.", 'أتعهد بالعمل وفق هذه الاتفاقية وشروطها', ('UNCHANGED', 'Existing Arabic.', 'keep')),
    ('Signature page', 'Column titles: Landlord · Tenant · Guarantor\n"— by" · "Signature / stamp / date" · "Signature / date"\n"Tenant\'s phone · Tenant\'s email · Tenant\'s TRN"\nAttachments received at signing: Trade licence / initial approval · MOA / incorporation · Owner(s) EID + passport · Manager EID + passport · Signatory POA / board resolution · TRN certificate · Guarantor EID + passport', '', ('TRANSLATE', 'New signature page.', 're')),
    ('Annex A', annex[0][0] + '\nColumns: No. · Period from · Period to · Due date · Rent (AED) · VAT (AED) · Total (AED) · Cheque no. · Bank · Total\nNote: "The cheque dates above are the due dates of the respective instalments (Clause 12). Annual rent AED {{ANNUAL_RENT}} + VAT AED {{RENT_VAT_AMOUNT}} = AED {{GROSS_ANNUAL_RENT_INCL_RENT_VAT}}. All cheques are drawn on the Tenant\'s account {{TENANT_BANK_ACCOUNT_NAME}}."', annex[0][1], ('CHECK DRAFT', 'Title drafted; translate the columns and note.', 'check')),
    ('Annex B', annex[1][0] + '\nHandover date and time · Meter readings: DEWA (electricity) · DEWA (water) · Cooling · Gas · Room-by-room condition (Item · Condition · Photo ref.) · Non-original fittings (Fitting · Location · Tenant accepts maintenance Yes / No) · Keys and access cards (Key / card · Qty · Card numbers · Replacement cost AED) · Fit-out drawings approved: Yes, ref. ___ / Not yet · Tenant / Landlord\'s representative / Witness · Name / Signature / Date · "Tenant declined to attend / sign — witness name"\nAreas: Shopfront / entrance · Glass and shutters · Main area · Ceiling and lights · Walls and floor · Air-conditioning (base-build FCUs) · Pantry / kitchen · Toilet · Store / back area · [Food Court only:] Kitchen exhaust · Grease trap · Gas connection', annex[1][1], ('CHECK DRAFT', 'Title drafted; translate the rest.', 'check')),
    ('Annex C', annex[2][0] + '\nKey return date and time · Final meter readings · Change since move-in · Keys and access cards returned (Qty given · Qty returned · Missing / charge AED) · "Reinstatement under Clause 31: Complete / Incomplete (items: ___)" · "Tenant declined to attend / sign — witness name"', annex[2][1], ('CHECK DRAFT', 'Title drafted; translate the rest.', 'check')),
    ('Annex D', 'Annex D — Schedule of Rates\nIntro: "The Parties agree that the rates below are a fair pre-estimate of the cost of repair, replacement or cleaning of the listed items and shall be applied in the deduction statement under Clause 20. Items not listed are charged at the actual cost evidenced by a contractor\'s quotation or invoice. Rates include labour and materials and exclude VAT, which is added where applicable."\nColumns: Item · Unit · Rate (AED)\nItems: Painting: Repainting (per wall / per area) · Cleaning: Deep cleaning - shop / office / food court unit (per unit) · AC duct / filter cleaning (per FCU) · [F] Grease trap and exhaust cleaning (if not done by Tenant) (per service) · Shopfront: Glass panel replacement (per panel) · Shutter repair / motor (each) · Doors & hardware: Door handle / lock cylinder / interior door (each) · Electrical: Light fitting / switch / socket plate (each) · Plumbing: Mixer tap / toilet seat / WC (each) · Floor & ceiling: Floor tile / ceiling tile replacement (per m² / per tile) · Reinstatement: Removal of partitions / fit-out and making good (per m²) · Sign removal and making good (each) · Access: Key / access card / parking remote (each)', '', ('TRANSLATE', 'New commercial list.', 're')),
]
allrows = [tail[0]] + p1 + rows + tail[1:]

n = {k: sum(1 for r in allrows if r[3][2] == k) for k in ('keep', 'check', 're')}
COLOR = {'keep': '#eeeeee', 'check': '#fff3cd', 're': '#f8d7da'}
esc = lambda t: html.escape(t).replace('&lt;b&gt;', '<b>').replace('&lt;/b&gt;', '</b>').replace('\n', '<br>')
trs = ''.join(f'<tr style="background:{COLOR[st[2]]}"><td class="no">{esc(no)}</td><td class="en">{esc(en)}</td><td class="ar" dir="rtl" align="right">{esc(a) if a else "&nbsp;"}</td><td class="st"><b>{esc(st[0])}</b><br>{esc(st[1])}</td></tr>' for no, en, a, st in allrows)
page = f'''<html><head><meta charset="utf-8"><title>Ayla Commercial Contract v2.1 - for translation</title><style>
body{{font-family:dejavusans, Arial, sans-serif;font-size:9px}}
h1{{font-size:15px;text-align:center;margin:0 0 4px}} .sub{{text-align:center;font-size:9px;margin-bottom:8px}}
.box{{border:1px solid #888;padding:6px;margin-bottom:8px;font-size:9px;line-height:1.5}}
table{{width:100%;border-collapse:collapse}} th{{background:#2f3b4c;color:#fff;padding:4px;border:1px solid #2f3b4c}}
td{{border:1px solid #999;padding:5px;vertical-align:top;line-height:1.45}}
td.no{{width:7%;text-align:center;font-weight:bold}} td.en{{width:38%}} td.ar{{width:37%;text-align:right;font-size:10px}} td.st{{width:18%;font-size:8px}}
</style></head><body>
<h1>Ayla Commercial Contract v2.1 (shop / office / food court) – for translation</h1>
<div class="sub">Ain Al Reem Properties L.L.C. · English master of 10 November 2026 · the Arabic text prevails (Clause 39), so every row must be correct</div>
<div class="box"><b>How to use this file</b><br>
• <b>Red – Translate ({n['re']}):</b> new or changed English. Where the old Arabic is shown it is a guide only.<br>
• <b>Yellow – Check draft ({n['check']}):</b> Arabic draft prepared. Please check and correct it, or fill in where empty.<br>
• <b>Grey – Unchanged ({n['keep']}):</b> existing Arabic. No action.<br>
• Clause numbers are as printed with every clause included. <b>[R]</b> renewal fee, <b>[F]</b> food court only, <b>[G]</b> only when there is a guarantor. The system renumbers the clauses and the references when one is left out.<br>
• Text in «[...]» prints only for that use, e.g. «[Office:]». Please translate each version.<br>
• Keep every <b>{{{{...}}}}</b> marker exactly as it is (the system fills them in).</div>
<table><thead><tr><th>No.</th><th>English (final)</th><th>Arabic</th><th>Action</th></tr></thead><tbody>{trs}</tbody></table></body></html>'''
open(OUT, 'w', encoding='utf-8').write(page)

md = ['# Ayla Commercial Contract v2.1 - translation sheet', '',
      'English from the v2.1 master (10 Nov 2026). Generated by `tools/contract/review/final_tr_com_v21.py` from the template; do not edit by hand.',
      'Numbering as printed with every clause included ([R] renewal fee, [F] food court, [G] guarantor).', '',
      f'Totals: TRANSLATE {n["re"]} · CHECK DRAFT {n["check"]} · UNCHANGED {n["keep"]}', '',
      '| No. | English (final) | Arabic | Action |', '|---|---|---|---|']
cell = lambda t: t.replace('|', '\\|').replace('\n', '<br>')
for no, en, a, st in allrows:
    md.append(f'| {cell(no)} | {cell(re.sub(r"</?b>", "**", en))} | {cell(a)} | {st[0]} |')
open(os.path.join(ROOT, 'docs/contract/commercial_translation_sheet.md'), 'w', encoding='utf-8').write('\n'.join(md) + '\n')
print(len(allrows), 'rows', n, '| clauses', len(rows))
