<?php


use WPCF\FirewallSync\Cloudflare\Client;
use WPCF\FirewallSync\Services\IpValidator;
use WPCF\FirewallSync\Services\BlockLogger;
use WPCF\FirewallSync\Services\SyncScheduler;

const EXPECTED_LIST_ID = '7811817676fa4bac90479557ab74ba93';
const EXPECTED_LIST_NAME = 'wordfence_hot_blocklist';
const EXPECTED_TEST_IP = '8.8.8.8';

function create_client(string $token): Client
{
	return new Client($token, '');
}

function contains_ip(
	string $token,
	string $accountId,
	string $listId,
	string $ip
): bool {
	$client = create_client($token);
	$contains = $client->account_list_contains_ip(
		$accountId,
		$listId,
		$ip
	);

	$error = $client->get_last_error_message();

	if ($error !== '') {
		throw new RuntimeException($error);
	}

	return $contains;
}

$secretFile = '/run/secrets/cloudflare-test.json';

if (!is_readable($secretFile)) {
	fwrite(STDERR, "ERROR: Cloudflare test secret is unavailable.\n");
	exit(1);
}

try {
	$configuration = json_decode(
		(string) file_get_contents($secretFile),
		true,
		16,
		JSON_THROW_ON_ERROR
	);
} catch (Throwable $error) {
	fwrite(
		STDERR,
		"ERROR: Could not read the Cloudflare test configuration.\n"
	);
	exit(1);
}

if (!is_array($configuration)) {
	fwrite(STDERR, "ERROR: Invalid Cloudflare test configuration.\n");
	exit(1);
}

$token = (string) ($configuration['token'] ?? '');
$accountId = (string) ($configuration['account_id'] ?? '');
$listId = (string) ($configuration['list_id'] ?? '');
$listName = (string) ($configuration['list_name'] ?? '');
$testIp = (string) ($configuration['test_ip'] ?? '');

if (!class_exists(Client::class)) {
	fwrite(STDERR, "ERROR: The plugin Cloudflare client is unavailable.\n");
	exit(1);
}

if (!class_exists(IpValidator::class)) {
	fwrite(STDERR, "ERROR: The plugin IP validator is unavailable.\n");
	exit(1);
}

if ($token === '' || preg_match('/\s/', $token) === 1) {
	fwrite(STDERR, "ERROR: The Cloudflare token is invalid.\n");
	exit(1);
}

if (preg_match('/^[0-9a-f]{32}$/i', $accountId) !== 1) {
	fwrite(STDERR, "ERROR: The Cloudflare Account ID is invalid.\n");
	exit(1);
}

if ($listId !== EXPECTED_LIST_ID) {
	fwrite(STDERR, "ERROR: The configured list ID is not approved.\n");
	exit(1);
}

if ($listName !== EXPECTED_LIST_NAME) {
	fwrite(STDERR, "ERROR: The configured list name is not approved.\n");
	exit(1);
}

if ($testIp !== EXPECTED_TEST_IP) {
	fwrite(STDERR, "ERROR: The configured test IP is not approved.\n");
	exit(1);
}

if (!IpValidator::validate_public_ip($testIp)) {
	fwrite(
		STDERR,
		"ERROR: The plugin rejected the approved test IP.\n"
	);
	exit(1);
}

$primaryError = '';
$cleanupError = '';
$safeToRemove = false;
$addVerified = false;
$removeVerified = false;

