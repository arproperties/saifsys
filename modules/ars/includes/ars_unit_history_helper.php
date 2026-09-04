<?php
/**
 * ARS unit tenant history helpers.
 * Manual long-term occupancies live beside existing booking history.
 */

if (!function_exists('arsHistoryTablesReady')) {
    function arsHistoryTablesReady(PDO $conn): bool {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            $conn->query("SELECT 1 FROM ars_unit_occupancies LIMIT 1");
            $conn->query("SELECT 1 FROM ars_unit_monthly_ledger LIMIT 1");
            $conn->query("SELECT 1 FROM ars_unit_occupancy_payments LIMIT 1");
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('arsUnitHistoryMigrationMessage')) {
    function arsUnitHistoryMigrationMessage(): string {
        return 'ARS flat tenant history tables are not installed yet. Run migrations/ars_flat_tenant_history.sql first.';
    }
}

if (!function_exists('arsCurrentUserId')) {
    function arsCurrentUserId(): ?int {
        if (function_exists('current_user_id')) {
            $id = current_user_id();
            return $id ? (int)$id : null;
        }
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null);
    }
}

if (!function_exists('arsGetUnitProfile')) {
    function arsGetUnitProfile(PDO $conn, int $unitId): ?array {
        $stmt = $conn->prepare("
            SELECT u.*, b.name AS building_name
            FROM re_units u
            LEFT JOIN re_buildings b ON b.id = u.building_id
            WHERE u.id = ? AND u.rental_mode IN ('short_term','both')
            LIMIT 1
        ");
        $stmt->execute([$unitId]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        return $unit ?: null;
    }
}

if (!function_exists('arsGetGuestOptions')) {
    function arsGetGuestOptions(PDO $conn, int $companyId, int $limit = 500): array {
        $stmt = $conn->prepare("
            SELECT id, first_name, last_name, phone, email, id_type, id_number, nationality
            FROM ars_guests
            WHERE company_id = ? AND is_active = 1
            ORDER BY first_name, last_name
            LIMIT {$limit}
        ");
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('arsFindOrCreateGuestForOccupancy')) {
    function arsFindOrCreateGuestForOccupancy(PDO $conn, int $companyId, array $data): ?int {
        $guestId = (int)($data['guest_id'] ?? 0);
        if ($guestId > 0) return $guestId;

        $firstName = trim((string)($data['tenant_first_name'] ?? ''));
        $lastName = trim((string)($data['tenant_last_name'] ?? ''));
        if ($firstName === '') return null;

        $phone = trim((string)($data['tenant_phone'] ?? ''));
        $idNumber = trim((string)($data['tenant_id_number'] ?? ''));

        if ($idNumber !== '') {
            $stmt = $conn->prepare("SELECT id FROM ars_guests WHERE company_id = ? AND id_number = ? LIMIT 1");
            $stmt->execute([$companyId, $idNumber]);
            $existing = (int)$stmt->fetchColumn();
            if ($existing) return $existing;
        }
        if ($phone !== '') {
            $stmt = $conn->prepare("SELECT id FROM ars_guests WHERE company_id = ? AND phone = ? AND first_name = ? LIMIT 1");
            $stmt->execute([$companyId, $phone, $firstName]);
            $existing = (int)$stmt->fetchColumn();
            if ($existing) return $existing;
        }

        $stmt = $conn->prepare("
            INSERT INTO ars_guests
                (company_id, first_name, last_name, email, phone, nationality, id_type, id_number, notes, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $companyId,
            $firstName,
            $lastName ?: '-',
            trim((string)($data['tenant_email'] ?? '')) ?: null,
            $phone ?: null,
            trim((string)($data['tenant_nationality'] ?? '')) ?: null,
            trim((string)($data['tenant_id_type'] ?? '')) ?: null,
            $idNumber ?: null,
            'Created from ARS flat tenant history.',
        ]);
        return (int)$conn->lastInsertId();
    }
}

if (!function_exists('arsSnapshotGuestForOccupancy')) {
    function arsSnapshotGuestForOccupancy(PDO $conn, int $companyId, ?int $guestId, array $data): array {
        $snapshot = [
            'tenant_first_name' => trim((string)($data['tenant_first_name'] ?? '')),
            'tenant_last_name' => trim((string)($data['tenant_last_name'] ?? '')),
            'tenant_phone' => trim((string)($data['tenant_phone'] ?? '')),
            'tenant_email' => trim((string)($data['tenant_email'] ?? '')),
            'tenant_id_type' => trim((string)($data['tenant_id_type'] ?? '')),
            'tenant_id_number' => trim((string)($data['tenant_id_number'] ?? '')),
            'tenant_nationality' => trim((string)($data['tenant_nationality'] ?? '')),
        ];

        if ($guestId) {
            $stmt = $conn->prepare("SELECT * FROM ars_guests WHERE id = ? AND company_id = ? LIMIT 1");
            $stmt->execute([$guestId, $companyId]);
            if ($guest = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $snapshot['tenant_first_name'] = $snapshot['tenant_first_name'] ?: (string)$guest['first_name'];
                $snapshot['tenant_last_name'] = $snapshot['tenant_last_name'] ?: (string)$guest['last_name'];
                $snapshot['tenant_phone'] = $snapshot['tenant_phone'] ?: (string)($guest['phone'] ?? '');
                $snapshot['tenant_email'] = $snapshot['tenant_email'] ?: (string)($guest['email'] ?? '');
                $snapshot['tenant_id_type'] = $snapshot['tenant_id_type'] ?: (string)($guest['id_type'] ?? '');
                $snapshot['tenant_id_number'] = $snapshot['tenant_id_number'] ?: (string)($guest['id_number'] ?? '');
                $snapshot['tenant_nationality'] = $snapshot['tenant_nationality'] ?: (string)($guest['nationality'] ?? '');
            }
        }

        return $snapshot;
    }
}

if (!function_exists('arsGetOccupanciesForUnit')) {
    function arsGetOccupanciesForUnit(PDO $conn, int $companyId, int $unitId): array {
        if (!arsHistoryTablesReady($conn)) return [];
        $stmt = $conn->prepare("
            SELECT o.*, g.first_name AS guest_first_name, g.last_name AS guest_last_name,
                   COALESCE(l.total_charges, 0) AS ledger_charges,
                   COALESCE(l.paid_amount, 0) AS ledger_paid,
                   COALESCE(l.pending_balance, 0) AS ledger_balance,
                   COALESCE(p.total_payments, 0) AS manual_payments
            FROM ars_unit_occupancies o
            LEFT JOIN ars_guests g ON g.id = o.guest_id
            LEFT JOIN (
                SELECT occupancy_id, SUM(total_charges) AS total_charges, SUM(paid_amount) AS paid_amount, SUM(pending_balance) AS pending_balance
                FROM ars_unit_monthly_ledger
                GROUP BY occupancy_id
            ) l ON l.occupancy_id = o.id
            LEFT JOIN (
                SELECT occupancy_id, SUM(amount) AS total_payments
                FROM ars_unit_occupancy_payments
                GROUP BY occupancy_id
            ) p ON p.occupancy_id = o.id
            WHERE o.company_id = ? AND o.unit_id = ?
            ORDER BY o.status = 'active' DESC, o.move_in_date DESC, o.id DESC
        ");
        $stmt->execute([$companyId, $unitId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('arsGetActiveOccupancy')) {
    function arsGetActiveOccupancy(PDO $conn, int $companyId, int $unitId): ?array {
        if (!arsHistoryTablesReady($conn)) return null;
        $stmt = $conn->prepare("
            SELECT *
            FROM ars_unit_occupancies
            WHERE company_id = ? AND unit_id = ? AND status = 'active'
            ORDER BY move_in_date DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$companyId, $unitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('arsCreateOccupancy')) {
    function arsCreateOccupancy(PDO $conn, int $companyId, int $unitId, array $data, ?int $createdBy = null): int {
        if (!arsHistoryTablesReady($conn)) {
            throw new RuntimeException(arsUnitHistoryMigrationMessage());
        }

        $moveIn = $data['move_in_date'] ?? date('Y-m-d');
        $guestId = arsFindOrCreateGuestForOccupancy($conn, $companyId, $data);
        $snapshot = arsSnapshotGuestForOccupancy($conn, $companyId, $guestId, $data);
        if ($snapshot['tenant_first_name'] === '') {
            throw new InvalidArgumentException('Tenant first name is required.');
        }

        $conn->beginTransaction();
        try {
            $previousMoveOut = date('Y-m-d', strtotime($moveIn . ' -1 day'));
            $conn->prepare("
                UPDATE ars_unit_occupancies
                SET status = 'inactive',
                    move_out_date = COALESCE(move_out_date, ?),
                    final_settlement_notes = COALESCE(final_settlement_notes, 'Automatically moved out when a new active tenant was added.')
                WHERE company_id = ? AND unit_id = ? AND status = 'active'
            ")->execute([$previousMoveOut, $companyId, $unitId]);

            $stmt = $conn->prepare("
                INSERT INTO ars_unit_occupancies
                    (company_id, unit_id, guest_id, tenant_first_name, tenant_last_name, tenant_phone, tenant_email,
                     tenant_id_type, tenant_id_number, tenant_nationality, contract_number, status,
                     contract_start, contract_end, move_in_date, monthly_rent, security_deposit,
                     deposit_status, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $companyId,
                $unitId,
                $guestId ?: null,
                $snapshot['tenant_first_name'],
                $snapshot['tenant_last_name'] ?: null,
                $snapshot['tenant_phone'] ?: null,
                $snapshot['tenant_email'] ?: null,
                $snapshot['tenant_id_type'] ?: null,
                $snapshot['tenant_id_number'] ?: null,
                $snapshot['tenant_nationality'] ?: null,
                trim((string)($data['contract_number'] ?? '')) ?: null,
                $data['contract_start'] ?: null,
                $data['contract_end'] ?: null,
                $moveIn,
                max(0, (float)($data['monthly_rent'] ?? 0)),
                max(0, (float)($data['security_deposit'] ?? 0)),
                $data['deposit_status'] ?: 'none',
                trim((string)($data['notes'] ?? '')) ?: null,
                $createdBy,
            ]);
            $occupancyId = (int)$conn->lastInsertId();

            if (!empty($data['generate_ledger'])) {
                arsGenerateMonthlyLedgerRows($conn, $companyId, $occupancyId, $unitId, $moveIn, $data['contract_end'] ?: null, (int)($data['ledger_months'] ?? 12));
            }

            $conn->commit();
            return $occupancyId;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('arsGenerateMonthlyLedgerRows')) {
    function arsGenerateMonthlyLedgerRows(PDO $conn, int $companyId, int $occupancyId, int $unitId, string $startDate, ?string $endDate = null, int $maxMonths = 12): int {
        if (!arsHistoryTablesReady($conn)) return 0;
        $stmt = $conn->prepare("SELECT monthly_rent, security_deposit FROM ars_unit_occupancies WHERE id = ? AND company_id = ? LIMIT 1");
        $stmt->execute([$occupancyId, $companyId]);
        $occ = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$occ) return 0;

        $monthlyRent = (float)$occ['monthly_rent'];
        $deposit = (float)$occ['security_deposit'];
        $start = new DateTime(date('Y-m-01', strtotime($startDate)));
        $months = max(1, min(120, $maxMonths ?: 12));
        if ($endDate) {
            $end = new DateTime(date('Y-m-01', strtotime($endDate)));
            $diff = (($end->format('Y') - $start->format('Y')) * 12) + ((int)$end->format('m') - (int)$start->format('m')) + 1;
            $months = max(1, min(120, $diff));
        }

        $inserted = 0;
        $stmt = $conn->prepare("
            INSERT INTO ars_unit_monthly_ledger
                (company_id, occupancy_id, unit_id, ledger_month, rent_amount, deposit_amount, total_charges, paid_amount, pending_balance, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0.00, ?, 'pending')
            ON DUPLICATE KEY UPDATE
                rent_amount = VALUES(rent_amount),
                deposit_amount = VALUES(deposit_amount),
                total_charges = rent_amount + deposit_amount + maintenance_charges + utility_charges + other_charges,
                pending_balance = GREATEST((rent_amount + deposit_amount + maintenance_charges + utility_charges + other_charges) - paid_amount, 0),
                status = CASE
                    WHEN pending_balance <= 0 THEN 'paid'
                    WHEN paid_amount > 0 THEN 'partial'
                    ELSE 'pending'
                END
        ");
        for ($i = 0; $i < $months; $i++) {
            $month = (clone $start)->modify("+{$i} months")->format('Y-m-01');
            $monthDeposit = $i === 0 ? $deposit : 0.00;
            $total = $monthlyRent + $monthDeposit;
            $stmt->execute([$companyId, $occupancyId, $unitId, $month, $monthlyRent, $monthDeposit, $total, $total]);
            $inserted++;
        }
        return $inserted;
    }
}

if (!function_exists('arsMoveOutOccupancy')) {
    function arsMoveOutOccupancy(PDO $conn, int $companyId, int $occupancyId, array $data): void {
        if (!arsHistoryTablesReady($conn)) {
            throw new RuntimeException(arsUnitHistoryMigrationMessage());
        }
        $stmt = $conn->prepare("
            UPDATE ars_unit_occupancies
            SET status = 'inactive',
                move_out_date = ?,
                final_settlement_amount = ?,
                final_settlement_date = ?,
                final_settlement_notes = ?,
                notes = CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE '\n' END, ?)
            WHERE id = ? AND company_id = ?
        ");
        $notes = trim((string)($data['final_settlement_notes'] ?? ''));
        $stmt->execute([
            $data['move_out_date'] ?: date('Y-m-d'),
            max(0, (float)($data['final_settlement_amount'] ?? 0)),
            $data['final_settlement_date'] ?: ($data['move_out_date'] ?: date('Y-m-d')),
            $notes ?: null,
            $notes ?: '',
            $occupancyId,
            $companyId,
        ]);
    }
}

if (!function_exists('arsGetOccupancyLedger')) {
    function arsGetOccupancyLedger(PDO $conn, int $companyId, int $occupancyId): array {
        if (!arsHistoryTablesReady($conn)) return [];
        $stmt = $conn->prepare("
            SELECT *
            FROM ars_unit_monthly_ledger
            WHERE company_id = ? AND occupancy_id = ?
            ORDER BY ledger_month ASC
        ");
        $stmt->execute([$companyId, $occupancyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('arsGetOccupancyPayments')) {
    function arsGetOccupancyPayments(PDO $conn, int $companyId, int $occupancyId): array {
        if (!arsHistoryTablesReady($conn)) return [];
        $stmt = $conn->prepare("
            SELECT p.*, l.ledger_month
            FROM ars_unit_occupancy_payments p
            LEFT JOIN ars_unit_monthly_ledger l ON l.id = p.ledger_id
            WHERE p.company_id = ? AND p.occupancy_id = ?
            ORDER BY p.payment_date DESC, p.id DESC
        ");
        $stmt->execute([$companyId, $occupancyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('arsRecordOccupancyPayment')) {
    function arsRecordOccupancyPayment(PDO $conn, int $companyId, int $occupancyId, array $data, ?int $createdBy = null): void {
        if (!arsHistoryTablesReady($conn)) {
            throw new RuntimeException(arsUnitHistoryMigrationMessage());
        }
        $ledgerId = (int)($data['ledger_id'] ?? 0);
        $amount = max(0, (float)($data['amount'] ?? 0));
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        $conn->beginTransaction();
        try {
            $unitStmt = $conn->prepare("SELECT unit_id FROM ars_unit_occupancies WHERE id = ? AND company_id = ? LIMIT 1");
            $unitStmt->execute([$occupancyId, $companyId]);
            if (!$unitStmt->fetchColumn()) {
                throw new InvalidArgumentException('Occupancy not found.');
            }

            $stmt = $conn->prepare("
                INSERT INTO ars_unit_occupancy_payments
                    (company_id, occupancy_id, ledger_id, payment_date, amount, payment_method, reference_number, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $companyId,
                $occupancyId,
                $ledgerId ?: null,
                $data['payment_date'] ?: date('Y-m-d'),
                $amount,
                $data['payment_method'] ?: 'cash',
                trim((string)($data['reference_number'] ?? '')) ?: null,
                trim((string)($data['notes'] ?? '')) ?: null,
                $createdBy,
            ]);

            if ($ledgerId) {
                $conn->prepare("
                    UPDATE ars_unit_monthly_ledger
                    SET paid_amount = paid_amount + ?,
                        pending_balance = GREATEST(total_charges - (paid_amount + ?), 0),
                        status = CASE
                            WHEN GREATEST(total_charges - (paid_amount + ?), 0) <= 0 THEN 'paid'
                            WHEN (paid_amount + ?) > 0 THEN 'partial'
                            ELSE 'pending'
                        END
                    WHERE id = ? AND company_id = ? AND occupancy_id = ?
                ")->execute([$amount, $amount, $amount, $amount, $ledgerId, $companyId, $occupancyId]);
            }

            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('arsUpdateLedgerCharges')) {
    function arsUpdateLedgerCharges(PDO $conn, int $companyId, int $ledgerId, array $data): void {
        if (!arsHistoryTablesReady($conn)) {
            throw new RuntimeException(arsUnitHistoryMigrationMessage());
        }
        $rent = max(0, (float)($data['rent_amount'] ?? 0));
        $deposit = max(0, (float)($data['deposit_amount'] ?? 0));
        $maintenance = max(0, (float)($data['maintenance_charges'] ?? 0));
        $utilities = max(0, (float)($data['utility_charges'] ?? 0));
        $other = max(0, (float)($data['other_charges'] ?? 0));
        $total = $rent + $deposit + $maintenance + $utilities + $other;

        $stmt = $conn->prepare("
            UPDATE ars_unit_monthly_ledger
            SET rent_amount = ?,
                deposit_amount = ?,
                maintenance_charges = ?,
                utility_charges = ?,
                other_charges = ?,
                total_charges = ?,
                pending_balance = GREATEST(? - paid_amount, 0),
                status = CASE
                    WHEN GREATEST(? - paid_amount, 0) <= 0 THEN 'paid'
                    WHEN paid_amount > 0 THEN 'partial'
                    ELSE 'pending'
                END,
                notes = ?
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([
            $rent,
            $deposit,
            $maintenance,
            $utilities,
            $other,
            $total,
            $total,
            $total,
            trim((string)($data['notes'] ?? '')) ?: null,
            $ledgerId,
            $companyId,
        ]);
    }
}

if (!function_exists('arsGetUnitBookingHistory')) {
    function arsGetUnitBookingHistory(PDO $conn, int $companyId, int $unitId): array {
        $stmt = $conn->prepare("
            SELECT b.*, g.first_name, g.last_name, g.phone AS guest_phone,
                   COALESCE(c.charge_total, 0) AS charge_total,
                   COALESCE(p.payment_total, 0) AS payment_total,
                   COALESCE(c.charge_count, 0) AS charge_count,
                   COALESCE(p.payment_count, 0) AS payment_count
            FROM ars_bookings b
            LEFT JOIN ars_guests g ON g.id = b.guest_id
            LEFT JOIN (
                SELECT booking_id, SUM(total) AS charge_total, COUNT(*) AS charge_count
                FROM ars_booking_charges
                GROUP BY booking_id
            ) c ON c.booking_id = b.id
            LEFT JOIN (
                SELECT booking_id, SUM(amount) AS payment_total, COUNT(*) AS payment_count
                FROM ars_booking_payments
                GROUP BY booking_id
            ) p ON p.booking_id = b.id
            WHERE b.company_id = ? AND b.unit_id = ?
            ORDER BY b.check_in DESC, b.id DESC
            LIMIT 300
        ");
        $stmt->execute([$companyId, $unitId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('arsGetUnitMaintenanceHistory')) {
    function arsGetUnitMaintenanceHistory(PDO $conn, int $unitId): array {
        try {
            $stmt = $conn->prepare("
                SELECT id, request_date, priority, category, description, status, cost, completed_at
                FROM re_maintenance_requests
                WHERE unit_id = ?
                ORDER BY request_date DESC, id DESC
                LIMIT 100
            ");
            $stmt->execute([$unitId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('arsBuildUnitTimeline')) {
    function arsBuildUnitTimeline(array $occupancies, array $bookings): array {
        $items = [];
        foreach ($occupancies as $o) {
            $items[] = [
                'type' => 'occupancy',
                'date' => $o['move_in_date'] ?? '',
                'end_date' => $o['move_out_date'] ?? '',
                'title' => trim(($o['tenant_first_name'] ?? '') . ' ' . ($o['tenant_last_name'] ?? '')),
                'subtitle' => ($o['contract_number'] ?? '') ?: 'Manual monthly tenant',
                'status' => $o['status'] ?? '',
            ];
        }
        foreach ($bookings as $b) {
            $items[] = [
                'type' => 'booking',
                'date' => $b['check_in'] ?? '',
                'end_date' => $b['check_out'] ?? '',
                'title' => trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')),
                'subtitle' => $b['booking_number'] ?? '',
                'status' => $b['status'] ?? '',
            ];
        }
        usort($items, fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));
        return $items;
    }
}

if (!function_exists('arsSearchUnitHistory')) {
    function arsSearchUnitHistory(PDO $conn, int $companyId, string $query): array {
        $query = trim($query);
        if ($query === '') return [];
        $like = '%' . $query . '%';
        $results = [];

        $stmt = $conn->prepare("
            SELECT DISTINCT u.id AS unit_id, u.unit_number, b.name AS building_name,
                   'booking' AS source_type,
                   CONCAT(g.first_name, ' ', g.last_name) AS match_name,
                   g.phone AS match_phone,
                   g.id_number AS match_id_number,
                   bk.booking_number AS match_reference
            FROM ars_bookings bk
            INNER JOIN re_units u ON u.id = bk.unit_id
            LEFT JOIN re_buildings b ON b.id = u.building_id
            LEFT JOIN ars_guests g ON g.id = bk.guest_id
            WHERE bk.company_id = ?
              AND (u.unit_number LIKE ? OR g.first_name LIKE ? OR g.last_name LIKE ? OR g.phone LIKE ? OR g.id_number LIKE ? OR bk.booking_number LIKE ?)
            ORDER BY u.unit_number, bk.check_in DESC
            LIMIT 100
        ");
        $stmt->execute([$companyId, $like, $like, $like, $like, $like, $like]);
        $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));

        if (arsHistoryTablesReady($conn)) {
            $stmt = $conn->prepare("
                SELECT DISTINCT u.id AS unit_id, u.unit_number, b.name AS building_name,
                       'occupancy' AS source_type,
                       CONCAT(o.tenant_first_name, ' ', COALESCE(o.tenant_last_name, '')) AS match_name,
                       o.tenant_phone AS match_phone,
                       o.tenant_id_number AS match_id_number,
                       o.contract_number AS match_reference
                FROM ars_unit_occupancies o
                INNER JOIN re_units u ON u.id = o.unit_id
                LEFT JOIN re_buildings b ON b.id = u.building_id
                WHERE o.company_id = ?
                  AND (u.unit_number LIKE ? OR o.tenant_first_name LIKE ? OR o.tenant_last_name LIKE ? OR o.tenant_phone LIKE ? OR o.tenant_id_number LIKE ? OR o.contract_number LIKE ?)
                ORDER BY u.unit_number, o.move_in_date DESC
                LIMIT 100
            ");
            $stmt->execute([$companyId, $like, $like, $like, $like, $like, $like]);
            $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        return $results;
    }
}

if (!function_exists('arsGetGuestOccupancyHistory')) {
    function arsGetGuestOccupancyHistory(PDO $conn, int $companyId, int $guestId): array {
        if (!arsHistoryTablesReady($conn)) return [];
        $stmt = $conn->prepare("
            SELECT o.*, u.unit_number, b.name AS building_name, COALESCE(l.pending_balance, 0) AS ledger_balance
            FROM ars_unit_occupancies o
            LEFT JOIN re_units u ON u.id = o.unit_id
            LEFT JOIN re_buildings b ON b.id = u.building_id
            LEFT JOIN (
                SELECT occupancy_id, SUM(pending_balance) AS pending_balance
                FROM ars_unit_monthly_ledger
                GROUP BY occupancy_id
            ) l ON l.occupancy_id = o.id
            WHERE o.company_id = ? AND o.guest_id = ?
            ORDER BY o.move_in_date DESC, o.id DESC
        ");
        $stmt->execute([$companyId, $guestId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
