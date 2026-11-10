# -*- coding: utf-8 -*-
import json, sys, re, html
m = json.load(open('map.json', encoding='utf-8'))['mapping']
ar = json.load(open('ar_draft.json', encoding='utf-8'))
h = open('/Users/ainalreem/Sites/saifsys/modules/realestate/contract_templates/tenancy_contract_v2.html', encoding='utf-8').read()
key = {'new - Rent until actual vacating': 'rent', 'new - Notices and addresses for service': 'notice',
       'new - Keys and access cards': 'keys', 'new - Guarantor (only when a guarantor is on the lease)': 'guar'}
order = [str(x['old']) if x['old'] else key[x['kind']] for x in m if x.get('pending') and x['en']]
marker = '<span class="ar-pending" dir="ltr">[AR – TRANSLATION PENDING – Clause {{CLAUSE_REF}}]</span>'
assert h.count(marker) == len(order), (h.count(marker), len(order))
parts = h.split(marker)
out = parts[0]
for i, k in enumerate(order):
    t = html.escape(ar[k]).replace('&lt;b&gt;', '<b>').replace('&lt;/b&gt;', '</b>').replace('\n', '<br>')
    out += t + parts[i + 1]
for (en, a), L in zip(ar['annex'], 'ABCD'):
    mk = f'<span class="ar-pending">[AR – TRANSLATION PENDING – Annex {L}]</span>'
    assert out.count(mk) == 1, L
    title = a.split('. ', 1)
    out = out.replace(mk, '<span dir="rtl" style="font-weight:bold">' + html.escape(title[0]) + '</span>'
                      + ('<div dir="rtl" style="font-size:9px;text-align:right">' + html.escape(title[1]) + '</div>' if len(title) > 1 else ''))
a = '<td class="label-ar" dir="rtl">المؤجر:</td>'; assert out.count(a) == 1
out = out.replace(a, '<td class="label-ar" dir="rtl">المؤجر (المالك):</td>')
a = 'as principal obligor, the due payment'; assert out.count(a) == 1
out = out.replace(a, 'as principal obligor, jointly and severally with the Tenant, the due payment')
vals = {'{{MINOR_REPAIR_THRESHOLD_WORDS_AR}}': 'خمسمائة', '{{CHEQUE_ADMIN_FEE_WORDS_AR}}': 'خمسمائة',
        '{{EJARI_REGISTRATION_DAYS_WORDS_AR}}': 'سبعة', '{{OCCUPANCY_TYPE_AR}}': 'عائلة واحدة',
        '{{LANDLORD_OFFICE_ADDRESS_AR}}': 'المكتب P01، أيلا ريزيدنس، البرشاء جنوب الرابعة، دبي'}
for k, v in vals.items(): out = out.replace(k, v)
open('preview_v2_arabic.html', 'w', encoding='utf-8').write(out)
print('ok', len(order), 'clauses filled; pending left:', out.count('TRANSLATION PENDING'))
