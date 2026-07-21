<?php
/**
 * providers/vultr/bootstrap.php
 *
 * Vultr provider entry point.
 * API key comes from `providers` table.
 *
 * Usage:
 *   require_once 'bootstrap.php';
 *   CloudProvider::reset();
 *   $cloud = new CloudProvider($api_key);
 *   $cloud->servers->list()
 *   $cloud->catalog->regions()
 *   $cloud->catalog->plans()
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/servers.php';
require_once __DIR__ . '/catalog.php';

class CloudProvider
{
    public VultrServers $servers;
    public VultrCatalog $catalog;

    private static array $instances = [];

    public function __construct(string $apiKey)
    {
        $client        = new VultrClient($apiKey);
        $this->servers = new VultrServers($client);
        $this->catalog = new VultrCatalog($client);
    }

    public static function instance(?int $providerId = null): self
    {
        $key = $providerId ?? 'default';
        if (!isset(self::$instances[$key])) {
            $row = self::loadProvider($providerId);
            if (!$row) throw new RuntimeException('Vultr provider not configured.');
            if (empty($row['api_key'])) throw new RuntimeException('Vultr API key missing.');
            self::$instances[$key] = new self(trim($row['api_key']));
        }
        return self::$instances[$key];
    }

    private static function loadProvider(?int $id): ?array
    {
        try {
            if ($id) {
                $st = db()->prepare('SELECT * FROM providers WHERE id=? AND is_active=1 LIMIT 1');
                $st->execute([$id]);
            } else {
                $st = db()->query("SELECT * FROM providers WHERE provider_type='vultr' AND is_active=1 ORDER BY id LIMIT 1");
            }
            return $st->fetch() ?: null;
        } catch (Throwable $e) {
            error_log('[vultr-bootstrap] ' . $e->getMessage());
            return null;
        }
    }

    public static function reset(): void { self::$instances = []; }
}

function cloud_provider(?int $id = null): CloudProvider
{
    return CloudProvider::instance($id);
}
