<?php
/**
 * providers/proxmox/bootstrap.php
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/servers.php';
require_once __DIR__ . '/catalog.php';

class CloudProvider
{
    public ProxmoxServers $servers;
    public ProxmoxCatalog $catalog;

    private static array $instances = [];

    public function __construct(string|array $creds)
    {
        $client        = new ProxmoxClient($creds);
        $this->servers = new ProxmoxServers($client);
        $this->catalog = new ProxmoxCatalog($client);
    }

    public static function instance(?int $providerId = null): self
    {
        $key = $providerId ?? 'default';
        if (!isset(self::$instances[$key])) {
            $prov = self::loadProvider($providerId);
            if (!$prov) throw new RuntimeException('No Proxmox provider configured.');
            $cred = trim($prov['api_key'] ?? '');
            if (!$cred) throw new RuntimeException('Proxmox provider has no credentials set.');
            self::$instances[$key] = new self($cred);
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
                $st = db()->query("SELECT * FROM providers WHERE provider_type='proxmox' AND is_active=1 ORDER BY id LIMIT 1");
            }
            return $st->fetch() ?: null;
        } catch (Throwable $e) {
            error_log('[proxmox] ' . $e->getMessage());
            return null;
        }
    }

    public static function reset(): void { self::$instances = []; }
}

function cloud_provider(?int $providerId = null): CloudProvider
{
    return CloudProvider::instance($providerId);
}
