# -*- coding: utf-8 -*-
"""Builds Ayla Commercial v2.1 (modules/realestate/contract_templates/tenancy_contract_com_v2.html).

    python3 -I tools/contract/review/build_com_v2_1.py <repo_root>

English: the v2.1 master (Downloads/Ayla_Commercial_Tenancy_Contract_v2.1_EN.pdf, 10 Nov 2026), verbatim.
Layout, styles, header/footer and the two-column bilingual rows are kept from the v2 template.
Every clause changed, so every Arabic cell is TRANSLATION PENDING; the v2 Arabic is kept in a comment
("old Arabic, guide only"). Clause numbers and cross-references are filled at render time:
{{CLAUSE_NO:key}} numbers a clause, {{REF:key}} prints that clause's number.
"""
import re, sys, os

ROOT = sys.argv[1]
PATH = os.path.join(ROOT, 'modules/realestate/contract_templates/tenancy_contract_com_v2.html')
src = open(PATH, encoding='utf-8').read()
if 'v2.1' in src[:2000]:
    sys.exit('already v2.1: rebuild from git (git show HEAD:...) or the v2 copy first')

PENDING = '<span class="ar-pending" dir="ltr">[AR – TRANSLATION PENDING – Clause {{CLAUSE_REF}}]</span>'
AR_P = '<span class="ar-pending" dir="ltr">[AR pending]</span>'
OFF, SHOP_FC, FC = '{{#if USE_OFFICE}}', '{{else}}', '{{#if USE_FOOD_COURT}}'


def R(k):
    return 'Clause {{REF:' + k + '}}'


# ---- old Arabic per v2 row (row n of v2 == clause n of v2.1, see mapping in CHANGELOG_commercial_v2.1.md) ----
body = src[src.find('<table class="clauses-table"'):src.find('<div class="remarks-section">')]
old_ar = []
for m in re.finditer(r'<tr class="clause-row">(.*?)</td>\s*</tr>\s*</table>\s*</td>\s*</tr>', body, re.S):
    ar = re.search(r'<td class="txt-ar" dir="rtl">(.*)', m.group(1), re.S).group(1)
    if 'TRANSLATION PENDING' not in ar:
        old_ar.append(ar.strip())
        continue
    guides = [g for note, g in re.findall(r'<!-- OLD ARABIC \(([^)]*)\), kept for the translator:\n(.*?)\n-->', ar, re.S)
              if 'different subject' not in note]
    old_ar.append('\n'.join(x.strip() for x in guides))
if len(old_ar) != 40:
    sys.exit('expected 40 v2 rows, got %d' % len(old_ar))

