<?php
// api/kb-search.php — Live search API
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/bootstrap.php';

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode(['results'=>[]]); exit; }

$like = '%' . $q . '%';
$st   = db()->prepare(
    "SELECT a.id, a.title, a.slug, a.excerpt, c.name cat_name, c.icon cat_icon
     FROM kb_articles a
     JOIN kb_categories c ON c.id=a.category_id
     WHERE a.is_active=1 AND c.is_active=1
       AND (a.title LIKE ? OR a.excerpt LIKE ? OR MATCH(a.title,a.excerpt,a.content) AGAINST(? IN BOOLEAN MODE))
     ORDER BY MATCH(a.title,a.excerpt,a.content) AGAINST(? IN BOOLEAN MODE) DESC, a.views DESC
     LIMIT 8"
);
$st->execute([$like, $like, $q.'*', $q.'*']);
echo json_encode(['results' => $st->fetchAll(PDO::FETCH_ASSOC)]);
