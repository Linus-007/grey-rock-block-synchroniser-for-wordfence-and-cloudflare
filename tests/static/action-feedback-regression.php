<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$paths = [
  'settings' => $root . '/src/includes/Admin/Settings.php',
  'fields' => $root . '/src/includes/Admin/Fields.php',
  'javascript' => $root . '/src/assets/admin.js',
  'css' => $root . '/src/assets/admin.css',
];

$contents = [];

foreach ($paths as $name => $path) {
  $value = file_get_contents($path);

  if (!is_string($value)) {
    throw new RuntimeException(
      'FAIL: Could not read ' . $path
    );
  }

  $contents[$name] = $value;
}

$failures = [];

$require = static function (
  bool $condition,
  string $message
) use (&$failures): void {
  if (!$condition) {
    $failures[] = $message;
  }
};

/*
 * The existing WordPress top-of-page notices are deliberately retained.
 */
$require(
  str_contains(
    $contents['settings'],
    "settings_errors('firewall_sync_messages');"
  ),
  'The settings-page top notice must remain.'
);

$require(
  str_contains(
    $contents['settings'],
    "settings_errors('firewall_sync_manual_block');"
  ),
  'The Manual Block top notice must remain.'
);

/*
 * Inline feedback must reuse WordPress settings errors instead of
 * maintaining a second message store.
 */
$require(
  str_contains(
    $contents['settings'],
    'private static function render_action_feedback('
  ),
  'The contextual-feedback renderer is missing.'
);

$require(
  str_contains(
    $contents['settings'],
    "string \$setting = 'firewall_sync_messages'"
  ),
  'The feedback renderer must default to the main settings collection.'
);

$require(
  str_contains(
    $contents['settings'],
    'get_settings_errors($setting)'
  ),
  'Contextual feedback must reuse the WordPress settings-error collection.'
);

$require(
  str_contains(
    $contents['settings'],
    "\$details['code'] ?? ''"
  ),
  'Contextual feedback must select the originating message code.'
);

$require(
  str_contains(
    $contents['settings'],
    "notice-%1\$s inline firewall-sync-inline-feedback"
  ),
  'Contextual feedback must use the inline WordPress notice presentation.'
);

$require(
  str_contains(
    $contents['settings'],
    "\$type === 'error' ? 'alert' : 'status'"
  ),
  'Contextual feedback must expose appropriate alert/status semantics.'
);

/*
 * Every directly rendered action on the settings page must have a
 * corresponding local-feedback association.
 */
foreach (
  [
    'firewall_sync_save_settings',
    'firewall_sync_validate_cf_credentials',
    'firewall_sync_test_block',
    'firewall_sync_network_sync_now',
    'firewall_sync_manual_list_ip',
  ] as $action
) {
  $require(
    str_contains(
      $contents['settings'],
      "'" . $action . "'"
    ),
    'Missing contextual-feedback association for ' . $action . '.'
  );
}

$require(
  str_contains(
    $contents['settings'],
    'self::render_action_feedback($action);'
  ),
  'Shared Site Action buttons must render contextual feedback.'
);

/*
 * Manual Block has its own settings-error collection and message code.
 */
$require(
  str_contains(
    $contents['settings'],
    "'firewall_sync_manual_block_message',"
  ),
  'Manual Block feedback must use its existing message code.'
);

$require(
  str_contains(
    $contents['settings'],
    "'firewall_sync_manual_block'"
  ),
  'Manual Block feedback must use its separate settings-error collection.'
);

/*
 * redirect_with_message() must associate the existing result with the
 * admin-post action that produced it.
 */
$require(
  str_contains(
    $contents['fields'],
    '$hook = current_action();'
  ),
  'The settings-message redirect must obtain the current admin action.'
);

$require(
  str_contains(
    $contents['fields'],
    "str_starts_with(\$hook, 'admin_post_')"
  ),
  'The action-message code must be restricted to admin_post hooks.'
);

$require(
  str_contains(
    $contents['fields'],
    "substr(\$hook, strlen('admin_post_'))"
  ),
  'The admin_post prefix must be removed from the contextual message code.'
);

$require(
  str_contains(
    $contents['fields'],
    "'firewall_sync_messages',\n      \$message_code,"
  ),
  'The action-specific code must be passed to add_settings_error().'
);

/*
 * The Cloudflare layout JavaScript moves Validate, Run Test Block and
 * the test-IP field away from their original forms. Explicit HTML form
 * ownership must survive that relocation.
 */
$require(
  str_contains(
    $contents['javascript'],
    "const validateForm = validateButton.closest('form');"
  ),
  'Validate must capture its original form before relocation.'
);

$require(
  str_contains(
    $contents['javascript'],
    "const runForm = runButton.closest('form');"
  ),
  'Run Test Block must capture its original form before relocation.'
);

$require(
  str_contains(
    $contents['javascript'],
    "validateButton.setAttribute('form', validateForm.id);"
  ),
  'Validate must retain explicit form ownership.'
);

$require(
  str_contains(
    $contents['javascript'],
    "runButton.setAttribute('form', runForm.id);"
  ),
  'Run Test Block must retain explicit form ownership.'
);

$require(
  str_contains(
    $contents['javascript'],
    "testInput.setAttribute('form', runForm.id);"
  ),
  'The Cloudflare test-IP field must remain associated with the test form.'
);

/*
 * The feedback wrapper itself must move with the relocated button so
 * the result remains beside the control.
 */
$require(
  str_contains(
    $contents['javascript'],
    "validateButton.closest(\n      '.firewall-sync-action-feedback'\n    )"
  ),
  'Validate must relocate its contextual-feedback wrapper.'
);

$require(
  str_contains(
    $contents['javascript'],
    "runButton.closest(\n      '.firewall-sync-action-feedback'\n    )"
  ),
  'Run Test Block must relocate its contextual-feedback wrapper.'
);

$require(
  str_contains(
    $contents['javascript'],
    'validateAction || validateButton'
  ),
  'Validate relocation must prefer the feedback wrapper.'
);

$require(
  str_contains(
    $contents['javascript'],
    'runAction || runButton'
  ),
  'Run Test Block relocation must prefer the feedback wrapper.'
);

/*
 * Presentation contract.
 */
$require(
  str_contains(
    $contents['css'],
    '.firewall-sync-action-feedback {'
  ),
  'Contextual action-feedback layout CSS is missing.'
);

$require(
  str_contains(
    $contents['css'],
    '.firewall-sync-inline-feedback {'
  ),
  'Inline feedback notice CSS is missing.'
);

$require(
  str_contains(
    $contents['css'],
    'flex-wrap: wrap;'
  ),
  'Action feedback must wrap on constrained admin layouts.'
);

if ($failures !== []) {
  foreach ($failures as $failure) {
    fwrite(
      STDERR,
      'FAIL: ' . $failure . PHP_EOL
    );
  }

  throw new RuntimeException(
    'Contextual action-feedback regression failed.'
  );
}

echo 'PASS: Contextual action-feedback contracts are present.'
  . PHP_EOL;
