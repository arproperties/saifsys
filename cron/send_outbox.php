<?php
// cron/send_outbox.php
declare(strict_types=1);

require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/notify.php';
require_once __DIR__.'/../integrations/whatsapp_provider.php';

function fetch_template(PDO $conn, string $code, string $channel): ?array {
  $st = $conn->prepare("SELECT * FROM notification_templates WHERE code=? AND channel=? AND enabled=1");
  $st->execute([$code, $channel]);
  return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$batch = $conn->query("
  SELECT *
  FROM outbox_messages
  WHERE status='queued' AND scheduled_at <= NOW()
  ORDER BY id
  LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($batch as $m) {
  $conn->prepare("UPDATE outbox_messages SET status='sending', attempts=attempts+1 WHERE id=? AND status='queued'")
       ->execute([$m['id']]);

  // re-read as 'sending' (avoid double-send)
  $st = $conn->prepare("SELECT * FROM outbox_messages WHERE id=? AND status='sending'");
  $st->execute([$m['id']]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) continue;

  $tpl = fetch_template($conn, $row['template_code'], $row['channel']);
  if (!$tpl) {
    $conn->prepare("UPDATE outbox_messages SET status='error', error_text='template missing' WHERE id=?")->execute([$row['id']]);
    continue;
  }

  $vars = json_decode($row['variables_json'] ?? '{}', true) ?: [];
  $text = nt_render($tpl['body'], $vars);

  $ok=false; $err=null;
  if ($row['channel']==='whatsapp') {
    [$ok, $err] = wa_send_text($row['recipient'], $text);
  } else {
    $err = 'channel not supported';
  }

  if ($ok) {
    $conn->prepare("UPDATE outbox_messages SET status='sent', sent_at=NOW(), error_text=NULL WHERE id=?")->execute([$row['id']]);
  } else {
    $conn->prepare("UPDATE outbox_messages SET status='error', error_text=? WHERE id=?")->execute([$err, $row['id']]);
  }
}

echo "Processed ".count($batch)." messages\n";
