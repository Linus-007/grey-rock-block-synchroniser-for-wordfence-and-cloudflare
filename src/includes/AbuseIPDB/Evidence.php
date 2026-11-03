<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\AbuseIPDB;

/** Only explicit source evidence is classified; raw evidence never leaves the site. */
final class Evidence {
  public static function classify(string $reason, bool $manual = false): ?array {
    if (strlen($reason) > 4096 || strlen(trim($reason)) < 12) { return null; }
    // Refuse negations, policy blocks, generic 404s and summaries. A keyword in
    // a URL or username must not become attack evidence.
    if (str_contains($reason, '?')) { return null; }
    if (preg_match('~https?://|[/\\\\@]|\b(?:not|no|non|unknown|unconfirmed|unverified|uncertain|unspecified|possible|possibly|potential|potentially|suspect|suspected|suspicious|maybe|could|might|may|country|geographic|404)\b~i', $reason)) { return null; }
    $category = null;
    if (!$manual && preg_match('/\bKnown Malicious User.Agents\b/i', $reason)) {
      $category = 19;
    } elseif (preg_match("/^Exceeded the maximum number of login failures which is: [1-9][0-9]*\. The last username they tried to sign in with was: '[^']*'$/D", $reason)) {
      $category = 18;
    } elseif ($reason === 'Exceeded the maximum number of requests per minute for crawlers.') {
      $category = 19;
    } elseif (!str_contains($reason, "'") && !str_contains($reason, '"')) {
      // WAF rule descriptions and administrator evidence must explicitly name
      // the attack. No inference from request paths, HTTP status or WAF alone.
      if (preg_match('/\bSQL injection\b/i', $reason)) { $category = 16; }
      elseif (preg_match('/\b(?:remote code execution|local file inclusion|remote file inclusion|directory traversal|cross.site scripting|vulnerability probing|web application exploitation)\b/i', $reason)) { $category = 21; }
      elseif ($manual && preg_match('/\bbrute.force (?:authentication|login|attack)/i', $reason)) { $category = 18; }
      elseif ($manual && preg_match('/\babusive automated (?:web )?crawling\b/i', $reason)) { $category = 19; }
      elseif (preg_match('/\b(?:blog|comment) spam\b/i', $reason)) { $category = 12; }
      elseif (preg_match('/\bweb spam\b/i', $reason)) { $category = 10; }
    }
    if ($category === null) { return null; }
    $labels = [16 => 'SQL injection', 18 => 'brute-force authentication attacks', 19 => 'malicious or abusive web bot activity', 21 => 'web application exploitation or vulnerability probing', 10 => 'web spam', 12 => 'blog or comment spam'];
    return ['categories' => (string) $category, 'comment' => ($manual ? 'Administrator evidence: ' : 'Wordfence block evidence: ') . $labels[$category] . '.'];
  }

  public static function incident(string $ip, int $at, string $source, string $reason, bool $manual = false): ?array {
    $classification = self::classify($reason, $manual);
    if ($classification === null || $at <= time() - 30 * DAY_IN_SECONDS || $at > time()) { return null; }
    // Promoting a temporary Wordfence block changes enforcement metadata,
    // not the underlying attack evidence.
    if (str_starts_with($source, 'active:')) { $source = 'active'; }
    return $classification + ['ip' => $ip, 'evidence_at' => $at,
      'incident' => hash('sha256', $ip . "\0" . $at . "\0" . $source . "\0" . $reason)];
  }
}
