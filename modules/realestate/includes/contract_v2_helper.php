<?php
/**
 * Tenancy Contract v2 (residential, Ayla standard) - data model and render rules.
 *
 * Used by ContractPDFGenerator only when the lease's template has
 * template_code = 'tenancy_contract_v2'. Older templates are rendered exactly as before.
 *
 * What lives here:
 *   - contract_v2_defaults()            every new placeholder, its type and its default
 *   - contract_v2_values()              the values for one lease (lease fields + defaults + annexes)
 *   - contract_v2_render_conditionals() {{#if FLAG}} ... {{else}} ... {{/if}}
 *   - contract_v2_number_clauses()      {{CLAUSE_NO}} / {{CLAUSE_NO_AR}} / {{CLAUSE_REF}}
 *   - contract_v2_assert_resolved()     throws when any {{...}} is left in the HTML
 *   - contract_v2_validate_schedule()   Annex A due-date rule (warnings only)
 *   - contract_v2_guarantor_required()  company tenant and annual rent over AED 100,000
 */

if (!defined('CONTRACT_V2_TEMPLATE_CODE')) {
    define('CONTRACT_V2_TEMPLATE_CODE', 'tenancy_contract_v2');
}
if (!defined('CONTRACT_V2_RATES_REQUIRED')) {
    // false: a blank Annex D rate prints "—" and is logged. Switch to true once all 22 rates are in.
    define('CONTRACT_V2_RATES_REQUIRED', false);
}
if (!defined('CONTRACT_V2_OCC_SQM_PER_PERSON')) {
    // Clause 17(b) occupancy: bedroom area per person (Dubai Municipality). Fixed for now; settings page later.
    define('CONTRACT_V2_OCC_SQM_PER_PERSON', 5);
}
if (!defined('CONTRACT_V2_GUARANTOR_RENT_LIMIT')) {
    // Company tenant with annual rent over this amount must name a guarantor.
    define('CONTRACT_V2_GUARANTOR_RENT_LIMIT', 100000);
}

if (!defined('CONTRACT_V2_LANDLORD_LICENCE_NO')) {
    // Commercial v2.1 page 1. null = prints a blank line to fill by hand (owner to supply the value).
    define('CONTRACT_V2_LANDLORD_LICENCE_NO', null);
}
if (!defined('CONTRACT_V2_LANDLORD_SIGNATORY')) {
    define('CONTRACT_V2_LANDLORD_SIGNATORY', null);
}
if (!defined('CONTRACT_V2_TPL_INSURANCE_MIN')) {
    // Commercial v2.1 clause 33: third-party liability minimum per occurrence (AED). null = blank line.
    define('CONTRACT_V2_TPL_INSURANCE_MIN', null);
}

