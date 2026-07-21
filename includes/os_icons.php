<?php
/**
 * includes/os_icons.php
 *
 * Central OS icon resolver.
 * Loads icon URLs from /servers/os_images.json (admin-editable).
 *
 * Usage:
 *   get_os_icon_url('Ubuntu 24.04')  → "https://..."
 *   os_icon_img('Ubuntu 24.04', 24)  → "<img src='...' ...>"
 */

declare(strict_types=1);

function _load_os_icons(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $json_file = dirname(__DIR__) . '/servers/os_images.json';
    if (!file_exists($json_file)) {
        $cache = [];
        return $cache;
    }

    $decoded = json_decode(file_get_contents($json_file), true);
    $cache   = is_array($decoded) ? $decoded : [];
    // Lowercase all keys for case-insensitive matching
    $cache   = array_change_key_case($cache, CASE_LOWER);
    return $cache;
}

/**
 * Resolve an OS label/name string to its icon URL.
 * Matches by checking if any key from the JSON is contained in the OS string.
 *
 * @param  string $os_label  e.g. "Ubuntu 24.04", "debian12", "rockylinux"
 * @param  string $fallback  URL to use if no match found
 * @return string
 */
function get_os_icon_url(string $os_label, string $fallback = ''): string
{
    if (!$os_label) return $fallback ?: _os_fallback();

    $icons  = _load_os_icons();
    $needle = strtolower($os_label);

    // Exact key match first
    if (isset($icons[$needle])) return $icons[$needle];

    // Partial match — check if any icon key is contained in the OS string
    // Sort by key length descending so "rockylinux" matches before "linux"
    $keys = array_keys($icons);
    usort($keys, fn($a, $b) => strlen($b) - strlen($a));

    foreach ($keys as $key) {
        if (str_contains($needle, $key)) {
            return $icons[$key];
        }
    }

    return $fallback ?: _os_fallback();
}

/**
 * Returns an <img> tag for the OS icon.
 *
 * @param string $os_label  OS name/label string
 * @param int    $size      Width & height in px
 * @param string $style     Extra inline CSS
 */
function os_icon_img(string $os_label, int $size = 24, string $style = ''): string
{
    $url     = get_os_icon_url($os_label);
    $alt     = htmlspecialchars($os_label);
    $style_s = $style ? ' style="' . $style . '"' : '';
    return "<img src=\"{$url}\" width=\"{$size}\" height=\"{$size}\" alt=\"{$alt}\" loading=\"lazy\"{$style_s} onerror=\"this.style.display='none'\">";
}

function _os_fallback(): string
{
    // Generic linux fallback
    return 'https://cdn.jsdelivr.net/gh/devicons/devicon/icons/linux/linux-original.svg';
}
