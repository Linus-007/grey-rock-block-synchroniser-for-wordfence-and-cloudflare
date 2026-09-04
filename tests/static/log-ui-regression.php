<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$paths = [
  'settings' => $root . '/src/includes/Admin/Settings.php',
  'table' => $root . '/src/includes/Admin/LogTable.php',
  'logger' => $root . '/src/includes/Services/BlockLogger.php',
  'readme' => $root . '/README.md',
  'wp_readme' => $root . '/readme.txt',
];

$contents = [];

foreach ($paths as $name => $path) {
  $value = file_get_contents($path);

  if (!is_string($value)) {
    fwrite(
      STDERR,
      'FAIL: Could not read ' . $path . PHP_EOL
    );
    exit(1);
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

$require(
  str_contains(
    $contents['table'],
    '$this->_column_headers = ['
  ),
  'LogTable must initialise WP_List_Table column headers.'
);

$require(
  str_contains(
    $contents['table'],
    '$this->get_columns()'
  ),
  'LogTable headers must use get_columns().'
);

$require(
  !str_contains($contents['settings'], "'Block Log'"),
  'Site Admin must not use the Block Log label.'
);

$require(
  !str_contains(
    $contents['settings'],
    "'Network Synchronisation Log'"
  ),
  'Network Admin must not use the old network heading.'
);

$require(
  !str_contains(
    $contents['settings'],
    "'Grey Rock Synchronisation Log'"
  ),
  'Site Admin must not use the old site heading.'
);

$require(
  str_contains(
    $contents['settings'],
    'This page shows synchronisation records for this site only'
  ),
  'The site log must explain its local scope.'
);

$require(
  str_contains(
    $contents['settings'],
    "foreach (get_sites(['fields' => 'ids']) as \$blog_id)"
  ),
  'Network Admin must enumerate sites.'
);

$require(
  str_contains(
    $contents['settings'],
    'switch_to_blog((int) $blog_id);'
  ),
  'Network Admin must switch to each site.'
);

$require(
  str_contains(
    $contents['settings'],
    'BlockLogger::get_logs('
  ),
  'Network Admin must read each site log.'
);

$require(
  str_contains(
    $contents['logger'],
    '$wpdb->prefix . self::TABLE'
  ),
  'Site log storage must use the current site prefix.'
);

$require(
  substr_count(
    $contents['readme'],
    "\n## Scheduling\n"
  ) === 1,
  'README.md must contain exactly one Scheduling heading.'
);

$require(
  substr_count(
    $contents['wp_readme'],
    "\n== Frequently Asked Questions ==\n"
  ) === 1,
  'readme.txt must contain exactly one Frequently Asked Questions heading.'
);

$require(
  str_contains(
    $contents['settings'],
    'An Account IP List does not block traffic by itself'
  ),
  'The administration guide must state that an Account IP List does not block traffic by itself.'
);

foreach (['readme', 'wp_readme'] as $document) {
  $require(
    str_contains(
      $contents[$document],
      'Settings → Configurations → Lists'
    ),
    $document . ' must document the list path.'
  );

  $require(
    str_contains(
      $contents[$document],
      'Account Filter Lists'
    ),
    $document . ' must document the current token permission.'
  );

  $require(
    str_contains(
      $contents[$document],
      'Security rules'
    ),
    $document . ' must document the Custom Rule path.'
  );

  $require(
    str_contains(
      $contents[$document],
      'does not block traffic by itself'
    ),
    $document . ' must explain that the list requires a blocking rule.'
  );

  $require(
    str_contains(
      $contents[$document],
      'ip.src in $wordfence_hot_blocklist'
    ),
    $document . ' must include the list expression.'
  );

  $require(
    str_contains(
      $contents[$document],
      'Site Admin'
    ),
    $document . ' must document site log scope.'
  );

  $require(
    str_contains(
      $contents[$document],
      'Network Admin'
    ),
    $document . ' must document network log scope.'
  );
}

$require(
  str_contains(
    $contents['table'],
    'get_sortable_columns'
  ),
  'Site LogTable must provide sortable columns.'
);

foreach (['ip', 'reason', 'created_at'] as $column) {
  $require(
    str_contains(
      $contents['table'],
      "'" . $column . "'"
    ),
    'Site LogTable must expose the ' . $column . ' column.'
  );
}

$require(
  str_contains(
    $contents['table'],
    'Reason contains'
  ),
  'Site LogTable must provide the reason filter.'
);

$require(
  str_contains(
    $contents['table'],
    "'date_from'"
  ),
  'Site LogTable must provide the From date filter.'
);

$require(
  str_contains(
    $contents['table'],
    "'date_to'"
  ),
  'Site LogTable must provide the To date filter.'
);

$require(
  str_contains(
    $contents['table'],
    "'Reset'"
  ),
  'Site LogTable must provide Reset.'
);

$require(
  str_contains(
    $contents['logger'],
    "WHERE (%s = '' OR ip = %s)"
  )
  && str_contains(
    $contents['logger'],
    "reason LIKE %s"
  )
  && str_contains(
    $contents['logger'],
    "created_at >= %s"
  )
  && str_contains(
    $contents['logger'],
    "created_at <= %s"
  ),
  'BlockLogger must implement server-side log filtering.'
);

$require(
  str_contains(
    $contents['logger'],
    '$allowed_orderby'
  ),
  'BlockLogger must allow-list sortable database columns.'
);

$require(
  str_contains(
    $contents['logger'],
    "reason LIKE %s"
  ),
  'BlockLogger must support reason substring filtering.'
);

$require(
  str_contains(
    $contents['settings'],
    "'All sites'"
  ),
  'Network Synchronisation Log must provide an All sites filter.'
);

$require(
  str_contains(
    $contents['settings'],
    "'orderby' => \$column"
  ),
  'Network Synchronisation Log must provide sortable headings.'
);

$require(
  str_contains(
    $contents['settings'],
    "network_admin_url('admin.php?page=firewall-sync-network-log')"
  ),
  'Network Synchronisation Log must provide a reset target.'
);

$require(
  str_contains(
    $contents['logger'],
    'INET6_ATON(ip)'
  ),
  'Site Synchronisation Log must sort IP addresses by binary address value.'
);

$require(
  str_contains(
    $contents['settings'],
    'compare_log_ip_addresses'
  )
  && str_contains(
    $contents['settings'],
    'inet_pton'
  ),
  'Network Synchronisation Log must sort IP addresses by address value rather than text.'
);

if ($failures !== []) {
  foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
  }

  exit(1);
}

echo 'PASS: Synchronisation Log and documentation contracts are present.'
  . PHP_EOL;
