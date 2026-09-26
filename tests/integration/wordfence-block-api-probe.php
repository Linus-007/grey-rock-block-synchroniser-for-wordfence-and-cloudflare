<?php

function grey_rock_probe_fail(string $message): void {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

if (!class_exists('wfBlock')) {
    grey_rock_probe_fail('wfBlock is unavailable.');
}

$test_ip = '8.8.4.4';
$reason = 'Grey Rock automated manual-block integration test';

echo "===== WORDFENCE MANUAL BLOCK TEST =====" . PHP_EOL;
echo "Test IP: {$test_ip}" . PHP_EOL;

/*
 * Ensure the disposable test starts without an existing block for this IP.
 */
wfBlock::unblockIP($test_ip, false);

if (wfBlock::findIPBlock($test_ip)) {
    grey_rock_probe_fail('Test IP remained blocked after initial cleanup.');
}

/*
 * Reproduce a permanent manually-created Wordfence IP block using
 * Wordfence's own API and its TYPE_IP_MANUAL block type.
 *
 * wfBlock::createIP() intentionally has no success return value.
 */
wfBlock::createIP(
    $reason,
    $test_ip,
    wfBlock::DURATION_FOREVER,
    time(),
    time(),
    0,
    wfBlock::TYPE_IP_MANUAL
);

$found = wfBlock::findIPBlock($test_ip);

if (!$found) {
    grey_rock_probe_fail(
        'wfBlock::findIPBlock() did not return the newly-created manual block.'
    );
}

echo "PASS: wfBlock::findIPBlock() returned the manual block." . PHP_EOL;

printf(
    "findIPBlock: ip=%s type=%s expiration=%s reason=%s\n",
    (string) $found->ip,
    (string) $found->type,
    (string) $found->expiration,
    (string) $found->reason
);

if ((int) $found->type !== wfBlock::TYPE_IP_MANUAL) {
    grey_rock_probe_fail(
        'findIPBlock() returned a block that is not TYPE_IP_MANUAL.'
    );
}

if ((int) $found->expiration !== wfBlock::DURATION_FOREVER) {
    grey_rock_probe_fail(
        'findIPBlock() returned a block that is not permanent.'
    );
}

$blocks = wfBlock::ipBlocks(true);
$matched = null;

foreach ($blocks as $block) {
    if ((string) $block->ip === $test_ip) {
        $matched = $block;
        break;
    }
}

if ($matched === null) {
    grey_rock_probe_fail(
        'Manual Wordfence block is absent from wfBlock::ipBlocks(true).'
    );
}

echo "PASS: Manual Wordfence block is present in wfBlock::ipBlocks(true)." . PHP_EOL;

printf(
    "ipBlocks: ip=%s type=%s expiration=%s blockedTime=%s reason=%s\n",
    (string) $matched->ip,
    (string) $matched->type,
    (string) $matched->expiration,
    (string) $matched->blockedTime,
    (string) $matched->reason
);

if ((int) $matched->type !== wfBlock::TYPE_IP_MANUAL) {
    grey_rock_probe_fail(
        'wfBlock::ipBlocks(true) returned the test IP with the wrong block type.'
    );
}

if ((int) $matched->expiration !== wfBlock::DURATION_FOREVER) {
    grey_rock_probe_fail(
        'wfBlock::ipBlocks(true) returned the test IP with a non-permanent expiration.'
    );
}

echo "PASS: Manual permanent Wordfence blocks are exposed through the API Grey Rock reads." . PHP_EOL;
