<?php


use WPCF\FirewallSync\Cloudflare\Client;
use WPCF\FirewallSync\Services\IpValidator;
use WPCF\FirewallSync\Services\BlockLogger;
use WPCF\FirewallSync\Services\BlockOwnership;
use WPCF\FirewallSync\Services\ResetWatermarkStore;
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

  /*
   * Reproduce the reported Wordfence workflow using Wordfence's current API:
   *
   * permanent manual Wordfence block
   * -> Grey Rock synchronization
   * -> Cloudflare list entry
   * -> Wordfence unblock
   * -> Grey Rock synchronization
   * -> Cloudflare list entry removed
   */
  if (!class_exists('wfBlock')) {
    throw new RuntimeException(
      'Wordfence wfBlock API is unavailable.'
    );
  }

  update_option(
    'firewall_sync_options',
    [
      'cloudflare_api_token' => $token,
      'cloudflare_mode' => 'account_list',
      'cloudflare_account_id' => $accountId,
      'cloudflare_list_id' => $listId,
      'cloudflare_list_name' => $listName,
      'ddns_allow_enabled' => '0',
      'historical_lookback_hours' => '24',
      'historical_minimum_events' => '100',
    ],
    false
  );

  wfBlock::unblockIP($testIp, false);

  if (wfBlock::findIPBlock($testIp)) {
    throw new RuntimeException(
      'The test IP remained blocked after initial Wordfence cleanup.'
    );
  }

  wfBlock::createIP(
    'Grey Rock live Wordfence manual-block integration test',
    $testIp,
    wfBlock::DURATION_FOREVER,
    time(),
    time(),
    0,
    wfBlock::TYPE_IP_MANUAL
  );

  $wordfenceBlock = wfBlock::findIPBlock($testIp);

  if (!$wordfenceBlock) {
    throw new RuntimeException(
      'Wordfence did not create the permanent manual test block.'
    );
  }

  if ((int) $wordfenceBlock->type !== wfBlock::TYPE_IP_MANUAL) {
    throw new RuntimeException(
      'Wordfence created the test block with an unexpected type.'
    );
  }

  if (
    (int) $wordfenceBlock->expiration
    !== wfBlock::DURATION_FOREVER
  ) {
    throw new RuntimeException(
      'Wordfence created the test block with an unexpected expiration.'
    );
  }

  echo "PASS: Wordfence created the permanent manual block.\n";

  if (!SyncScheduler::run_now()) {
    $error = SyncScheduler::get_last_error_message();

    throw new RuntimeException(
      $error !== ''
        ? $error
        : 'Grey Rock failed to synchronize the Wordfence manual block.'
    );
  }

  if (
    !BlockOwnership::has(
      $testIp,
      BlockOwnership::OWNER_WORDFENCE
    )
  ) {
    throw new RuntimeException(
      'Grey Rock did not record Wordfence ownership.'
    );
  }

  if (!BlockLogger::has_synced($testIp)) {
    throw new RuntimeException(
      'Grey Rock did not record synchronization provenance.'
    );
  }

  $lastAddError = '';

  for ($attempt = 1; $attempt <= 30; $attempt++) {
    try {
      if (
        contains_ip(
          $token,
          $accountId,
          $listId,
          $testIp
        )
      ) {
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
        : 'Cloudflare did not expose the synchronized Wordfence block in time.'
    );
  }

  echo "PASS: Grey Rock synchronized the Wordfence manual block to Cloudflare.\n";
  echo "PASS: Grey Rock recorded Wordfence ownership and synchronization provenance.\n";

  wfBlock::unblockIP($testIp, false);

  if (wfBlock::findIPBlock($testIp)) {
    throw new RuntimeException(
      'Wordfence retained the test block after unblockIP().'
    );
  }

  echo "PASS: Wordfence removed the manual block.\n";

  if (!SyncScheduler::run_now()) {
    $error = SyncScheduler::get_last_error_message();

    throw new RuntimeException(
      $error !== ''
        ? $error
        : 'Grey Rock failed to reconcile the removed Wordfence block.'
    );
  }

  $lastRemoveError = '';

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
    } catch (Throwable $error) {
      $lastRemoveError = $error->getMessage();
    }

    if ($attempt < 30) {
      sleep(2);
    }
  }

  if (!$removeVerified) {
    throw new RuntimeException(
      $lastRemoveError !== ''
        ? $lastRemoveError
        : 'Cloudflare retained the IP after the Wordfence block was removed.'
    );
  }

  if (
    BlockOwnership::has(
      $testIp,
      BlockOwnership::OWNER_WORDFENCE
    )
  ) {
    throw new RuntimeException(
      'Grey Rock retained stale Wordfence ownership.'
    );
  }

  if (BlockLogger::has_synced($testIp)) {
    throw new RuntimeException(
      'Grey Rock retained stale synchronization provenance.'
    );
  }

  if (ResetWatermarkStore::get($testIp) <= 0) {
    throw new RuntimeException(
      'Grey Rock did not record the removal reset watermark.'
    );
  }

  echo "PASS: Grey Rock removed the Cloudflare item after the Wordfence block was removed.\n";
  echo "PASS: Grey Rock cleared Wordfence ownership and synchronization provenance.\n";
  echo "PASS: Grey Rock recorded the removal reset watermark.\n";


  /*
   * Overlapping-owner safety test:
   *
   * The same IP is owned by Grey Rock manual ownership and Wordfence.
   * Removing only the Wordfence block must not remove the Cloudflare item.
   */
  if (!ResetWatermarkStore::clear($testIp)) {
    throw new RuntimeException(
      'Grey Rock could not clear the reset watermark before the overlap test.'
    );
  }

  if (
    !BlockOwnership::add(
      $testIp,
      BlockOwnership::OWNER_MANUAL
    )
  ) {
    throw new RuntimeException(
      'Grey Rock could not establish manual ownership for the overlap test.'
    );
  }

  if (
    !BlockOwnership::has(
      $testIp,
      BlockOwnership::OWNER_MANUAL
    )
  ) {
    throw new RuntimeException(
      'Grey Rock manual ownership was not present for the overlap test.'
    );
  }

  wfBlock::createIP(
    'Grey Rock overlapping-owner live integration test',
    $testIp,
    wfBlock::DURATION_FOREVER,
    time(),
    time(),
    0,
    wfBlock::TYPE_IP_MANUAL
  );

  if (!wfBlock::findIPBlock($testIp)) {
    throw new RuntimeException(
      'Wordfence did not create the overlap-test manual block.'
    );
  }

  if (!SyncScheduler::run_now()) {
    $error = SyncScheduler::get_last_error_message();

    throw new RuntimeException(
      $error !== ''
        ? $error
        : 'Grey Rock failed while synchronizing the overlap test.'
    );
  }

  $overlapAddVerified = false;
  $lastOverlapAddError = '';

  for ($attempt = 1; $attempt <= 30; $attempt++) {
    try {
      if (
        contains_ip(
          $token,
          $accountId,
          $listId,
          $testIp
        )
      ) {
        $overlapAddVerified = true;
        break;
      }
    } catch (Throwable $error) {
      $lastOverlapAddError = $error->getMessage();
    }

    if ($attempt < 30) {
      sleep(2);
    }
  }

  if (!$overlapAddVerified) {
    throw new RuntimeException(
      $lastOverlapAddError !== ''
        ? $lastOverlapAddError
        : 'Cloudflare did not expose the overlap-test IP in time.'
    );
  }

  if (
    !BlockOwnership::has(
      $testIp,
      BlockOwnership::OWNER_MANUAL
    )
    || !BlockOwnership::has(
      $testIp,
      BlockOwnership::OWNER_WORDFENCE
    )
  ) {
    throw new RuntimeException(
      'Grey Rock did not retain both owners during the overlap test.'
    );
  }

  echo "PASS: Overlap test established both manual and Wordfence ownership.\n";
  echo "PASS: Overlap test synchronized the shared IP to Cloudflare.\n";

  wfBlock::unblockIP($testIp, false);

  if (wfBlock::findIPBlock($testIp)) {
    throw new RuntimeException(
      'Wordfence retained the overlap-test block after unblockIP().'
    );
  }

  if (!SyncScheduler::run_now()) {
    $error = SyncScheduler::get_last_error_message();

    throw new RuntimeException(
      $error !== ''
        ? $error
        : 'Grey Rock failed while reconciling the overlap-test Wordfence removal.'
    );
  }

  if (
    BlockOwnership::has(
      $testIp,
      BlockOwnership::OWNER_WORDFENCE
    )
  ) {
    throw new RuntimeException(
      'Grey Rock retained Wordfence ownership after the overlap-test unblock.'
    );
  }

  if (
    !BlockOwnership::has(
      $testIp,
      BlockOwnership::OWNER_MANUAL
    )
  ) {
    throw new RuntimeException(
      'Grey Rock incorrectly removed manual ownership during Wordfence reconciliation.'
    );
  }

  $overlapPreserved = false;
  $lastOverlapCheckError = '';

  for ($attempt = 1; $attempt <= 30; $attempt++) {
    try {
      if (
        contains_ip(
          $token,
          $accountId,
          $listId,
          $testIp
        )
      ) {
        $overlapPreserved = true;
        break;
      }
    } catch (Throwable $error) {
      $lastOverlapCheckError = $error->getMessage();
    }

    if ($attempt < 30) {
      sleep(2);
    }
  }

  if (!$overlapPreserved) {
    throw new RuntimeException(
      $lastOverlapCheckError !== ''
        ? $lastOverlapCheckError
        : 'Grey Rock removed the Cloudflare item even though manual ownership remained.'
    );
  }

  echo "PASS: Removing Wordfence ownership preserved the Cloudflare item because manual ownership remained.\n";
  echo "PASS: Manual ownership remained after Wordfence reconciliation.\n";

  if (
    !BlockOwnership::remove(
      $testIp,
      BlockOwnership::OWNER_MANUAL
    )
  ) {
    throw new RuntimeException(
      'Grey Rock could not clear manual ownership after the overlap test.'
    );
  }


  /*
   * DDNS trusted-address live acceptance test.
   *
   * The overlap test intentionally leaves the synchronized IP present in
   * Cloudflare. Once that address becomes a resolved trusted address,
   * Grey Rock must automatically remove it from the block destination and
   * clear the local synchronization record.
   */
  if (!contains_ip($token, $accountId, $listId, $testIp)) {
    throw new RuntimeException(
      'The DDNS test requires the synchronized IP to remain in Cloudflare.'
    );
  }

  if (!BlockLogger::has_synced($testIp)) {
    throw new RuntimeException(
      'The DDNS test requires the synchronization record to remain present.'
    );
  }

  if (BlockOwnership::has_any($testIp)) {
    throw new RuntimeException(
      'The DDNS test must begin without another synchronization owner.'
    );
  }

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

  if (!SyncScheduler::run_now()) {
    $error = SyncScheduler::get_last_error_message();

    throw new RuntimeException(
      $error !== ''
        ? $error
        : 'Automatic trusted-address removal failed.'
    );
  }

  $automaticRemovalVerified = false;
  $lastTrustedRemovalError = '';

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
        $automaticRemovalVerified = true;
        $removeVerified = true;
        break;
      }
    } catch (Throwable $error) {
      $lastTrustedRemovalError = $error->getMessage();
    }

    if ($attempt < 30) {
      sleep(2);
    }
  }

  if (!$automaticRemovalVerified) {
    throw new RuntimeException(
      $lastTrustedRemovalError !== ''
        ? $lastTrustedRemovalError
        : 'Grey Rock did not automatically remove 8.8.8.8 from the Cloudflare block list.'
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
