<?php
// Synthetic rule controls; this file is scanned, never executed.
// ruleid: grey-rock-request-code-execution
eval($_GET['command']);
// ruleid: grey-rock-request-code-execution
unserialize($_POST['payload']);
// ruleid: grey-rock-disabled-tls-verification
wp_remote_get('https://example.invalid/', ['sslverify' => false]);
// ok: grey-rock-disabled-tls-verification
wp_remote_get('https://example.invalid/', ['sslverify' => true]);
