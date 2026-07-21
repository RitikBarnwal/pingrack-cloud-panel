<?php
// api/kb-action.php — Knowledge Base admin AJAX
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
if (empty($input)) $input = $_POST; // fallback for FormData
$action = $input['action'] ?? '';

if (!verify_csrf($input['csrf'] ?? '')) {
    echo json_encode(['ok'=>false,'error'=>'Invalid CSRF']); exit;
}

// ── helpers ────────────────────────────────────────────────────
function kb_slug(string $text): string {
    $s = mb_strtolower(trim($text));
    $s = preg_replace('/[^\w\s-]/u', '', $s);
    $s = preg_replace('/[\s_]+/', '-', $s);
    $s = trim($s, '-');
    return $s ?: 'article';
}
function kb_unique_slug(string $base, string $table, int $exclude_id = 0): string {
    $slug = kb_slug($base);
    $orig = $slug; $i = 2;
    while (true) {
        $st = db()->prepare("SELECT id FROM `{$table}` WHERE slug=? AND id!=?");
        $st->execute([$slug, $exclude_id]);
        if (!$st->fetch()) break;
        $slug = $orig . '-' . $i++;
    }
    return $slug;
}

// ══════════════════════════════════════════════════════════════
// CATEGORY ACTIONS
// ══════════════════════════════════════════════════════════════

if ($action === 'save_category') {
    $id   = (int)($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $desc = trim($input['description'] ?? '');
    $icon = trim($input['icon'] ?? '📁');
    $color= trim($input['color'] ?? '#673de6');
    $sort = (int)($input['sort_order'] ?? 0);

    if (!$name) { echo json_encode(['ok'=>false,'error'=>'Name required']); exit; }

    $slug = kb_unique_slug($name, 'kb_categories', $id);

    if ($id) {
        db()->prepare("UPDATE kb_categories SET name=?,slug=?,description=?,icon=?,color=?,sort_order=?,updated_at=NOW() WHERE id=?")
           ->execute([$name,$slug,$desc,$icon,$color,$sort,$id]);
    } else {
        db()->prepare("INSERT INTO kb_categories (name,slug,description,icon,color,sort_order,is_active,created_at,updated_at) VALUES (?,?,?,?,?,?,1,NOW(),NOW())")
           ->execute([$name,$slug,$desc,$icon,$color,$sort]);
        $id = (int)db()->lastInsertId();
    }
    echo json_encode(['ok'=>true,'id'=>$id,'slug'=>$slug]); exit;
}

if ($action === 'toggle_category') {
    $id  = (int)($input['id'] ?? 0);
    $val = (int)($input['is_active'] ?? 0);
    db()->prepare("UPDATE kb_categories SET is_active=? WHERE id=?")->execute([$val,$id]);
    echo json_encode(['ok'=>true]); exit;
}

if ($action === 'delete_category') {
    $id = (int)($input['id'] ?? 0);
    $c  = db()->prepare("SELECT COUNT(*) FROM kb_articles WHERE category_id=?");
    $c->execute([$id]);
    if ((int)$c->fetchColumn() > 0) {
        echo json_encode(['ok'=>false,'error'=>'Move or delete articles first']); exit;
    }
    db()->prepare("DELETE FROM kb_categories WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

// ══════════════════════════════════════════════════════════════
// ARTICLE ACTIONS
// ══════════════════════════════════════════════════════════════

if ($action === 'save_article') {
    $id          = (int)($input['id'] ?? 0);
    $cat_id      = (int)($input['category_id'] ?? 0);
    $title       = trim($input['title'] ?? '');
    $excerpt     = trim($input['excerpt'] ?? '');
    $content     = $input['content'] ?? '';           // raw HTML from editor
    $seo_title   = trim($input['seo_title'] ?? '');
    $seo_desc    = trim($input['seo_description'] ?? '');
    $seo_kw      = trim($input['seo_keywords'] ?? '');
    $featured    = (int)($input['is_featured'] ?? 0);
    $active      = (int)($input['is_active'] ?? 1);
    $sort        = (int)($input['sort_order'] ?? 0);
    $author_id   = (int)($input['author_id'] ?? current_user()['id']);

    if (!$title || !$cat_id || !$content) {
        echo json_encode(['ok'=>false,'error'=>'Title, category and content are required']); exit;
    }

    // Auto-fill SEO title if empty
    if (!$seo_title) $seo_title = $title . ' — ' . APP_NAME;

    $slug = kb_unique_slug($title, 'kb_articles', $id);

    if ($id) {
        db()->prepare(
            "UPDATE kb_articles SET category_id=?,title=?,slug=?,excerpt=?,content=?,
             seo_title=?,seo_description=?,seo_keywords=?,is_featured=?,is_active=?,
             sort_order=?,updated_at=NOW() WHERE id=?"
        )->execute([$cat_id,$title,$slug,$excerpt,$content,$seo_title,$seo_desc,$seo_kw,$featured,$active,$sort,$id]);
    } else {
        db()->prepare(
            "INSERT INTO kb_articles
             (category_id,title,slug,excerpt,content,seo_title,seo_description,seo_keywords,
              is_featured,is_active,sort_order,views,helpful_yes,helpful_no,author_id,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,0,0,0,?,NOW(),NOW())"
        )->execute([$cat_id,$title,$slug,$excerpt,$content,$seo_title,$seo_desc,$seo_kw,$featured,$active,$sort,$author_id]);
        $id = (int)db()->lastInsertId();
    }
    echo json_encode(['ok'=>true,'id'=>$id,'slug'=>$slug]); exit;
}

if ($action === 'toggle_article') {
    db()->prepare("UPDATE kb_articles SET is_active=? WHERE id=?")->execute([(int)($input['is_active']??0),(int)($input['id']??0)]);
    echo json_encode(['ok'=>true]); exit;
}

if ($action === 'delete_article') {
    db()->prepare("DELETE FROM kb_articles WHERE id=?")->execute([(int)($input['id']??0)]);
    echo json_encode(['ok'=>true]); exit;
}

if ($action === 'helpful_vote') {
    $id   = (int)($input['id'] ?? 0);
    $vote = $input['vote'] === 'yes' ? 'helpful_yes' : 'helpful_no';
    if ($id) db()->prepare("UPDATE kb_articles SET {$vote}={$vote}+1 WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