C = [  # (key, flag, title, english)  flag: '' / 'R' renewal fee / 'F' food court / 'G' guarantor
 ('handover', '', 'Handover condition.', "The Tenant, upon signing this Agreement, acknowledges having received the Leased Premises and its appurtenances in the condition recorded in the signed Move-In Inspection Report attached as Annex B, which both Parties acknowledge as the agreed record of the condition of the Leased Premises at handover. The Tenant undertakes to return the Leased Premises to the Landlord or its authorised representative upon the expiry or termination of the lease term in the same condition as received, subject to normal wear and tear of the base-build resulting from ordinary use, and subject to " + R('reinstate') + ". This clause is in accordance with Law No. 26 of 2007 Regulating the Relationship between Landlords and Tenants in the Emirate of Dubai, as amended."),
 ('return', '', 'Return condition.', "Upon vacating, the Tenant shall return the Leased Premises in the condition recorded in Annex B. The Tenant shall be responsible, at its own expense, for repairing or restoring any damage, breakage, loss, unauthorised addition or alteration made during the lease term. If the Tenant fails to carry out the necessary works, the Landlord may assess the condition of the Leased Premises through its maintenance team after handover and charge the Tenant the cost of repair in accordance with " + R('deposit') + " and Annex D. Reinstatement of the Tenant's fit-out is governed by " + R('reinstate') + "."),
 ('alterations', '', 'Alterations.', "The Tenant shall not affix carpets, flooring or any installation using adhesives or materials that may damage the flooring or the structure of the Leased Premises, and shall not carry out any alteration or internal works inside or on the Leased Premises without the Landlord's prior written consent under " + R('fitout') + ". Signs are governed by " + R('signage') + ". On expiry or termination the Tenant shall remove all such additions and restore the Leased Premises in accordance with " + R('reinstate') + ", at its own cost and without the need for formal notice. The Landlord may deduct the cost of removal and repair from the security deposit and the fit-out deposit, without prejudice to its right to claim any amount exceeding them."),
 ('maintenance', '', 'Maintenance and repairs.', "(a) Tenant's responsibility: (i) minor maintenance and repairs of the Leased Premises where the cost of an individual repair incident, as assessed by the Landlord's maintenance team or a contractor approved by the Landlord, is below AED {{MINOR_REPAIR_THRESHOLD}} ({{MINOR_REPAIR_THRESHOLD_WORDS}} Dirhams); (ii) all maintenance, repair and eventual removal of any alteration, addition or installation made by the Tenant; (iii) maintenance of any non-original fitting that the Tenant has expressly accepted in Annex B; (iv) irrespective of cost, repair of any damage caused by the Tenant's misuse or negligence, by persons the Tenant admits to the Leased Premises, or by the Tenant's failure to report a defect promptly; and (v) " + OFF + "the office fit-out, partitions, doors and locks, and any equipment installed by or for the Tenant" + SHOP_FC + "the shop fit-out, shopfront, glass, shutters, doors and locks, and any additional or replacement air-conditioning, exhaust, refrigeration or other equipment installed by or for the Tenant{{/if}}" + FC + ", including the kitchen equipment, gas lines, grease trap and kitchen exhaust{{/if}}.<br>(b) Landlord's responsibility: (i) any individual repair incident costing AED {{MINOR_REPAIR_THRESHOLD}} ({{MINOR_REPAIR_THRESHOLD_WORDS}} Dirhams) or more, in full and not only the excess; (ii) all structural works, waterproofing, main electrical, plumbing and drainage infrastructure, the base-build fan-coil units and chilled-water connection, district cooling / chiller plant and any building-wide system, irrespective of cost; and (iii) any non-original fitting not listed in Annex B as accepted by the Tenant.<br>(c) Reporting: the Tenant shall report any defect or damage through the \"Ain Al Reem Living\" application or in writing within forty-eight (48) hours of becoming aware of it. Where a delay in reporting increases the cost of repair, the additional cost shall be borne by the Tenant.<br>(d) Repairs within the Tenant's responsibility that the Tenant fails to carry out within a reasonable time may be carried out by the Landlord and charged to the Tenant at cost."),
 ('inspection', '', 'Inspection reports.', "On the handover date, both Parties shall jointly complete and sign the Move-In Inspection Report (Annex B), recording the condition of each area and fitting with dated photographs, the meter readings (DEWA, cooling, gas) and a list of any non-original fittings and whether the Tenant accepts their maintenance. A Move-Out Inspection Report (Annex C) shall be completed jointly on the day the keys are returned, following the same order of areas. Where the Tenant declines to attend or sign, the Landlord's report, witnessed by a second representative and sent to the Tenant's notice address the same day, shall be the record. Both Parties shall retain a copy of these reports and shall document any remarks or changes to the condition of the Leased Premises during the lease term."),
 ('access', '', 'Access.', "The Tenant shall permit the Landlord, its agents and workers to enter the Leased Premises at reasonable times to inspect its condition, to supervise or carry out repair and maintenance works, and, during the last ninety (90) days of the term, to show the Leased Premises to prospective tenants. The Tenant shall be notified in advance of the intended visit, except in an emergency where immediate access is required."),
 ('goods', '', 'Goods outside the premises.', "The Tenant shall not place any goods, stock, display stands, seating or equipment outside the Leased Premises, including on walkways, parking, loading areas and common areas, without the Landlord's prior written approval. The Landlord may remove them at the Tenant's cost."),
 ('signage', '', 'Signage.', OFF + "The Tenant may display its name only in the place and style the Landlord allows. No signs are permitted outside the Leased Premises or on the building." + SHOP_FC + "The Tenant shall not install any sign, logo, light box or display on or outside the Leased Premises without the Landlord's prior written approval of its design, size and position. The Tenant shall obtain all permits for signs at its own cost.{{/if}} The Landlord may remove any sign installed without approval at the Tenant's cost. On expiry or termination the Tenant shall remove its signs and make good."),
 ('approvals', '', 'Approvals and Civil Defence.', "The Tenant shall, at its own cost, obtain and keep valid all approvals needed for its activity, including Civil Defence, Dubai Municipality, food-safety and health permits, and shall comply with all fire-safety rules, including those on gas and LPG cylinders. No LPG shall be stored in the Leased Premises without Civil Defence approval." + FC + " The Tenant shall keep valid food permits and health cards for all food-handling staff and arrange pest control by an approved company at least once a month.{{/if}} Any fine imposed on the Tenant or the Landlord because of the Tenant's activity shall be paid by the Tenant within seven (7) days of demand."),
 ('fixtures', '', 'Fixtures.', "The Tenant shall not remove any fixture (including wiring, pipes or sanitary fittings) where this would damage the Leased Premises. In the event of breach, the Landlord may claim the full cost of repair and compensation in accordance with the laws of Dubai and may refer the matter to the Rental Disputes Centre or other competent authority."),
 ('works', '', 'Substantial works.', "The Tenant shall not carry out any substantial repair or modification to the Leased Premises without the Landlord's prior written consent. Any unauthorised work is a breach of this Agreement and entitles the Landlord to claim damages."),
 ('rent', '', 'Rent payment and returned cheques.', "(a) The Tenant shall pay the rent on the due dates set out in the Payment Schedule (Annex A). The cheque dates in Annex A are the due dates of the respective instalments. All cheques shall be drawn on the Tenant's own bank account and signed by its authorised signatory; cheques drawn on a third party's account are not accepted.<br>(b) If a cheque is returned unpaid for any reason attributable to the Tenant, the Tenant shall, within seven (7) days of written notice:<br>&nbsp;&nbsp;&nbsp;&nbsp;(i) pay the amount of the returned cheque;<br>&nbsp;&nbsp;&nbsp;&nbsp;(ii) reimburse the Landlord's actual third-party costs arising from the return, including bank charges and the cost of serving notices, against copies of the relevant receipts; and<br>&nbsp;&nbsp;&nbsp;&nbsp;(iii) pay an administrative handling fee of AED {{CHEQUE_ADMIN_FEE}} ({{CHEQUE_ADMIN_FEE_WORDS}} Dirhams) per returned cheque. The Parties agree that this fee is a genuine pre-estimate of the Landlord's internal cost of handling a returned cheque (staff time, correspondence, re-presentation and record-keeping) and is not a penalty.<br>(c) The Landlord may invoice the amounts in (b)(ii) and (b)(iii) and, if unpaid, deduct them from the security deposit in accordance with " + R('deposit') + ". The Landlord may make acceptance of a replacement cheque conditional on payment of these amounts.<br>(d) A cheque returned because the account is closed, the signature does not match, or the amounts in words and figures differ shall be treated as a material breach.<br>(e) The Landlord's acceptance of a replacement cheque or of a partial payment is on account only and does not extend any due date, waive any notice already served, or waive any right of the Landlord.<br>(f) In the event of non-payment, the Landlord may exercise its rights under Article 25 of Law No. 26 of 2007 as amended, including service of the statutory notice through the notary public or registered mail, and may claim rent until the date the Leased Premises are actually vacated and handed over.<br>(g) Sub-clauses (b) and (c) survive the expiry or termination of this Agreement."),
 ('effective', '', 'Effectiveness, documents and Ejari.', "(a) This Agreement shall not be considered effective nor delivered to the Tenant until the first rent instalment cheque has cleared and the Tenant has provided: a copy of its trade licence (or, for a new business, the initial approval of the licensing authority); its memorandum of association or certificate of incorporation; the Emirates ID and passport copies of its owner(s) and manager; the power of attorney or board resolution of the authorised signatory; and its TRN certificate where registered for VAT. Failure to fulfil these conditions entitles the Landlord to withhold delivery of the Agreement or suspend its effectiveness until full compliance.<br>(b) The Landlord shall register the Agreement with Ejari within {{EJARI_REGISTRATION_DAYS_WORDS}} ({{EJARI_REGISTRATION_DAYS}}) working days after the first instalment has cleared and the documents in (a) have been received. Nothing in this clause entitles the Landlord to withhold Ejari registration once those conditions are met."),
 ('early', '', 'Early termination by the Tenant.', "The Tenant shall not have the right to terminate this Agreement before the expiry of its fixed term unless prior written approval is granted by the Landlord. Where the Landlord consents to an early termination, the Tenant shall pay early-termination compensation equal to three (3) months' rent, payable before or on the actual date of vacating, and shall comply with " + R('reinstate') + ". Any rent-free fit-out period granted under this Agreement shall become payable in full if the Agreement ends within the first twelve (12) months by reason of the Tenant's default or early termination."),
 ('renewal', '', 'Renewal and changes of terms.', "(a) Either Party wishing to amend the rent or any term for the following lease period shall notify the other Party in writing at least ninety (90) days before the expiry date. Any rent increase shall be within the limits of the RERA Rental Index applicable at the time.<br>(b) If no such notice is given and the Tenant remains in occupation, the Agreement renews for a like period on the same terms, in accordance with Article 6 of Law No. 26 of 2007 as amended.<br>(c) The Landlord may decline to renew only on the grounds and subject to the notice periods and methods prescribed by Article 25(2) of Law No. 26 of 2007 as amended.<br>(d) A Tenant who does not wish to renew shall notify the Landlord in writing at least ninety (90) days before the expiry date to allow re-letting; where notice is given later, the Tenant remains liable for rent until the expiry date or the actual handover date, whichever is later, and the Landlord shall use reasonable efforts to re-let."),
 ('vacating', '', 'Vacating without approval.', "If the Tenant intends to vacate the Leased Premises before the expiry of the term, it shall notify the Landlord in writing and obtain prior written approval. If the Tenant vacates without such approval, or the Leased Premises are found closed or unoccupied in breach of " + R('licence') + "(c), or the Tenant has failed to fulfil its contractual obligations, the Landlord may file a request with the competent authorities (including the Rental Disputes Centre) to repossess the Leased Premises in accordance with the applicable laws. The Tenant shall have no right to object to such procedures, without prejudice to the Landlord's right to claim damages and any sums outstanding under this Agreement."),
 ('sublease', '', 'Sublease, assignment and authority to consent.', "(a) The Tenant shall not sublease the Leased Premises in whole or in part, nor assign this Agreement, nor permit any third party to occupy or use the Leased Premises in any manner, with or without consideration, without the Landlord's prior express written consent. The Tenant shall use the Leased Premises only for the Permitted Activity stated on page 1. In the event of breach, the Landlord may take all necessary legal action, including eviction and a claim for damages.<br>(b) A transfer of more than fifty per cent (50%) of the shares or ownership of the Tenant, or a change of its manager named on the trade licence, is treated as an assignment and requires the Landlord's prior written consent. The Tenant shall notify the Landlord of any such change within fourteen (14) days.<br>(c) The Tenant shall provide the Landlord, on request and within seven (7) days, with a copy of its current trade licence and the identification documents of its staff working in the Leased Premises.<br>(d) Authority to consent: no consent, approval or no-objection certificate binds the Landlord unless it is issued on the Landlord's letterhead, bears a reference number and is signed by an authorised signatory named in the Landlord's trade licence or a notarised power of attorney. Real-estate brokers, agents and building staff have no authority to issue consents or documents on the Landlord's behalf."),
 ('fees', '', 'Fees and utilities.', "(a) The Tenant shall bear all governmental and municipal fees relating to the Leased Premises and its activity throughout the term, including Dubai Municipality market fees, the Ejari registration fee, trade-licence fees, signage permits, Civil Defence and Dubai Municipality approvals and inspections, waste collection, and any new government fee introduced during the term.<br>(b) The Tenant shall transfer the DEWA, district cooling and gas accounts for the Leased Premises into its own name within seven (7) days of handover and shall pay all water, electricity, sewerage, district cooling (including capacity and consumption charges), gas, telecommunications and internet charges on time. Any delay or failure in payment is a breach of this Agreement.<br>(c) Building service charges are payable by the Tenant only as stated on page 1 under \"Service charge\"."),
 ('renewalfee', 'R', 'Renewal service fee.', "The Tenant shall pay a service fee of AED 1,500 or two per cent (2%) of the annual rent, whichever is higher, on each renewal of this Agreement."),
 ('deposit', '', 'Security deposit.', "(a) Within seven (7) working days after the Move-Out Inspection Report (Annex C) is completed, the Landlord shall send the Tenant an itemised deduction statement, each item referenced to Annex B and Annex C with photographs and the applicable rate from the Schedule of Rates (Annex D) or, for items not listed in Annex D, the repair cost evidenced by a contractor's quotation or invoice. Where all deductions can be determined from Annex D, the statement may be issued and signed at the Move-Out Inspection itself.<br>(b) The Tenant may object in writing within five (5) working days of receipt. An objection by the Tenant does not suspend the Landlord's right to carry out repairs, make-ready works or re-letting of the Leased Premises. Any dispute regarding a deduction shall be determined on the basis of Annex B, Annex C, the photographs forming part of them, and Annex D. The undisputed balance of the deposit shall be refunded within twenty-five (25) working days after the Tenant has provided final clearance certificates for DEWA, district cooling, gas and telecommunications / internet, has returned all keys and access cards, and has completed reinstatement under " + R('reinstate') + ".<br>(c) No deduction shall be made for normal wear and tear of the base-build, which includes minor scuffs and marks, fading from sunlight and ordinary repainting after a tenancy of twelve (12) months or more, unless specific damage is recorded in Annex C. This sub-clause does not apply to the Tenant's fit-out, signs and equipment or to reinstatement under " + R('reinstate') + ", which are not wear and tear.<br>(d) The Landlord may deduct unpaid rent, VAT and utility charges, any unpaid amount invoiced under " + R('rent') + "(b) supported by the invoice and receipts, the cost of replacing unreturned keys or access cards at the rates in Annex D, the cost of reinstatement under " + R('reinstate') + ", and the cost of repairing damage beyond normal wear and tear proven by Annex B and Annex C."),
 ('licence', '', 'Trade licence, Permitted Activity and continuous trading.', "(a) The Tenant shall use the Leased Premises only for the Permitted Activity. The Tenant shall obtain its trade licence for the Leased Premises within thirty (30) days of Ejari registration and in any event within sixty (60) days of the start date, shall keep it valid for the whole term, and shall provide a copy to the Landlord within seven (7) days of each issue or renewal.<br>(b) The Tenant shall not change or add any activity without the Landlord's prior written consent. Expiry, suspension or cancellation of the trade licence is a breach of this Agreement." + OFF + SHOP_FC + "<br>(c) The Tenant shall open for business within thirty (30) days after the later of the trade-licence issue date and the end of any fit-out period, and shall thereafter trade continuously during the building's opening hours. Closure of the Leased Premises for more than thirty (30) consecutive days without the Landlord's written consent is a breach of this Agreement and " + R('vacating') + " applies.{{/if}}"),
 ('conduct', '', 'Conduct and common areas.', "The Tenant shall not carry on any activity that causes disturbance, nuisance or inconvenience to other occupants of the building, including noise, music, smells or smoke from the Leased Premises, and shall comply with the building's rules. The Tenant shall not store or place any goods, materials, waste or equipment in service corridors, shop entrances, loading areas, waste rooms, car parks or any common area. The Landlord or the building management may remove any such item without prior notice, shall not be liable for any resulting loss or damage, and may charge the Tenant the cost of removal, cleaning and any related damage."),
 ('liability', '', 'Liability in common areas.', "The Landlord, the property agent and their representatives shall not be liable for any bodily injury or damage to property suffered by the Tenant, its staff, customers or suppliers in the common areas, loading bays or car park of the building, except where caused by the Landlord's proven negligence. The Tenant shall comply with all safety instructions issued by the building management."),
 ('parking', '', 'Parking.', "Parking is provided to the Tenant only as stated on page 1 or agreed by the Landlord in writing. The Landlord shall not be liable for any damage, loss or theft of a vehicle parked in the building, whatever the cause; the Tenant shall insure its vehicles. Additional spaces may be provided subject to availability, the Landlord's prior approval and additional charges." + OFF + SHOP_FC + " Customer parking is subject to the building's rules and time limits.{{/if}}"),
 ('facilities', '', 'Residential facilities.', "The Tenant, its staff and customers have no right to use the building's residential facilities (including the gym, swimming pool, sauna, steam room and jacuzzi)."),
 ('breach', '', 'Breach.', "If the Tenant breaches any term of this Agreement, the Landlord shall notify the Tenant in writing to remedy the breach within a period of not less than thirty (30) days from the date of the notice, served in accordance with Article 25(1)(h) of Law No. 26 of 2007 as amended. If the Tenant fails to remedy the breach within that period, the Landlord may terminate this Agreement and demand eviction from the Leased Premises, holding the Tenant liable for all damages and losses arising from the breach, and may take all necessary legal action to enforce its rights, including claiming compensation and legal costs, in accordance with this Agreement and the laws of the Emirate of Dubai."),
 ('holdover', '', 'Rent until actual handover.', "(a) Rent at the contract rate continues to accrue until the Tenant returns all keys and access cards and the Move-Out Inspection Report (Annex C) is signed or issued, including any period of occupation after expiry or termination. Any such period shall be charged pro rata on a daily basis.<br>(b) The Landlord is not obliged to accept the return of the keys, and this Agreement does not end, until all rent and charges due for the elapsed period have been paid. Acceptance of keys, access to the Leased Premises or the start of make-ready works does not waive any unpaid rent, any cheque already presented, or the Tenant's reinstatement obligations."),
 ('notices', '', 'Notices.', "The Tenant's address for contractual notices is the Leased Premises, the email address {{TENANT_EMAIL}} and the mobile number {{TENANT_PHONE}}. The Landlord's address for contractual notices is the email address {{LANDLORD_NOTICE_EMAIL}}, or such other email address as the Landlord may notify the Tenant in writing. Notices sent by email to these addresses, or by registered post to the Leased Premises, are deemed received on the next working day. The Tenant shall notify the Landlord in writing of any change of contact details within seven (7) days; notices sent to the last notified details are valid. Statutory notices under Law No. 26 of 2007 shall additionally be served by the methods that law prescribes."),
 ('keys', '', 'Keys and access cards.', "The Tenant acknowledges receipt of the keys and access cards listed in Annex B and shall return all of them on the handover date. The replacement cost of any unreturned or damaged key or access card, as listed in Annex B and Annex D, shall be borne by the Tenant."),
 ('fitout', '', 'Handover and fit-out.', "(a) The Tenant accepts the Leased Premises in the condition recorded in Annex B. The Tenant shall not start any fit-out works before the Landlord has approved its drawings in writing and the Tenant has obtained all required authority approvals. All fit-out works, approvals and costs are the Tenant's responsibility and at its own risk.<br>(b) Fit-out works shall be carried out by licensed contractors holding third-party liability insurance, within the hours set by the building management, without hot works unless permitted in writing, and in a manner that does not affect the building's structure, services or other occupants. The Landlord may inspect the works at any time and may stop any work that breaches this clause.<br>(c) The Tenant shall not open for business until the works are complete and the required completion certificates (including Civil Defence and Dubai Municipality) have been issued and copies given to the Landlord.<br>(d) Rent is payable from the start date unless page 1 states a fit-out period, in which case rent is waived for that period only; utilities, cooling charges and all other obligations apply from the start date. The fit-out period does not extend the lease term.<br>(e) The Tenant shall pay the fit-out deposit stated on page 1 before works begin. It is held as security for damage to the building during the works and for reinstatement, and is refunded without interest within twenty-five (25) working days after the Landlord's inspection confirms completion of the works without damage, or, if retained in part, in accordance with " + R('deposit') + "."),
 ('reinstate', '', 'Reinstatement and abandoned goods.', "(a) At the end of the lease the Tenant shall, at its own cost, remove its fit-out, signs, stock and equipment and return the Leased Premises in the condition recorded in Annex B, unless the Landlord elects in writing before the Move-Out Inspection to keep any part of the fit-out, with no payment to the Tenant. If the Tenant fails to do so, the Landlord may do it and deduct the cost from the fit-out deposit and the security deposit. " + R('holdover') + " applies until reinstatement is complete.<br>(b) Any goods, stock, equipment or documents left in the Leased Premises after termination, expiry or repossession may, after fifteen (15) days' written notice to the Tenant's notice address, be removed, stored or disposed of by the Landlord at the Tenant's cost, and the Landlord shall have no liability for them."),
 ('vat', '', 'VAT and tax invoices.', "All amounts under this Agreement are stated exclusive of VAT. VAT at the applicable rate shall be added to the rent and to every other charge where applicable and is payable with each payment; if the rate changes, the new rate applies from the date of change. The Landlord shall issue a tax invoice for each instalment and for every other charge. The security deposit and fit-out deposit are not consideration for a supply when paid."),
 ('insurance', '', 'Insurance.', "The Tenant shall, for the whole term, maintain insurance against loss of or damage to its fit-out, stock and contents, and third-party liability insurance with a limit of not less than AED {{TPL_INSURANCE_MIN}} per occurrence, with a reputable insurer licensed in the UAE, and shall give the Landlord a copy of the policy within fourteen (14) days of the start date and on each renewal."),
 ('hours', '', 'Opening hours, deliveries and waste.', "The Tenant shall follow the building's rules on opening hours, deliveries, loading, storage and waste, including any requirement to use designated service entrances and waste rooms and to separate and dispose of waste as required by Dubai Municipality."),
 ('kitchen', 'F', 'Kitchen, drains and seating.', "The Tenant shall have the grease trap and kitchen exhaust cleaned by an approved company at least once a month or as often as Dubai Municipality requires, whichever is more frequent, and shall keep the records for the Landlord's inspection. Any blockage of or damage to the building's drains caused by the Tenant shall be repaired at the Tenant's cost. The seating area is shared with other tenants; the Tenant has no exclusive right to it and shall keep its allocated area clean."),
 ('guarantee', 'G', 'Guarantee.', "{{GUARANTOR_NAME}} (Emirates ID {{GUARANTOR_EID}}) irrevocably and unconditionally guarantees, as principal obligor, jointly and severally with the Tenant, the due payment of all rent and other sums payable by the Tenant under this Agreement and the performance of the Tenant's obligations, and signs this Agreement in that capacity. The Guarantor's address for notices is the email address {{GUARANTOR_EMAIL}} and the mobile number {{GUARANTOR_PHONE}}."),
 ('poa', '', 'Powers of attorney.', "Where authorised signatories or company representatives are involved, legally certified powers of attorney or board resolutions evidencing their authority to sign shall be provided and attached to this Agreement."),
 ('jurisdiction', '', 'Jurisdiction.', "Any dispute between the Landlord and the Tenant relating to the interpretation, implementation or enforcement of this Agreement shall be referred to the Rental Disputes Centre of the Emirate of Dubai in accordance with Decree No. 26 of 2013, which shall have exclusive jurisdiction."),
 ('prevailing', '', 'Prevailing text.', "In the event of any discrepancy or conflict between the Arabic text and the translated version of this Agreement, the Arabic text shall prevail and shall be deemed the official and legally binding version before all judicial and administrative authorities in the Emirate of Dubai. All Parties acknowledge and sign the Arabic version as the primary and authoritative version."),
 ('copies', '', 'Copies.', "This Agreement is made in two copies and each Party is deemed to have received a copy upon signing."),
]
assert len(C) == 40


