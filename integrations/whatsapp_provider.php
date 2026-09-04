<?php
// integrations/whatsapp_provider.php
declare(strict_types=1);

/**
 * Stub provider. Replace with Twilio / Meta Cloud later.
 * Return array: [bool $ok, ?string $error].
 */
function wa_send_text(string $to, string $text): array {
  // simulate success; add simple validation
  if (!$to || !$text) return [false, 'missing to/text'];
  // You could log to a file for now:
  // file_put_contents(__DIR__.'/wa_stub.log', date('c')." -> $to : $text\n", FILE_APPEND);
  return [true, null];
}
