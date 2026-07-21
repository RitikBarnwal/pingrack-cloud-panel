<?php
/**
 * providers/hetzner/bootstrap.php
 *
 * Single entry point. Usage:
 *   cloud_provider()              → default/first active provider
 *   cloud_provider($providerId)   → specific provider by DB id
 *
 * API key comes from the `providers` table — NOT from settings.
 * The `cloud_api_key` setting key is now DEPRECATED/REMOVED.
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/servers.php';
require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/catalog.php';

class CloudProvider
{
    public CloudServers $servers;
    public CloudActions $actions;
    public CloudCatalog $catalog;

    private static array $instances = [];

    public function __construct(string $apiKey)
    {
        $client        = new CloudProviderClient($apiKey);
        $this->servers = new CloudServers($client);
        $this->actions = new CloudActions($client);
        $this->catalog = new CloudCatalog($client);
    }

    /**
     * Get instance for a provider.
     *
     * @param int|null $providerId  DB provider id. null = first active provider.
     */
    public static function instance(?int $providerId = null): self
    {
        $cacheKey = $providerId ?? 'default';

        if (!isset(self::$instances[$cacheKey])) {
            $prov = self::loadProvider($providerId);
            if (!$prov) {
                throw new RuntimeException(
                    'No cloud provider configured. Go to Admin → Providers and add one.'
                );
            }
            $key = trim($prov['api_key'] ?? '');
            if (!$key) {
                throw new RuntimeException(
                    "Cloud provider \"{$prov['display_name']}\" has no API key set."
                );
            }
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
                $st = db()->query('SELECT * FROM providers WHERE is_active=1 ORDER BY id LIMIT 1');
            }
            $row = $st->fetch();
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('[cloud_provider] DB error: ' . $e->getMessage());
            return null;
        }
    }

    /** Reset instance cache (useful after provider config changes) */
    public static function reset(): void
    {
        self::$instances = [];
    }
}

/**
 * Global helper.
 *
 * Usage:
 *   cloud_provider()->servers->list()
 *   cloud_provider(2)->catalog->plans()   // provider id=2
 */
function cloud_provider(?int $providerId = null): CloudProvider
{
    return CloudProvider::instance($providerId);
}
