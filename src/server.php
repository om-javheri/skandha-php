<?php

declare(strict_types=1);

/**
 * The site's own server (docs/39 in UniversalIDE): its forms' messages, its page views and, under
 * `php -S`, its files. Settings come from the environment: DATA_DIR, ADMIN_KEY, TRUST_PROXY
 * (see README.md). Needs PHP 8.1 or later with PDO SQLite.
 */

const ROOT = __DIR__ . '/..';
const SITE = ROOT . '/public';

const SCHEMA = [
    'CREATE TABLE IF NOT EXISTS form_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, form_id TEXT NOT NULL, page TEXT NOT NULL, fields TEXT NOT NULL, created_at TEXT NOT NULL)',
    'CREATE TABLE IF NOT EXISTS daily_counts (day TEXT NOT NULL, kind TEXT NOT NULL, key TEXT NOT NULL, count INTEGER NOT NULL, PRIMARY KEY (day, kind, key))',
    'CREATE TABLE IF NOT EXISTS rate_hits (bucket TEXT NOT NULL, at REAL NOT NULL)',
];

const CONTENT_TYPES = [
    'html' => 'text/html; charset=utf-8',
    'css' => 'text/css; charset=utf-8',
    'js' => 'text/javascript; charset=utf-8',
    'mjs' => 'text/javascript; charset=utf-8',
    'json' => 'application/json',
    'txt' => 'text/plain; charset=utf-8',
    'xml' => 'application/xml',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf' => 'font/ttf',
    'otf' => 'font/otf',
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'mp3' => 'audio/mpeg',
    'pdf' => 'application/pdf',
];

/** Whitespace as JavaScript trims it (the other servers' rule). */
const SPACE = '[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

function config(): array
{
    static $config = null;
    return $config ??= json_decode((string) file_get_contents(ROOT . '/server.json'), true, 512, JSON_THROW_ON_ERROR);
}

function setting(string $name, string $default = ''): string
{
    $value = getenv($name);
    if ($value === false || $value === '') $value = $_SERVER[$name] ?? $_SERVER['REDIRECT_' . $name] ?? '';
    return is_string($value) && $value !== '' ? $value : $default;
}

function db(): PDO
{
    static $db = null;
    if ($db === null) {
        $dir = setting('DATA_DIR', ROOT . '/data');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
            // In case the folder is ever inside the web root: never served.
            file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        $db = new PDO('sqlite:' . $dir . '/site.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        foreach (SCHEMA as $sql) $db->exec($sql);
    }
    return $db;
}

function answer_json(int $status, mixed $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function refuse(int $status, string $message): never
{
    answer_json($status, ['message' => $message]);
}

function not_found_page(): never
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    readfile(ROOT . '/not-found.html');
    exit;
}

/** A string's length as JavaScript counts it (UTF-16 code units), so limits match the other servers. */
function js_length(string $s): int
{
    return strlen($s) - preg_match_all('/[\x80-\xBF]/', $s) + preg_match_all('/[\xF0-\xF7]/', $s);
}

function js_trim(string $s): string
{
    return (string) preg_replace('/^' . SPACE . '+|' . SPACE . '+$/u', '', $s);
}

function request_header(string $name): string
{
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) if (strcasecmp((string) $key, $name) === 0) return (string) $value;
    }
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return (string) ($_SERVER[$key] ?? $_SERVER['REDIRECT_' . $key] ?? '');
}

