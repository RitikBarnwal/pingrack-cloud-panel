<?php
/**
 * providers/contabo/bootstrap.php
 * Same interface as hetzner/linode/utho bootstrap.
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/servers.php';
require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/catalog.php';

class CloudProvider
{
    public ContaboServers $servers;
    public ContaboActions $actions;
    public ContaboCatalog $catalog;

    private static array $instances = [];

    public function __construct(string $apiKeyJson)
    {
        $client        = new ContaboClient($apiKeyJson);
        $this->servers = new ContaboServers($client);
        $this->actions = new ContaboActions($client);
        $this->catalog = new ContaboCatalog($client);
    }

    public static function instance(?int $providerId = null): self
    {
        $key = $providerId ?? 'default';
        if (!isset(self::$instances[$key])) {
            $prov = self::loadProvider($providerId);
            if (!$prov) throw new RuntimeException('No Contabo provider configured.');
            $token = trim($prov['api_key'] ?? '');
            if (!$token) throw new RuntimeException('Contabo provider has no credentials set.');
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
                $st = db()->query("SELECT * FROM providers WHERE provider_type='contabo' AND is_active=1 ORDER BY id LIMIT 1");
            }
            return $st->fetch() ?: null;
        } catch (Throwable $e) {
            error_log('[contabo] ' . $e->getMessage());
            return null;
        }
    }

    public static function reset(): void { self::$instances = []; }
}

function cloud_provider(?int $providerId = null): CloudProvider
{
    return CloudProvider::instance($providerId);
}
