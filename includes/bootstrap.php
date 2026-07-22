<?php
// ── Composer autoloader ───────────────────────────────────────
// Loads all vendor libraries (PHPMailer, Stripe, dompdf, monolog) app-wide,
// so individual files don't each require their own paths. Guarded so the app
// still boots (with a clear log line) if vendor/ hasn't been installed yet.
$__autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($__autoload)) {
    require_once $__autoload;
} else {
    error_log('[bootstrap] vendor/autoload.php missing — run "composer install" in the app root.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/security.php';

// ── AUTO PAGE TRACKING ────────────────────────────────────────
// Logs every HTTP page request automatically to activity_log.
// No need to call track_action() manually on every page.
(function() {
    if (php_sapi_name() === 'cli') return;
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    // Skip static assets
    if (preg_match('/\.(css|js|png|jpg|jpeg|gif|ico|woff|woff2|svg|map)(\?|$)/i', $uri)) return;
    // Skip noisy polling endpoints
    foreach (['/api/server-metrics', '/api/server-status-poll', '/api/servers-list', '/api/servers-page'] as $p) {
        if (strpos($uri, $p) !== false) return;
    }
    $ip   = sec_get_ip();
    $uid  = $_SESSION['user_id'] ?? null;
    $page = parse_url($uri, PHP_URL_PATH) ?: '/';
    $action = match(true) {
        str_ends_with($page, 'index.php') || $page === '/'   => 'page_home',
        str_ends_with($page, 'dashboard.php')                => 'page_dashboard',
        str_ends_with($page, 'login.php')                    => ($_SERVER['REQUEST_METHOD']==='POST' ? 'login_attempt' : 'page_login'),
        str_ends_with($page, 'register.php')                 => 'page_register',
        str_ends_with($page, 'logout.php')                   => 'logout',
        str_ends_with($page, 'billing.php')                  => 'page_billing',
        str_ends_with($page, 'tickets.php')                  => 'page_tickets',
        str_ends_with($page, 'profile.php')                  => 'page_profile',
        strpos($page, '/servers')  !== false                 => 'page_servers',
        strpos($page, '/storage')  !== false                 => 'page_storage',
        strpos($page, '/proxy')    !== false                 => 'page_proxy',
        strpos($page, '/dns')      !== false                 => 'page_dns',
        strpos($page, '/api/')     !== false                 => 'api_request',
        strpos($page, '/admin/')   !== false                 => 'admin_page',
        default                                              => 'page_view',
    };
    try {
        db()->prepare("INSERT INTO activity_log (user_id,ip,action,url,user_agent,meta,created_at) VALUES (?,?,?,?,?,?,NOW())")
            ->execute([$uid, $ip, $action, substr($uri,0,1000), substr($_SERVER['HTTP_USER_AGENT']??'',0,512), json_encode(['method'=>$_SERVER['REQUEST_METHOD']??'GET'])]);
    } catch (\Throwable $e) {
        error_log('[bootstrap] track: '.$e->getMessage());
    }
})();

function getGravatar($email, $user_profile) {
    if (!empty($user_profile) && file_exists('/www/uploads/' . $user_profile)) {
        return BASE_URL . '/serve-file.php?type=pr_pic&file=' . urlencode(basename($user_profile));
    }
    $hash = md5(strtolower(trim($email)));
    return "https://www.gravatar.com/avatar/{$hash}?s=200&d=identicon";
}

function check_otp_limit($email) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $now = time();
    $limit = 5;
    $window = 3600;
    $st = db()->prepare("SELECT * FROM otp_limits WHERE ip=? OR email=? LIMIT 1");
    $st->execute([$ip, $email]);
    $row = $st->fetch();
    if (!$row) {
        db()->prepare("INSERT INTO otp_limits (ip,email,attempts,last_reset) VALUES (?,?,1,?)")->execute([$ip,$email,$now]);
        return [true,0];
    }
    if ($now - $row['last_reset'] > $window) {
        db()->prepare("UPDATE otp_limits SET attempts=1,last_reset=? WHERE id=?")->execute([$now,$row['id']]);
        return [true,0];
    }
    if ($row['attempts'] >= $limit) { $wait = $window - ($now - $row['last_reset']); return [false,$wait]; }
    db()->prepare("UPDATE otp_limits SET attempts=attempts+1 WHERE id=?")->execute([$row['id']]);
    return [true,0];
}

// ── Global head injector ─────────────────────────────────────
function inject_global_head(): void {
    $primary       = PRIMARY_COLOR       ?: '#16a34a';
    $primary_hover = PRIMARY_COLOR_HOVER ?: '#135f2f';
    $favicon       = get_setting('site_favicon', '');
    $site_logo_l   = get_setting('site_logo', '');
    $site_logo_d   = get_setting('site_logo_d', '');
    $custom_css    = get_setting('site_custom_css', '');
    $custom_js     = get_setting('site_custom_js', '');
    $site_name     = htmlspecialchars(get_setting('site_name', APP_NAME));

    echo "<meta name=\"application-name\" content=\"{$site_name}\">\n";
    if ($favicon) {
        echo "<link rel=\"icon\" href=\"".htmlspecialchars($favicon)."\" type=\"image/x-icon\">\n";
        echo "<link rel=\"shortcut icon\" href=\"".htmlspecialchars($favicon)."\">\n";
        echo "<link rel=\"apple-touch-icon\" href=\"".htmlspecialchars($favicon)."\">\n";
    }
    echo "<style>\n:root {\n  --primary: {$primary} !important;\n  --primary-hover: {$primary_hover} !important;\n}\n</style>\n";
    if (trim($custom_css)) echo $custom_css . "\n";
    if (trim($custom_js))  echo $custom_js  . "\n";

    // Cookie consent
    if (get_setting('cookie_consent_enabled','1')==='1') {
        $p  = get_setting('cookie_consent_position','bottom');
        $sl = get_setting('cookie_consent_policy_slug','cookie-policy');
        $t  = addslashes(get_setting('cookie_consent_title','We use cookies'));
        $m  = addslashes(get_setting('cookie_consent_message','We use cookies to enhance your experience.'));
        $a  = addslashes(get_setting('cookie_consent_btn_accept','Accept All'));
        $d  = addslashes(get_setting('cookie_consent_btn_decline','Decline'));
        $mg = addslashes(get_setting('cookie_consent_btn_manage','Manage'));
        $an = get_setting('cookie_analytics_enabled','1')==='1'?'true':'false';
        $pr = get_setting('cookie_preferences_enabled','1')==='1'?'true':'false';
        $mk = get_setting('cookie_marketing_enabled','0')==='1'?'true':'false';
        echo "<script>\nwindow.__siteBase='".BASE_URL."';\nwindow.__cookieConsentConfig={title:'$t',message:'$m',btnAccept:'$a',btnDecline:'$d',btnManage:'$mg',position:'$p',policySlug:'$sl',gaId:'".htmlspecialchars($ga_id??'')."',analyticsEnabled:$an,preferencesEnabled:$pr,marketingEnabled:$mk};\n</script>\n";
        echo "<script src=\"".BASE_URL."/assets/js/cookie-consent.js\" defer></script>\n";
    }
}

function user_currency_symbol(string $currency): string
{
    return '₹'; // INR-only platform
}