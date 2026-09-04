<?php
/**
 * Real Estate Module - Add/Edit Common Area
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/maintenance_location_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$currentCompanyId = (int)(current_company_id($conn) ?: 0);
if ($currentCompanyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $buildingId = (int)($_POST['building_id'] ?? 0);
    $areaName = trim($_POST['area_name'] ?? '');
    $areaType = $_POST['area_type'] ?? 'other';
    $floorNumber = ($_POST['floor_number'] ?? '') !== '' ? (int)$_POST['floor_number'] : null;
    $areaSqm = ($_POST['area_sqm'] ?? '') !== '' ? (float)$_POST['area_sqm'] : null;
    $capacity = ($_POST['capacity'] ?? '') !== '' ? (int)$_POST['capacity'] : null;
    $description = trim($_POST['description'] ?? '');
    $isActive = !empty($_POST['is_active']) ? 1 : 0;

    $allowedTypes = re_maint_area_types();
    if (!in_array($areaType, $allowedTypes, true)) {
        $areaType = 'other';
    }

    $stmt = $conn->prepare('SELECT id FROM re_buildings WHERE id = ? AND company_id = ?');
    $stmt->execute([$buildingId, $currentCompanyId]);
    if (!$stmt->fetch()) {
        header('Location: buildings.php');
        exit;
    }

    // Floor must be empty (building-wide) or an existing floor for this building
    if ($floorNumber !== null) {
        $fst = $conn->prepare('SELECT id FROM re_floors WHERE building_id = ? AND floor_number = ? LIMIT 1');
        $fst->execute([$buildingId, $floorNumber]);
        if (!$fst->fetch()) {
            $_SESSION['flash_error'] = 'Selected floor is not configured for this building. Use Manage Floors first.';
            header('Location: building_view.php?id=' . $buildingId);
            exit;
        }
    }

    $areaId = !empty($_POST['area_id']) ? (int)$_POST['area_id'] : null;
    $action = $_POST['action'] ?? 'add';

    if ($areaName === '') {
        $_SESSION['flash_error'] = 'Area name is required.';
        header('Location: building_view.php?id=' . $buildingId);
        exit;
    }

    // Duplicate name check (same building)
    $dupSql = 'SELECT id FROM re_building_common_areas WHERE building_id = ? AND area_name = ?';
    $dupParams = [$buildingId, $areaName];
    if ($action === 'edit' && $areaId) {
        $dupSql .= ' AND id <> ?';
        $dupParams[] = $areaId;
    }
    $dup = $conn->prepare($dupSql);
    $dup->execute($dupParams);
    if ($dup->fetch()) {
        $_SESSION['flash_error'] = 'A common area with this name already exists in this building.';
        header('Location: building_view.php?id=' . $buildingId);
        exit;
    }

    try {
        if ($action === 'edit' && $areaId) {
            $stmt = $conn->prepare('SELECT id FROM re_building_common_areas WHERE id = ? AND building_id = ?');
            $stmt->execute([$areaId, $buildingId]);
            if ($stmt->fetch()) {
                $stmt = $conn->prepare("
                    UPDATE re_building_common_areas
                    SET area_name = ?, area_type = ?, floor_number = ?, area_sqm = ?,
                        capacity = ?, description = ?, is_active = ?
                    WHERE id = ? AND building_id = ?
                ");
                $stmt->execute([
                    $areaName, $areaType, $floorNumber, $areaSqm, $capacity,
                    $description !== '' ? $description : null, $isActive, $areaId, $buildingId,
                ]);
            }
        } else {
            $stmt = $conn->prepare("
                INSERT INTO re_building_common_areas
                (building_id, area_name, area_type, floor_number, area_sqm, capacity, description, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $buildingId, $areaName, $areaType, $floorNumber, $areaSqm, $capacity,
                $description !== '' ? $description : null, $isActive,
            ]);
        }
    } catch (PDOException $e) {
        if ((int)$e->errorInfo[1] === 1062) {
            $_SESSION['flash_error'] = 'A common area with this name already exists in this building.';
        } else {
            $_SESSION['flash_error'] = 'Could not save common area.';
        }
        header('Location: building_view.php?id=' . $buildingId);
        exit;
    }

    header('Location: building_view.php?id=' . $buildingId);
    exit;
}

header('Location: buildings.php');
exit;
