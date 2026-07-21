<?php
/**
 * providers/hetzner/actions.php
 *
 * Server power actions (start, stop, reboot, rebuild, reset-password).
 */

declare(strict_types=1);

require_once __DIR__ . '/client.php';

class CloudActions
{
    private CloudProviderClient $http;

    public function __construct(CloudProviderClient $http)
    {
        $this->http = $http;
    }

    /* ─── Power On ──────────────────────────── */

    public function start(int $serverId): array
    {
        return $this->doAction($serverId, 'poweron');
    }

    /* ─── Power Off (graceful shutdown) ─────── */

    public function stop(int $serverId): array
    {
        return $this->doAction($serverId, 'shutdown');
    }

    /* ─── Force Off ─────────────────────────── */

    public function forceOff(int $serverId): array
    {
        return $this->doAction($serverId, 'poweroff');
    }

    /* ─── Soft Reboot ───────────────────────── */

    public function reboot(int $serverId): array
    {
        return $this->doAction($serverId, 'reboot');
    }

    /* ─── Hard Reset ────────────────────────── */

    public function reset(int $serverId): array
    {
        return $this->doAction($serverId, 'reset');
    }

    /* ─── Reset Root Password ───────────────── */

    public function resetPassword(int $serverId): array
    {
        $raw = $this->http->post('/servers/' . $serverId . '/actions/reset_password');

        return [
            'action'        => $this->mapAction($raw['action'] ?? []),
            'root_password' => $raw['root_password'] ?? null,
        ];
    }

    /* ─── Rebuild (re-image) ─────────────────── */

    /**
     * $imageSlug e.g. "ubuntu-24.04", "debian-12"
     */
    public function rebuild(int $serverId, string $imageSlug): array
    {
        $raw = $this->http->post('/servers/' . $serverId . '/actions/rebuild', [
            'image' => $imageSlug,
        ]);

        return [
            'action'        => $this->mapAction($raw['action'] ?? []),
            'root_password' => $raw['root_password'] ?? null,
        ];
    }

    /* ─── Enable/Disable Backups ────────────── */

    public function enableBackups(int $serverId): array
    {
        return $this->doAction($serverId, 'enable_backup');
    }

    public function disableBackups(int $serverId): array
    {
        return $this->doAction($serverId, 'disable_backup');
    }

    /* ─── Attach / Detach ISO ───────────────── */

    public function attachIso(int $serverId, string $isoName): array
    {
        $raw = $this->http->post('/servers/' . $serverId . '/actions/attach_iso', [
            'iso' => $isoName,
        ]);
        return $this->mapAction($raw['action'] ?? []);
    }

    public function detachIso(int $serverId): array
    {
        $raw = $this->http->post('/servers/' . $serverId . '/actions/detach_iso');
        return $this->mapAction($raw['action'] ?? []);
    }

    /* ─── Poll action status ─────────────────── */

    public function getActionStatus(int $serverId, int $actionId): array
    {
        $raw = $this->http->get('/servers/' . $serverId . '/actions/' . $actionId);
        return $this->mapAction($raw['action'] ?? []);
    }

    /* ─── Private helpers ────────────────────── */

    private function doAction(int $serverId, string $action): array
    {
        $raw = $this->http->post('/servers/' . $serverId . '/actions/' . $action);

        if (empty($raw['action'])) {
            $msg = $raw['error']['message'] ?? 'Action failed.';
            throw new RuntimeException($msg);
        }

        return $this->mapAction($raw['action']);
    }

    private function mapAction(array $a): array
    {
        return [
            'id'        => $a['id']        ?? null,
            'status'    => $a['status']    ?? '',
            'progress'  => $a['progress']  ?? 0,
            'started'   => $a['started']   ?? null,
            'finished'  => $a['finished']  ?? null,
            'error'     => $a['error']['message'] ?? null,
        ];
    }
}