def esc_comment(t):
    return t.replace('--', '- -')


rows = ''
for i, (key, flag, title, en) in enumerate(C):
    guide = old_ar[i]
    ar = ('<!-- OLD ARABIC (v2 clause row ' + str(i + 1) + '), guide only:\n' + esc_comment(guide) + '\n-->' if guide else '') + PENDING
    row = '''
        <tr class="clause-row">
            <td class="clause-col-en">
                <table class="clause-inner" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td class="num-en">{{CLAUSE_NO:''' + key + '''}}.</td>
                        <td class="txt-en"><b>''' + title + '</b> ' + en.replace(' & ', ' &amp; ') + '''</td>
                    </tr>
                </table>
            </td>
            <td class="clause-col-ar" dir="rtl">
                <table class="clause-inner" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td class="num-ar">{{CLAUSE_NO_AR}}-</td>
                        <td class="txt-ar" dir="rtl">''' + ar + '''</td>
                    </tr>
                </table>
            </td>
        </tr>
'''
    if flag:
        fl = {'R': 'INCLUDE_RENEWAL_FEE', 'F': 'USE_FOOD_COURT', 'G': 'HAS_GUARANTOR'}[flag]
        row = '\n        {{#if ' + fl + '}}' + row + '        {{/if}}\n'
    rows += row


