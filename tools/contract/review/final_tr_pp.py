# -*- coding: utf-8 -*-
import json, sys, re, html
d = json.load(open('map_pp.json', encoding='utf-8')); m, old = d['mapping'], d['old']
ar = json.load(open('ar_draft.json', encoding='utf-8'))
key = {'new - Rent until actual vacating': 'rent', 'new - Notices and addresses for service': 'notice',
       'new - Keys and access cards': 'keys', 'new - Guarantor (only when a guarantor is on the lease)': 'guar'}
def en_plain(t):
    t = t.replace('&nbsp;', ' ').replace('<br>', '\n')
    t = re.sub(r'<(?!/?b>)[^>]+>', '', t)
    return html.unescape(t).strip()
def esc(t):
    return html.escape(t).replace('&lt;b&gt;', '<b>').replace('&lt;/b&gt;', '</b>').replace('\n', '<br>')
S_KEEP = ('Unchanged', 'Existing Arabic. No action needed.', 'keep')
S_CHECK = ('Check draft', 'Arabic draft prepared. Please check and correct.', 'check')
S_RE = ('TRANSLATE AGAIN', 'English changed after the draft was made. Please translate the new English. The old draft is shown only as a guide.', 're')
rows = []
for x in m:
    if 'deleted' in x['kind']:
        continue
    k = str(x['old']) if x['old'] else key[x['kind']]
    num = str(x['new']) if x['new'] else f"{x['position_after']+1}*"
    en = en_plain(x['en'])
    if x.get('pending'):
        a = ar[k]
        st = S_RE if k in ('17', 'notice') else S_CHECK
        if k in ('19', '21'):
            st = ('TRANSLATE AGAIN (Park Place)', 'Park Place wording (chiller billed by the Landlord) differs from the draft. Please translate the new English; the draft is only a guide.', 're')
        if k == '17':
            st = ('TRANSLATE AGAIN (b) only', 'Sub-clause (b) English changed after the draft. Please translate the new (b). (a) and (c): check the draft.', 're')
    else:
        a = html.unescape(re.sub(r'<[^>]+>', '', old[k]['ar'])).strip()
        st = S_KEEP
    if k == 'guar':
        en = en.replace('as principal obligor,', 'as principal obligor, jointly and severally with the Tenant,')
    rows.append((num, en, a, st))
annex = ar['annex']
extra = [
 ('Page 1', 'Rent: AED {{ANNUAL_RENT}} + Chiller Fees AED {{CHILLER_FEES}} = AED {{ANNUAL_RENT_WITH_CHILLER}}', '', ('Unchanged', 'Same as the current Park Place contract (Arabic label «قيمة الايجار:» exists). No action.', 'keep')),
 ('Page 1', 'Terms of Payment: {{PAYMENT_SUMMARY}}  (prints e.g. "4 instalments as per Annex A")', '', ('Translate', 'New wording for the page-1 Terms of Payment value. The label «اقساط الدفع» already exists.', 'check')),
 ('Sign page', "Guarantor's Signature:  (printed only when a guarantor is on the lease)", '', ('Translate', 'New label.', 'check')),
 ('Annex A', annex[0][0] + '\nNote under the table: "The cheque dates above are the due dates of the respective instalments (Clause 12)."', annex[0][1], ('Check draft', 'Title drafted; please also translate the note.', 'check')),
 ('Annex B', annex[1][0] + '\nSection titles: Handover date and time · Meter readings · Room-by-room condition · Non-original fittings / Tenant accepts maintenance · Keys and access cards / Replacement cost (AED) · Occupants · "Maximum occupants under Clause 17(b): {{MAX_OCCUPANTS}}" · Tenant / Landlord\'s representative / Witness', annex[1][1], ('Check draft', 'Title drafted; please translate the section titles.', 'check')),
 ('Annex C', annex[2][0] + '\nSection titles: Key return date and time · Final meter readings · Room-by-room condition / Change since move-in · Keys and access cards returned · signatures', annex[2][1], ('Check draft', 'Title drafted; please translate the section titles.', 'check')),
 ('Annex D', annex[3][0] + '\nColumns: Item · Unit · Rate (AED). Item and category names as listed in the annex.', annex[3][1], ('Check draft', 'Heading drafted; please translate the column and item names.', 'check')),
]
for e in extra:
    rows.append(e)
