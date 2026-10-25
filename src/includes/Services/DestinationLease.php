<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

/*
 * Operational coordination deliberately bypasses the options object cache.
 * The installation's primary options table supplies a UNIQUE option_name;
 * network sitemeta has no equivalent unique constraint. Never use an API token
 * in the coordination key or value.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class DestinationLease {
  private const TTL = 900;
  private string $table;
  private string $key;
  private string $owner;
  private bool $held = false;
  private bool $lost = false;
  private static ?\WeakMap $active = null;

  public function __construct(string $scope) {
    global $wpdb;
    $this->table = $wpdb->base_prefix . 'options';
    $this->key = 'firewall_sync_destination_' . hash('sha256', $scope);
    $this->owner = wp_generate_uuid4();
  }

  private static function now(): int {
    global $wpdb;
    $now = $wpdb->get_var('SELECT UNIX_TIMESTAMP()');
    if (($wpdb->last_error ?? '') !== '' || !is_numeric($now) || (int) $now <= 0) {
      throw new \RuntimeException('Destination lease clock is unavailable.');
    }
    return (int) $now;
  }

  public function acquire(): void {
    if (AtomicFence::locked()) { throw new \RuntimeException('Lease acquisition cannot run inside local transactions.'); }
    global $wpdb;
    $row = ['owner' => $this->owner, 'started_at' => self::now(), 'pending' => ''];
    $inserted = $wpdb->query($wpdb->prepare(
      "INSERT IGNORE INTO {$this->table} (option_name, option_value, autoload)
       VALUES (%s, %s, 'no')", $this->key, maybe_serialize($row)
    ));
    if ($inserted !== 1) {
      if ($inserted === false) { throw new \RuntimeException('Destination coordination storage is unavailable.'); }
      $old = $this->read();
      if (!is_array($old) || !isset($old['owner'], $old['started_at'], $old['pending'])) {
        throw new \RuntimeException('Destination coordination state is unavailable.');
      }
      if ($old['owner'] !== '' && self::now() - (int) $old['started_at'] < self::TTL) {
        throw new \RuntimeException('Another worker is using this Cloudflare destination. Retry later.');
      }
      $row['pending'] = (string) $old['pending'];
      $this->replace($old, $row);
    }
    $this->held = true;
    self::$active = self::$active ?? new \WeakMap();
    self::$active[$this] = true;
  }

  private function read(): ?array {
    global $wpdb;
    $raw = $wpdb->get_var($wpdb->prepare(
      "SELECT option_value FROM {$this->table} WHERE option_name = %s", $this->key
    ));
    if (($wpdb->last_error ?? '') !== '') {
      throw new \RuntimeException('Destination coordination storage is unavailable.');
    }
    $row = $raw === null ? null : maybe_unserialize($raw);
    if (!is_array($row) || !is_string($row['owner'] ?? null)
      || !is_int($row['started_at'] ?? null) || !is_string($row['pending'] ?? null)) {
      return null;
    }
    return $row;
  }

  private function replace(array $old, array $new, bool $require_live = false): void {
    global $wpdb;
    try {
      if ($require_live) {
        $updated = $wpdb->query($wpdb->prepare(
          "UPDATE {$this->table} SET option_value = %s
           WHERE option_name = %s AND BINARY option_value = BINARY %s AND UNIX_TIMESTAMP() < %d",
          maybe_serialize($new), $this->key, maybe_serialize($old), (int) $old['started_at'] + self::TTL
        ));
      } else {
        $updated = $wpdb->query($wpdb->prepare(
          "UPDATE {$this->table} SET option_value = %s
           WHERE option_name = %s AND BINARY option_value = BINARY %s",
          maybe_serialize($new), $this->key, maybe_serialize($old)
        ));
      }
      // UNIX_TIMESTAMP() is statement-start time. A fresh clock read also
      // rejects a renewal/update that spent the remaining lease waiting in SQL.
      if ($updated !== 1 || ($require_live && self::now() >= (int) $old['started_at'] + self::TTL)) {
        throw new \RuntimeException('Destination coordination update failed. Retry later.');
      }
    } catch (\Throwable $error) {
      $this->lost = true;
      throw $error;
    }
  }

  public function assert_live(): array {
    if (!$this->held || $this->lost) {
      throw new \RuntimeException('Destination lease was lost.');
    }
    try {
      $row = $this->read();
      if ($row === null || ($row['owner'] ?? '') !== $this->owner
        || self::now() - (int) ($row['started_at'] ?? 0) >= self::TTL) {
        throw new \RuntimeException('Destination lease expired or was replaced.');
      }
      if (self::now() - (int) $row['started_at'] >= intdiv(self::TTL, 2)) {
        $renewed = $row;
        $renewed['started_at'] = self::now();
        $this->replace($row, $renewed, true);
        $row = $renewed;
      }
      return $row;
    } catch (\Throwable $error) {
      $this->lost = true;
      throw $error;
    }
  }

  public static function assert_held_leases(): void {
    if (AtomicFence::locked()) { return; }

    if (self::$active === null) { return; }
    foreach (self::$active as $lease => $unused) { $lease->assert_live(); }
  }

  /** Explicit identities consumed only by the locking transaction boundary. */
  public static function fencing_leases(): array {
    $rows = [];
    if (self::$active !== null) {
      foreach (self::$active as $lease => $unused) {
        if (!$lease->held || $lease->lost) { throw new \RuntimeException('Destination lease was lost.'); }
        $rows[] = ['table' => $lease->table, 'key' => $lease->key, 'owner' => $lease->owner, 'ttl' => self::TTL, 'pending' => true];
      }
    }
    return $rows;
  }

  public function pending(): string {
    return (string) $this->assert_live()['pending'];
  }

  /** Persist before dispatch; unknown acceptance must survive worker death. */
  public function set_pending(string $operation): void {
    if (AtomicFence::locked()) { throw new \RuntimeException('Pending operations must be durable outside local transactions.'); }
    $old = $this->assert_live();
    $new = $old;
    $new['pending'] = $operation;
    if ($new !== $old) { $this->replace($old, $new, true); }
  }

  public function release(): void {
    if (AtomicFence::locked()) { throw new \RuntimeException('Lease release cannot run inside local transactions.'); }
    global $wpdb;
    if (!$this->held) { return; }
    try {
      $row = $this->read();
      if ($row !== null && ($row['owner'] ?? '') === $this->owner) {
        if (($row['pending'] ?? '') !== '') {
          $idle = $row;
          $idle['owner'] = '';
          $idle['started_at'] = 0;
          $this->replace($row, $idle);
        } else {
          $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE option_name = %s AND BINARY option_value = BINARY %s",
            $this->key, maybe_serialize($row)
          ));
        }
      }
    } catch (\Throwable) {
      // Leave the row and any pending identity intact for the next worker.
    } finally {
      $this->held = false;
      if (self::$active !== null) { unset(self::$active[$this]); }
    }
  }
}
