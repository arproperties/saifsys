# -*- coding: utf-8 -*-
import json, sys, re
p = sys.argv[1]
d = json.load(open(p, encoding='utf-8'))
n_inner = 0
for k, v in list(d.items()):
    if k == 'annex':
        continue
    v2, n = re.subn(r'\(الملحق \(([أبجد])\)\)', r'(الملحق \1)', v); n_inner += n
    d[k] = v2
c = d['29'].count('بين المالك والمستأجر'); assert c == 1, c
d['29'] = d['29'].replace('بين المالك والمستأجر', 'بين المؤجر والمستأجر')
json.dump(d, open(p, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print('inner brackets removed:', n_inner)
allt = ' '.join(v for k, v in d.items() if k != 'annex')
print('left (الملحق (X)):', len(re.findall(r'\(الملحق \([أبجد]\)\)', allt)))
print('standalone without brackets:', re.findall(r'(?<!\()الملحق [أبجد](?![\w)])', allt))
for m in re.finditer(r'.{0,10}\(الملحق [أبجد]\).{0,3}|.{0,6}الملحق(?:ين|ان)? \([أبجد]\).{0,8}', allt): print('  ', m.group(0))
