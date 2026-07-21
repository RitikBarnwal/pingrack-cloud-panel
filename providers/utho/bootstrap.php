<?php
/**
 * providers/utho/bootstrap.php
 *
 * Utho Cloud provider entry point.
 * Same interface as hetzner/bootstrap.php and linode/bootstrap.php.
 *
 * Usage:
 *   CloudProvider::reset();
 *   $cloud = new CloudProvider($apiToken);
 *   $cloud->servers->create([...])
 *   $cloud->catalog->regions()
 */

declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/servers.php';
require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/catalog.php';

class CloudProvider
{
    public UthoServers $servers;
    public UthoActions $actions;
    public UthoCatalog $catalog;

    private static array $instances = [];

    public function __construct(string $apiToken)
    {
        $client        = new UthoClient($apiToken);
        $this->servers = new UthoServers($client);
        $this->actions = new UthoActions($client);
        $this->catalog = new UthoCatalog($client);
    }

    public static function instance(?int $providerId = null): self
    {
        $key = $providerId ?? 'default';
        if (!isset(self::$instances[$key])) {
            $prov = self::loadProvider($providerId);
            if (!$prov) throw new RuntimeException('No Utho provider configured.');
            $token = trim($prov['api_key'] ?? '');
            if (!$token) throw new RuntimeException('Utho provider has no API token set.');
            self::$instances[$key] = new self($token);
        }
        return self::$instances[$key];
    }

    private static function loadProvider(?int $providerId): ?array
    {
        try {
            if ($providerId) {
                $st = db()->prepare('SELECT * FROM providers WHERE id=? AND is_active=1 LIMIT 1');
                $st->execute([$providerId]);
            } else {
                $st = db()->query("SELECT * FROM providers WHERE provider_type='utho' AND is_active=1 ORDER BY id LIMIT 1");
            }
            return $st->fetch() ?: null;
        } catch (Throwable $e) {
            error_log('[utho-provider] ' . $e->getMessage());
            return null;
        }
    }

    public static function reset(): void { self::$instances = []; }
}

function cloud_provider(?int $providerId = null): CloudProvider
{
    return CloudProvider::instance($providerId);
}
