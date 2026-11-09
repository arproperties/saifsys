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
if (!defined('CONTRACT_V2_GUARANTOR_RENT_LIMIT')) {
    // Company tenant with annual rent over this amount must name a guarantor.
    define('CONTRACT_V2_GUARANTOR_RENT_LIMIT', 100000);
}

if (!function_exists('contract_v2_is_v2_template')) {

    function contract_v2_is_v2_template($template): bool
    {
        return is_array($template)
            && isset($template['template_code'])
            && (string)$template['template_code'] === CONTRACT_V2_TEMPLATE_CODE;
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
            'MAX_OCCUPANTS'             => ['type' => 'int',    'default' => 4],
            'OCCUPANCY_TYPE'            => ['type' => 'string', 'default' => 'a single family'],
            'LANDLORD_NOTICE_EMAIL'     => ['type' => 'string', 'default' => 'ar.properties15@gmail.com'],
            'GUARANTOR_NAME'            => ['type' => 'string', 'default' => ''],
            'GUARANTOR_EID'             => ['type' => 'string', 'default' => ''],
            'HAS_GUARANTOR'             => ['type' => 'bool',   'default' => false],
            // TODO(legal): confirm enforceability of renewal service fee (clause on AED 1,500 / 2%).
            'INCLUDE_RENEWAL_FEE'       => ['type' => 'bool',   'default' => true],
            // Set true to stop the Annex A day-of-month warning for a lease with agreed odd dates.
            'SCHEDULE_DATE_OVERRIDE'    => ['type' => 'bool',   'default' => false],
        ];
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
        $n = 0;
        return preg_replace_callback('/\{\{(CLAUSE_NO|CLAUSE_NO_AR|CLAUSE_REF)\}\}/', function ($m) use (&$n) {
            if ($m[1] === 'CLAUSE_NO') {
                $n++;
                return (string)$n;
            }
            return $m[1] === 'CLAUSE_NO_AR' ? contract_v2_arabic_digits($n) : (string)$n;
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
            SELECT cheque_date, cheque_amount, cheque_number, bank_name, payment_method
            FROM re_lease_cheques
            WHERE lease_id = ?
              AND status NOT IN ('replaced', 'cancelled', 'returned')
              AND replaced_by_cheque_id IS NULL
            ORDER BY cheque_date ASC, id ASC
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
    function contract_v2_render_move_in(?array $mi): string
    {
        $handover = $mi && $mi['handover'] !== '' ? date('d/m/Y H:i', strtotime($mi['handover'])) : '';
        $html = '<table class="annex-kv" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td class="k">Handover date and time:</td><td class="v">' . ($handover !== '' ? contract_v2_h($handover) : '&nbsp;') . '</td></tr></table>';

        $html .= '<div class="annex-h">1. Meter readings</div><table class="annex-table" cellpadding="0" cellspacing="0" border="0"><tr><th>Meter</th><th>Reading at handover</th></tr>';
        $meters = $mi['meters'] ?? ['DEWA (electricity)' => '', 'Cooling / chiller' => '', 'Gas' => ''];
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
            foreach (contract_v2_default_rooms() as $room) {
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

        $html .= '<div class="annex-h">5. Occupants</div><table class="annex-table" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><th>No.</th><th>Name</th><th>Emirates ID / Passport no.</th></tr>';
        for ($i = 1; $i <= 5; $i++) {
            $html .= '<tr><td class="c">' . $i . '</td><td class="annex-blank">&nbsp;</td><td class="annex-blank">&nbsp;</td></tr>';
        }
        $html .= '</table>';

        return $html . contract_v2_signature_block(['Tenant', "Landlord's representative", 'Witness']);
    }

    /** Annex C body: always blank at contract time; same rooms as Annex B plus "change since move-in". */
    function contract_v2_render_move_out(?array $mi): string
    {
        $html = '<table class="annex-kv" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td class="k">Key return date and time:</td><td class="v">&nbsp;</td></tr></table>';
        $html .= '<div class="annex-h">1. Final meter readings</div><table class="annex-table" cellpadding="0" cellspacing="0" border="0"><tr><th>Meter</th><th>Final reading</th></tr>'
            . '<tr><td>DEWA (electricity)</td><td>&nbsp;</td></tr><tr><td>Cooling / chiller</td><td>&nbsp;</td></tr><tr><td>Gas</td><td>&nbsp;</td></tr></table>';

        $html .= '<div class="annex-h">2. Room-by-room condition</div><table class="annex-table" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><th>Room</th><th>Item</th><th>Condition</th><th>Change since move-in</th><th>Photo ref.</th></tr>';
        if (!empty($mi['rooms'])) {
            foreach ($mi['rooms'] as $r) {
                $html .= '<tr><td>' . contract_v2_h($r['room']) . '</td><td>' . contract_v2_h($r['item'])
                    . '</td><td class="annex-blank">&nbsp;</td><td class="annex-blank">&nbsp;</td><td class="annex-blank">&nbsp;</td></tr>';
            }
        } else {
            foreach (contract_v2_default_rooms() as $room) {
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

        return $html . contract_v2_signature_block(['Tenant', "Landlord's representative", 'Witness']);
    }

    /**
     * All v2 placeholder values for one lease.
     * $lease may carry '__payment_schedule' / '__move_in' (used by the sample renderer and tests)
     * instead of reading them from the database.
     *
     * Returns ['values' => [ '{{NAME}}' => html ], 'flags' => [ NAME => bool ], 'warnings' => [...]].
     */
    function contract_v2_values(?PDO $conn, array $lease): array
    {
        $defs = contract_v2_defaults();
        $v = [];
        foreach ($defs as $name => $def) {
            $v[$name] = $def['default'];
        }

        if (isset($lease['max_occupants']) && (int)$lease['max_occupants'] > 0) {
            $v['MAX_OCCUPANTS'] = (int)$lease['max_occupants'];
        }
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

        $warnings = contract_v2_validate_schedule($schedule, (string)($lease['start_date'] ?? ''), (bool)$v['SCHEDULE_DATE_OVERRIDE']);

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
            '{{PAYMENT_SCHEDULE}}' => contract_v2_render_schedule($schedule),
            '{{MOVE_IN_REPORT}}' => contract_v2_render_move_in($moveIn ?: null),
            '{{MOVE_OUT_REPORT}}' => contract_v2_render_move_out($moveIn ?: null),
        ];
        $flags = [
            'HAS_GUARANTOR' => (bool)$v['HAS_GUARANTOR'],
            'INCLUDE_RENEWAL_FEE' => (bool)$v['INCLUDE_RENEWAL_FEE'],
        ];

        return ['values' => $values, 'flags' => $flags, 'warnings' => $warnings, 'schedule' => $schedule];
    }

    /**
     * v2 render pipeline, run on the template HTML before the old placeholder replacement:
     * resolve {{#if}} blocks, fill v2 values, number the clauses.
     * The caller fills the old placeholders and then calls contract_v2_assert_resolved().
     */
    function contract_v2_prepare(?PDO $conn, string $html, array $lease): string
    {
        $data = contract_v2_values($conn, $lease);
        foreach ($data['warnings'] as $w) {
            error_log('Tenancy contract v2, lease ' . ($lease['id'] ?? '?') . ': ' . $w);
        }
        $html = contract_v2_render_conditionals($html, $data['flags']);
        $html = str_replace(array_keys($data['values']), array_values($data['values']), $html);
        return contract_v2_number_clauses($html);
    }
}
