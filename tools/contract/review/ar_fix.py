# -*- coding: utf-8 -*-
import json, sys, re
p = sys.argv[1]
d = json.load(open(p, encoding='utf-8'))
log = []
def rep(k, a, b, n=1):
    global d
    c = d[k].count(a)
    assert c == n, (k, a, c)
    d[k] = d[k].replace(a, b); log.append(f'{k}: {c}x')
# Fix 1
assert d['13'].startswith('يُعتبر عقد الإيجار نافذًا ولا'), d['13'][:30]
d['13'] = 'لا ' + d['13']; log.append('13: negation added')
# Fix 2
rep('4', 'أقل من {{MINOR_REPAIR_THRESHOLD}} درهم', 'أقل من ({{MINOR_REPAIR_THRESHOLD}}) درهم ({{MINOR_REPAIR_THRESHOLD_WORDS_AR}} درهم)')
rep('4', 'تبلغ تكلفتها {{MINOR_REPAIR_THRESHOLD}} درهم أو أكثر', 'تبلغ تكلفتها ({{MINOR_REPAIR_THRESHOLD}}) درهم ({{MINOR_REPAIR_THRESHOLD_WORDS_AR}} درهم) أو أكثر')
rep('12', 'قدره {{CHEQUE_ADMIN_FEE}} درهم', 'قدره ({{CHEQUE_ADMIN_FEE}}) درهم ({{CHEQUE_ADMIN_FEE_WORDS_AR}} درهم)')
rep('13', 'خلال {{EJARI_REGISTRATION_DAYS}} أيام عمل', 'خلال مدة ({{EJARI_REGISTRATION_DAYS}}) ({{EJARI_REGISTRATION_DAYS_WORDS_AR}}) من أيام العمل')
# Fix 3
rep('21', 'ما لم يُثبت وجود ضرر محدد', 'ما لم يكن قد تم تسجيل ضرر محدد في الملحق (ج)')
# Fix 5
rep('17', 'على ألا يتجاوز عددهم {{MAX_OCCUPANTS}} أشخاص', 'على ألا يتجاوز عددهم ({{MAX_OCCUPANTS}}) من الأشخاص')
# Fix 6 (clause 13, 17(a), 26 = old 27)
part_a, rest = d['17'].split('\n', 1)
for k in ['13', '27']:
    d[k] = d[k].replace('للمالك', 'للمؤجر').replace('المالك', 'المؤجر')
part_a = part_a.replace('للمالك', 'للمؤجر').replace('المالك', 'المؤجر')
d['17'] = part_a + '\n' + rest
for k in ['13', '27']: assert 'المالك' not in d[k]
assert 'المالك' not in part_a
# Fix 7
rep('guar', 'بصفته مديناً أصلياً،', 'بصفته مديناً أصلياً وكفيلاً متضامناً مع المستأجر،')
# Fix 8
rep('notice', 'المكتب P01، أيلا ريزيدنس، البرشاء جنوب الرابعة، دبي', '{{LANDLORD_OFFICE_ADDRESS_AR}}')
# Fix 9
cnt = 0
for k, v in list(d.items()):
    if k == 'annex':
        d[k] = [[e, re.sub(r'الملحق ([أبجد])(?![)\w])', r'الملحق (\1)', a)] for e, a in v]
        continue
    new, n = re.subn(r'الملحق ([أبجد])(?=[)\s،.؛])', r'الملحق (\1)', v)
    d[k] = new; cnt += n
log.append(f'fix9: {cnt} annex refs bracketed')
# annex titles in draft had a bug: wrong letters. Rebuild them.
d['annex'] = [
 ['Annex A - Payment Schedule', 'الملحق (أ) - جدول الدفعات'],
 ['Annex B - Move-In Inspection Report', 'الملحق (ب) - تقرير معاينة الاستلام'],
 ['Annex C - Move-Out Inspection Report', 'الملحق (ج) - تقرير معاينة الإخلاء'],
 [d['annex'][3][0], re.sub(r'^الملحق \(?د\)?', 'الملحق (د)', d['annex'][3][1])],
]
json.dump(d, open(p, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print('\n'.join(log))
for k in d:
    if k == 'annex': continue
    for m in re.finditer(r'.{0,8}الملحق.{0,5}', d[k]):
        if not re.search(r'الملحق(ين)? \([أبجد]\)|بالملحقين \(|الملحقين \(', m.group(0)): print('CHECK', k, m.group(0))
