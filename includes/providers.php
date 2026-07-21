<?php
/**
 * includes/providers.php
 *
 * Detects available provider types by scanning the providers/ directory.
 * Folder name = provider type slug (must match providers.provider_type column).
 */
declare(strict_types=1);

/**
 * Scan providers/ directory and return available provider type slugs.
 * Only returns folders that have all required files.
 *
 * @return array  e.g. ['hetzner', 'digitalocean', 'vultr']
 */
function get_available_provider_types(): array
{
    $base     = dirname(__DIR__) . '/providers/';
    // actions.php is optional (some providers use servers/actions/{type}.php instead)
    $required = ['client.php', 'servers.php', 'catalog.php', 'bootstrap.php'];
    $found    = [];

    if (!is_dir($base)) return $found;

    foreach (scandir($base) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (!is_dir($base . $entry))           continue;

        // Check all required files exist
        $complete = true;
        foreach ($required as $f) {
            if (!file_exists($base . $entry . '/' . $f)) {
                $complete = false;
                break;
            }
        }
        if ($complete) $found[] = $entry;
    }

    return $found;
}

/**
 * Human-readable labels for known provider types.
 */
function provider_type_label(string $type): string
{
    return match(strtolower($type)) {
        'hetzner'      => 'Hetzner Cloud',
        'digitalocean' => 'DigitalOcean',
        'virtualizor'  => 'Virtualizor',
        'vultr'        => 'Vultr',
        'linode'       => 'Linode / Akamai',
        'utho'         => 'Utho Cloud',
        'contabo'      => 'Contabo',
        'digitalocean' => 'DigitalOcean',
        'virtualizor'  => 'Virtualizor',
        'aws'          => 'Amazon Web Services',
        'gcp'          => 'Google Cloud',
        'azure'        => 'Microsoft Azure',
        default        => ucfirst($type),
    };
}

/**
 * Provider type icon/logo URL (from devicon CDN where available).
 */
function provider_type_icon(string $type): string
{
    return match(strtolower($type)) {
        'hetzner'      => '', // Hetzner has no devicon — use text
        'digitalocean' => 'https://cdn.jsdelivr.net/gh/devicons/devicon/icons/digitalocean/digitalocean-original.svg',
        'vultr'        => '', // no devicon
        'linode'       => 'https://cdn.jsdelivr.net/gh/devicons/devicon/icons/akamai/akamai-original.svg',
        'aws'          => 'https://cdn.jsdelivr.net/gh/devicons/devicon/icons/amazonwebservices/amazonwebservices-original.svg',
        'gcp'          => 'https://cdn.jsdelivr.net/gh/devicons/devicon/icons/googlecloud/googlecloud-original.svg',
        'azure'        => 'https://cdn.jsdelivr.net/gh/devicons/devicon/icons/azure/azure-original.svg',
        default        => '',
    };
}

/**
 * Returns the base currency a provider typically uses.
 * Shown as default in the Add/Edit form.
 */
function provider_default_currency(string $type): string
{
    return match(strtolower($type)) {
        'hetzner' => 'EUR',
        default   => 'USD',
    };
}
