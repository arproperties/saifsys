# -*- coding: utf-8 -*-
import re, sys, zipfile, html
from xml.sax.saxutils import escape as x
src = open(sys.argv[1], encoding='utf-8').read()
rows = re.findall(r'<tr style="background:(#\w+)"><td class="no">(.*?)</td><td class="en">(.*?)</td><td class="ar"[^>]*>(.*?)</td><td class="st">(.*?)</td></tr>', src, re.S)
box = re.search(r'<div class="box">(.*?)</div>', src, re.S).group(1)
def lines(h):
    h = h.replace('<br>', '\n')
    parts = re.split(r'(<b>.*?</b>)', h, flags=re.S)
    out = []  # list of (text, bold)
    for p in parts:
        if not p: continue
        b = p.startswith('<b>')
        t = html.unescape(re.sub(r'<[^>]+>', '', p))
        out.append((t, b))
    return out
def para(h, rtl=False, size=18, align=None):
    runs = ''
    first = True
    xml = ''
    segs = lines(h)
    # split on newlines into separate paragraphs
    paras = [[]]
    for t, b in segs:
        bits = t.split('\n')
        for i, bit in enumerate(bits):
            if i > 0: paras.append([])
            if bit: paras[-1].append((bit, b))
    for p in paras:
        ppr = '<w:pPr>' + ('<w:bidi/>' if rtl else '') + (f'<w:jc w:val="{align}"/>' if align else ('<w:jc w:val="right"/>' if rtl else '')) + '<w:spacing w:after="40"/></w:pPr>'
        rs = ''
        for t, b in p:
            rpr = '<w:rPr>' + ('<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>') + ('<w:b/><w:bCs/>' if b else '') + ('<w:rtl/>' if rtl else '') + f'<w:sz w:val="{size}"/><w:szCs w:val="{size+2}"/></w:rPr>'
            rs += f'<w:r>{rpr}<w:t xml:space="preserve">{x(t)}</w:t></w:r>'
        xml += f'<w:p>{ppr}{rs}</w:p>'
    return xml or '<w:p/>'
def cell(content, w, fill=None):
    shd = f'<w:shd w:val="clear" w:color="auto" w:fill="{fill}"/>' if fill else ''
    return f'<w:tc><w:tcPr><w:tcW w:w="{w}" w:type="dxa"/>{shd}</w:tcPr>{content}</w:tc>'
W = [700, 5600, 5600, 2400]
hdr = '<w:tr><w:trPr><w:tblHeader/></w:trPr>' + ''.join(cell(para(f'<b>{t}</b>', size=18), w, '2F3B4C') for t, w in zip(['No.', 'English (final)', 'Arabic', 'Action'], W)) + '</w:tr>'
hdr = hdr.replace('<w:rPr><w:rFonts', '<w:rPr><w:color w:val="FFFFFF"/><w:rFonts')
body = ''
for bg, no, en, ar, st in rows:
    fill = bg.lstrip('#').upper()
    body += '<w:tr><w:trPr><w:cantSplit/></w:trPr>' + cell(para(f'<b>{no}</b>', align='center'), W[0], fill) + cell(para(en), W[1], fill) + cell(para(ar if ar.strip() != '&nbsp;' else '', rtl=True, size=20), W[2], fill) + cell(para(st, size=16), W[3], fill) + '</w:tr>'
borders = '<w:tblBorders>' + ''.join(f'<w:{b} w:val="single" w:sz="4" w:space="0" w:color="999999"/>' for b in ['top', 'left', 'bottom', 'right', 'insideH', 'insideV']) + '</w:tblBorders>'
table = f'<w:tbl><w:tblPr><w:tblW w:w="{sum(W)}" w:type="dxa"/>{borders}<w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid>' + ''.join(f'<w:gridCol w:w="{w}"/>' for w in W) + f'</w:tblGrid>{hdr}{body}</w:tbl>'
title = para('<b>Tenancy Contract v2 (Ayla residential) – for translation</b>', size=28, align='center')
sub = para('Ain Al Reem Properties L.L.C. · prepared 9 November 2026 · the Arabic text prevails (clause 32), so every row must be correct', size=16, align='center')
doc = f'''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>{title}{sub}{para(box, size=17)}{table}
<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/><w:pgMar w:top="567" w:right="567" w:bottom="567" w:left="567" w:header="300" w:footer="300" w:gutter="0"/></w:sectPr></w:body></w:document>'''
ct = '''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>'''
rels = '''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>'''
with zipfile.ZipFile(sys.argv[2], 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('[Content_Types].xml', ct); z.writestr('_rels/.rels', rels); z.writestr('word/document.xml', doc)
print(len(rows), 'rows written')
