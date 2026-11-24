-- Recurrent Income: everything saifsys keeps for the module, in one file.
--
-- The payments, entries, receipts, accounts, buildings and income heads live in the Reem app;
-- these tables only keep what saifsys adds on top, keyed by Reem's ids: rental periods typed
-- by hand, notes, income heads deleted / restored, hidden accounts, groups of income heads,
-- rooms and their bedspaces / partitions, and payments moved to a room.
--
-- Safe to run again: every table is CREATE IF NOT EXISTS and every column ADD IF NOT EXISTS.


-- ==================== due_periods ====================
-- Recurrent Income: the rental period of a payment, typed by hand in saifsys.
--
-- A payment line itself lives in the Reem app; this table only keeps the period someone
-- corrected for it (from the receipt), keyed by Reem's line id. No row = the period is
-- worked out from the due date, as before.

CREATE TABLE IF NOT EXISTS rpay_due_periods (
    due_id      INT UNSIGNED NOT NULL PRIMARY KEY COMMENT 'Reem recurring_payment_dues.id',
    period_from DATE NOT NULL,
    period_to   DATE NOT NULL,
    updated_by  INT NULL COMMENT 'saifsys user.id',
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== entry_notes ====================
-- Recurrent Income: notes on an entry, kept in saifsys. Optional; add one at any time.
-- The entries themselves live in the Reem app; entry_id is Reem's entry id.

CREATE TABLE IF NOT EXISTS rpay_entry_notes (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entry_id    INT UNSIGNED NOT NULL COMMENT 'Reem recurring_payments.id',
    note        TEXT NOT NULL,
    created_by  INT NULL COMMENT 'saifsys user.id',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rpay_entry_notes_entry (entry_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A note written on one payment (a row of the payments list) also says which: due_id is
-- Reem's payment line id. Empty = a note about the whole entry.
ALTER TABLE rpay_entry_notes
    ADD COLUMN IF NOT EXISTS due_id INT UNSIGNED NULL COMMENT 'Reem recurring_payment_dues.id' AFTER entry_id,
    ADD KEY IF NOT EXISTS idx_rpay_entry_notes_due (due_id);

-- ==================== head_log ====================
-- Recurrent Income: income heads deleted and restored in saifsys, each with a reason.
-- The heads live in the Reem app and are not removed there: a head whose last row here is
-- 'deleted' is hidden from saifsys's lists and pickers; 'restored' brings it back.
-- Every row stays, so the history shows who did what, when and why.

CREATE TABLE IF NOT EXISTS rpay_head_log (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    head_id     INT UNSIGNED NOT NULL COMMENT 'Reem recurring_heads.id',
    head_name   VARCHAR(80) NOT NULL COMMENT 'its name at the time',
    action      ENUM('deleted', 'restored') NOT NULL,
    reason      VARCHAR(500) NOT NULL,
    created_by  INT NULL COMMENT 'saifsys user.id',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rpay_head_log_head (head_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== hidden_accounts ====================
-- Recurrent Income: accounts hidden in saifsys. The accounts live in the Reem app and keep
-- their money and history; a hidden one is only left out of the lists and the pickers.
-- Showing it again deletes its row.

CREATE TABLE IF NOT EXISTS rpay_hidden_accounts (
    account_id  INT UNSIGNED NOT NULL PRIMARY KEY COMMENT 'Reem recurring_accounts.id',
    hidden_by   INT NULL COMMENT 'saifsys user.id',
    hidden_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== head_groups ====================
-- Recurrent Income: groups of income heads, kept in saifsys (e.g. "DEWA" holds
-- "DEWA - Golden K Mart Ladies Camp" and "DEWA - Grocery Boys Camp").
-- The heads themselves live in the Reem app; head_id is Reem's head id.
-- A head is in one group at most. Deleting a group lets its heads go.

CREATE TABLE IF NOT EXISTS rpay_head_groups (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(80) NOT NULL,
    created_by  INT NULL COMMENT 'saifsys user.id',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rpay_head_groups_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rpay_head_group_members (
    head_id     INT UNSIGNED NOT NULL PRIMARY KEY COMMENT 'Reem recurring payment head id',
    group_id    INT UNSIGNED NOT NULL,
    KEY idx_rpay_hgm_group (group_id),
    CONSTRAINT fk_rpay_hgm_group FOREIGN KEY (group_id) REFERENCES rpay_head_groups (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fixed: the same amount every month (rent, partition, bedspace).
-- Dynamic: the amount changes every month (DEWA, washing machine coins, commission).
ALTER TABLE rpay_head_groups
    ADD COLUMN IF NOT EXISTS kind ENUM('fixed', 'dynamic') NOT NULL DEFAULT 'fixed' AFTER name;

-- Income heads already placed (by hand, or put in a group on their own when they were new).
-- A head that is in no group and not here is new: it is put in the group its name matches.
-- A head someone took out of every group stays here, so it is never put back on its own.
CREATE TABLE IF NOT EXISTS rpay_head_seen (
    head_id     INT UNSIGNED NOT NULL PRIMARY KEY COMMENT 'Reem recurring_heads.id',
    seen_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- The heads already in a group count as placed.
INSERT IGNORE INTO rpay_head_seen (head_id) SELECT head_id FROM rpay_head_group_members;

-- What period a group's payment is for, worked out from its due date.
-- prev_month: the month before (DEWA: the bill of October is due on 1 November).
-- none: no period unless one is typed by hand.
ALTER TABLE rpay_head_groups
    ADD COLUMN IF NOT EXISTS period_rule ENUM('none', 'prev_month') NOT NULL DEFAULT 'none' AFTER kind;

-- ==================== rooms ====================
-- Recurrent Income: rooms and their bedspaces, kept in saifsys (the camps' G1..G5, laundry room).
-- A room is in one of Reem's Recurrent Income buildings (building_id is Reem's id). Each bed is
-- occupied or vacant; the room's card adds them up. A group with has_rooms shows its rooms.

CREATE TABLE IF NOT EXISTS rpay_rooms (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    building_id INT UNSIGNED NOT NULL COMMENT 'Reem recurring_buildings.id',
    name        VARCHAR(80) NOT NULL,
    note        VARCHAR(255) NULL COMMENT 'e.g. Staff',
    sort        INT NOT NULL DEFAULT 0,
    created_by  INT NULL COMMENT 'saifsys user.id',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rpay_rooms_name (building_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rpay_room_beds (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    room_id     INT UNSIGNED NOT NULL,
    bed_no      VARCHAR(20) NOT NULL,
    status      ENUM('occupied', 'vacant') NOT NULL DEFAULT 'vacant',
    occupant    VARCHAR(120) NULL,
    note        VARCHAR(255) NULL,
    updated_by  INT NULL COMMENT 'saifsys user.id',
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rpay_room_beds (room_id, bed_no),
    CONSTRAINT fk_rpay_room_beds_room FOREIGN KEY (room_id) REFERENCES rpay_rooms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A group whose page shows the rooms (Bedspace / Room).
ALTER TABLE rpay_head_groups
    ADD COLUMN IF NOT EXISTS has_rooms TINYINT(1) NOT NULL DEFAULT 0 AFTER period_rule;

-- Who is in an occupied bed: staff (a saifsys employee and the company) or an outsider (a
-- name, in occupant, and the rent they pay).
ALTER TABLE rpay_room_beds
    ADD COLUMN IF NOT EXISTS occupant_type ENUM('staff', 'outsider') NULL AFTER status,
    ADD COLUMN IF NOT EXISTS employee_id INT NULL COMMENT 'saifsys employees.id' AFTER occupant_type,
    ADD COLUMN IF NOT EXISTS company_id INT NULL COMMENT 'saifsys companies.id' AFTER employee_id,
    ADD COLUMN IF NOT EXISTS rent DECIMAL(12,2) NULL COMMENT 'outsider: rent a month' AFTER occupant;

-- A payment moved to a room by hand (it was in the wrong room, or in none). It wins over the
-- room number found in the payment's unit or title. due_id is Reem's payment line id.
CREATE TABLE IF NOT EXISTS rpay_due_rooms (
    due_id      INT UNSIGNED NOT NULL PRIMARY KEY COMMENT 'Reem recurring_payment_dues.id',
    room_id     INT UNSIGNED NOT NULL,
    moved_by    INT NULL COMMENT 'saifsys user.id',
    moved_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rpay_due_rooms_room FOREIGN KEY (room_id) REFERENCES rpay_rooms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An outsider's rent due date.
ALTER TABLE rpay_room_beds
    ADD COLUMN IF NOT EXISTS due_date DATE NULL COMMENT 'outsider: when the rent is due' AFTER rent;

-- What a group calls a room's spaces ("Bedspace" in a camp, "Partition" in a flat).
ALTER TABLE rpay_head_groups
    ADD COLUMN IF NOT EXISTS space_word VARCHAR(30) NOT NULL DEFAULT 'Bedspace' AFTER has_rooms;

-- Which group a room is shown in (Bedspace / Room, Flat 219 (Partition), ...).
ALTER TABLE rpay_rooms
    ADD COLUMN IF NOT EXISTS group_id INT UNSIGNED NULL COMMENT 'rpay_head_groups.id' AFTER building_id;

-- Whether a group's spaces can be given to staff (a camp: yes; a flat's partitions: never).
ALTER TABLE rpay_head_groups
    ADD COLUMN IF NOT EXISTS allows_staff TINYINT(1) NOT NULL DEFAULT 1 AFTER space_word;

-- ==================== entry rooms ====================
-- The room an entry's payments go to, picked on the entry form (wins over the room number
-- found in the unit or title; a payment moved by hand still wins over this).
CREATE TABLE IF NOT EXISTS rpay_entry_rooms (
    entry_id    INT UNSIGNED NOT NULL PRIMARY KEY COMMENT 'Reem recurring_payments.id',
    room_id     INT UNSIGNED NOT NULL,
    set_by      INT NULL COMMENT 'saifsys user.id',
    set_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rpay_entry_rooms_room FOREIGN KEY (room_id) REFERENCES rpay_rooms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== the setup made on local (2026-11-17) ====================
-- The groups and their income heads, Fixed / Dynamic, period rules, the rooms and their
-- bedspaces / partitions, and payments moved to a room. Ids are Reem's (heads, payments) or
-- these tables' own. INSERT IGNORE: run again, nothing doubles and nothing done later on
-- live is overwritten. The 'who' columns are left empty (local user ids mean nothing on live).

INSERT IGNORE INTO `rpay_head_groups` (`id`, `name`, `kind`, `period_rule`, `has_rooms`, `space_word`, `allows_staff`, `created_by`, `created_at`) VALUES
    ('2', 'Commission', 'dynamic', 'none', '0', 'Bedspace', '1', NULL, '2026-11-17 11:12:35'),
    ('3', 'Flat 219 (Partition)', 'fixed', 'none', '1', 'Partition', '0', NULL, '2026-11-17 11:12:35'),
    ('4', 'Bedspace / Room', 'fixed', 'none', '1', 'Bedspace', '1', NULL, '2026-11-17 11:12:35'),
    ('5', 'Car Wash', 'fixed', 'none', '0', 'Bedspace', '1', NULL, '2026-11-17 11:12:35'),
    ('6', 'DEWA', 'dynamic', 'prev_month', '0', 'Bedspace', '1', NULL, '2026-11-17 11:12:35'),
    ('7', 'Other', 'dynamic', 'none', '0', 'Bedspace', '1', NULL, '2026-11-17 11:12:35'),
    ('8', 'Security Deposit', 'fixed', 'none', '0', 'Bedspace', '1', NULL, '2026-11-17 11:12:35'),
    ('9', 'Washing Machine', 'dynamic', 'none', '0', 'Bedspace', '1', NULL, '2026-11-17 11:12:35'),
    ('10', 'Water Cooler', 'dynamic', 'none', '0', 'Bedspace', '1', NULL, '2026-11-17 11:12:35');

INSERT IGNORE INTO `rpay_head_group_members` (`head_id`, `group_id`) VALUES
    ('1', '9'),
    ('2', '4'),
    ('3', '4'),
    ('4', '6'),
    ('5', '10'),
    ('6', '2'),
    ('7', '9'),
    ('8', '7'),
    ('9', '3'),
    ('10', '8'),
    ('11', '5'),
    ('12', '4'),
    ('13', '4'),
    ('14', '2'),
    ('15', '6');

INSERT IGNORE INTO `rpay_head_seen` (`head_id`, `seen_at`) VALUES
    ('1', '2026-11-17 12:34:40'),
    ('2', '2026-11-17 12:34:40'),
    ('3', '2026-11-17 12:34:40'),
    ('4', '2026-11-17 12:34:40'),
    ('5', '2026-11-17 12:34:40'),
    ('6', '2026-11-17 12:34:40'),
    ('7', '2026-11-17 12:34:40'),
    ('8', '2026-11-17 12:34:40'),
    ('9', '2026-11-17 12:34:40'),
    ('10', '2026-11-17 12:34:40'),
    ('11', '2026-11-17 12:34:40'),
    ('12', '2026-11-17 12:34:40'),
    ('13', '2026-11-17 12:34:40'),
    ('14', '2026-11-17 12:34:40'),
    ('15', '2026-11-17 12:34:40');

INSERT IGNORE INTO `rpay_rooms` (`id`, `building_id`, `group_id`, `name`, `note`, `sort`, `created_by`, `created_at`) VALUES
    ('1', '3', '4', 'G01', 'Staff', '1', NULL, '2026-11-17 14:02:56'),
    ('2', '3', '4', 'G02', NULL, '2', NULL, '2026-11-17 14:02:56'),
    ('3', '3', '4', 'G03', NULL, '3', NULL, '2026-11-17 14:02:56'),
    ('4', '3', '4', 'G04', NULL, '4', NULL, '2026-11-17 14:02:56'),
    ('5', '3', '4', 'G05', NULL, '5', NULL, '2026-11-17 14:02:56'),
    ('6', '3', '4', 'Laundry room', 'Free', '6', NULL, '2026-11-17 14:02:56'),
    ('7', '6', '3', 'Partition 01', NULL, '1', NULL, '2026-11-17 14:56:28'),
    ('8', '6', '3', 'Partition 02', NULL, '2', NULL, '2026-11-17 14:56:28'),
    ('9', '6', '3', 'Partition 03', NULL, '3', NULL, '2026-11-17 14:56:28');

INSERT IGNORE INTO `rpay_room_beds` (`id`, `room_id`, `bed_no`, `status`, `occupant_type`, `employee_id`, `company_id`, `occupant`, `rent`, `due_date`, `note`, `updated_by`, `updated_at`) VALUES
    ('1', '1', '1', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:06:12'),
    ('2', '1', '2', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:06:12'),
    ('3', '1', '3', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:06:12'),
    ('4', '1', '4', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:06:12'),
    ('5', '1', '5', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:06:12'),
    ('6', '1', '6', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:06:12'),
    ('7', '1', '7', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:06:12'),
    ('8', '1', '8', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:06:12'),
    ('9', '1', '9', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:06:12'),
    ('10', '1', '10', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:06:12'),
    ('11', '1', '11', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:06:12'),
    ('12', '1', '12', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:06:12'),
    ('13', '1', '13', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:06:12'),
    ('14', '1', '14', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:06:12'),
    ('15', '2', '1', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:29:29'),
    ('16', '2', '2', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:29:29'),
    ('17', '2', '3', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:29:29'),
    ('18', '2', '4', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:29:29'),
    ('19', '2', '5', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:02:56'),
    ('20', '2', '6', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:02:56'),
    ('21', '2', '7', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:02:56'),
    ('22', '2', '8', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:02:56'),
    ('23', '2', '9', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:02:56'),
    ('24', '2', '10', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:02:56'),
    ('25', '2', '11', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:02:56'),
    ('26', '2', '12', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:02:56'),
    ('27', '3', '1', 'occupied', 'outsider', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 16:14:00'),
    ('28', '3', '2', 'occupied', 'outsider', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('29', '3', '3', 'occupied', 'outsider', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('30', '3', '4', 'occupied', 'outsider', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('31', '3', '5', 'occupied', 'outsider', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('32', '3', '6', 'occupied', 'outsider', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('33', '3', '7', 'occupied', 'outsider', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('34', '3', '8', 'occupied', 'outsider', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('35', '4', '1', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('36', '4', '2', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('37', '4', '3', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('38', '4', '4', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('39', '4', '5', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('40', '4', '6', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('41', '4', '7', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('42', '4', '8', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('43', '4', '9', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('44', '4', '10', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('45', '4', '11', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('46', '4', '12', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('47', '5', '1', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('48', '5', '2', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('49', '5', '3', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('50', '5', '4', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('51', '5', '5', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('52', '5', '6', 'occupied', 'staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:21:46'),
    ('53', '7', '1', 'occupied', 'outsider', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 14:56:28'),
    ('54', '8', '1', 'occupied', 'outsider', NULL, NULL, 'Julie', NULL, NULL, NULL, NULL, '2026-11-17 14:56:28'),
    ('55', '9', '1', 'vacant', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-17 15:04:41');

INSERT IGNORE INTO `rpay_due_rooms` (`due_id`, `room_id`, `moved_by`, `moved_at`) VALUES
    ('5476', '3', NULL, '2026-11-17 14:54:13');
