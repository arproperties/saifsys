<?php
/**
 * On/off switch for Attendance → Bulk Apply.
 *
 * false = Bulk Apply is hidden for every user (Owner and Admin included):
 * the button is gone from Attendance and Monthly Summary, and opening
 * attendance_bulk.php directly just returns to the Attendance page.
 * Flip to true to bring it back. Nothing else changes either way.
 *
 * Turned off on 2026-11-12 after the Glaiza (E00029) review: one bulk click
 * overwrote a whole month and kept no old values.
 */
function hr_attendance_bulk_enabled(): bool
{
    return false;
}