if (!function_exists('contract_v2_is_v2_template')) {

    function contract_v2_is_v2_template($template): bool
    {
        // Ayla v2 and Park Place v2 (same contract; Park Place bills chiller on page 1).
        return is_array($template)
            && isset($template['template_code'])
            && in_array((string)$template['template_code'], array_merge([CONTRACT_V2_TEMPLATE_CODE, 'tenancy_contract_pp_v2'], contract_v2_commercial_codes(), contract_v2_labour_codes()), true);
    }

    /** Commercial v2 templates (shop / office / food court). No occupancy; use, activity, licence and VAT on page 1. */
    function contract_v2_commercial_codes(): array
    {
        // Ayla, Park Place (chiller when the lease has chiller fees), Gents Camp, Ladies Camp.
        return ['tenancy_contract_com_v2', 'tenancy_contract_pp_com_v2', 'tenancy_contract_gc_com_v2', 'tenancy_contract_lc_com_v2'];
    }

    /** Labour accommodation v2 templates: company renting camp rooms for its workers (rooms in Annex E). */
    function contract_v2_labour_codes(): array
    {
        return ['tenancy_contract_gc_lab_v2', 'tenancy_contract_lc_lab_v2'];
    }

    function contract_v2_is_labour_code(?string $code): bool
    {
        return in_array((string)$code, contract_v2_labour_codes(), true);
    }

    /** Residential templates only: max occupants worked out from bedrooms (clause 17(b)). */
    function contract_v2_has_occupancy_clause(?string $code): bool
    {
        return !contract_v2_is_commercial_code($code) && !contract_v2_is_labour_code($code);
    }

    /** Rooms on the lease: every unit of a multi-unit lease, otherwise the lease's unit. [['room', 'premises']]. */
    function contract_v2_lease_rooms(?PDO $conn, array $lease): array
    {
        if (array_key_exists('__rooms', $lease)) {
            return (array)$lease['__rooms'];
        }
        $rows = [];
        if ($conn && !empty($lease['is_multi_unit']) && !empty($lease['id'])) {
            $st = $conn->prepare("SELECT u.unit_number, u.premises_number FROM re_lease_units lu JOIN re_units u ON u.id = lu.unit_id
                                  WHERE lu.lease_id = ? ORDER BY lu.sort_order ASC, lu.id ASC");
            $st->execute([(int)$lease['id']]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = ['room' => (string)$r['unit_number'], 'premises' => (string)($r['premises_number'] ?? '')];
            }
        }
        if (!$rows && trim((string)($lease['unit_number'] ?? '')) !== '') {
            $rows[] = ['room' => (string)$lease['unit_number'], 'premises' => (string)($lease['premises_number'] ?? '')];
        }
        return $rows;
    }

    /** Annex E: rooms in a compact grid, 4 rooms (room + DEWA premises no.) per line. */
    function contract_v2_render_room_list(array $rooms): string
    {
        $per = 4;
        $html = '<table class="annex-table" cellpadding="0" cellspacing="0" border="0"><tr>'
            . str_repeat('<th>Room</th><th>DEWA premises no.</th>', $per) . '</tr>';
        foreach (array_chunk($rooms, $per) as $chunk) {
            $html .= '<tr>';
            foreach ($chunk as $r) {
                $html .= '<td>' . contract_v2_h($r['room']) . '</td><td>' . contract_v2_h($r['premises'] !== '' ? $r['premises'] : '—') . '</td>';
            }
            $html .= str_repeat('<td>&nbsp;</td><td>&nbsp;</td>', $per - count($chunk)) . '</tr>';
        }
        $html .= '<tr class="annex-total"><td colspan="' . (2 * $per) . '">Total rooms: ' . count($rooms) . '</td></tr>';
        return $html . '</table>';
    }

    /** Labour contract values. Throws when the lease has no persons per room or no rooms. */
    function contract_v2_labour_values(?PDO $conn, array $lease): array
    {
        $ppr = (int)($lease['persons_per_room'] ?? 0);
        if ($ppr < 1) {
            throw new RuntimeException('Enter "Persons per Room" on the lease before printing the labour accommodation contract.');
        }
        $rooms = contract_v2_lease_rooms($conn, $lease);
        if (!$rooms) {
            throw new RuntimeException('The lease has no rooms to list in Annex E.');
        }
        $licence = trim((string)($lease['trade_licence_no'] ?? ''));
        return [
            '{{PERSONS_PER_ROOM}}' => (string)$ppr,
            '{{PERSONS_PER_ROOM_WORDS}}' => strtolower(contract_v2_number_words($ppr)),
            '{{ROOM_COUNT}}' => (string)count($rooms),
            '{{MAX_PERSONS_TOTAL}}' => number_format($ppr * count($rooms)),
            '{{TRADE_LICENCE_NO}}' => $licence !== '' ? contract_v2_h($licence) : '_______________',
            '{{ROOM_LIST}}' => contract_v2_render_room_list($rooms),
        ];
    }

    function contract_v2_default_rooms_labour(): array
    {
        return ['Room doors and locks', 'Beds and mattresses', 'Lockers / cupboards', 'Air-conditioning', 'Lights and sockets',
            'Walls, floor and ceiling', 'Windows', 'Toilets and showers', 'Corridors and stairs'];
    }

    /** Commercial templates on the v2.1 text (Ayla). Others stay on v2 until they are rebuilt. */
    function contract_v2_commercial_v21_codes(): array
    {
        return ['tenancy_contract_com_v2'];
    }

    function contract_v2_is_commercial_v21_code(?string $code): bool
    {
        return in_array((string)$code, contract_v2_commercial_v21_codes(), true);
    }

    /** Commercial v2.1 Annex D (food-court item only for a food court). */
    function contract_v2_schedule_of_rates_commercial(bool $foodCourt = true): array
    {
        $rows = [
            ['Painting', 'Repainting', 'per wall / per area'],
            ['Cleaning', 'Deep cleaning - shop / office / food court unit', 'per unit'],
            ['Cleaning', 'AC duct / filter cleaning', 'per FCU'],
        ];
        if ($foodCourt) {
            $rows[] = ['Cleaning', 'Grease trap and exhaust cleaning (if not done by Tenant)', 'per service'];
        }
        $rows = array_merge($rows, [
            ['Shopfront', 'Glass panel replacement', 'per panel'],
            ['Shopfront', 'Shutter repair / motor', 'each'],
            ['Doors & hardware', 'Door handle / lock cylinder / interior door', 'each'],
            ['Electrical', 'Light fitting / switch / socket plate', 'each'],
            ['Plumbing', 'Mixer tap / toilet seat / WC', 'each'],
            ['Floor & ceiling', 'Floor tile / ceiling tile replacement', 'per m² / per tile'],
            ['Reinstatement', 'Removal of partitions / fit-out and making good', 'per m²'],
            ['Reinstatement', 'Sign removal and making good', 'each'],
            ['Access', 'Key / access card / parking remote', 'each'],
        ]);
        return array_map(fn($r) => ['category' => $r[0], 'item' => $r[1], 'unit' => $r[2], 'rate_aed' => null], $rows);
    }

    /** The current Annex D list for a template (commercial v2.1 has its own list). */
    function contract_v2_rate_list_for(?string $code, array $lease): array
    {
        if (contract_v2_is_commercial_v21_code($code)) {
            return contract_v2_schedule_of_rates_commercial(($lease['commercial_use'] ?? '') === 'food_court');
        }
        return contract_v2_schedule_of_rates();
    }

    /** "Ninety Thousand" / "Ninety Thousand and 50/100" for an amount in dirhams. */
    function contract_v2_amount_words($amount): string
    {
        $a = round((float)$amount, 2);
        $int = (int)floor($a);
        $fils = (int)round(($a - $int) * 100);
        return contract_v2_number_words($int) . ($fils > 0 ? ' and ' . $fils . '/100' : '');
    }

    /**
     * Commercial v2.1 page-1 values that are not in v2. $lease may carry __landlord_trn (tests);
     * otherwise the TRN is read from company_settings.
     */
    function contract_v2_commercial_v21_values(?PDO $conn, array $lease): array
    {
        $blank = '_______________';
        $t = fn($k) => trim((string)($lease[$k] ?? ''));
        $or = fn($v) => $v !== '' && $v !== null ? contract_v2_h((string)$v) : $blank;

        $hasGuarantor = $t('guarantor_name') !== '';
        if (!$hasGuarantor && $t('guarantor_waived_reason') === '') {
            throw new RuntimeException('The commercial contract needs a guarantor. Add the guarantor on the lease, or an Admin must enter a reason for no guarantor.');
        }

        $trn = array_key_exists('__landlord_trn', $lease) ? (string)$lease['__landlord_trn'] : '';
        if ($trn === '' && $conn && !empty($lease['company_id'])) {
            try {
                $st = $conn->prepare("SELECT trn FROM company_settings WHERE company_id = ? AND trn IS NOT NULL AND trn <> '' ORDER BY id DESC LIMIT 1");
                $st->execute([(int)$lease['company_id']]);
                $trn = (string)$st->fetchColumn();
            } catch (Throwable $e) {
                $trn = '';
            }
        }
        $sqft = $lease['unit_size_sqft'] ?? null;
        if (($sqft === null || (float)$sqft <= 0) && !empty($lease['area_sqm'])) {
            $sqft = (float)$lease['area_sqm'] * 10.7639;
        }
        $fitDep = (float)($lease['fit_out_deposit'] ?? 0);
        $rent = isset($lease['annual_rent']) ? (float)$lease['annual_rent'] : (float)($lease['monthly_rent'] ?? 0) * 12;
        $tpl = CONTRACT_V2_TPL_INSURANCE_MIN;
        return [
            '{{LANDLORD_LICENCE_NO}}' => $or(CONTRACT_V2_LANDLORD_LICENCE_NO),
            '{{LANDLORD_TRN}}' => $or($trn),
            '{{LANDLORD_SIGNATORY}}' => $or(CONTRACT_V2_LANDLORD_SIGNATORY),
            '{{TENANT_LICENCE_NO}}' => $or($t('trade_licence_no')),
            '{{TENANT_TRN}}' => $or($t('tenant_trn')),
            '{{TENANT_SIGNATORY_NAME}}' => $or($t('tenant_signatory_name')),
            '{{TENANT_SIGNATORY_EID}}' => $or($t('tenant_signatory_eid')),
            '{{TENANT_BANK_ACCOUNT_NAME}}' => $or($t('tenant_bank_account_name')),
            '{{GUARANTOR_PHONE}}' => $or($t('guarantor_phone')),
            '{{GUARANTOR_EMAIL}}' => $or($t('guarantor_email')),
            '{{COMMUNITY}}' => $or($t('building_address')),
            '{{PREMISES_AREA_SQFT}}' => $sqft !== null && (float)$sqft > 0 ? number_format((float)$sqft, 2) : $blank,
            '{{SERVICE_CHARGE_TERMS}}' => $t('service_charge_terms') !== '' ? contract_v2_h($t('service_charge_terms')) : 'None — included in the rent',
            '{{FIT_OUT_DEPOSIT}}' => $fitDep > 0
                ? 'AED ' . number_format($fitDep, 2) . ' (refundable under Clause {{REF:fitout}})'
                : 'None',
            '{{PARKING_TERMS}}' => $t('parking_terms') !== '' ? contract_v2_h($t('parking_terms')) : 'None, unless agreed by the Landlord in writing',
            '{{TPL_INSURANCE_MIN}}' => $tpl !== null ? number_format((float)$tpl) : $blank,
            '{{ANNUAL_RENT_WORDS}}' => contract_v2_amount_words($rent),
            '{{SECURITY_DEPOSIT_WORDS}}' => contract_v2_amount_words((float)($lease['security_deposit'] ?? 0)),
        ];
    }

    /** Annex A with Rent / VAT / Total columns (commercial v2.1). Row 'vat' = VAT recorded for that instalment. */
    function contract_v2_render_schedule_vat(array $schedule): string
    {
        $html = '<table class="annex-table" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><th>No.</th><th>Period from</th><th>Period to</th><th>Due date</th><th>Rent (AED)</th><th>VAT (AED)</th><th>Total (AED)</th><th>Cheque no.</th><th>Bank</th></tr>';
        if (!$schedule) {
            return $html . contract_v2_blank_rows(6, 9) . '</table>';
        }
        $tr = $tv = $tt = 0.0;
        foreach ($schedule as $r) {
            $total = (float)$r['amount'];
            $vat = (float)($r['vat'] ?? 0);
            $rent = round($total - $vat, 2);
            $tr += $rent; $tv += $vat; $tt += $total;
            $html .= '<tr>'
                . '<td class="c">' . (int)$r['instalment_no'] . '</td>'
                . '<td class="c">' . contract_v2_h(contract_v2_date($r['period_from'])) . '</td>'
                . '<td class="c">' . contract_v2_h(contract_v2_date($r['period_to'])) . '</td>'
                . '<td class="c">' . contract_v2_h(contract_v2_date($r['due_date'])) . '</td>'
                . '<td class="r">' . number_format($rent, 2) . '</td>'
                . '<td class="r">' . number_format($vat, 2) . '</td>'
                . '<td class="r">' . number_format($total, 2) . '</td>'
                . '<td class="c">' . contract_v2_h($r['cheque_no']) . '</td>'
                . '<td>' . contract_v2_h($r['bank']) . '</td>'
                . '</tr>';
        }
        $html .= '<tr class="annex-total"><td colspan="4" class="r">Total</td><td class="r">' . number_format($tr, 2) . '</td><td class="r">'
            . number_format($tv, 2) . '</td><td class="r">' . number_format($tt, 2) . '</td><td colspan="2"></td></tr>';
        return $html . '</table>';
    }

    function contract_v2_is_commercial_code(?string $code): bool
    {
        return in_array((string)$code, contract_v2_commercial_codes(), true);
    }

    /** Lease form dropdown: stored value => wording printed on page 1 of the commercial contract. */
    function contract_v2_commercial_uses(): array
    {
        return [
            'shop'       => 'Shop',
            'office'     => 'Office',
            'food_court' => 'Food Court',
        ];
    }

    /**
     * Commercial contract values (page 1 + use flags). Throws when the lease has no use, or no VAT on rent.
     * $lease keys: commercial_use, permitted_activity, trade_licence_no, fit_out_days, vat_applicable_on_rent, rent_vat_amount.
     */
    function contract_v2_commercial_values(array $lease): array
    {
        $uses = contract_v2_commercial_uses();
        $use = trim((string)($lease['commercial_use'] ?? ''));
        if (!isset($uses[$use])) {
            throw new RuntimeException('Choose the Commercial Use on the lease (Shop / Office / Food Court) before printing the commercial contract.');
        }
        if (empty($lease['vat_applicable_on_rent']) || (float)($lease['rent_vat_amount'] ?? 0) <= 0) {
            throw new RuntimeException('The commercial contract says rent is subject to 5% VAT. Tick "Apply VAT on annual rent" on the lease before printing.');
        }
        $activity = trim((string)($lease['permitted_activity'] ?? ''));
        $licence = trim((string)($lease['trade_licence_no'] ?? ''));
        $days = (int)($lease['fit_out_days'] ?? 0);
        $blank = '_______________';
        return [
            'values' => [
                '{{COMMERCIAL_USE}}' => contract_v2_h($uses[$use]),
                '{{PERMITTED_ACTIVITY}}' => $activity !== '' ? contract_v2_h($activity) : $blank,
                '{{TRADE_LICENCE_NO}}' => $licence !== '' ? contract_v2_h($licence) : $blank,
                '{{FIT_OUT_PERIOD}}' => $days > 0 ? $days . ' day' . ($days === 1 ? '' : 's') . ' from the start date' : 'None',
            ],
            'flags' => [
                'USE_SHOP' => $use === 'shop',
                'USE_OFFICE' => $use === 'office',
                'USE_FOOD_COURT' => $use === 'food_court',
            ],
        ];
    }

    function contract_v2_default_rooms_commercial(): array
    {
        return ['Shopfront / entrance', 'Glass and shutters', 'Main area', 'Ceiling and lights', 'Walls and floor',
            'Air-conditioning', 'Pantry / kitchen', 'Toilet', 'Store / back area'];
    }

    /**
     * New placeholders of v2, with type and default. Values from the lease override these.
     * Bool entries are flags for {{#if ...}} blocks.
     */
    function contract_v2_defaults(): array
    {
        return [
            'MINOR_REPAIR_THRESHOLD'    => ['type' => 'int',    'default' => 500],
            'EJARI_REGISTRATION_DAYS'   => ['type' => 'int',    'default' => 7],
            // Clause 12(b)(iii): handling fee per returned cheque, no VAT (owner's decision 2026-11-09).
            'CHEQUE_ADMIN_FEE'          => ['type' => 'int',    'default' => 500],
            // No default: worked out from the unit (contract_v2_compute_max_occupants) and saved on the lease.
            'MAX_OCCUPANTS'             => ['type' => 'int',    'default' => null],
            'OCCUPANCY_TYPE'            => ['type' => 'string', 'default' => 'a single family'],
            'LANDLORD_NOTICE_EMAIL'     => ['type' => 'string', 'default' => 'tenantcare@ainalreempro.com'],
            'GUARANTOR_NAME'            => ['type' => 'string', 'default' => ''],
            'GUARANTOR_EID'             => ['type' => 'string', 'default' => ''],
            'HAS_GUARANTOR'             => ['type' => 'bool',   'default' => false],
            // TODO(legal): confirm enforceability of renewal service fee (clause on AED 1,500 / 2%).
            'INCLUDE_RENEWAL_FEE'       => ['type' => 'bool',   'default' => true],
            // Set true to stop the Annex A day-of-month warning for a lease with agreed odd dates.
            'SCHEDULE_DATE_OVERRIDE'    => ['type' => 'bool',   'default' => false],
        ];
    }

    /**
     * Annex D Schedule of Rates (fixed list for every v2 lease). rate_aed null = not set yet:
     * printed as "—" and logged, until CONTRACT_V2_RATES_REQUIRED is switched on.
     * Rates to come from the maintenance team before v2 goes live.
     */
    function contract_v2_schedule_of_rates(): array
    {
        $rows = [
            ['Painting', 'Repainting', 'per wall'],
            ['Painting', 'Repainting', 'per room'],
            ['Cleaning', 'Deep cleaning - studio', 'per unit'],
            ['Cleaning', 'Deep cleaning - 1 bedroom', 'per unit'],
            ['Cleaning', 'Deep cleaning - 2 bedroom', 'per unit'],
            ['Cleaning', 'Deep cleaning - villa', 'per unit'],
            ['Cleaning', 'AC duct / filter cleaning', 'per AC unit'],
            ['Doors & hardware', 'Door handle', 'each'],
            ['Doors & hardware', 'Interior door', 'each'],
            ['Doors & hardware', 'Lock cylinder', 'each'],
            ['Kitchen', 'Cabinet door', 'each'],
            ['Kitchen', 'Hinge / drawer runner', 'each'],
            ['Kitchen', 'Mixer tap', 'each'],
            ['Bathroom', 'Toilet seat', 'each'],
            ['Bathroom', 'Shower head / hose', 'each'],
            ['Bathroom', 'Mixer tap', 'each'],
            ['Electrical', 'Light fitting', 'each'],
            ['Electrical', 'Switch / socket plate', 'each'],
            ['Windows', 'Blind / curtain rail', 'each'],
            ['Access', 'Key', 'each'],
            ['Access', 'Access card', 'each'],
            ['Access', 'Parking remote', 'each'],
        ];
        return array_map(fn($r) => ['category' => $r[0], 'item' => $r[1], 'unit' => $r[2], 'rate_aed' => null], $rows);
    }

    /**
     * Rates for one lease: the copy saved on the lease when its contract was first generated,
     * so a later change to the list never changes that lease's Annex D. Only rates that were
     * blank in the saved copy are taken from the current list (a blank was never agreed).
     */
    function contract_v2_rates_for_lease(array $lease, ?array $current = null): array
    {
        $current = $current ?? contract_v2_schedule_of_rates();
        $saved = [];
        if (!empty($lease['contract_rates_snapshot'])) {
            $decoded = json_decode((string)$lease['contract_rates_snapshot'], true);
            $saved = is_array($decoded) ? $decoded : [];
        }
        if (!$saved) {
            return $current;
        }
        $currentByKey = [];
        foreach ($current as $r) {
            $currentByKey[$r['category'] . '|' . $r['item'] . '|' . $r['unit']] = $r['rate_aed'];
        }
        foreach ($saved as &$r) {
            $key = ($r['category'] ?? '') . '|' . ($r['item'] ?? '') . '|' . ($r['unit'] ?? '');
            if ((!isset($r['rate_aed']) || $r['rate_aed'] === '' ) && isset($currentByKey[$key])) {
                $r['rate_aed'] = $currentByKey[$key];
            }
        }
        unset($r);
        return $saved;
    }

    /** Items with no rate (for the render-log warning). */
    function contract_v2_rates_missing(array $rates): array
    {
        $missing = [];
        foreach ($rates as $r) {
            if (!isset($r['rate_aed']) || $r['rate_aed'] === '' || !is_numeric($r['rate_aed'])) {
                $missing[] = $r['category'] . ': ' . $r['item'] . ' (' . $r['unit'] . ')';
            }
        }
        return $missing;
    }

    function contract_v2_render_rates(array $rates): string
    {
        $html = '<table class="annex-table" cellpadding="0" cellspacing="0" border="0"><tr><th>Item</th><th>Unit</th><th>Rate (AED)</th></tr>';
        $cat = null;
        foreach ($rates as $r) {
            if ($r['category'] !== $cat) {
                $cat = $r['category'];
                $html .= '<tr class="annex-cat"><td colspan="3">' . contract_v2_h($cat) . '</td></tr>';
            }
            $rate = (isset($r['rate_aed']) && is_numeric($r['rate_aed'])) ? number_format((float)$r['rate_aed'], 2) : '—';
            $html .= '<tr><td>' . contract_v2_h($r['item']) . '</td><td>' . contract_v2_h($r['unit']) . '</td><td class="r">' . $rate . '</td></tr>';
        }
        $html .= '</table>';
        return $html . contract_v2_signature_block(['Tenant', "Landlord's representative"]);
    }

    /** Upper bound per bedroom count (market / Dubai Municipality). Fixed for now; settings page later. */
    function contract_v2_occupancy_caps(): array
    {
        return [1 => 4, 2 => 6, 3 => 9, 4 => 11];
    }

    /**
     * Max occupants from the unit: studio 2; otherwise the lowest of 2 x bedrooms + 2,
     * the bedroom-area limit (area / 5 sqm, when the area is entered) and the cap table.
     * Throws when the unit has no bedrooms entered (the contract must not print a guess).
     */
    function contract_v2_compute_max_occupants($bedrooms, $bedroomAreaSqm = null): array
    {
        if ($bedrooms === null || $bedrooms === '' || !is_numeric($bedrooms) || (int)$bedrooms < 0) {
            throw new RuntimeException('The unit has no bedrooms entered. Enter the number of bedrooms on the unit (0 = studio) before printing the contract.');
        }
        $b = (int)$bedrooms;
        $area = ($bedroomAreaSqm !== null && $bedroomAreaSqm !== '' && (float)$bedroomAreaSqm > 0) ? (float)$bedroomAreaSqm : null;
        if ($b === 0) {
            return ['value' => 2, 'formula' => 2, 'area_cap' => null, 'cap' => 2, 'basis' => 'Studio → 2'];
        }
        $formula = 2 * $b + 2;
        $areaCap = $area !== null ? (int)floor($area / CONTRACT_V2_OCC_SQM_PER_PERSON) : null;
        $caps = contract_v2_occupancy_caps();
        $cap = $caps[$b] ?? (2 * $b + 3);
        $value = min($formula, $areaCap ?? $formula, $cap);
        $areaTxt = $area !== null ? rtrim(rtrim(number_format($area, 2, '.', ''), '0'), '.') . ' sqm bedrooms' : 'bedroom area not entered';
        return ['value' => $value, 'formula' => $formula, 'area_cap' => $areaCap, 'cap' => $cap,
            'basis' => $b . 'BR, ' . $areaTxt . ' → ' . $value];
    }

    /** bedrooms / bedroom_area_sqm of a unit (bedroom_area_sqm only if the column exists). */
    function contract_v2_load_unit_occupancy(PDO $conn, int $unitId): array
    {
        try {
            $st = $conn->prepare('SELECT bedrooms, bedroom_area_sqm FROM re_units WHERE id = ?');
            $st->execute([$unitId]);
        } catch (Throwable $e) {
            $st = $conn->prepare('SELECT bedrooms, NULL AS bedroom_area_sqm FROM re_units WHERE id = ?');
            $st->execute([$unitId]);
        }
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['bedrooms' => $row['bedrooms'] ?? null, 'bedroom_area_sqm' => $row['bedroom_area_sqm'] ?? null];
    }

    /**
     * Max occupants for the contract: the value saved on the lease (snapshot or Admin override);
     * when the lease has none yet, worked out from its unit. ['value' => int, 'basis' => string].
     */
    function contract_v2_max_occupants_for_lease(?PDO $conn, array $lease): array
    {
        if (isset($lease['max_occupants']) && (int)$lease['max_occupants'] > 0) {
            return ['value' => (int)$lease['max_occupants'], 'basis' => (string)($lease['max_occupants_basis'] ?? 'saved on lease')];
        }
        if (array_key_exists('unit_bedrooms', $lease)) {
            $u = ['bedrooms' => $lease['unit_bedrooms'], 'bedroom_area_sqm' => $lease['unit_bedroom_area_sqm'] ?? null];
        } elseif ($conn && !empty($lease['unit_id'])) {
            $u = contract_v2_load_unit_occupancy($conn, (int)$lease['unit_id']);
        } else {
            $u = ['bedrooms' => null, 'bedroom_area_sqm' => null];
        }
        $c = contract_v2_compute_max_occupants($u['bedrooms'], $u['bedroom_area_sqm']);
        return ['value' => $c['value'], 'basis' => $c['basis']];
    }

    /**
     * Lease save: what to store for max occupants.
     *   - Admin override ticked: the typed value, reason required, warning if above the cap or area limit.
     *   - Otherwise an existing lease keeps its saved value (snapshot) unless the unit changed.
     *   - Otherwise worked out from the unit (NULL when the unit has no bedrooms entered).
     * $existing = the lease row before this save (null for a new lease).
     * Returns max_occupants, basis, override_reason, overridden_by_now (bool), warning, error.
     */
    function contract_v2_resolve_lease_occupancy(?array $existing, int $unitId, array $unitOcc, bool $isAdmin,
                                                  bool $overrideOn, $overrideValue, string $reason): array
    {
        $out = ['max_occupants' => null, 'basis' => null, 'override_reason' => null,
                'overridden_by_now' => false, 'keep_override_meta' => false, 'warning' => null, 'error' => null];
        $calc = null;
        try {
            $calc = contract_v2_compute_max_occupants($unitOcc['bedrooms'] ?? null, $unitOcc['bedroom_area_sqm'] ?? null);
        } catch (Throwable $e) {
            $calc = null;
        }
        $sameUnit = $existing && (int)($existing['unit_id'] ?? 0) === $unitId;
        $hadOverride = $existing && trim((string)($existing['max_occupants_override_reason'] ?? '')) !== '';

        if ($isAdmin && $overrideOn) {
            $n = is_numeric($overrideValue) ? (int)$overrideValue : 0;
            $reason = trim($reason);
            if ($n < 1 || $n > 50) {
                $out['error'] = 'Override max occupants must be between 1 and 50.';
                return $out;
            }
            if ($reason === '') {
                $out['error'] = 'Enter a reason for overriding max occupants.';
                return $out;
            }
            $out['max_occupants'] = $n;
            $out['override_reason'] = mb_substr($reason, 0, 255);
            $out['basis'] = 'Admin override: ' . $n . ($calc ? ' (calculated ' . $calc['basis'] . ')' : ' (unit has no bedrooms entered)');
            $out['overridden_by_now'] = !$hadOverride || (int)($existing['max_occupants'] ?? 0) !== $n
                || trim((string)$existing['max_occupants_override_reason']) !== $out['override_reason'];
            $out['keep_override_meta'] = !$out['overridden_by_now'];
            if ($calc) {
                $over = [];
                if ($n > $calc['cap']) {
                    $over[] = 'the cap of ' . $calc['cap'] . ' for this unit';
                }
                if ($calc['area_cap'] !== null && $n > $calc['area_cap']) {
                    $over[] = 'the bedroom-area limit of ' . $calc['area_cap'];
                }
                if ($over) {
                    $out['warning'] = 'Max occupants override (' . $n . ') is above ' . implode(' and ', $over) . '.';
                }
            }
            return $out;
        }
        if ($existing && $sameUnit && (int)($existing['max_occupants'] ?? 0) > 0 && (!$hadOverride || !$isAdmin)) {
            // Snapshot kept; a non-Admin save never removes an Admin override.
            $out['max_occupants'] = (int)$existing['max_occupants'];
            $out['basis'] = $existing['max_occupants_basis'] ?? null;
            $out['override_reason'] = $hadOverride ? $existing['max_occupants_override_reason'] : null;
            $out['keep_override_meta'] = $hadOverride;
            return $out;
        }
        if ($calc) {
            $out['max_occupants'] = $calc['value'];
            $out['basis'] = $calc['basis'];
        }
        return $out;
    }

    /** Lease form dropdown: stored value => wording printed in clause 17(b). */
    function contract_v2_occupancy_types(): array
    {
        return [
            'single_family' => ['label' => 'Single family', 'text' => 'a single family'],
            'couple'        => ['label' => 'Couple',        'text' => 'a couple'],
            'company_staff' => ['label' => 'Company staff', 'text' => "the Tenant's nominated employee(s) and their immediate family"],
        ];
    }

    /** Wording for clause 17(b) from the stored occupancy_type (a key above, or free text for Other). */
    function contract_v2_occupancy_text(?string $stored): string
    {
        $stored = trim((string)$stored);
        if ($stored === '') {
            return (string)contract_v2_defaults()['OCCUPANCY_TYPE']['default'];
        }
        $types = contract_v2_occupancy_types();
        return isset($types[$stored]) ? $types[$stored]['text'] : $stored;
    }

    function contract_v2_guarantor_required(?string $tenantType, float $annualRent): bool
    {
        return strtolower(trim((string)$tenantType)) === 'company'
            && $annualRent > CONTRACT_V2_GUARANTOR_RENT_LIMIT;
    }

    /** 500 -> "Five Hundred", 1250 -> "One Thousand Two Hundred Fifty". Whole numbers 0..999,999,999. */
    function contract_v2_number_words(int $n): string
    {
        if ($n < 0 || $n > 999999999) {
            throw new InvalidArgumentException('Number out of range for words: ' . $n);
        }
        if ($n === 0) {
            return 'Zero';
        }
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
            'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $under1000 = function (int $x) use ($ones, $tens): string {
            $parts = [];
            if ($x >= 100) {
                $parts[] = $ones[intdiv($x, 100)] . ' Hundred';
                $x %= 100;
            }
            if ($x >= 20) {
                $parts[] = $tens[intdiv($x, 10)] . ($x % 10 ? '-' . $ones[$x % 10] : '');
            } elseif ($x > 0) {
                $parts[] = $ones[$x];
            }
            return implode(' ', $parts);
        };
        $out = [];
        foreach ([1000000 => 'Million', 1000 => 'Thousand', 1 => ''] as $unit => $name) {
            if ($n >= $unit) {
                $chunk = intdiv($n, $unit);
                $n %= $unit;
                $out[] = trim($under1000($chunk) . ' ' . $name);
            }
        }
        return implode(' ', $out);
    }

    /** Western digits -> Arabic-Indic digits (used for the Arabic clause number). */
    function contract_v2_arabic_digits($n): string
    {
        return strtr((string)$n, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
    }

    /**
     * {{#if FLAG}} ... {{/if}} and {{#if FLAG}} ... {{else}} ... {{/if}}. Not nested.
     * An unknown flag throws, so a typo cannot silently drop a clause.
     */
    function contract_v2_render_conditionals(string $html, array $flags): string
    {
        $out = preg_replace_callback(
            '/\{\{#if\s+([A-Z0-9_]+)\s*\}\}(.*?)\{\{\/if\}\}/s',
            function ($m) use ($flags) {
                $name = $m[1];
                if (!array_key_exists($name, $flags)) {
                    throw new RuntimeException('Contract template uses unknown flag {{#if ' . $name . '}}');
                }
                $parts = preg_split('/\{\{else\}\}/', $m[2], 2);
                return !empty($flags[$name]) ? $parts[0] : ($parts[1] ?? '');
            },
            $html
        );
        if ($out === null) {
            throw new RuntimeException('Contract template conditional blocks could not be read');
        }
        if (preg_match('/\{\{(#if[^}]*|else|\/if)\}\}/', $out, $m)) {
            throw new RuntimeException('Contract template has an unmatched {{' . $m[1] . '}}');
        }
        return $out;
    }

    /**
     * Clause numbers after the {{#if}} blocks are resolved, so a dropped clause leaves no gap.
     * {{CLAUSE_NO}} starts the next clause and prints it; {{CLAUSE_NO_AR}} prints the same
     * number in Arabic digits; {{CLAUSE_REF}} prints the current number (for markers).
     */
    function contract_v2_number_clauses(string $html): string
    {
        // {{CLAUSE_NO:key}} numbers a clause and records the key; {{REF:key}} prints that number
        // (two passes, so a clause may refer to a later one). A reference to a clause that is not
        // printed (e.g. a food-court-only clause) throws, so a broken reference never prints.
        $n = 0;
        $keys = [];
        $html = preg_replace_callback('/\{\{(CLAUSE_NO|CLAUSE_NO_AR|CLAUSE_REF)(?::([a-z0-9_]+))?\}\}/', function ($m) use (&$n, &$keys) {
            if ($m[1] === 'CLAUSE_NO') {
                $n++;
                if (!empty($m[2])) {
                    if (isset($keys[$m[2]])) {
                        throw new RuntimeException('Contract template has the clause key "' . $m[2] . '" twice');
                    }
                    $keys[$m[2]] = $n;
                }
                return (string)$n;
            }
            return $m[1] === 'CLAUSE_NO_AR' ? contract_v2_arabic_digits($n) : (string)$n;
        }, $html);
        return preg_replace_callback('/\{\{REF:([a-z0-9_]+)\}\}/', function ($m) use ($keys) {
            if (!isset($keys[$m[1]])) {
                throw new RuntimeException('Contract refers to clause "' . $m[1] . '", which is not printed in this contract');
            }
            return (string)$keys[$m[1]];
        }, $html);
    }

    /** Throws when any {{...}} placeholder is left in the rendered HTML. */
    function contract_v2_assert_resolved(string $html): void
    {
        if (preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/', $html, $m)) {
            $names = array_values(array_unique($m[1]));
            throw new RuntimeException('Contract has unfilled placeholders: ' . implode(', ', $names));
        }
    }

    /**
     * Annex A rule: the first due date equals the lease start date, and every due date falls on
     * the start date's day of month (or the month's last day when that month is shorter).
     * Returns a list of warnings; empty when fine or when $override is true.
     */
    function contract_v2_validate_schedule(array $schedule, string $startDate, bool $override = false): array
    {
        if ($override || !$schedule || $startDate === '') {
            return [];
        }
        $start = DateTime::createFromFormat('Y-m-d', substr($startDate, 0, 10));
        if (!$start) {
            return ['Lease start date "' . $startDate . '" is not a valid date'];
        }
        $warnings = [];
        $first = (string)($schedule[0]['due_date'] ?? '');
        if ($first !== $start->format('Y-m-d')) {
            $warnings[] = 'First due date ' . $first . ' is not the lease start date ' . $start->format('Y-m-d');
        }
        $day = (int)$start->format('j');
        foreach ($schedule as $row) {
            $d = DateTime::createFromFormat('Y-m-d', (string)($row['due_date'] ?? ''));
            if (!$d) {
                $warnings[] = 'Instalment ' . ($row['instalment_no'] ?? '?') . ' has no valid due date';
                continue;
            }
            $expectedDay = min($day, (int)$d->format('t'));
            if ((int)$d->format('j') !== $expectedDay) {
                $warnings[] = 'Instalment ' . ($row['instalment_no'] ?? '?') . ' due ' . $d->format('Y-m-d')
                    . ' is not on day ' . $day . ' of the month';
            }
        }
        return $warnings;
    }

    function contract_v2_h($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }

    function contract_v2_date($ymd): string
    {
        $ymd = (string)$ymd;
        return $ymd !== '' && strtotime($ymd) ? date('d/m/Y', strtotime($ymd)) : '';
    }

    /**
     * Payment schedule rows for Annex A from the lease's cheques (replaced/cancelled/returned left out).
     * Each row: instalment_no, period_from, period_to, due_date, amount, cheque_no, bank.
     */
    function contract_v2_load_schedule(PDO $conn, array $lease): array
    {
        $stmt = $conn->prepare("
            SELECT c.cheque_date, c.cheque_amount, c.cheque_number, c.bank_name, c.payment_method, i.vat_amount
            FROM re_lease_cheques c
            LEFT JOIN re_lease_installments i ON i.id = c.installment_id
            WHERE c.lease_id = ?
              AND c.status NOT IN ('replaced', 'cancelled', 'returned')
              AND c.replaced_by_cheque_id IS NULL
            ORDER BY c.cheque_date ASC, c.id ASC
        ");
        $stmt->execute([(int)$lease['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $schedule = [];
        foreach ($rows as $r) {
            $schedule[] = [
                'due_date'  => (string)$r['cheque_date'],
                'amount'    => (float)$r['cheque_amount'],
                'cheque_no' => (string)($r['cheque_number'] ?? ''),
                'bank'      => (string)($r['bank_name'] ?? ''),
                'vat'       => (float)($r['vat_amount'] ?? 0),
            ];
        }
        return contract_v2_number_schedule($schedule, (string)($lease['end_date'] ?? ''));
    }

    /** Adds instalment_no and the period each payment covers (to the day before the next due date). */
    function contract_v2_number_schedule(array $schedule, string $endDate): array
    {
        $count = count($schedule);
        foreach ($schedule as $i => &$row) {
            $row['instalment_no'] = $i + 1;
            $row['period_from'] = $row['period_from'] ?? $row['due_date'];
            if (!isset($row['period_to'])) {
                // Next later due date (two cheques on one date cover the same period).
                $next = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    if ((string)$schedule[$j]['due_date'] > (string)$row['due_date']) {
                        $next = (string)$schedule[$j]['due_date'];
                        break;
                    }
                }
                $row['period_to'] = $next !== '' ? date('Y-m-d', strtotime($next . ' -1 day')) : $endDate;
            }
        }
        unset($row);
        return $schedule;
    }

    /** Latest non-cancelled move-in with its inspection items, keys and move-in meter readings. */
    function contract_v2_load_move_in(PDO $conn, array $lease): ?array
    {
        $stmt = $conn->prepare("
            SELECT id, move_in_date, keys_handed_over_at, inspection_completed_at
            FROM re_move_ins
            WHERE lease_id = ? AND status <> 'cancelled'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([(int)$lease['id']]);
        $mi = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$mi) {
            return null;
        }
        $items = $conn->prepare("
            SELECT section_name, item_text, is_checked, is_na, note, remark
            FROM re_move_in_inspection_items
            WHERE move_in_id = ?
            ORDER BY section_order, section_name, item_order, id
        ");
        $items->execute([(int)$mi['id']]);
        $photos = $conn->prepare("SELECT room_area, COUNT(*) n FROM re_move_in_photos WHERE move_in_id = ? AND file_type = 'photo' GROUP BY room_area");
        $photos->execute([(int)$mi['id']]);
        $photoCounts = [];
        foreach ($photos->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $photoCounts[strtolower(trim((string)$p['room_area']))] = (int)$p['n'];
        }
        $keys = $conn->prepare("SELECT key_label, quantity, card_numbers FROM re_move_in_keys WHERE move_in_id = ? ORDER BY display_order, id");
        $keys->execute([(int)$mi['id']]);
        $meters = $conn->prepare("
            SELECT meter_type, reading_value, meter_number FROM re_meter_readings
            WHERE lease_id = ? AND reading_type = 'move_in' ORDER BY reading_date DESC, id DESC
        ");
        $meters->execute([(int)$lease['id']]);
        $meterRows = [];
        foreach ($meters->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $meterRows[$m['meter_type']] = $meterRows[$m['meter_type']] ?? $m;
        }
        $rooms = [];
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $section = trim((string)$it['section_name']) ?: 'General';
            $condition = !empty($it['is_na']) ? 'N/A' : (!empty($it['is_checked']) ? 'Good' : 'Not checked');
            $remark = trim(implode(' ', array_filter([(string)$it['note'], (string)$it['remark']], 'strlen')));
            $rooms[] = [
                'room' => $section,
                'item' => (string)$it['item_text'],
                'condition' => $condition . ($remark !== '' ? ' - ' . $remark : ''),
                'photo_ref' => isset($photoCounts[strtolower($section)]) ? $photoCounts[strtolower($section)] . ' photo(s)' : '',
            ];
        }
        return [
            'handover' => (string)($mi['keys_handed_over_at'] ?: $mi['move_in_date']),
            'meters' => [
                'DEWA (electricity)' => isset($meterRows['electricity']) ? (string)$meterRows['electricity']['reading_value'] : '',
                'Cooling / chiller'  => isset($meterRows['other']) ? (string)$meterRows['other']['reading_value'] : '',
                'Gas'                => isset($meterRows['gas']) ? (string)$meterRows['gas']['reading_value'] : '',
            ],
            'rooms' => $rooms,
            'keys' => array_map(fn($k) => [
                'label' => (string)$k['key_label'],
                'qty' => (int)$k['quantity'],
                'cards' => (string)($k['card_numbers'] ?? ''),
            ], $keys->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    function contract_v2_default_rooms(): array
    {
        return ['Entrance / corridor', 'Living / dining', 'Kitchen', 'Bedroom 1', 'Bedroom 2',
            'Bathroom 1', 'Bathroom 2', 'Balcony', 'Utility / store'];
    }

    function contract_v2_blank_rows(int $count, int $cols): string
    {
        return str_repeat('<tr>' . str_repeat('<td class="annex-blank">&nbsp;</td>', $cols) . '</tr>', $count);
    }

    function contract_v2_signature_block(array $labels): string
    {
        $labelsRow = $lineRow = $subRow = '';
        foreach ($labels as $label) {
            $labelsRow .= '<td class="annex-sign-label">' . contract_v2_h($label) . '</td><td class="annex-sign-gap"></td>';
            $lineRow .= '<td class="annex-sign-line">&nbsp;</td><td class="annex-sign-gap"></td>';
            $subRow .= '<td class="annex-sign-sub">Name / Signature / Date</td><td class="annex-sign-gap"></td>';
        }
        return '<table class="annex-sign-table" cellpadding="0" cellspacing="0" border="0">'
            . '<tr>' . $labelsRow . '</tr><tr>' . $lineRow . '</tr><tr>' . $subRow . '</tr></table>';
    }

    function contract_v2_render_schedule(array $schedule): string
    {
        $html = '<table class="annex-table" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><th>No.</th><th>Period from</th><th>Period to</th><th>Due date</th><th>Amount (AED)</th><th>Cheque no.</th><th>Bank</th></tr>';
        if (!$schedule) {
            return $html . contract_v2_blank_rows(6, 7) . '</table>';
        }
        $total = 0.0;
        foreach ($schedule as $r) {
            $total += (float)$r['amount'];
            $html .= '<tr>'
                . '<td class="c">' . (int)$r['instalment_no'] . '</td>'
                . '<td class="c">' . contract_v2_h(contract_v2_date($r['period_from'])) . '</td>'
                . '<td class="c">' . contract_v2_h(contract_v2_date($r['period_to'])) . '</td>'
                . '<td class="c">' . contract_v2_h(contract_v2_date($r['due_date'])) . '</td>'
                . '<td class="r">' . number_format((float)$r['amount'], 2) . '</td>'
                . '<td class="c">' . contract_v2_h($r['cheque_no']) . '</td>'
                . '<td>' . contract_v2_h($r['bank']) . '</td>'
                . '</tr>';
        }
        $html .= '<tr class="annex-total"><td colspan="4" class="r">Total</td><td class="r">' . number_format($total, 2)
            . '</td><td colspan="2"></td></tr>';
        return $html . '</table>';
    }

    /** Annex B body: filled from the move-in record when there is one, otherwise a blank form. */
    function contract_v2_render_move_in(?array $mi, int $maxOccupants = 5, bool $commercial = false, ?array $rooms = null, array $opt = []): string
    {
        $handover = $mi && $mi['handover'] !== '' ? date('d/m/Y H:i', strtotime($mi['handover'])) : '';
        $html = '<table class="annex-kv" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td class="k">Handover date and time:</td><td class="v">' . ($handover !== '' ? contract_v2_h($handover) : '&nbsp;') . '</td></tr></table>';

        $html .= '<div class="annex-h">1. Meter readings</div><table class="annex-table" cellpadding="0" cellspacing="0" border="0"><tr><th>Meter</th><th>Reading at handover</th></tr>';
        $meters = $mi['meters'] ?? ($opt['meters'] ?? ['DEWA (electricity)' => '', 'Cooling / chiller' => '', 'Gas' => '']);
        foreach ($meters as $name => $val) {
            $html .= '<tr><td>' . contract_v2_h($name) . '</td><td>' . ($val !== '' ? contract_v2_h($val) : '&nbsp;') . '</td></tr>';
        }
        $html .= '</table>';

        $html .= '<div class="annex-h">2. Room-by-room condition</div><table class="annex-table" cellpadding="0" cellspacing="0" border="0"><tr><th>Room</th><th>Item</th><th>Condition</th><th>Photo ref.</th></tr>';
        if (!empty($mi['rooms'])) {
            foreach ($mi['rooms'] as $r) {
                $html .= '<tr><td>' . contract_v2_h($r['room']) . '</td><td>' . contract_v2_h($r['item']) . '</td><td>'
                    . contract_v2_h($r['condition']) . '</td><td>' . contract_v2_h($r['photo_ref']) . '</td></tr>';
            }
        } else {
            foreach ($rooms ?? ($commercial ? contract_v2_default_rooms_commercial() : contract_v2_default_rooms()) as $room) {
                $html .= '<tr><td>' . contract_v2_h($room) . '</td><td class="annex-blank">&nbsp;</td><td class="annex-blank">&nbsp;</td><td class="annex-blank">&nbsp;</td></tr>';
            }
        }
        $html .= '</table>';

        $html .= '<div class="annex-h">3. Non-original fittings</div><table class="annex-table" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><th>Fitting</th><th>Location</th><th>Tenant accepts maintenance</th></tr>';
        for ($i = 0; $i < 4; $i++) {
            $html .= '<tr><td class="annex-blank">&nbsp;</td><td class="annex-blank">&nbsp;</td><td class="c">&#9744; Yes &nbsp; &#9744; No</td></tr>';
        }
        $html .= '</table>';

        $html .= '<div class="annex-h">4. Keys and access cards</div><table class="annex-table" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><th>Key / card</th><th>Qty</th><th>Card numbers</th><th>Replacement cost (AED)</th></tr>';
        if (!empty($mi['keys'])) {
            foreach ($mi['keys'] as $k) {
                $html .= '<tr><td>' . contract_v2_h($k['label']) . '</td><td class="c">' . (int)$k['qty'] . '</td><td>'
                    . contract_v2_h($k['cards']) . '</td><td class="annex-blank">&nbsp;</td></tr>';
            }
        } else {
            $html .= contract_v2_blank_rows(4, 4);
        }
        $html .= '</table>';

        if ($commercial) {
            if (!empty($opt['v21'])) {
                $html .= '<div class="annex-h">5. Fit-out drawings approved</div>'
                    . '<div class="annex-note">&#9744; Yes, ref. ________________ &nbsp;&nbsp; &#9744; Not yet</div>';
            }
            return $html . contract_v2_signature_block(['Tenant', "Landlord's representative", 'Witness'])
                . (!empty($opt['v21']) ? '<div class="annex-note">&#9744; Tenant declined to attend / sign — witness name: ________________</div>' : '');
        }

        $html .= '<div class="annex-h">5. Occupants</div>'
            . '<div class="annex-note">Maximum occupants under Clause 17(b): ' . (int)$maxOccupants . '</div>'
            . '<table class="annex-table" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><th>No.</th><th>Name</th><th>Emirates ID / Passport no.</th></tr>';
        for ($i = 1; $i <= max(1, $maxOccupants); $i++) {
            $html .= '<tr><td class="c">' . $i . '</td><td class="annex-blank">&nbsp;</td><td class="annex-blank">&nbsp;</td></tr>';
        }
        $html .= '</table>';

        return $html . contract_v2_signature_block(['Tenant', "Landlord's representative", 'Witness']);
    }

    /** Annex C body: always blank at contract time; same rooms as Annex B plus "change since move-in". */
    function contract_v2_render_move_out(?array $mi, bool $commercial = false, ?array $rooms = null, array $opt = []): string
    {
        $html = '<table class="annex-kv" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td class="k">Key return date and time:</td><td class="v">&nbsp;</td></tr></table>';
        $html .= '<div class="annex-h">1. Final meter readings</div><table class="annex-table" cellpadding="0" cellspacing="0" border="0"><tr><th>Meter</th><th>Final reading</th></tr>';
        foreach (array_keys($opt['meters'] ?? ['DEWA (electricity)' => '', 'Cooling / chiller' => '', 'Gas' => '']) as $meter) {
            $html .= '<tr><td>' . contract_v2_h($meter) . '</td><td>&nbsp;</td></tr>';
        }
        $html .= '</table>';

        $html .= '<div class="annex-h">2. Room-by-room condition</div><table class="annex-table" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><th>Room</th><th>Item</th><th>Condition</th><th>Change since move-in</th><th>Photo ref.</th></tr>';
        if (!empty($mi['rooms'])) {
            foreach ($mi['rooms'] as $r) {
                $html .= '<tr><td>' . contract_v2_h($r['room']) . '</td><td>' . contract_v2_h($r['item'])
                    . '</td><td class="annex-blank">&nbsp;</td><td class="annex-blank">&nbsp;</td><td class="annex-blank">&nbsp;</td></tr>';
            }
        } else {
            foreach ($rooms ?? ($commercial ? contract_v2_default_rooms_commercial() : contract_v2_default_rooms()) as $room) {
                $html .= '<tr><td>' . contract_v2_h($room) . '</td>' . str_repeat('<td class="annex-blank">&nbsp;</td>', 4) . '</tr>';
            }
        }
        $html .= '</table>';

        $html .= '<div class="annex-h">3. Keys and access cards returned</div><table class="annex-table" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><th>Key / card</th><th>Qty given</th><th>Qty returned</th><th>Missing / charge (AED)</th></tr>';
        if (!empty($mi['keys'])) {
            foreach ($mi['keys'] as $k) {
                $html .= '<tr><td>' . contract_v2_h($k['label']) . '</td><td class="c">' . (int)$k['qty']
                    . '</td><td class="annex-blank">&nbsp;</td><td class="annex-blank">&nbsp;</td></tr>';
            }
        } else {
            $html .= contract_v2_blank_rows(4, 4);
        }
        $html .= '</table>';

        if (!empty($opt['v21'])) {
            $html .= '<div class="annex-h">4. Reinstatement under Clause {{REF:reinstate}}</div>'
                . '<div class="annex-note">&#9744; Complete &nbsp;&nbsp; &#9744; Incomplete (items: ________________________________)</div>';
        }
        return $html . contract_v2_signature_block(['Tenant', "Landlord's representative", 'Witness'])
            . (!empty($opt['v21']) ? '<div class="annex-note">&#9744; Tenant declined to attend / sign — witness name: ________________</div>' : '');
    }

    /**
     * All v2 placeholder values for one lease.
     * $lease may carry '__payment_schedule' / '__move_in' (used by the sample renderer and tests)
     * instead of reading them from the database.
     *
     * Returns ['values' => [ '{{NAME}}' => html ], 'flags' => [ NAME => bool ], 'warnings' => [...]].
     */
    function contract_v2_values(?PDO $conn, array $lease, ?string $templateCode = null): array
    {
        $defs = contract_v2_defaults();
        $v = [];
        foreach ($defs as $name => $def) {
            $v[$name] = $def['default'];
        }

        $commercial = contract_v2_is_commercial_code($templateCode);
        $labour = contract_v2_is_labour_code($templateCode);
        $com = $commercial ? contract_v2_commercial_values($lease) : null;
        $v21 = contract_v2_is_commercial_v21_code($templateCode);
        $com21 = $v21 ? contract_v2_commercial_v21_values($conn, $lease) : null;
        $lab = $labour ? contract_v2_labour_values($conn, $lease) : null;
        // Commercial and labour contracts have no bedroom-based occupancy clause: no bedrooms needed.
        $occ = ($commercial || $labour) ? ['value' => 0, 'basis' => ''] : contract_v2_max_occupants_for_lease($conn, $lease);
        $v['MAX_OCCUPANTS'] = $occ['value'];
        if (isset($lease['occupancy_type']) && trim((string)$lease['occupancy_type']) !== '') {
            $v['OCCUPANCY_TYPE'] = contract_v2_occupancy_text($lease['occupancy_type']);
        }
        $v['GUARANTOR_NAME'] = trim((string)($lease['guarantor_name'] ?? ''));
        $v['GUARANTOR_EID'] = trim((string)($lease['guarantor_eid'] ?? ''));
        $v['HAS_GUARANTOR'] = $v['GUARANTOR_NAME'] !== '';
        foreach (['INCLUDE_RENEWAL_FEE', 'SCHEDULE_DATE_OVERRIDE'] as $flag) {
            if (array_key_exists('__' . strtolower($flag), $lease)) {
                $v[$flag] = (bool)$lease['__' . strtolower($flag)];
            }
        }

        $annualRent = isset($lease['annual_rent']) ? (float)$lease['annual_rent'] : (float)($lease['monthly_rent'] ?? 0) * 12;
        if (contract_v2_guarantor_required($lease['tenant_type'] ?? '', $annualRent)
            && ($v['GUARANTOR_NAME'] === '' || $v['GUARANTOR_EID'] === '')) {
            throw new RuntimeException('This lease needs a guarantor (company tenant, annual rent over AED '
                . number_format(CONTRACT_V2_GUARANTOR_RENT_LIMIT) . '). Add the guarantor name and Emirates ID on the lease first.');
        }
        if ($v['HAS_GUARANTOR'] && $v['GUARANTOR_EID'] === '') {
            throw new RuntimeException('Guarantor Emirates ID is missing on the lease.');
        }

        if (array_key_exists('__payment_schedule', $lease)) {
            $schedule = contract_v2_number_schedule((array)$lease['__payment_schedule'], (string)($lease['end_date'] ?? ''));
        } else {
            $schedule = $conn ? contract_v2_load_schedule($conn, $lease) : [];
        }
        $moveIn = array_key_exists('__move_in', $lease)
            ? $lease['__move_in']
            : ($conn ? contract_v2_load_move_in($conn, $lease) : null);

        $rates = array_key_exists('__schedule_of_rates', $lease)
            ? (array)$lease['__schedule_of_rates']
            : contract_v2_rates_for_lease($lease, contract_v2_rate_list_for($templateCode, $lease));
        $missingRates = contract_v2_rates_missing($rates);
        $ratesRequired = array_key_exists('__rates_required', $lease) ? (bool)$lease['__rates_required'] : CONTRACT_V2_RATES_REQUIRED;
        if ($missingRates && $ratesRequired) {
            throw new RuntimeException('Annex D has no rate for: ' . implode('; ', $missingRates));
        }

        $warnings = contract_v2_validate_schedule($schedule, (string)($lease['start_date'] ?? ''), (bool)$v['SCHEDULE_DATE_OVERRIDE']);
        if ($missingRates) {
            $warnings[] = 'Annex D rates missing (printed as —) for ' . count($missingRates) . ' item(s): ' . implode('; ', $missingRates);
        }

        $v21Rooms = null;
        $v21Opt = [];
        if ($v21) {
            $v21Rooms = ['Shopfront / entrance', 'Glass and shutters', 'Main area', 'Ceiling and lights', 'Walls and floor',
                'Air-conditioning (base-build FCUs)', 'Pantry / kitchen', 'Toilet', 'Store / back area'];
            if (($lease['commercial_use'] ?? '') === 'food_court') {
                $v21Rooms = array_merge($v21Rooms, ['Kitchen exhaust', 'Grease trap', 'Gas connection']);
            }
            $v21Opt = ['v21' => true, 'meters' => ['DEWA (electricity)' => '', 'DEWA (water)' => '', 'Cooling' => '', 'Gas' => '']];
            // Annex A VAT column vs page 1 (records do not always agree; logged, not blocked).
            $vatSum = round(array_sum(array_map(fn($r) => (float)($r['vat'] ?? 0), $schedule)), 2);
            $vatLease = round((float)($lease['rent_vat_amount'] ?? 0), 2);
            if ($schedule && abs($vatSum - $vatLease) > 0.05) {
                $warnings[] = 'Annex A VAT total ' . number_format($vatSum, 2) . ' differs from VAT on rent ' . number_format($vatLease, 2) . ' on page 1';
            }
        }

        $count = $schedule ? count($schedule) : (int)($lease['number_of_installments'] ?? 0);
        $summary = $count > 0
            ? $count . ' instalment' . ($count === 1 ? '' : 's') . ' as per Annex A'
            : 'As per Annex A';

        $values = [
            '{{MINOR_REPAIR_THRESHOLD}}' => number_format((int)$v['MINOR_REPAIR_THRESHOLD']),
            '{{MINOR_REPAIR_THRESHOLD_WORDS}}' => contract_v2_number_words((int)$v['MINOR_REPAIR_THRESHOLD']),
            '{{CHEQUE_ADMIN_FEE}}' => number_format((int)$v['CHEQUE_ADMIN_FEE']),
            '{{CHEQUE_ADMIN_FEE_WORDS}}' => contract_v2_number_words((int)$v['CHEQUE_ADMIN_FEE']),
            '{{EJARI_REGISTRATION_DAYS}}' => (string)(int)$v['EJARI_REGISTRATION_DAYS'],
            '{{EJARI_REGISTRATION_DAYS_WORDS}}' => strtolower(contract_v2_number_words((int)$v['EJARI_REGISTRATION_DAYS'])),
            '{{MAX_OCCUPANTS}}' => (string)(int)$v['MAX_OCCUPANTS'],
            '{{MAX_OCCUPANTS_WORDS}}' => strtolower(contract_v2_number_words((int)$v['MAX_OCCUPANTS'])),
            '{{OCCUPANCY_TYPE}}' => contract_v2_h($v['OCCUPANCY_TYPE']),
            '{{LANDLORD_NOTICE_EMAIL}}' => contract_v2_h($v['LANDLORD_NOTICE_EMAIL']),
            '{{GUARANTOR_NAME}}' => contract_v2_h($v['GUARANTOR_NAME']),
            '{{GUARANTOR_EID}}' => contract_v2_h($v['GUARANTOR_EID']),
            '{{PAYMENT_SUMMARY}}' => contract_v2_h($summary),
            '{{PAYMENT_SCHEDULE}}' => $v21 ? contract_v2_render_schedule_vat($schedule) : contract_v2_render_schedule($schedule),
            '{{MOVE_IN_REPORT}}' => contract_v2_render_move_in($moveIn ?: null, (int)$v['MAX_OCCUPANTS'], $commercial || $labour,
                $labour ? contract_v2_default_rooms_labour() : ($v21 ? $v21Rooms : null), $v21 ? $v21Opt : []),
            '{{MOVE_OUT_REPORT}}' => contract_v2_render_move_out($moveIn ?: null, $commercial || $labour,
                $labour ? contract_v2_default_rooms_labour() : ($v21 ? $v21Rooms : null), $v21 ? $v21Opt : []),
            '{{SCHEDULE_OF_RATES}}' => contract_v2_render_rates($rates),
        ];
        $flags = [
            'HAS_GUARANTOR' => (bool)$v['HAS_GUARANTOR'],
            'INCLUDE_RENEWAL_FEE' => (bool)$v['INCLUDE_RENEWAL_FEE'],
        ];
        // Park Place Commercial: chiller lines only when the lease bills chiller.
        $flags['HAS_CHILLER'] = (float)($lease['chiller_fees'] ?? 0) > 0;
        if ($com) {
            $values += $com['values'];
            $flags += $com['flags'];
        }
        if ($lab) {
            $values += $lab;
        }
        if ($com21) {
            $values += $com21;
        }

        return ['values' => $values, 'flags' => $flags, 'warnings' => $warnings, 'schedule' => $schedule, 'rates' => $rates];
    }

    /**
     * v2 render pipeline, run on the template HTML before the old placeholder replacement:
     * resolve {{#if}} blocks, fill v2 values, number the clauses.
     * The caller fills the old placeholders and then calls contract_v2_assert_resolved().
     */
    function contract_v2_prepare(?PDO $conn, string $html, array $lease, ?string $templateCode = null): string
    {
        $data = contract_v2_values($conn, $lease, $templateCode);
        foreach ($data['warnings'] as $w) {
            error_log('Tenancy contract v2, lease ' . ($lease['id'] ?? '?') . ': ' . $w);
        }
        $html = contract_v2_render_conditionals($html, $data['flags']);
        $html = str_replace(array_keys($data['values']), array_values($data['values']), $html);
        return contract_v2_number_clauses($html);
    }
}