def lab(en, value, ar=None):
    return ('    <tr>\n        <td class="label">' + en + '</td>\n        <td class="value">' + value + '</td>\n'
            '        <td class="label-ar" dir="rtl">' + (ar or AR_P) + '</td>\n    </tr>\n')


page1 = ('<table class="contract-details" cellpadding="0" cellspacing="0" border="0">\n'
         + lab('Landlord:', '{{LANDLORD_NAME}} (the "Landlord") — Trade Licence No. {{LANDLORD_LICENCE_NO}} — TRN {{LANDLORD_TRN}}', 'المؤجر:')
         + lab('Tenant:', '{{TENANT_NAME}} (the "Tenant") — Trade Licence No. {{TENANT_LICENCE_NO}} — TRN {{TENANT_TRN}}', 'المستأجر:')
         + lab('Authorised signatory of the Tenant:', '{{TENANT_SIGNATORY_NAME}} — Emirates ID {{TENANT_SIGNATORY_EID}}')
         + '    {{#if HAS_GUARANTOR}}\n'
         + lab('Guarantor:', '{{GUARANTOR_NAME}} — Emirates ID {{GUARANTOR_EID}} — Mobile {{GUARANTOR_PHONE}} — Email {{GUARANTOR_EMAIL}}')
         + '    {{/if}}\n'
         + lab('Leased Premises:', 'Unit {{UNIT_NO}}, {{PROPERTY_NAME}}, {{COMMUNITY}}, Dubai — Area {{PREMISES_AREA_SQFT}} sq ft')
         + lab('Premises No (DEWA):', '{{PREMISES_NUMBER}}', 'رقم عداد الكهرباء:')
         + lab('Use of Premises:', '{{COMMERCIAL_USE}}')
         + lab('Permitted Activity:', '{{PERMITTED_ACTIVITY}}')
         + lab('Lease term:', '{{START_DATE}} to {{END_DATE}}', 'مدة الايجار:')
         + lab('Fit-out period:', '{{FIT_OUT_PERIOD}}')
         + lab('Annual rent (excl. VAT):', 'AED {{ANNUAL_RENT}} ({{ANNUAL_RENT_WORDS}} Dirhams)', 'قيمة الايجار:')
         + lab('VAT on rent (5%):', 'AED {{RENT_VAT_AMOUNT}}')
         + lab('Annual rent incl. VAT:', 'AED {{GROSS_ANNUAL_RENT_INCL_RENT_VAT}}')
         + lab('Service charge:', '{{SERVICE_CHARGE_TERMS}}')
         + lab('Terms of payment:', '{{PAYMENT_SUMMARY}}', 'اقساط الدفع:')
         + lab('Security deposit:', 'AED {{SECURITY_DEPOSIT}} ({{SECURITY_DEPOSIT_WORDS}} Dirhams)', 'وديعة التامين:')
         + lab('Fit-out deposit:', '{{FIT_OUT_DEPOSIT}}')
         + lab('Parking:', '{{PARKING_TERMS}}')
         + lab('Remarks:', '{{LEASE_NOTES}}&nbsp;')
         + '</table>\n')

