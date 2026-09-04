<?php
// includes/calendar_tokens.php
declare(strict_types=1);

require_once __DIR__.'/db_connect.php';

/** Return existing token row for entity or null */
function cal_get_token(PDO $conn, string $type, int $id): ?array {
  $st = $conn->prepare("SELECT * FROM calendar_tokens WHERE entity_type=? AND entity_id=? AND revoked_at IS NULL ORDER BY id DESC LIMIT 1");
  $st->execute([$type, $id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

/** Ensure a token exists; create if missing; return token string */
function cal_ensure_token(PDO $conn, string $type, int $id): string {
  if ($row = cal_get_token($conn, $type, $id)) return $row['token'];
  $token = hash('sha256', bin2hex(random_bytes(32)));
  $ins = $conn->prepare("INSERT INTO calendar_tokens (entity_type, entity_id, token) VALUES (?,?,?)");
  $ins->execute([$type, $id, $token]);
  return $token;
}

/** Revoke current token (optional admin action) */
function cal_revoke_token(PDO $conn, string $type, int $id): void {
  $st = $conn->prepare("UPDATE calendar_tokens SET revoked_at=NOW() WHERE entity_type=? AND entity_id=? AND revoked_at IS NULL");
  $st->execute([$type, $id]);
}