try {
	$resolver = create_client($token);
	$resolvedListId = $resolver->resolve_account_list_id(
		$accountId,
		$listName,
		$listId
	);

	if ($resolvedListId !== $listId) {
		$error = $resolver->get_last_error_message();

		throw new RuntimeException(
			$error !== ''
				? $error
				: 'The plugin resolved an unexpected Cloudflare list.'
		);
	}

	echo "PASS: Plugin resolved the approved Cloudflare list.\n";

	if (contains_ip($token, $accountId, $listId, $testIp)) {
		throw new RuntimeException(
			'8.8.8.8 already existed before the live test.'
		);
	}

	echo "PASS: 8.8.8.8 was absent before the live test.\n";

	/*
	 * From this point onward, cleanup may safely remove 8.8.8.8 because
	 * the immediately preceding live check proved it did not preexist.
	 */
	$safeToRemove = true;

	$comment = sprintf(
		'Greyrock live integration test %s',
		gmdate('Ymd\THis\Z')
	);

	$added = false;
	$lastAddError = '';

	for ($attempt = 1; $attempt <= 20; $attempt++) {
		try {
			if (contains_ip($token, $accountId, $listId, $testIp)) {
				$added = true;
				break;
			}
		} catch (Throwable $error) {
			$lastAddError = $error->getMessage();
		}

		$client = create_client($token);

		if (
			$client->add_ip_to_account_list(
				$accountId,
				$listId,
				$testIp,
				$comment
			)
		) {
			$added = true;
			break;
		}

		$lastAddError = $client->get_last_error_message();

		if ($attempt < 20) {
			sleep(3);
		}
	}

	if (!$added) {
		throw new RuntimeException(
			$lastAddError !== ''
				? $lastAddError
				: 'The plugin could not submit the test list item.'
		);
	}

	echo "PASS: Plugin submitted 8.8.8.8 to Cloudflare.\n";

	for ($attempt = 1; $attempt <= 30; $attempt++) {
		try {
			if (contains_ip($token, $accountId, $listId, $testIp)) {
				$addVerified = true;
				break;
			}
		} catch (Throwable $error) {
			$lastAddError = $error->getMessage();
		}

		if ($attempt < 30) {
			sleep(2);
		}
	}

	if (!$addVerified) {
		throw new RuntimeException(
			$lastAddError !== ''
				? $lastAddError
				: 'Cloudflare did not expose the added item in time.'
		);
	}

	echo "PASS: Fresh plugin client verified 8.8.8.8 in the list.\n";

  update_option(
    'firewall_sync_options',
    [
      'cloudflare_api_token' => $token,
      'cloudflare_mode' => 'account_list',
      'cloudflare_account_id' => $accountId,
      'cloudflare_list_id' => $listId,
      'cloudflare_list_name' => $listName,
      'ddns_hostname' => 'trusted-test.invalid',
      'ddns_allow_enabled' => '1',
      'historical_lookback_hours' => '24',
      'historical_minimum_events' => '100',
    ],
    false
  );

  update_option(
    'firewall_sync_ddns_state',
    [
      'hostname' => 'trusted-test.invalid',
      'ips' => [$testIp],
      'resolved_at' => time(),
      'last_attempt' => time(),
      'status' => 'resolved',
      'error' => '',
    ],
    false
  );

  BlockLogger::log(
    $testIp,
    'live test: trusted address awaiting removal'
  );

  if (!BlockLogger::has_synced($testIp)) {
    throw new RuntimeException(
      'The live test could not create the local synchronization record.'
    );
  }

  if (!SyncScheduler::run_now()) {
    $error = SyncScheduler::get_last_error_message();

    throw new RuntimeException(
      $error !== ''
        ? $error
        : 'Automatic trusted-address removal failed.'
    );
  }

  $automaticRemovalVerified = false;

  for ($attempt = 1; $attempt <= 30; $attempt++) {
    if (
      !contains_ip(
        $token,
        $accountId,
        $listId,
        $testIp
      )
    ) {
      $automaticRemovalVerified = true;
      $removeVerified = true;
      break;
    }

    if ($attempt < 30) {
      sleep(2);
    }
  }

  if (!$automaticRemovalVerified) {
    throw new RuntimeException(
      'Grey Rock did not automatically remove 8.8.8.8 from the Cloudflare block list.'
    );
  }

  if (BlockLogger::has_synced($testIp)) {
    throw new RuntimeException(
      'Grey Rock retained the local synchronization record for the trusted address.'
    );
  }

  echo "PASS: Grey Rock automatically removed 8.8.8.8 after it became a trusted address.\n";

} catch (Throwable $error) {
	$primaryError = $error->getMessage();
} finally {
	if ($safeToRemove) {
		$lastCleanupMessage = '';

		for ($attempt = 1; $attempt <= 30; $attempt++) {
			try {
				if (
					!contains_ip(
						$token,
						$accountId,
						$listId,
						$testIp
					)
				) {
					$removeVerified = true;
					break;
				}

				$client = create_client($token);

				if (
					!$client->remove_ip_from_account_list(
						$accountId,
						$listId,
						$testIp
					)
				) {
					$lastCleanupMessage =
						$client->get_last_error_message();
				}
			} catch (Throwable $error) {
				$lastCleanupMessage = $error->getMessage();
			}

			if ($attempt < 30) {
				sleep(3);
			}
		}

		if (!$removeVerified) {
			try {
				$removeVerified = !contains_ip(
					$token,
					$accountId,
					$listId,
					$testIp
				);
			} catch (Throwable $error) {
				$lastCleanupMessage = $error->getMessage();
			}
		}

		if (!$removeVerified) {
			$cleanupError = $lastCleanupMessage !== ''
				? $lastCleanupMessage
				: '8.8.8.8 could not be confirmed removed.';
		}
	}
}

if ($removeVerified) {
	echo "PASS: Fresh plugin client verified 8.8.8.8 was removed.\n";
}

if ($primaryError !== '') {
	fwrite(STDERR, "TEST ERROR: {$primaryError}\n");
}

if ($cleanupError !== '') {
	fwrite(STDERR, "CLEANUP ERROR: {$cleanupError}\n");
}

if ($primaryError !== '' || $cleanupError !== '') {
	exit(1);
}

if (!$addVerified || !$removeVerified) {
	fwrite(STDERR, "ERROR: The complete add/remove cycle was not verified.\n");
	exit(1);
}

echo "CLOUDFLARE PLUGIN LIVE TEST RESULT: PASS\n";