sign = '''<div class="signature-section">
    <div class="undertake-en">I undertake to act in accordance with this Contract and its Conditions.</div>
    <div class="undertake-ar" dir="rtl">أتعهد بالعمل وفق هذه الاتفاقية وشروطها</div>
    <table class="sign3" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <th>Landlord</th><th>Tenant</th>{{#if HAS_GUARANTOR}}<th>Guarantor</th>{{/if}}
        </tr>
        <tr>
            <td>{{LANDLORD_NAME}} — by {{LANDLORD_SIGNATORY}}</td>
            <td>{{TENANT_NAME}} — by {{TENANT_SIGNATORY_NAME}} (Emirates ID {{TENANT_SIGNATORY_EID}})</td>
            {{#if HAS_GUARANTOR}}<td>{{GUARANTOR_NAME}} (Emirates ID {{GUARANTOR_EID}})</td>{{/if}}
        </tr>
        <tr class="sign3-line">
            <td>{{LANDLORD_SIGNATURE}}{{COMPANY_STAMP}}&nbsp;</td>
            <td>{{TENANT_SIGNATURE}}&nbsp;</td>
            {{#if HAS_GUARANTOR}}<td>&nbsp;</td>{{/if}}
        </tr>
        <tr class="sign3-sub">
            <td>Signature / stamp / date</td><td>Signature / stamp / date</td>{{#if HAS_GUARANTOR}}<td>Signature / date</td>{{/if}}
        </tr>
    </table>
    <div class="sign3-contact">Tenant's phone: {{TENANT_PHONE}} &middot; Tenant's email: {{TENANT_EMAIL}} &middot; Tenant's TRN: {{TENANT_TRN}}</div>
    <div class="sign3-attach"><b>Attachments received at signing:</b> &#9744; Trade licence / initial approval &nbsp; &#9744; MOA / incorporation &nbsp; &#9744; Owner(s) EID + passport &nbsp; &#9744; Manager EID + passport &nbsp; &#9744; Signatory POA / board resolution &nbsp; &#9744; TRN certificate &nbsp; &#9744; Guarantor EID + passport</div>
    <div class="annex-ar-pending"><span class="ar-pending">[AR – TRANSLATION PENDING – Signature page]</span></div>
</div>
'''