/** The visitor's address, for the limits only (kept hashed for a minute). */
function visitor(): string
{
    if (setting('TRUST_PROXY') === '1') {
        $forwarded = trim(explode(',', request_header('X-Forwarded-For'))[0]);
        if ($forwarded !== '') return $forwarded;
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/** At most $perMinute hits a minute per visitor. PHP keeps nothing between requests, so the hits are in the database. */
function allow(string $kind, int $perMinute): bool
{
    $db = db();
    $now = microtime(true);
    $bucket = hash('sha256', $kind . '|' . visitor());
    $db->prepare('DELETE FROM rate_hits WHERE at <= ?')->execute([$now - 60]);
    $count = $db->prepare('SELECT COUNT(*) FROM rate_hits WHERE bucket = ?');
    $count->execute([$bucket]);
    if ((int) $count->fetchColumn() >= $perMinute) return false;
    $db->prepare('INSERT INTO rate_hits (bucket, at) VALUES (?, ?)')->execute([$bucket, $now]);
    return true;
}

/** The request body, refused when larger than the rules allow. */
function request_body(): string
{
    $max = (int) config()['rules']['maxBody'];
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) refuse(413, config()['messages']['tooLong']);
    $body = (string) stream_get_contents(fopen('php://input', 'r'), $max + 1);
    if (strlen($body) > $max) refuse(413, config()['messages']['tooLong']);
    return $body;
}

/** The page a message came from: one of the site's pages, else the home page. */
function page_path(?string $page): string
{
    $path = preg_split('/[?#]/', $page ?? '')[0];
    if (str_ends_with($path, '/index.html')) $path = substr($path, 0, -strlen('index.html'));
    if (strlen($path) > 1 && str_ends_with($path, '/')) $path = substr($path, 0, -1);
    return in_array($path, config()['pages'], true) ? $path : '/';
}

function today(int $offset = 0): string
{
    return gmdate('Y-m-d', time() + $offset * 86400);
}

function take_message(string $formId): never
{
    header('Access-Control-Allow-Origin: *');
    $config = config();
    $rules = $config['rules'];
    $messages = $config['messages'];
    $data = json_decode(request_body(), false);
    if (!$data instanceof stdClass) refuse(400, $messages['unreadable']);
    $fields = $data->fields ?? null;
    if (!is_array($fields) || !array_is_list($fields) || count($fields) < 1 || count($fields) > $rules['maxFields']) refuse(400, $messages['unreadable']);
    foreach ($fields as $f) {
        if (!$f instanceof stdClass) refuse(400, $messages['unreadable']);
        $keys = array_keys(get_object_vars($f));
        if (array_diff($keys, ['label', 'value']) !== [] || !is_string($f->label ?? null) || !is_string($f->value ?? null)) refuse(400, $messages['unreadable']);
        if (js_length($f->label) > $rules['maxLabel'] || js_length($f->value) > $rules['maxValue']) refuse(400, $messages['unreadable']);
    }
    $page = property_exists($data, 'page') ? $data->page : null;
    if (property_exists($data, 'page') && (!is_string($page) || js_length($page) > $rules['maxPage'])) refuse(400, $messages['unreadable']);

    if (!array_key_exists($formId, $config['forms'])) refuse(404, $messages['unknownForm']);
    if (count($fields) > $config['forms'][$formId]) refuse(400, $messages['tooManyFields']);
    $empty = true;
    $total = 0;
    foreach ($fields as $f) {
        if (js_trim($f->value) !== '') $empty = false;
        $total += js_length($f->label) + js_length($f->value);
    }
    if ($empty) refuse(400, $messages['empty']);
    if ($total > $rules['maxTotal']) refuse(400, $messages['tooLong']);
    if (!allow('message', (int) $rules['messagesPerMinute'])) refuse(429, $messages['tooMany']);

    $kept = array_map(fn ($f) => ['label' => js_trim($f->label), 'value' => js_trim($f->value)], $fields);
    $db = db();
    $created = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    $db->prepare('INSERT INTO form_messages (form_id, page, fields, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$formId, page_path($page), json_encode($kept, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $created]);
    $db->prepare('DELETE FROM form_messages WHERE id NOT IN (SELECT id FROM form_messages ORDER BY id DESC LIMIT ?)')->execute([(int) $rules['keepMessages']]);
    answer_json(200, ['ok' => true]);
}

function count_view(): never
{
    $config = config();
    $agent = request_header('User-Agent');
    $body = request_body();
    if ($agent !== '' && !preg_match('#' . $config['botPattern'] . '#i', $agent) && allow('view', (int) $config['rules']['viewsPerMinute'])) {
        $data = json_decode($body, false);
        $path = $data instanceof stdClass && is_string($data->path ?? null) ? preg_split('/[?#]/', $data->path)[0] : null;
        if ($path !== null && in_array($path, $config['pages'], true)) {
            $bump = db()->prepare('INSERT INTO daily_counts (day, kind, key, count) VALUES (?, ?, ?, 1) ON CONFLICT (day, kind, key) DO UPDATE SET count = count + 1');
            $bump->execute([today(), 'view', $path]);
            $referrer = is_string($data->referrer ?? null) ? $data->referrer : '';
            $parts = $referrer !== '' ? parse_url($referrer) : false;
            $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
            $own = strtolower((string) parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
            if ($host !== '' && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) && $host !== $own && strlen($host) <= 253) {
                $bump->execute([today(), 'referrer', $host]);
            }
        }
    }
    // A beacon never fails.
    http_response_code(204);
    exit;
}

function require_admin(): void
{
    $key = setting('ADMIN_KEY');
    if ($key === '') refuse(404, config()['messages']['notFound']);
    $sent = preg_match('/^Bearer (.+)$/', request_header('Authorization'), $m) ? $m[1] : '';
    if ($sent === '' || !hash_equals(hash('sha256', $key), hash('sha256', $sent))) refuse(401, config()['messages']['unauthorized']);
}

function admin_messages(): never
{
    $rows = db()->query('SELECT id, form_id, page, fields, created_at FROM form_messages ORDER BY id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
    answer_json(200, ['messages' => array_map(fn ($r) => [
        'id' => (int) $r['id'],
        'formId' => $r['form_id'],
        'page' => $r['page'],
        'fields' => json_decode($r['fields'], true),
        'createdAt' => $r['created_at'],
    ], $rows)]);
}

function admin_stats(): never
{
    $days = filter_var($_GET['days'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 366]]);
    $days = $days === false ? 30 : $days;
    $since = today(-($days - 1));
    $db = db();
    $totals = function (string $kind) use ($db, $since): array {
        $q = $db->prepare('SELECT key, SUM(count) AS count FROM daily_counts WHERE kind = ? AND day >= ? GROUP BY key ORDER BY count DESC, key');
        $q->execute([$kind, $since]);
        return array_map(fn ($r) => ['key' => $r['key'], 'count' => (int) $r['count']], $q->fetchAll(PDO::FETCH_ASSOC));
    };
    $daily = $db->prepare("SELECT day, SUM(count) AS views FROM daily_counts WHERE kind = 'view' AND day >= ? GROUP BY day ORDER BY day");
    $daily->execute([$since]);
    answer_json(200, [
        'days' => $days,
        'daily' => array_map(fn ($r) => ['day' => $r['day'], 'views' => (int) $r['views']], $daily->fetchAll(PDO::FETCH_ASSOC)),
        'pages' => $totals('view'),
        'referrers' => $totals('referrer'),
    ]);
}

/** A file of the site (php -S only: Apache serves them itself, see public/.htaccess). */
function serve_file(string $path): never
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (($method !== 'GET' && $method !== 'HEAD') || str_contains($path, "\0") || str_contains($path, '\\')) not_found_page();
    foreach (explode('/', $path) as $segment) {
        if ($segment === '..' || str_starts_with($segment, '.')) not_found_page();
    }
    $file = SITE . (str_ends_with($path, '/') ? $path . 'index.html' : $path);
    if (!str_ends_with($path, '/') && is_dir($file) && is_file($file . '/index.html')) {
        $query = $_SERVER['QUERY_STRING'] ?? '';
        http_response_code(301);
        header('Location: ' . $path . '/' . ($query !== '' ? '?' . $query : ''));
        exit;
    }
    $real = realpath($file);
    $site = realpath(SITE);
    if ($real === false || !is_file($real) || !str_starts_with($real, $site . DIRECTORY_SEPARATOR) || basename($real) === 'index.php') not_found_page();
    $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    header('Content-Type: ' . (CONTENT_TYPES[$extension] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($real));
    // Exact copies of imported pages run only sandboxed.
    if (str_starts_with($path, '/_embeds/')) header('Content-Security-Policy: ' . config()['embedCsp']);
    if ($method === 'GET') readfile($real);
    exit;
}

/** Answers the request: the API, then (under php -S) the site's files, else the 404 page. */
function handle_request(bool $serveFiles): never
{
    header('X-Content-Type-Options: nosniff');
    header_remove('X-Powered-By');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    if (str_starts_with($path, '/api/')) {
        if ($method === 'POST' && preg_match('#^/api/forms/([^/]+)$#', $path, $m)) take_message($m[1]);
        if ($method === 'POST' && $path === '/api/views') count_view();
        if ($method === 'GET' && $path === '/api/admin/messages') {
            require_admin();
            admin_messages();
        }
        if ($method === 'DELETE' && preg_match('#^/api/admin/messages/([^/]+)$#', $path, $m)) {
            require_admin();
            $id = filter_var($m[1], FILTER_VALIDATE_INT);
            $delete = db()->prepare('DELETE FROM form_messages WHERE id = ?');
            if ($id === false || !$delete->execute([$id]) || $delete->rowCount() === 0) refuse(404, config()['messages']['notFound']);
            http_response_code(204);
            exit;
        }
        if ($method === 'GET' && $path === '/api/admin/stats') {
            require_admin();
            admin_stats();
        }
        refuse(404, config()['messages']['notFound']);
    }
    if ($serveFiles) serve_file($path);
    not_found_page();
}
