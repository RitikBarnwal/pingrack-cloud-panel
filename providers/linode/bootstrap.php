<?php
/**
 * providers/linode/bootstrap.php
 *
 * Single entry point for the Linode provider.
 * Usage: same as hetzner/bootstrap.php
 *   CloudProvider::reset();
 *   $cloud = new CloudProvider($apiKey);
 *   $cloud->servers->create([...])
 *   $cloud->catalog->regions()
 *
 * Class names match the shared interface:
 *   CloudProvider  — main container (same name, different file)
 *   CloudServers   — server CRUD
 *   CloudActions   — power actions
 *   CloudCatalog   — regions, images, plans, SSH keys
 */

declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/servers.php';
require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/catalog.php';

class CloudProvider
{
    public LinodeServers  $servers;
    public LinodeActions  $actions;
    public LinodeCatalog  $catalog;

    private static array $instances = [];

    public function __construct(string $apiKey)
    {
        $client        = new LinodeClient($apiKey);
        $this->servers = new LinodeServers($client);
        $this->actions = new LinodeActions($client);
        $this->catalog = new LinodeCatalog($client);
    }

    public static function instance(?int $providerId = null): self
    {
        $cacheKey = $providerId ?? 'default';
        if (!isset(self::$instances[$cacheKey])) {
            $prov = self::loadProvider($providerId);
            if (!$prov) throw new RuntimeException('No Linode provider configured.');
            $key = trim($prov['api_key'] ?? '');
            if (!$key) throw new RuntimeException('Linode provider has no API token set.');
            self::$instances[$cacheKey] = new self($key);
        }
        return self::$instances[$cacheKey];
    }

    private static function loadProvider(?int $providerId): ?array
    {
        try {
            if ($providerId) {
                $st = db()->prepare('SELECT * FROM providers WHERE id=? AND is_active=1 LIMIT 1');
                $st->execute([$providerId]);
            } else {
                $st = db()->query("SELECT * FROM providers WHERE provider_type='linode' AND is_active=1 ORDER BY id LIMIT 1");
            }
            return $st->fetch() ?: null;
        } catch (Throwable $e) {
            error_log('[linode-provider] DB error: ' . $e->getMessage());
            return null;
        }
    }

    public static function reset(): void
    {
        self::$instances = [];
    }
}

function cloud_provider(?int $providerId = null): CloudProvider
{
    return CloudProvider::instance($providerId);
}