annex = '''
<div class="annex-page">
    <div class="annex-title">Annex A - Payment Schedule</div>
    <div class="annex-ar-pending"><span class="ar-pending">[AR – TRANSLATION PENDING – Annex A]</span></div>
    <div class="annex-sub">Lease No. {{LEASE_NUMBER}} &middot; {{TENANT_NAME}} &middot; Unit {{UNIT_NO}}, {{PROPERTY_NAME}} &middot; {{START_DATE}} - {{END_DATE}}</div>
    {{PAYMENT_SCHEDULE}}
    <div class="annex-note">The cheque dates above are the due dates of the respective instalments (Clause {{REF:rent}}). Annual rent AED {{ANNUAL_RENT}} + VAT AED {{RENT_VAT_AMOUNT}} = AED {{GROSS_ANNUAL_RENT_INCL_RENT_VAT}}. All cheques are drawn on the Tenant's account {{TENANT_BANK_ACCOUNT_NAME}}.</div>
</div>

<div class="annex-page">
    <div class="annex-title">Annex B - Move-In Inspection Report</div>
    <div class="annex-ar-pending"><span class="ar-pending">[AR – TRANSLATION PENDING – Annex B]</span></div>
    <div class="annex-sub">Lease No. {{LEASE_NUMBER}} &middot; {{TENANT_NAME}} &middot; Unit {{UNIT_NO}}, {{PROPERTY_NAME}}</div>
    {{MOVE_IN_REPORT}}
</div>

<div class="annex-page">
    <div class="annex-title">Annex C - Move-Out Inspection Report</div>
    <div class="annex-ar-pending"><span class="ar-pending">[AR – TRANSLATION PENDING – Annex C]</span></div>
    <div class="annex-sub">Lease No. {{LEASE_NUMBER}} &middot; {{TENANT_NAME}} &middot; Unit {{UNIT_NO}}, {{PROPERTY_NAME}}</div>
    {{MOVE_OUT_REPORT}}
</div>

<div class="annex-page">
    <div class="annex-title">Annex D — Schedule of Rates</div>
    <div class="annex-ar-pending"><span class="ar-pending">[AR – TRANSLATION PENDING – Annex D]</span></div>
    <div class="annex-sub">Lease No. {{LEASE_NUMBER}} &middot; {{TENANT_NAME}} &middot; Unit {{UNIT_NO}}, {{PROPERTY_NAME}}</div>
    <div class="annex-intro">The Parties agree that the rates below are a fair pre-estimate of the cost of repair, replacement or cleaning of the listed items and shall be applied in the deduction statement under Clause {{REF:deposit}}. Items not listed are charged at the actual cost evidenced by a contractor's quotation or invoice. Rates include labour and materials and exclude VAT, which is added where applicable.</div>
    {{SCHEDULE_OF_RATES}}
</div>
</body>
</html>
'''

