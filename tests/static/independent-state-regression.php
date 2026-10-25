<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services {
  final class BlockLogger {
    public static bool $readFailure = false;
    public static array $rows = ['8.8.8.8', '1.1.1.1'];
    public static bool $deleteFailure = true;
    public static function get_all_ips(): array {
      if (self::$readFailure) { throw new \RuntimeException('Injected database failure'); }
      return self::$rows;
    }
    public static function remove(string $ip): bool {
      if ($ip === '1.1.1.1' && self::$deleteFailure) { return false; }
      self::$rows = array_values(array_diff(self::$rows, [$ip]));
      return true;
    }
  }
  final class ResetWatermarkStore {
    public static array $values = [];
    public static function set(string $ip, int $at): bool { self::$values[$ip] = $at; return true; }
  }
}
namespace {
  require_once __DIR__ . '/../fixtures/task14-unit-fence.php';
  use WPCF\FirewallSync\Services\BlockLogger;
  use WPCF\FirewallSync\Services\Reconciler;
  use WPCF\FirewallSync\Services\ResetWatermarkStore;
  use WPCF\FirewallSync\Cloudflare\Client;
  function __($message, $domain = null): string { return $message; }
  function ensure(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
  $root = dirname(__DIR__, 2);
  require $root . '/src/includes/Services/IpValidator.php';
  require $root . '/src/includes/Services/CloudflareIdentifierValidator.php';
  require $root . '/src/includes/Cloudflare/Client.php';
  require $root . '/src/includes/Services/Reconciler.php';
  BlockLogger::$readFailure = true;
  $result = Reconciler::run(new Client('offline-worker-fixture', ''), [], []);
  ensure(!$result['complete'] && $result['purged'] === [] && ResetWatermarkStore::$values === [], 'Failed local inventory triggered cleanup.');
  echo "PASS: independent state failed local inventory stops reconciliation\n";
  BlockLogger::$readFailure = false;
  $purge = new ReflectionMethod(Reconciler::class, 'purge_missing_local_records');
  $result = $purge->invoke(null, BlockLogger::$rows, 2000000000);
  ensure($result['purged'] === ['8.8.8.8'] && $result['error'] !== '' && BlockLogger::$rows === ['1.1.1.1'], 'Partial cleanup concealed failure or lost remaining intent.');
  BlockLogger::$deleteFailure = false;
  $result = $purge->invoke(null, BlockLogger::$rows, 2000000001);
  ensure($result['purged'] === ['1.1.1.1'] && $result['error'] === '' && BlockLogger::$rows === [], 'New cleanup could not resume remaining records.');
  ensure(isset(ResetWatermarkStore::$values['8.8.8.8'], ResetWatermarkStore::$values['1.1.1.1']), 'Cleanup lost required reset evidence.');
  echo "PASS: independent state interrupted cleanup resumes with reset evidence\n";
}