n_keep = sum(1 for r in rows if r[3][2] == 'keep'); n_check = sum(1 for r in rows if r[3][2] == 'check'); n_re = sum(1 for r in rows if r[3][2] == 're')
color = {'keep': '#eeeeee', 'check': '#fff3cd', 're': '#f8d7da'}
body = ''.join(f'<tr style="background:{color[st[2]]}"><td class="no">{esc(num)}</td><td class="en">{esc(en)}</td><td class="ar" dir="rtl" align="right">{esc(a) if a else "&nbsp;"}</td><td class="st"><b>{esc(st[0])}</b><br>{esc(st[1])}</td></tr>' for num, en, a, st in rows)
out = f'''<html><head><meta charset="utf-8"><title>Park Place Tenancy Contract v2 - for translation</title><style>
body{{font-family:dejavusans, Arial, sans-serif;font-size:9px}}
h1{{font-size:15px;text-align:center;margin:0 0 4px}}
.sub{{text-align:center;font-size:9px;margin-bottom:8px}}
.box{{border:1px solid #888;padding:6px;margin-bottom:8px;font-size:9px;line-height:1.5}}
table{{width:100%;border-collapse:collapse}}
th{{background:#2f3b4c;color:#fff;padding:4px;border:1px solid #2f3b4c}}
td{{border:1px solid #999;padding:5px;vertical-align:top;line-height:1.45}}
td.no{{width:6%;text-align:center;font-weight:bold}} td.en{{width:38%}} td.ar{{width:38%;text-align:right;font-size:10px}} td.st{{width:18%;font-size:8px}}
</style></head><body>
<h1>Park Place Tenancy Contract v2 (PP v2) – for translation</h1>
<div class="sub">Ain Al Reem Properties L.L.C. · prepared 9 November 2026 · the Arabic text prevails (clause 32), so every row must be correct</div>
<div class="box"><b>How to use this file</b><br>
• <b>Grey – Unchanged ({n_keep}):</b> existing Arabic from the current contract. No action.<br>
• <b>Yellow – Check draft ({n_check}):</b> Arabic draft prepared. Please check and correct it, or fill in where empty.<br>
• <b>Red – Translate again ({n_re}):</b> the English changed after the draft. Please translate the new English.<br>
• Keep every <b>{{{{...}}}}</b> marker exactly as it is (the system fills them in, e.g. {{{{MAX_OCCUPANTS}}}} → 4). Amounts in words in Arabic use the _WORDS_AR markers.<br>
• Clause numbers are as printed without a guarantor. <b>30*</b> = guarantor clause, printed only when there is a guarantor (later clauses then move down by one).<br>
• Park Place v2 is the same contract as Ayla v2, except that the chiller is billed by the Landlord (page 1 Rent, clauses 18 and 20(b)). If the Ayla v2 translation is already done, reuse it and only translate the red rows here.<br>• Points to confirm: clause 13 now starts «لا يُعتبر» (the old Arabic said the opposite of the English); number wording «({{{{MAX_OCCUPANTS}}}}) من الأشخاص» and «({{{{EJARI_REGISTRATION_DAYS}}}}) … من أيام العمل»; guarantor «كفيلاً متضامناً»; annex references «الملحق (ب)» / «(الملحق ب)».</div>
<table><thead><tr><th>No.</th><th>English (final)</th><th>Arabic</th><th>Action</th></tr></thead><tbody>{body}</tbody></table>
</body></html>'''
open(sys.argv[1], 'w', encoding='utf-8').write(out)
print(len(rows), 'rows', n_keep, n_check, n_re)