css = '''.contract-details .label { width: 27%; }
.contract-details .value { width: 53%; }
.contract-details .label-ar { width: 20%; }
.sign3 { width: 100%; table-layout: fixed; margin-top: 10px; }
.sign3 th { text-align: left; font-size: 10.5px; padding: 4px 8px 4px 0; border-bottom: 1px solid #000; }
.sign3 td { vertical-align: top; font-size: 9.5px; padding: 4px 8px 4px 0; }
.sign3-line td { border-bottom: 1px solid #000; height: 44px; vertical-align: bottom; }
.sign3-sub td { font-size: 8.5px; color: #444; }
.sign3-contact { font-size: 9.5px; margin-top: 8px; }
.sign3-attach { font-size: 9px; margin-top: 6px; line-height: 1.6; }
</style>'''

head = src[:src.find('<table class="contract-details"')]
head = head.replace('</style>', css, 1)
i = head.find('<!-- template_code:')
j = head.find('-->', i) + 3
head = head[:i] + ('<!-- template_code: tenancy_contract_com_v2 - Ayla Commercial v2.1 (shop / office / food court), English from the v2.1 master of 10 Nov 2026.\n'
                   '     CLAUSE_NO:key markers number the clauses and REF:key markers print a clause\'s number (resolved at render time). All Arabic pending. -->') + head[j:]
clauses_head = src[src.find('<div class="clauses-section">'):src.find('<table class="clauses-table"')]
out = (head + page1 + '\n' + clauses_head + '<table class="clauses-table" cellpadding="0" cellspacing="0" border="0">' + rows
       + '\n    </table>\n</div>\n\n' + sign + annex)
open(PATH, 'w', encoding='utf-8').write(out)
print('wrote', PATH, len(out), 'chars,', out.count('{{CLAUSE_NO:'), 'clauses')
