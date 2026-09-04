<?php
$dir = __DIR__ . '/uploads/branding';
mkdir($dir, 0777, true);
chmod(__DIR__ . '/uploads', 0777);
chmod($dir, 0777);
echo "Permissions fixed. Branding dir perms: " . substr(sprintf('%o', fileperms($dir)), -4);

