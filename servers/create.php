<?php
/**
 * servers/create.php
 * The VPS deploy page. Renders the package-based, VPS-only deploy flow
 * (identical to /packages.php) so every "Deploy" entry point is consistent.
 * packages.php uses __DIR__-relative includes, so this resolves correctly.
 */
require __DIR__ . '/../packages.php';
