<?php
// PHP + SQLite port of worker/server.js, block-layout.js, presenter-access.js and xlsx.js
// for classic shared hosting. Behaviour and API responses mirror the Cloudflare Worker.
declare(strict_types=1);

ini_set('pcre.backtrack_limit', '10000000');
ini_set('display_errors', '0');
mb_internal_encoding('UTF-8');

final class HttpError extends Exception {}

const APP_DIR = __DIR__;
const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

const BLOCK_DEFINITIONS = [
    ['id' => 'hero', 'title' => 'Главный баннер', 'anchor' => 'top', 'podium' => true, 'decorations' => true],
    ['id' => 'claim', 'title' => 'Самый большой автосалон', 'anchor' => ''],
    ['id' => 'intro', 'title' => 'О мероприятии', 'anchor' => 'event'],
    ['id' => 'models', 'title' => 'Модельный ряд', 'anchor' => 'models', 'podium' => true],
    ['id' => 'countdown', 'title' => 'Счётчик до мероприятия', 'anchor' => 'countdown'],
    ['id' => 'programme', 'title' => 'Программа', 'anchor' => 'programme'],
    ['id' => 'gifts', 'title' => 'Подарки и розыгрыш', 'anchor' => 'gifts'],
    ['id' => 'city', 'title' => 'Городской акцент', 'anchor' => ''],
    ['id' => 'venue', 'title' => 'Место проведения', 'anchor' => 'venue'],
    ['id' => 'registration', 'title' => 'Форма регистрации', 'anchor' => 'registration'],
    ['id' => 'sponsors', 'title' => 'Партнёры мероприятия', 'anchor' => 'sponsors'],
    ['id' => 'faq', 'title' => 'Вопросы и ответы', 'anchor' => 'faq'],
    ['id' => 'footer', 'title' => 'Подвал сайта', 'anchor' => ''],
];
const PRESENTER_SETTING = 'presenterAccess';
const RU_MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

// ---------------------------------------------------------------- basics

function config(): array { static $c; return $c ??= require APP_DIR . '/config.php'; }
function defaults(): array { static $d; return $d ??= json_decode(file_get_contents(APP_DIR . '/defaults.json'), true); }
function legacy_defaults(): array { static $d; return $d ??= json_decode(file_get_contents(APP_DIR . '/legacy.json'), true); }
function template(string $name): string { return file_get_contents(APP_DIR . '/templates/' . $name . '.html'); }
function jenc($v): string { return json_encode($v, JSON_FLAGS); }
function is_obj($v): bool { return is_array($v) && ($v === [] || !array_is_list($v)); }
function is_list_arr($v): bool { return is_array($v) && array_is_list($v); }
function is_int_like($v): bool { return is_int($v) || (is_float($v) && is_finite($v) && floor($v) == $v); }
function slen(string $s): int { return mb_strlen($s, 'UTF-8'); }
function esc($v): string {
    if (is_array($v)) return '';
    if (is_bool($v)) $v = $v ? 'true' : 'false';
    return strtr((string)($v ?? ''), ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;']);
}
function stamp(): string { return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'); }
function uuid4(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
function b64u(string $bytes): string { return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); }

function send(int $status, array $headers, string $body = ''): never {
    http_response_code($status);
    foreach ($headers as $k => $v) header($k . ': ' . $v);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $body;
    exit;
}
function json_out($body, int $status = 200, array $extra = []): never {
    send($status, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'] + $extra, jenc($body));
}
function presenter_json($body, int $status = 200): never {
    json_out($body, $status, ['Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);
}
function html_out(string $html, array $extra = [], int $status = 200): never {
    send($status, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'] + $extra, $html);
}
function text_out(string $text, int $status): never {
    send($status, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], $text);
}

function request_scheme(): string {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return 'https';
    if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return 'https';
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') return 'https';
    return 'http';
}
function request_host(): string { return $_SERVER['HTTP_HOST'] ?? parse_url(config()['siteOrigin'], PHP_URL_HOST); }
function request_origin(): string { return request_scheme() . '://' . request_host(); }
function site_origin(): string { return config()['siteOrigin']; }

function request_header(string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) return (string)$_SERVER[$key];
    if ($name === 'Authorization') return (string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($name === 'Content-Type') return (string)($_SERVER['CONTENT_TYPE'] ?? '');
    if ($name === 'Content-Length') return (string)($_SERVER['CONTENT_LENGTH'] ?? '');
    return '';
}

function read_json(int $max = 150000) {
    if (!str_starts_with(request_header('Content-Type'), 'application/json')) throw new HttpError('Отправьте данные в формате JSON.');
    if ((int)request_header('Content-Length') > $max) throw new HttpError('Слишком большой запрос.');
    $text = (string)file_get_contents('php://input', false, null, 0, $max + 1);
    if (strlen($text) > $max) throw new HttpError('Слишком большой запрос.');
    try { return json_decode($text, true, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new HttpError('Некорректные данные.'); }
}

// ---------------------------------------------------------------- database

function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $dir = APP_DIR . '/data';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $pdo = new PDO('sqlite:' . $dir . '/site.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    $pdo->exec('PRAGMA busy_timeout=10000');
    $pdo->exec('PRAGMA foreign_keys=ON');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version >= 1) return;
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS registrations (
 id TEXT PRIMARY KEY NOT NULL,
 full_name TEXT NOT NULL,
 phone TEXT NOT NULL UNIQUE,
 consent_version TEXT NOT NULL,
 created_at TEXT NOT NULL,
 marketing_consent INTEGER NOT NULL DEFAULT 0 CHECK (marketing_consent IN (0,1)),
 marketing_consent_at TEXT,
 marketing_consent_version TEXT,
 existing_client INTEGER CHECK (existing_client IN (0,1)),
 age INTEGER CHECK (age >= 18 AND age <= 120),
 gender TEXT CHECK (gender IN ('male','female','unspecified')),
 registration_source TEXT NOT NULL DEFAULT 'site' CHECK (registration_source IN ('site','qr','call-center'))
);
CREATE TABLE IF NOT EXISTS site_settings (
 key TEXT PRIMARY KEY NOT NULL,
 value TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS prizes (
 id TEXT PRIMARY KEY NOT NULL,
 title TEXT NOT NULL,
 partner TEXT NOT NULL DEFAULT '',
 description TEXT NOT NULL DEFAULT '',
 quantity INTEGER NOT NULL DEFAULT 1 CHECK (quantity >= 1 AND quantity <= 1000),
 image TEXT NOT NULL DEFAULT '',
 active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
 published INTEGER NOT NULL DEFAULT 0 CHECK (published IN (0,1)),
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS raffle_draws (
 id TEXT PRIMARY KEY NOT NULL,
 request_id TEXT NOT NULL UNIQUE,
 registration_id TEXT NOT NULL UNIQUE REFERENCES registrations(id),
 prize TEXT NOT NULL,
 eligible_count INTEGER NOT NULL,
 eligible_ids TEXT NOT NULL,
 drawn_at TEXT NOT NULL,
 prize_id TEXT REFERENCES prizes(id)
);
CREATE INDEX IF NOT EXISTS raffle_draws_prize_id_idx ON raffle_draws(prize_id);
CREATE TABLE IF NOT EXISTS email_deliveries (
 registration_id TEXT PRIMARY KEY NOT NULL REFERENCES registrations(id),
 state TEXT NOT NULL,
 detail TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
PRAGMA user_version=1;
SQL);
}

function q(string $sql, array $params = []): PDOStatement { $st = db()->prepare($sql); $st->execute($params); return $st; }
function first(string $sql, array $params = []): ?array { $r = q($sql, $params)->fetch(); return $r === false ? null : $r; }
function all(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function changes(string $sql, array $params = []): int { return q($sql, $params)->rowCount(); }

function setting(string $key, $fallback) {
    $r = first('SELECT value FROM site_settings WHERE key=?', [$key]);
    return $r ? json_decode($r['value'], true) : $fallback;
}
function set_setting(string $key, $value): void {
    q('INSERT INTO site_settings (key,value,updated_at) VALUES (?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at', [$key, jenc($value), stamp()]);
}

// ---------------------------------------------------------------- content

function content(): array {
    $saved = setting('content', []);
    if (!is_array($saved)) $saved = [];
    $version = $saved['_designVersion'] ?? null;
    unset($saved['_designVersion']);
    $D = defaults();
    $L = legacy_defaults();
    if (!$version) {
        foreach (array_keys($saved) as $key) {
            if (array_key_exists($key, $L) && jenc($saved[$key]) === jenc($L[$key])) unset($saved[$key]);
        }
    }
    $c = array_merge($D, $saved);
    if (($c['dialogLogo'] ?? null) === '/assets/dialog-logo.svg') $c['dialogLogo'] = $D['dialogLogo'];
    if (($c['fleetLeftImage'] ?? null) === '/assets/fleet-jolion-headon.webp') $c['fleetLeftImage'] = $D['fleetLeftImage'];
    if (($c['fleetRightImage'] ?? null) === '/assets/fleet-f7-headon.webp') $c['fleetRightImage'] = $D['fleetRightImage'];
    if (($c['fleetLeftImageAlt'] ?? null) === 'HAVAL JOLION — вид спереди') $c['fleetLeftImageAlt'] = $D['fleetLeftImageAlt'];
    if (($c['fleetRightImageAlt'] ?? null) === 'HAVAL F7 — вид спереди') $c['fleetRightImageAlt'] = $D['fleetRightImageAlt'];
    $old = [
        'fleetLeftImage' => '/assets/fleet-jolion-front.webp',
        'fleetRightImage' => '/assets/fleet-f7-front.webp',
        'fleetLeftImageAlt' => 'HAVAL JOLION — передний ракурс три четверти',
        'fleetRightImageAlt' => 'HAVAL F7 — передний ракурс три четверти',
        'introTitle' => "Набережные Челны.\nНовый повод встретиться.",
        'introText' => 'Открываем новый дом HAVAL CITY в городе на Каме. Зеркальные шары над автомобилями, живая музыка и тёплый welcome — вечер, в котором город, люди и HAVAL встречаются впервые.',
        'heroEventTitle' => 'HAVAL CITY · Набережные Челны',
    ];
    foreach ($old as $field => $oldDefault) if (($c[$field] ?? null) === $oldDefault) $c[$field] = $D[$field];
    $photos = $c['gallery'] ?: $D['gallery'];
    $interior = null;
    if (count($photos) > 2) {
        foreach ($photos as $i => $p) {
            if (preg_match('/интерьер|внутри/iu', ($p['title'] ?? '') . ' ' . ($p['imageAlt'] ?? ''))) { $interior = $i; break; }
        }
    }
    $c['gallery'] = $interior !== null && $interior !== 0 ? [$photos[0], $photos[$interior]] : array_slice($photos, 0, 2);
    $c['experiences'] = array_map(fn($row) => ['title' => $row['title'] ?? null, 'text' => $row['text'] ?? null], $c['experiences']);
    return $c;
}

function valid_image_url($value): bool {
    if (!is_string($value) || slen($value) > 2500 || preg_match('/[\x00-\x20\\\\]/', $value)) return false;
    if (str_starts_with($value, '/')) return (bool)preg_match('#^/(assets|media)/#', $value) || $value === '/haval.jpg';
    if (!preg_match('/^[a-z][a-z0-9+.\-]*:/i', $value)) return true; // relative URL resolves against the https origin
    $p = parse_url($value);
    if ($p === false) return false;
    return strtolower($p['scheme'] ?? '') === 'https' && !empty($p['host']) && !isset($p['user']) && !isset($p['pass']);
}
function absolute_image($value): string {
    $v = valid_image_url($value) ? $value : defaults()['atmosphereImage'];
    if (preg_match('/^https:/i', $v)) return $v;
    return site_origin() . (str_starts_with($v, '/') ? $v : '/' . $v);
}

function render_hero_fleet(array $c): string {
    $out = '';
    foreach (['Left', 'Center', 'Right'] as $position) {
        $img = esc($c['fleet' . $position . 'Image'] ?? '');
        $out .= '<div class="fleet-position fleet-position-' . strtolower($position) . '"><img class="fleet-front-car" src="' . $img . '" alt="' . esc($c['fleet' . $position . 'ImageAlt'] ?? '') . '" width="1000" height="750" fetchpriority="high"><img class="fleet-reflection" src="' . $img . '" alt="" aria-hidden="true" width="1000" height="750"></div>';
    }
    return $out;
}

function render(array $c, bool $isAdmin, array $prizes = [], array $sponsors = [], ?string $tpl = null, string $registrationSource = 'site', ?array $blockLayout = null): string {
    $tpl ??= template('page');
    $D = defaults();
    if ($blockLayout) $c = array_merge($c, ['heroBackgroundMode' => 'silver', 'heroBackgroundImage' => '', 'heroDecorations' => $blockLayout['hero']['decorations'] ?? 'show']);
    [$y, $m, $d] = array_map('intval', explode('-', (string)$c['eventDate']) + [0, 1, 1]);
    $month = RU_MONTHS[max(0, min(11, $m - 1))];
    $heroImageBg = ($c['heroBackgroundMode'] ?? '') === 'image' && valid_image_url($c['heroBackgroundImage'] ?? '');
    $fields = array_merge($c, [
        'registrationSource' => $registrationSource,
        'heroBackdropClass' => $heroImageBg ? 'hero-has-background' : '',
        'heroDecorClass' => ($c['heroDecorations'] ?? '') === 'hide' ? 'hero-decor-hidden' : '',
        'eventStart' => $c['eventDate'] . 'T' . $c['startTime'] . ':00+03:00',
        'eventEnd' => $c['eventDate'] . 'T' . $c['endTime'] . ':00+03:00',
        'socialImage' => absolute_image($c['gallery'][0]['image'] ?? $c['heroImage']),
        'dateNumeric' => sprintf('%02d.%02d', $d, $m),
        'dateDay' => sprintf('%02d', $d),
        'dateMonth' => $month,
        'dateYear' => (string)$y,
        'dateLong' => $d . ' ' . $month . ' ' . $y . ' г.',
        'dateShort' => $d . ' ' . $month,
        'mapUrl' => 'https://yandex.ru/maps/?text=' . rawurlencode($c['city'] . ', ' . $c['address']),
    ]);
    $claimMatch = preg_match('/^(Самый большой)\s+(.+)$/iuD', (string)$c['claim'], $cm);
    $models = $c['models'] ?: $D['models'];
    $modelsHtml = '';
    $tabsHtml = '';
    foreach ($models as $i => $v) {
        $modelsHtml .= '<article class="lineup-panel' . ($i === 0 ? ' is-active' : '') . '" id="model-panel-' . $i . '" role="tabpanel" aria-labelledby="model-tab-' . $i . '"' . ($i ? ' hidden' : '') . '><div class="lineup-copy"><span class="lineup-kicker">HAVAL CITY / ' . sprintf('%02d', $i + 1) . '</span><h3>' . esc($v['name']) . '</h3><strong>' . esc($v['tagline']) . '</strong><p>' . esc($v['description']) . '</p><a class="lineup-cta" href="#registration">Познакомиться на открытии <span aria-hidden="true">↗</span></a></div><div class="lineup-car-stage"><span class="lineup-model-ghost" aria-hidden="true">' . esc($v['name']) . '</span><img src="' . esc($v['image']) . '" alt="' . esc($v['imageAlt']) . '" width="800" height="400" loading="lazy"></div></article>';
        $tabsHtml .= '<button class="model-tab' . ($i === 0 ? ' is-active' : '') . '" type="button" id="model-tab-' . $i . '" role="tab" aria-selected="' . ($i === 0 ? 'true' : 'false') . '" aria-controls="model-panel-' . $i . '" tabindex="' . ($i === 0 ? '0' : '-1') . '" data-model-index="' . $i . '">' . esc($v['name']) . '</button>';
    }
    $sponsorsHtml = '';
    if ($sponsors) {
        $sponsorsHtml = '<section data-block="sponsors" class="event-sponsors"><div class="wrap"><div class="eyebrow">Вместе делаем этот вечер</div><h2>Партнёры вечера</h2><div class="sponsor-logos">';
        foreach ($sponsors as $v) {
            $sponsorsHtml .= '<div class="sponsor-logo">' . (!empty($v['url']) ? '<a href="' . esc($v['url']) . '" target="_blank" rel="noopener sponsored">' : '<span>') . '<img src="' . esc($v['image']) . '" alt="' . esc($v['name']) . '" loading="lazy" width="240" height="100">' . (!empty($v['url']) ? '</a>' : '</span>') . '</div>';
        }
        $sponsorsHtml .= '</div></div></section>';
    }
    $gallery = '';
    foreach ($c['gallery'] ?? [] as $i => $v) {
        $gallery .= '<article class="gallery-card"><button class="gallery-open" type="button" data-gallery-index="' . $i . '" aria-label="Открыть фото ' . esc($v['title']) . '"><div class="gallery-media"><img src="' . esc($v['image']) . '" alt="' . esc($v['imageAlt']) . '" loading="lazy" width="1920" height="1080"></div><div class="gallery-caption"><span class="gallery-model">' . esc($v['title']) . '</span><span class="gallery-subtitle">' . esc($v['subtitle']) . '</span><span class="gallery-expand" aria-hidden="true">↗</span></div></button></article>';
    }
    $prizesHtml = '';
    foreach ($prizes as $v) {
        $prizesHtml .= '<article class="prize-card"><div class="prize-media">' . ($v['image'] ? '<img src="' . esc($v['image']) . '" alt="' . esc($v['title']) . '" loading="lazy" width="600" height="450">' : '<span class="prize-placeholder" aria-hidden="true">✧</span>') . '</div><div class="prize-copy">' . ($v['partner'] ? '<span class="prize-partner">' . esc($v['partner']) . '</span>' : '') . '<h3>' . esc($v['title']) . '</h3>' . ($v['description'] ? '<p>' . esc($v['description']) . '</p>' : '') . '</div></article>';
    }
    $exp = '';
    foreach ($c['experiences'] as $i => $v) $exp .= '<article class="principle"><div class="principle-heading"><span class="number">0' . ($i + 1) . '</span><h3>' . esc($v['title']) . '</h3></div><p>' . esc($v['text']) . '</p></article>';
    $prog = '';
    foreach ($c['programme'] as $v) $prog .= '<article class="timeline-row"><time>' . esc($v['time']) . '</time><div><h3>' . esc($v['title']) . '</h3><p>' . esc($v['text']) . '</p></div></article>';
    $faq = '';
    foreach ($c['faq'] as $v) $faq .= '<details><summary>' . esc($v['question']) . '</summary><p>' . esc($v['answer']) . '</p></details>';

    $raw = [
        'heroBackdropHtml' => $heroImageBg ? '<div class="hero-uploaded-backdrop" aria-hidden="true"><img src="' . esc($c['heroBackgroundImage']) . '" alt="" fetchpriority="high"></div>' : '',
        'sponsorsSection' => $sponsorsHtml,
        'heroFleetHtml' => render_hero_fleet($c),
        'heroScaleHtml' => $claimMatch ? '<strong>' . esc($cm[1]) . '</strong><span>' . esc($cm[2]) . '</span>' : '<strong>' . esc($c['claim']) . '</strong>',
        'claimStatementHtml' => $claimMatch ? '<span class="landmark-large">' . esc($cm[1]) . '</span><span class="landmark-detail">' . esc($cm[2]) . '</span>' : '<span class="landmark-large">' . esc($c['claim']) . '</span>',
        'modelsHtml' => $modelsHtml,
        'modelTabsHtml' => $tabsHtml,
        'organizerLink' => $isAdmin ? '<a class="organizer-login" href="/admin">Панель организатора <span aria-hidden="true">↗</span></a>' : '',
        'ownerTools' => $isAdmin ? '<div class="owner-tools wrap"><span>Вы вошли как организатор</span><a href="/admin">Панель организатора ↗</a></div>' : '',
        'galleryHtml' => $gallery,
        'prizesHtml' => $prizesHtml,
        'experiencesHtml' => $exp,
        'programmeHtml' => $prog,
        'faqHtml' => $faq,
        'raffleLink' => $isAdmin ? '<a class="raffle-shortcut" href="/raffle" target="_blank" rel="noopener">Открыть экран розыгрыша <span aria-hidden="true">↗</span></a>' : '',
        'adminLink' => $isAdmin ? '<a href="/admin">Управление сайтом ↗</a><a href="/raffle">Розыгрыш подарков ↗</a>' : '',
    ];
    $page = preg_replace_callback('/\{\{(\w+)\}\}/', fn($m) => array_key_exists($m[1], $raw) ? $raw[$m[1]] : esc($fields[$m[1]] ?? ''), $tpl);
    return $blockLayout ? apply_block_layout($page, $blockLayout) : $page;
}

function validate_content($body): array {
    if (!is_obj($body)) throw new HttpError('Проверьте данные.');
    $out = [];
    $maxMap = ['qrTitle' => 120, 'eventTitle' => 180, 'qrDescription' => 1000, 'qrInstruction' => 220, 'qrPosterNote' => 180, 'qrSubmitText' => 80, 'qrSuccessTitle' => 120, 'qrSuccessText' => 1000, 'heroKicker' => 200];
    foreach (defaults() as $k => $v) {
        $x = $body[$k] ?? null;
        $maxText = $maxMap[$k] ?? 8000;
        if (is_string($x) && slen($x) > $maxText) throw new HttpError('Сократите текст поля ' . $k . ' до ' . $maxText . ' символов.');
        if ($k === 'heroBackgroundImage' && is_string($x) && trim($x) === '') { $out[$k] = ''; continue; }
        if (is_list_arr($v)) {
            if (!is_list_arr($x) || count($x) < 1 || count($x) > ($k === 'gallery' ? 2 : 30)) throw new HttpError('Заполните список: ' . $k);
            $rows = [];
            foreach ($x as $row) {
                $r = [];
                foreach (array_keys($v[0]) as $field) {
                    $val = is_array($row) ? ($row[$field] ?? null) : null;
                    if (!is_string($val) || trim($val) === '' || slen($val) > 3000) throw new HttpError('Проверьте поля: ' . $k);
                    if ($field === 'image' && !valid_image_url($val)) throw new HttpError('Укажите HTTPS-ссылку для изображения.');
                    $r[$field] = trim($val);
                }
                $rows[] = $r;
            }
            $out[$k] = $rows;
        } else {
            if (!is_string($x) || trim($x) === '' || slen($x) > 8000) throw new HttpError('Заполните поле: ' . $k);
            if (preg_match('/(Image|Logo)$/', $k) && !valid_image_url($x)) throw new HttpError('Укажите HTTPS-ссылку или загрузите изображение.');
            if ($k === 'footerOfficialUrl') {
                $p = parse_url(trim($x));
                if (!$p || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || slen($x) > 2500) throw new HttpError('Укажите корректную HTTPS-ссылку на официальный сайт.');
            }
            $out[$k] = trim($x);
        }
    }
    if (!in_array($out['heroBackgroundMode'], ['silver', 'image'], true)) throw new HttpError('Выберите тип подложки баннера.');
    if (!in_array($out['heroDecorations'], ['show', 'hide'], true)) throw new HttpError('Выберите режим серебряного декора.');
    if ($out['heroBackgroundMode'] === 'image' && !$out['heroBackgroundImage']) throw new HttpError('Загрузите картинку подложки или выберите стандартный серебряный фон.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $out['eventDate']) || !checkdate((int)substr($out['eventDate'], 5, 2), (int)substr($out['eventDate'], 8, 2), (int)substr($out['eventDate'], 0, 4))) throw new HttpError('Проверьте дату.');
    foreach (['startTime', 'testDriveTime', 'endTime'] as $k) if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $out[$k])) throw new HttpError('Проверьте время.');
    return $out;
}

// ---------------------------------------------------------------- block layout

function default_block_layout(array $c = []): array {
    $layout = [];
    foreach (BLOCK_DEFINITIONS as $def) {
        $item = ['visible' => true, 'backgroundMode' => 'default', 'backgroundImage' => '', 'overlay' => 'light'];
        if (!empty($def['decorations'])) $item['decorations'] = ($c['heroDecorations'] ?? '') === 'hide' ? 'hide' : 'show';
        if (!empty($def['podium'])) { $item['podiumMode'] = 'default'; $item['podiumImage'] = ''; }
        if ($def['id'] === 'hero' && ($c['heroBackgroundMode'] ?? '') === 'image' && valid_image_url($c['heroBackgroundImage'] ?? null)) {
            $item['backgroundMode'] = 'image';
            $item['backgroundImage'] = $c['heroBackgroundImage'];
        }
        $layout[$def['id']] = $item;
    }
    return $layout;
}

function validate_one_block_layout($value, array $def): array {
    $fail = function (string $m) use ($def) { throw new HttpError($def['title'] . ': ' . $m); };
    if (!is_obj($value)) $fail('проверьте настройки блока.');
    $allowed = ['visible', 'backgroundMode', 'backgroundImage', 'overlay'];
    if (!empty($def['decorations'])) $allowed[] = 'decorations';
    if (!empty($def['podium'])) array_push($allowed, 'podiumMode', 'podiumImage');
    foreach (array_keys($value) as $key) if (!in_array($key, $allowed, true)) $fail('неизвестное поле настроек.');
    if (!is_bool($value['visible'] ?? null)) $fail('укажите, показывать ли блок на сайте.');
    if (!in_array($value['backgroundMode'] ?? null, ['default', 'image'], true)) $fail('выберите стандартную подложку или свою картинку.');
    if (!in_array($value['overlay'] ?? null, ['light', 'none'], true)) $fail('выберите режим подложки под текстом.');
    $image = function (string $key, bool $required) use ($value, $fail) {
        if (!is_string($value[$key] ?? null)) $fail('проверьте ссылку на изображение.');
        $url = trim($value[$key]);
        if ($url !== '' && !valid_image_url($url)) $fail('укажите HTTPS-ссылку или загрузите изображение.');
        if ($required && $url === '') $fail($key === 'podiumImage' ? 'загрузите подложку под автомобилями.' : 'загрузите изображение фона.');
        return $url;
    };
    $item = ['visible' => $value['visible'], 'backgroundMode' => $value['backgroundMode'], 'backgroundImage' => $image('backgroundImage', $value['backgroundMode'] === 'image'), 'overlay' => $value['overlay']];
    if (!empty($def['decorations'])) {
        if (!in_array($value['decorations'] ?? null, ['show', 'hide'], true)) $fail('выберите, показывать ли серебряный декор.');
        $item['decorations'] = $value['decorations'];
    }
    if (!empty($def['podium'])) {
        if (!in_array($value['podiumMode'] ?? null, ['default', 'image', 'none'], true)) $fail('выберите режим подложки под автомобилями.');
        $item['podiumMode'] = $value['podiumMode'];
        $item['podiumImage'] = $image('podiumImage', $value['podiumMode'] === 'image');
    }
    return $item;
}

function validate_block_layout($body): array {
    if (!is_obj($body)) throw new HttpError('Передайте настройки блоков сайта.');
    $known = array_column(BLOCK_DEFINITIONS, 'id');
    foreach (array_keys($body) as $key) if (!in_array($key, $known, true)) throw new HttpError('Неизвестный блок сайта. Обновите страницу админки.');
    $layout = [];
    foreach (BLOCK_DEFINITIONS as $def) {
        if (!array_key_exists($def['id'], $body)) throw new HttpError('Не найдены настройки блока «' . $def['title'] . '». Обновите страницу админки.');
        $layout[$def['id']] = validate_one_block_layout($body[$def['id']], $def);
    }
    return $layout;
}

function get_block_layout(array $c = []): array {
    $defaults = default_block_layout($c);
    $saved = setting('blockLayout', null);
    if (!is_obj($saved)) return $defaults;
    $layout = [];
    foreach (BLOCK_DEFINITIONS as $def) {
        $fallback = $defaults[$def['id']];
        $value = $saved[$def['id']] ?? null;
        if (!is_obj($value)) { $layout[$def['id']] = $fallback; continue; }
        $candidate = $fallback;
        foreach (array_keys($fallback) as $key) if (array_key_exists($key, $value)) $candidate[$key] = $value[$key];
        try { $layout[$def['id']] = validate_one_block_layout($candidate, $def); }
        catch (HttpError) { $layout[$def['id']] = $fallback; }
    }
    return $layout;
}

function block_add_class(string $opening, string $name): string {
    if (preg_match('/\bclass=([\'"])/', $opening)) {
        return preg_replace_callback('/\bclass=(["\'])([^"\']*)\1/', fn($m) => 'class=' . $m[1] . $m[2] . ' ' . $name . $m[1], $opening, 1);
    }
    return preg_replace('/>$/', ' class="' . $name . '">', $opening);
}
function block_map_div(string $html, string $target, callable $transform): string {
    return preg_replace_callback('/<div\b[^>]*\bclass=(["\'])([^"\']*)\1[^>]*>/', function ($m) use ($target, $transform) {
        return in_array($target, preg_split('/\s+/', $m[2]), true) ? $transform($m[0]) : $m[0];
    }, $html);
}
function block_definition(string $id): ?array {
    foreach (BLOCK_DEFINITIONS as $d) if ($d['id'] === $id) return $d;
    return null;
}

function apply_block_layout(string $html, ?array $layout): string {
    $defaults = default_block_layout();
    $output = preg_replace_callback('/<section\b([^>]*\bdata-block=(["\'])([a-z-]+)\2[^>]*)>([\s\S]*?)<\/section\s*>/i', function ($m) use ($layout, $defaults) {
        [$whole, $attributes, , $id, $body] = $m;
        if (!block_definition($id)) return $whole;
        $item = $layout[$id] ?? $defaults[$id];
        if (($item['visible'] ?? true) === false) return '';
        $opening = '<section' . $attributes . '>';
        if (($item['backgroundMode'] ?? '') === 'image' && valid_image_url($item['backgroundImage'] ?? null)) {
            $opening = block_add_class($opening, 'section-custom-surface');
            $overlay = ($item['overlay'] ?? '') === 'none' ? 'none' : 'light';
            $body = '<div class="section-background layer-overlay-' . $overlay . '" aria-hidden="true"><img src="' . esc($item['backgroundImage']) . '" alt="" ' . ($id === 'hero' ? 'fetchpriority="high"' : 'loading="lazy"') . '></div>' . $body;
        }
        $podiumMode = $item['podiumMode'] ?? null;
        if ($id === 'hero' && $podiumMode === 'image' && valid_image_url($item['podiumImage'] ?? null)) {
            $body = block_map_div($body, 'hero-visual', fn($t) => block_add_class($t, 'hero-custom-podium'));
            $art = '<div class="hero-podium-art" aria-hidden="true"><img src="' . esc($item['podiumImage']) . '" alt="" width="1600" height="500"></div>';
            $inserted = false;
            $body = block_map_div($body, 'hero-fleet-arc', function ($t) use (&$inserted, $art) { if ($inserted) return $t; $inserted = true; return $art . $t; });
        } elseif ($id === 'hero' && $podiumMode === 'none') {
            $body = block_map_div($body, 'hero-visual', fn($t) => block_add_class($t, 'hero-no-podium'));
        }
        if ($id === 'models' && $podiumMode === 'image' && valid_image_url($item['podiumImage'] ?? null)) {
            $art = '<img class="lineup-podium-art" src="' . esc($item['podiumImage']) . '" alt="" aria-hidden="true" width="1600" height="500" loading="lazy">';
            $body = block_map_div($body, 'lineup-car-stage', fn($t) => block_add_class($t, 'model-custom-podium') . $art);
        } elseif ($id === 'models' && $podiumMode === 'none') {
            $body = block_map_div($body, 'lineup-car-stage', fn($t) => block_add_class($t, 'model-no-podium'));
        }
        return $opening . $body . '</section>';
    }, $html);
    preg_match_all('/<[a-z][^>]*\sid=(["\'])([^"\']+)\1/i', $output, $mm);
    $ids = array_flip($mm[2]);
    $output = preg_replace_callback('/<nav\b[^>]*\bclass=(["\'])([^"\']*)\1[^>]*>[\s\S]*?<\/nav>/', function ($m) use ($ids) {
        if (!in_array('nav', preg_split('/\s+/', $m[2]), true)) return $m[0];
        return preg_replace_callback('/<a\b[^>]*\bhref=(["\'])#([^"\']+)\1[^>]*>[\s\S]*?<\/a>/', function ($l) use ($ids) {
            if (isset($ids[$l[2]])) return $l[0];
            return $l[2] === 'registration' ? preg_replace('/\bhref=(["\'])#registration\1/', 'href="/register"', $l[0]) : '';
        }, $m[0]);
    }, $output);
    if (!isset($ids['registration'])) $output = preg_replace('/\bhref=(["\'])#registration\1/', 'href="/register"', $output);
    if (!isset($ids['event'])) $output = preg_replace('/<a\b[^>]*\bhref=(["\'])#event\1[^>]*>[\s\S]*?<\/a>/', '', $output);
    return $output;
}

// ---------------------------------------------------------------- secrets & email

function settings_key(): string {
    $key = base64_decode(config()['settingsKey'] ?? '', true);
    if (!$key || strlen($key) !== 32) throw new RuntimeException('Не настроен ключ защиты почты.');
    return $key;
}
function encrypt_value(string $value): string {
    $iv = random_bytes(12);
    $cipher = openssl_encrypt($value, 'aes-256-gcm', settings_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv) . '.' . base64_encode($cipher . $tag);
}
function decrypt_value(string $value): string {
    [$iv, $data] = array_map(fn($p) => base64_decode($p), explode('.', $value, 2) + [1 => '']);
    $plain = openssl_decrypt(substr($data, 0, -16), 'aes-256-gcm', settings_key(), OPENSSL_RAW_DATA, $iv, substr($data, -16));
    if ($plain === false) throw new RuntimeException('decrypt');
    return $plain;
}
function email_settings(): array {
    $s = setting('email', null);
    return is_array($s) ? $s + ['recipient' => '', 'sender' => '', 'encryptedKey' => ''] : ['recipient' => '', 'sender' => '', 'encryptedKey' => ''];
}

function notify(array $registration): array {
    $settings = email_settings();
    $state = 'not_configured';
    $detail = 'Настройте почту в разделе «Уведомления».';
    if ($settings['recipient'] && $settings['sender'] && $settings['encryptedKey']) {
        try {
            $c = content();
            $text = 'Новая заявка на ' . $c['eventDate'] . "\n\nФИО: " . $registration['full_name'] . "\nТелефон: " . $registration['phone'] . "\nРегистрация: " . $registration['created_at']
                . "\nКлиент Диалог Авто: " . ($registration['existing_client'] === 1 ? 'Да' : ($registration['existing_client'] === 0 ? 'Нет' : 'Не указано'))
                . "\nВозраст: " . ($registration['age'] ?? 'Не указан')
                . "\nПол: " . (['male' => 'Мужской', 'female' => 'Женский', 'unspecified' => 'Не указан'][$registration['gender'] ?? ''] ?? 'Не указан')
                . "\nИсточник регистрации: " . (['site' => 'Сайт', 'qr' => 'QR мероприятия', 'call-center' => 'QR мероприятия'][$registration['registration_source'] ?? ''] ?? 'Сайт')
                . "\nРассылка: " . ($registration['marketing_consent'] === 1 ? 'Согласен' : 'Нет согласия')
                . "\n\nЗаявка сохранена в админке сайта.";
            $ch = curl_init('https://api.resend.com/emails');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . decrypt_value($settings['encryptedKey']), 'Content-Type: application/json', 'Idempotency-Key: registration-' . $registration['id']],
                CURLOPT_POSTFIELDS => jenc(['from' => $settings['sender'], 'to' => [$settings['recipient']], 'subject' => 'HAVAL CITY — новая регистрация', 'text' => $text]),
            ]);
            $res = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($res === false || $code === 0) throw new RuntimeException('network');
            if ($code >= 200 && $code < 300) { $state = 'sent'; $detail = 'Уведомление отправлено.'; }
            else { $state = 'failed'; $detail = 'Почтовый сервис вернул ошибку ' . $code . '. Проверьте ключ и подтверждённого отправителя.'; }
        } catch (Throwable) {
            $state = 'failed';
            $detail = 'Не удалось отправить письмо. Проверьте подключение почты и повторите отправку.';
        }
    }
    q('INSERT INTO email_deliveries (registration_id,state,detail,updated_at) VALUES (?,?,?,?) ON CONFLICT(registration_id) DO UPDATE SET state=excluded.state,detail=excluded.detail,updated_at=excluded.updated_at', [$registration['id'], $state, $detail, stamp()]);
    return ['state' => $state, 'detail' => $detail];
}

// ---------------------------------------------------------------- registrations, prizes, draws

function all_registrations(): array {
    return all('SELECT r.*,d.prize AS prize,e.state AS email_state,e.detail AS email_detail FROM registrations r LEFT JOIN raffle_draws d ON d.registration_id=r.id LEFT JOIN email_deliveries e ON e.registration_id=r.id ORDER BY r.created_at DESC,r.id DESC');
}
function all_prizes(): array {
    return all('SELECT p.*,COUNT(d.id) AS awarded,p.quantity-COUNT(d.id) AS remaining FROM prizes p LEFT JOIN raffle_draws d ON d.prize_id=p.id GROUP BY p.id ORDER BY p.created_at,p.id');
}
function all_draws(): array {
    return all('SELECT d.id,d.request_id,d.prize,d.prize_id,d.eligible_count,d.drawn_at,r.full_name,r.phone FROM raffle_draws d JOIN registrations r ON r.id=d.registration_id ORDER BY d.drawn_at DESC');
}

function validate_prize($data): array {
    if (!is_obj($data) || $data === []) throw new HttpError('Проверьте поля подарка.');
    $text = function (string $key, int $max, bool $required = false) use ($data) {
        if (!is_string($data[$key] ?? null)) throw new HttpError('Проверьте поле ' . $key . '.');
        $v = trim($data[$key]);
        if (slen($v) > $max || ($required && $v === '')) throw new HttpError($key === 'title' ? 'Укажите название подарка, до 200 символов.' : 'Слишком длинное поле ' . $key . '.');
        return $v;
    };
    $title = $text('title', 200, true);
    $partner = $text('partner', 200);
    $description = $text('description', 2000);
    $image = $text('image', 2500);
    $qty = $data['quantity'] ?? null;
    if (!is_int_like($qty) || $qty < 1 || $qty > 1000) throw new HttpError('Количество подарков должно быть целым числом от 1 до 1000.');
    if (!is_bool($data['active'] ?? null) || !is_bool($data['published'] ?? null)) throw new HttpError('Проверьте настройки доступности и публикации.');
    if ($image !== '' && !valid_image_url($image)) throw new HttpError('Укажите корректную HTTPS-ссылку или загрузите фото подарка.');
    return ['title' => $title, 'partner' => $partner, 'description' => $description, 'image' => $image, 'quantity' => (int)$qty, 'active' => $data['active'] ? 1 : 0, 'published' => $data['published'] ? 1 : 0];
}

function prize_crud(string $method, string $path): never {
    if (!preg_match('#^/api/admin/prizes(?:/([a-f0-9-]{36}))?$#', $path, $m)) json_out(['error' => 'Подарок не найден.'], 404);
    $id = $m[1] ?? null;
    if ($method === 'GET' && !$id) json_out(['prizes' => all_prizes()]);
    if ($method === 'POST' && !$id) {
        try { $d = validate_prize(read_json()); } catch (HttpError $e) { json_out(['error' => $e->getMessage() ?: 'Проверьте поля подарка.'], 400); }
        $createdAt = stamp();
        $newId = uuid4();
        q('INSERT INTO prizes (id,title,partner,description,quantity,image,active,published,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)', [$newId, $d['title'], $d['partner'], $d['description'], $d['quantity'], $d['image'], $d['active'], $d['published'], $createdAt, $createdAt]);
        json_out(['ok' => true, 'id' => $newId, 'prize' => ['id' => $newId] + $d + ['created_at' => $createdAt, 'updated_at' => $createdAt, 'awarded' => 0, 'remaining' => $d['quantity']]], 201);
    }
    if (!$id) json_out(['error' => 'Метод не поддерживается.'], 405);
    $existing = first('SELECT p.*,COUNT(d.id) AS awarded FROM prizes p LEFT JOIN raffle_draws d ON d.prize_id=p.id WHERE p.id=? GROUP BY p.id', [$id]);
    if (!$existing) json_out(['error' => 'Подарок не найден.'], 404);
    if ($method === 'PUT') {
        try { $d = validate_prize(read_json()); } catch (HttpError $e) { json_out(['error' => $e->getMessage() ?: 'Проверьте поля подарка.'], 400); }
        if ($d['quantity'] < $existing['awarded']) json_out(['error' => 'Нельзя указать количество меньше числа уже выданных подарков (' . $existing['awarded'] . ').'], 409);
        $n = changes('UPDATE prizes SET title=?,partner=?,description=?,quantity=?,image=?,active=?,published=?,updated_at=? WHERE id=? AND ? >= (SELECT COUNT(*) FROM raffle_draws WHERE prize_id=?)', [$d['title'], $d['partner'], $d['description'], $d['quantity'], $d['image'], $d['active'], $d['published'], stamp(), $id, $d['quantity'], $id]);
        if ($n === 0) json_out(['error' => 'Количество уже выданных подарков изменилось. Обновите данные и повторите сохранение.'], 409);
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($method === 'DELETE') {
        if ($existing['awarded'] > 0) json_out(['error' => 'Этот подарок уже разыгрывался. Отключите его для следующих розыгрышей вместо удаления.'], 409);
        $n = changes('DELETE FROM prizes WHERE id=? AND NOT EXISTS (SELECT 1 FROM raffle_draws WHERE prize_id=?)', [$id, $id]);
        if ($n === 0) json_out(['error' => 'Этот подарок уже разыгрывался. Отключите его вместо удаления.'], 409);
        json_out(['ok' => true]);
    }
    json_out(['error' => 'Метод не поддерживается.'], 405);
}

/** @return array{0: array, 1: int} */
function draw($data): array {
    $winnerSql = 'SELECT d.*,r.full_name,r.phone FROM raffle_draws d JOIN registrations r ON r.id=d.registration_id WHERE ';
    $reused = 'Этот запрос уже использован для другого подарка. Обновите данные.';
    if (!is_obj($data) || $data === []) return [['error' => 'Проверьте параметры розыгрыша.'], 400];
    if (!is_string($data['requestId'] ?? null) || !preg_match('/^[a-zA-Z0-9-]{20,80}$/', $data['requestId'])) return [['error' => 'Некорректный идентификатор розыгрыша.'], 400];
    if (!is_string($data['prizeId'] ?? null) || !preg_match('/^[a-f0-9-]{36}$/', $data['prizeId'])) return [['error' => 'Выберите подарок из каталога.'], 400];
    $previous = first($winnerSql . 'request_id=?', [$data['requestId']]);
    if ($previous) return $previous['prize_id'] === $data['prizeId'] ? [['winner' => $previous], 200] : [['error' => $reused], 409];
    $prize = first('SELECT p.*,COUNT(d.id) AS awarded FROM prizes p LEFT JOIN raffle_draws d ON d.prize_id=p.id WHERE p.id=? GROUP BY p.id', [$data['prizeId']]);
    if (!$prize) return [['error' => 'Подарок не найден. Обновите список.'], 404];
    if (!$prize['active']) return [['error' => 'Этот подарок отключён для розыгрыша.'], 409];
    if ($prize['awarded'] >= $prize['quantity']) return [['error' => 'Все экземпляры этого подарка уже разыграны.'], 409];
    $pdo = db();
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $eligible = all('SELECT id,full_name,phone FROM registrations WHERE id NOT IN (SELECT registration_id FROM raffle_draws) ORDER BY id');
            if (!$eligible) { $pdo->exec('ROLLBACK'); return [['error' => 'Нет участников без выигрыша. Сначала дождитесь регистраций.'], 409]; }
            $chosen = $eligible[random_int(0, count($eligible) - 1)];
            $id = uuid4();
            $n = changes('INSERT INTO raffle_draws (id,request_id,registration_id,prize,eligible_count,eligible_ids,drawn_at,prize_id) SELECT ?,?,?,p.title,?,?,?,p.id FROM prizes p WHERE p.id=? AND p.active=1 AND (SELECT COUNT(*) FROM raffle_draws WHERE prize_id=p.id)<p.quantity',
                [$id, $data['requestId'], $chosen['id'], count($eligible), jenc(array_column($eligible, 'id')), stamp(), $data['prizeId']]);
            $pdo->exec('COMMIT');
            if ($n === 0) {
                $repeated = first($winnerSql . 'request_id=?', [$data['requestId']]);
                if ($repeated) return $repeated['prize_id'] === $data['prizeId'] ? [['winner' => $repeated], 200] : [['error' => $reused], 409];
                return [['error' => 'Подарок больше недоступен: он отключён или все экземпляры уже разыграны. Обновите список.'], 409];
            }
            $winner = first($winnerSql . 'd.id=?', [$id]);
            if ($winner) return [['winner' => $winner], 200];
        } catch (Throwable) {
            if ($pdo->inTransaction()) { try { $pdo->exec('ROLLBACK'); } catch (Throwable) {} }
            $existing = first($winnerSql . 'request_id=?', [$data['requestId']]);
            if ($existing) return $existing['prize_id'] === $data['prizeId'] ? [['winner' => $existing], 200] : [['error' => $reused], 409];
        }
    }
    return [['error' => 'Список участников изменился. Повторите розыгрыш.'], 409];
}

// ---------------------------------------------------------------- presenter link

function presenter_token_hash(string $token): string { return hash('sha256', $token); }
function presenter_grant_active($grant, array $c): bool {
    if (!is_array($grant) || empty($grant['tokenHash']) || empty($grant['encryptedToken']) || !empty($grant['revokedAt'])) return false;
    if (($grant['eventDate'] ?? null) !== $c['eventDate']) return false;
    $t = strtotime((string)($grant['expiresAt'] ?? ''));
    return $t !== false && $t > time();
}
function presenter_link_info(): array {
    $grant = setting(PRESENTER_SETTING, null);
    $c = content();
    $active = presenter_grant_active($grant, $c);
    $result = ['active' => $active, 'createdAt' => $grant['createdAt'] ?? null, 'expiresAt' => $grant['expiresAt'] ?? null, 'eventDate' => $grant['eventDate'] ?? $c['eventDate'], 'eventTitle' => $c['eventTitle']];
    if ($active) $result['url'] = request_origin() . '/raffle#access=' . decrypt_value($grant['encryptedToken']);
    return $result;
}
function presenter_link_admin(string $method): never {
    if ($method === 'GET') presenter_json(presenter_link_info());
    if ($method === 'POST') {
        try { $data = read_json(1024); } catch (HttpError) { presenter_json(['error' => 'Проверьте срок действия ссылки.'], 400); }
        $hours = is_array($data) ? ($data['hours'] ?? 72) : null;
        if (!is_obj($data) || !is_int_like($hours) || $hours < 1 || $hours > 720) presenter_json(['error' => 'Срок действия ссылки — от 1 до 720 часов.'], 400);
        $token = b64u(random_bytes(32));
        $c = content();
        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . (int)$hours . ' hours')->format('Y-m-d\TH:i:s.v\Z');
        set_setting(PRESENTER_SETTING, ['tokenHash' => presenter_token_hash($token), 'encryptedToken' => encrypt_value($token), 'createdAt' => stamp(), 'expiresAt' => $expires, 'eventDate' => $c['eventDate']]);
        presenter_json(presenter_link_info(), 201);
    }
    if ($method === 'DELETE') {
        $old = setting(PRESENTER_SETTING, null);
        set_setting(PRESENTER_SETTING, ['createdAt' => $old['createdAt'] ?? null, 'expiresAt' => $old['expiresAt'] ?? null, 'eventDate' => $old['eventDate'] ?? null, 'revokedAt' => stamp()]);
        presenter_json(presenter_link_info());
    }
    presenter_json(['error' => 'Метод не поддерживается.'], 405);
}
function authorize_presenter(): void {
    $auth = request_header('Authorization');
    if ($auth === '') presenter_json(['error' => 'Откройте специальную ссылку, которую вам передал организатор.'], 401);
    if (!preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/', $auth, $m)) presenter_json(['error' => 'Ссылка недействительна. Попросите организатора прислать новую.'], 403);
    $grant = setting(PRESENTER_SETTING, null);
    if (!presenter_grant_active($grant, content()) || !hash_equals((string)$grant['tokenHash'], presenter_token_hash($m[1]))) {
        presenter_json(['error' => 'Ссылка отключена или срок её действия истёк. Попросите организатора прислать новую.'], 403);
    }
}
function presenter_winner(array $row): array {
    $digits = preg_replace('/\D/', '', (string)($row['phone'] ?? ''));
    return ['id' => $row['id'], 'request_id' => $row['request_id'], 'prize' => $row['prize'], 'prize_id' => $row['prize_id'], 'eligible_count' => $row['eligible_count'], 'drawn_at' => $row['drawn_at'], 'full_name' => $row['full_name'],
        'phoneMasked' => strlen($digits) >= 4 ? '+7 ··· ··· ' . substr($digits, -4, 2) . ' ' . substr($digits, -2) : 'Телефон скрыт'];
}
function presenter_api(string $method, string $path): never {
    authorize_presenter();
    if ($path === '/api/presenter/state' && $method === 'GET') {
        $c = content();
        $counts = first('SELECT COUNT(*) AS total,COUNT(CASE WHEN NOT EXISTS (SELECT 1 FROM raffle_draws d WHERE d.registration_id=r.id) THEN 1 END) AS eligible FROM registrations r');
        $prizes = [];
        foreach (all_prizes() as $p) {
            if (!$p['active']) continue;
            $prizes[] = ['id' => $p['id'], 'title' => $p['title'], 'partner' => $p['partner'], 'description' => $p['description'], 'image' => $p['image'], 'quantity' => $p['quantity'], 'active' => 1, 'awarded' => (int)$p['awarded'], 'remaining' => (int)$p['remaining']];
        }
        presenter_json(['counts' => ['total' => (int)($counts['total'] ?? 0), 'eligible' => (int)($counts['eligible'] ?? 0)], 'event' => ['title' => $c['eventTitle'], 'date' => $c['eventDate']], 'prizes' => $prizes, 'draws' => array_map('presenter_winner', all_draws())]);
    }
    if ($path === '/api/presenter/draw' && $method === 'POST') {
        try { $data = read_json(1024); } catch (HttpError) { presenter_json(['error' => 'Некорректные параметры розыгрыша.'], 400); }
        [$body, $status] = draw($data);
        if ($status < 200 || $status >= 300) json_out($body, $status);
        presenter_json(['winner' => presenter_winner($body['winner'])], $status);
    }
    presenter_json(['error' => 'Не найдено или метод не поддерживается.'], 404);
}

// ---------------------------------------------------------------- xlsx export

function xlsx_xml($v): string {
    $s = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', (string)($v ?? ''));
    return strtr($s, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&apos;']);
}
function xlsx_col(int $index): string {
    $value = $index + 1; $name = '';
    while ($value > 0) { $name = chr(65 + ($value - 1) % 26) . $name; $value = intdiv($value - 1, 26); }
    return $name;
}
function excel(array $rows): string {
    $maxColumns = max(1, ...array_map('count', $rows));
    $sheet = '';
    foreach ($rows as $i => $r) {
        $sheet .= '<row r="' . ($i + 1) . '">';
        foreach (array_values($r) as $j => $v) {
            $ref = xlsx_col($j) . ($i + 1);
            $sheet .= (is_int($v) || (is_float($v) && is_finite($v))) ? '<c r="' . $ref . '" t="n"><v>' . $v . '</v></c>' : '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_xml($v) . '</t></is></c>';
        }
        $sheet .= '</row>';
    }
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Регистрации" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="40" customWidth="1"/><col min="2" max="2" width="36" customWidth="1"/><col min="3" max="' . $maxColumns . '" width="24" customWidth="1"/></cols><sheetData>' . $sheet . '</sheetData><autoFilter ref="A1:' . xlsx_col($maxColumns - 1) . count($rows) . '"/></worksheet>',
    ];
    // Stored (uncompressed) ZIP, same layout as worker/xlsx.js.
    $out = ''; $central = '';
    foreach ($files as $name => $data) {
        $crc = crc32($data); $len = strlen($data); $nl = strlen($name); $offset = strlen($out);
        $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $len, $len, $nl, 0) . $name . $data;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $len, $len, $nl, 0, 0, 0, 0, 0, $offset) . $name;
    }
    return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($out), 0);
}
function moscow_time(?string $iso): string {
    if (!$iso) return '';
    try { return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y, H:i:s'); }
    catch (Throwable) { return ''; }
}

// ---------------------------------------------------------------- admin session

function session_boot(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $dir = APP_DIR . '/data/sessions';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    session_save_path($dir);
    session_name('dah_admin');
    ini_set('session.gc_maxlifetime', '43200');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => request_scheme() === 'https', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
function is_admin(): bool {
    if (empty($_COOKIE['dah_admin'])) return false;
    session_boot();
    $ok = !empty($_SESSION['admin']) && ($_SESSION['at'] ?? 0) > time() - 43200;
    session_write_close();
    return $ok;
}
function check_password(string $password): bool {
    $c = config();
    $hash = hash_pbkdf2('sha256', $password, $c['passwordSalt'], (int)$c['passwordIterations'], 32, true);
    return hash_equals(hex2bin($c['passwordHash']), $hash);
}
function throttle_file(): string { return APP_DIR . '/data/login-throttle.json'; }
function login_blocked(): bool {
    $s = @json_decode((string)@file_get_contents(throttle_file()), true) ?: [];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $row = $s[$ip] ?? null;
    return $row && $row['count'] >= 10 && $row['since'] > time() - 900;
}
function login_failed(): void {
    $s = @json_decode((string)@file_get_contents(throttle_file()), true) ?: [];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    foreach ($s as $k => $row) if ($row['since'] < time() - 900) unset($s[$k]);
    $row = $s[$ip] ?? ['count' => 0, 'since' => time()];
    $row['count']++;
    $s[$ip] = $row;
    @file_put_contents(throttle_file(), json_encode($s), LOCK_EX);
}
function safe_return(string $to): string { return preg_match('#^/(admin|raffle)(\?[^\s]*)?$#', $to) ? $to : '/admin'; }
function login_page(string $error = '', string $returnTo = '/admin', int $status = 200): never {
    $html = strtr(template('login'), ['{{error}}' => $error ? '<p class="error" role="alert">' . esc($error) . '</p>' : '', '{{returnTo}}' => esc(safe_return($returnTo))]);
    html_out($html, ['X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'], $status);
}

// ---------------------------------------------------------------- router

function registration(): never {
    try { $d = read_json(4096); } catch (HttpError) { json_out(['error' => 'Некорректные данные.'], 400); }
    if (!is_obj($d) || $d === [] || !empty($d['website'])) json_out(['error' => 'Не удалось принять заявку.'], 400);
    $fullName = is_string($d['fullName'] ?? null) ? preg_replace('/\s+/u', ' ', trim($d['fullName'])) : '';
    if (slen($fullName) < 5 || slen($fullName) > 150 || count(explode(' ', $fullName)) < 2 || !preg_match("/^[\\p{L}\\p{M}\\s.'’\\-]+$/u", $fullName)) json_out(['error' => 'Укажите фамилию, имя и отчество, если оно есть.'], 400);
    $digits = is_string($d['phone'] ?? null) ? preg_replace('/\D/', '', $d['phone']) : '';
    if (str_starts_with($digits, '8')) $digits = '7' . substr($digits, 1);
    if (!preg_match('/^7\d{10}$/', $digits)) json_out(['error' => 'Введите корректный номер телефона: +7 и 10 цифр.'], 400);
    if (($d['consent'] ?? null) !== true || ($d['ageConfirmed'] ?? null) !== true) json_out(['error' => 'Подтвердите совершеннолетие и согласие на обработку данных.'], 400);
    if (array_key_exists('registrationSource', $d) && !in_array($d['registrationSource'], ['site', 'qr', 'call-center'], true)) json_out(['error' => 'Некорректный источник регистрации.'], 400);
    if (array_key_exists('age', $d) && (!is_int_like($d['age']) || $d['age'] < 18 || $d['age'] > 120)) json_out(['error' => 'Укажите возраст от 18 до 120 лет.'], 400);
    if (array_key_exists('existingClient', $d) && !is_bool($d['existingClient'])) json_out(['error' => 'Укажите, являетесь ли вы клиентом «Диалог Авто».'], 400);
    if (array_key_exists('gender', $d) && !in_array($d['gender'], ['male', 'female', 'unspecified'], true)) json_out(['error' => 'Выберите пол из списка.'], 400);
    if (array_key_exists('marketingConsent', $d) && !is_bool($d['marketingConsent'])) json_out(['error' => 'Проверьте согласие на рассылку.'], 400);
    $createdAt = stamp();
    $mc = ($d['marketingConsent'] ?? null) === true ? 1 : 0;
    $row = [
        'id' => uuid4(), 'full_name' => $fullName, 'phone' => '+' . $digits, 'created_at' => $createdAt,
        'marketing_consent' => $mc, 'marketing_consent_at' => $mc ? $createdAt : null, 'marketing_consent_version' => $mc ? 'dialog-auto-marketing-v1' : null,
        'existing_client' => array_key_exists('existingClient', $d) ? ($d['existingClient'] ? 1 : 0) : null,
        'age' => array_key_exists('age', $d) && $d['age'] !== null ? (int)$d['age'] : null,
        'gender' => $d['gender'] ?? 'unspecified',
        'registration_source' => !array_key_exists('registrationSource', $d) || $d['registrationSource'] === 'site' ? 'site' : 'qr',
    ];
    $n = changes('INSERT INTO registrations (id,full_name,phone,consent_version,created_at,marketing_consent,marketing_consent_at,marketing_consent_version,existing_client,age,gender,registration_source) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(phone) DO NOTHING',
        [$row['id'], $row['full_name'], $row['phone'], 'event-registration-v3-profile-qr', $row['created_at'], $row['marketing_consent'], $row['marketing_consent_at'], $row['marketing_consent_version'], $row['existing_client'], $row['age'], $row['gender'], $row['registration_source']]);
    if ($n === 0) json_out(['error' => 'Этот телефон уже зарегистрирован на текущее мероприятие. Повторная регистрация не создаётся.'], 409);
    // Answer the visitor first, then send the e-mail notification.
    $body = jenc(['ok' => true]);
    ignore_user_abort(true);
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if (function_exists('fastcgi_finish_request')) { echo $body; fastcgi_finish_request(); try { notify($row); } catch (Throwable) {} exit; }
    try { notify($row); } catch (Throwable) {}
    echo $body;
    exit;
}

function admin_api(string $method, string $path): never {
    if ($path === '/api/admin/presenter-link') presenter_link_admin($method);
    if ($path === '/api/admin/state' && $method === 'GET') {
        $c = content();
        $mail = email_settings();
        json_out(['content' => $c, 'blockLayout' => get_block_layout($c), 'blockDefinitions' => BLOCK_DEFINITIONS, 'registrations' => all_registrations(), 'prizes' => all_prizes(), 'sponsors' => setting('sponsors', []), 'draws' => all_draws(),
            'email' => ['recipient' => $mail['recipient'], 'sender' => $mail['sender'], 'keyConfigured' => (bool)$mail['encryptedKey']], 'adminEmail' => 'Организатор']);
    }
    if ($path === '/api/admin/blocks' && $method === 'PUT') {
        try { $layout = validate_block_layout(read_json()); } catch (HttpError $e) { json_out(['error' => $e->getMessage() ?: 'Проверьте настройки блоков.'], 400); }
        set_setting('blockLayout', $layout);
        json_out(['ok' => true, 'blockLayout' => $layout]);
    }
    if ($path === '/api/admin/sponsors' && $method === 'PUT') {
        try {
            $values = read_json();
            if (!is_list_arr($values) || count($values) > 5) throw new HttpError('Можно разместить до 5 спонсоров.');
            $seen = [];
            $values = array_map(function ($v) use (&$seen) {
                if (!is_obj($v) || !is_string($v['name'] ?? null) || trim($v['name']) === '' || slen($v['name']) > 120 || !valid_image_url($v['image'] ?? null)) throw new HttpError('Укажите название и загрузите логотип каждого спонсора.');
                $id = is_string($v['id'] ?? null) && preg_match('/^[a-f0-9-]{36}$/', $v['id']) ? $v['id'] : uuid4();
                if (isset($seen[$id])) throw new HttpError('Повторный спонсор.');
                $seen[$id] = true;
                $url = is_string($v['url'] ?? null) ? trim($v['url']) : '';
                if ($url !== '') {
                    $p = parse_url($url);
                    if (!$p || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) throw new HttpError('Ссылка спонсора должна начинаться с https://');
                }
                return ['id' => $id, 'name' => trim($v['name']), 'image' => $v['image'], 'url' => $url];
            }, $values);
        } catch (HttpError $e) { json_out(['error' => $e->getMessage() ?: 'Проверьте спонсоров.'], 400); }
        set_setting('sponsors', $values);
        json_out(['ok' => true, 'sponsors' => $values]);
    }
    if ($path === '/api/admin/prizes' || str_starts_with($path, '/api/admin/prizes/')) prize_crud($method, $path);
    if ($path === '/api/admin/content' && $method === 'PUT') {
        try { $c = validate_content(read_json()); } catch (HttpError $e) { json_out(['error' => $e->getMessage() ?: 'Проверьте данные.'], 400); }
        set_setting('content', $c + ['_designVersion' => 'light-v1']);
        json_out(['ok' => true]);
    }
    if ($path === '/api/admin/email' && $method === 'PUT') {
        try { $d = read_json(); } catch (HttpError $e) { json_out(['error' => $e->getMessage()], 400); }
        if (!is_array($d)) $d = [];
        $valid = fn($s) => is_string($s) && strlen($s) <= 254 && preg_match('/^[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+$/', $s);
        if ((!empty($d['recipient']) && !$valid($d['recipient'])) || (!empty($d['sender']) && !$valid($d['sender']))) json_out(['error' => 'Укажите корректные адреса получателя и отправителя.'], 400);
        $old = email_settings();
        $encryptedKey = $old['encryptedKey'];
        if (($d['clearKey'] ?? null) === true) $encryptedKey = '';
        if (!empty($d['apiKey'])) {
            if (!is_string($d['apiKey']) || !preg_match('/^re_[a-zA-Z0-9_-]{10,200}$/', $d['apiKey'])) json_out(['error' => 'Проверьте API-ключ Resend.'], 400);
            $encryptedKey = encrypt_value($d['apiKey']);
        }
        set_setting('email', ['recipient' => trim((string)($d['recipient'] ?? '')), 'sender' => trim((string)($d['sender'] ?? '')), 'encryptedKey' => $encryptedKey]);
        json_out(['ok' => true, 'keyConfigured' => (bool)$encryptedKey]);
    }
    if ($path === '/api/admin/email/retry' && $method === 'POST') {
        try { $d = read_json(); } catch (HttpError $e) { json_out(['error' => $e->getMessage()], 400); }
        $row = first('SELECT * FROM registrations WHERE id=?', [(string)(is_array($d) ? ($d['id'] ?? '') : '')]);
        if (!$row) json_out(['error' => 'Заявка не найдена.'], 404);
        json_out(notify($row));
    }
    if ($path === '/api/admin/upload' && $method === 'POST') {
        if ((int)request_header('Content-Length') > 5 * 1024 * 1024 + 10000) json_out(['error' => 'Максимальный размер — 5 МБ.'], 413);
        $file = $_FILES['file'] ?? null;
        if (!$file || !is_string($file['tmp_name'] ?? null) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) json_out(['error' => 'Выберите изображение размером до 5 МБ.'], 400);
        $head = (string)file_get_contents($file['tmp_name'], false, null, 0, 12);
        if (str_starts_with($head, "\xFF\xD8\xFF")) $ext = 'jpg';
        elseif (str_starts_with($head, "\x89PNG\r\n\x1a\n")) $ext = 'png';
        elseif (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') $ext = 'webp';
        else json_out(['error' => 'Поддерживаются JPG, PNG и WebP.'], 400);
        $dir = dirname(APP_DIR) . '/media';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $key = uuid4() . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $key)) json_out(['error' => 'Хранилище изображений недоступно.'], 503);
        @chmod($dir . '/' . $key, 0644);
        json_out(['url' => '/media/' . $key]);
    }
    if ($path === '/api/admin/draw' && $method === 'POST') {
        try { $data = read_json(); } catch (HttpError) { json_out(['error' => 'Некорректные параметры розыгрыша.'], 400); }
        [$body, $status] = draw($data);
        json_out($body, $status);
    }
    if ($path === '/api/admin/export' && $method === 'GET') {
        $table = [['ID заявки', 'ФИО', 'Телефон', 'Дата регистрации (МСК)', 'Согласие', 'Подарок', 'Уведомление', 'Статус приглашения', 'Рассылка', 'Дата согласия на рассылку (МСК)', 'Версия согласия', 'Клиент Диалог Авто', 'Возраст', 'Пол', 'Источник регистрации']];
        foreach (all_registrations() as $r) {
            $table[] = [$r['id'], $r['full_name'], $r['phone'], moscow_time($r['created_at']), $r['consent_version'], $r['prize'] ?: '', $r['email_state'] ?: 'Не отправлено', 'Заявка, требует подтверждения',
                $r['marketing_consent'] === 1 ? 'Да' : 'Нет', moscow_time($r['marketing_consent_at']), $r['marketing_consent_version'] ?: '',
                $r['existing_client'] === 1 ? 'Да' : ($r['existing_client'] === 0 ? 'Нет' : 'Не указано'), $r['age'] ?? '',
                ['male' => 'Мужской', 'female' => 'Женский', 'unspecified' => 'Не указан'][$r['gender'] ?? ''] ?? 'Не указан',
                ['site' => 'Сайт', 'qr' => 'QR мероприятия', 'call-center' => 'QR мероприятия'][$r['registration_source'] ?? ''] ?? 'Сайт'];
        }
        send(200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="haval-registrations.xlsx"', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'], excel($table));
    }
    json_out(['error' => 'Не найдено или метод не поддерживается.'], 404);
}

function handle(): never {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if ($path === '/index.php') $path = '/';
    $get = $method === 'GET' || $method === 'HEAD';
    if (!$get) {
        $origin = request_header('Origin');
        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        if (!$origin || ($host . ($port ? ':' . $port : '')) !== request_host()) json_out(['error' => 'Недопустимый источник запроса.'], 403);
    }

    if (str_starts_with($path, '/assets/admin-guide-') && $get) {
        if (!is_admin()) json_out(['error' => 'Инструкция доступна только организатору.'], 403);
        $name = basename($path);
        $file = APP_DIR . '/private-assets/' . $name;
        if (!preg_match('/^admin-guide-[a-z0-9-]+\.md$/', $name) || !is_file($file)) text_out('Не найдено', 404);
        send(200, ['Content-Type' => 'text/markdown; charset=utf-8', 'Cache-Control' => 'private,no-store', 'X-Content-Type-Options' => 'nosniff'], file_get_contents($file));
    }
    if (str_starts_with($path, '/assets/') || str_starts_with($path, '/media/')) text_out('Не найдено', 404);

    if ($path === '/raffle' && $get) html_out(template('raffle'), ['X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);

    if ($path === '/admin' && $get) {
        if (!is_admin()) login_page('', '/admin');
        html_out(template('admin'), ['X-Frame-Options' => 'DENY', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
    if ($path === '/admin/login' && $method === 'POST') {
        $returnTo = safe_return((string)($_POST['return_to'] ?? '/admin'));
        if (login_blocked()) login_page('Слишком много попыток входа. Подождите 15 минут.', $returnTo, 429);
        if (!check_password((string)($_POST['password'] ?? ''))) { login_failed(); usleep(500000); login_page('Неверный пароль.', $returnTo, 401); }
        session_boot();
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['at'] = time();
        session_write_close();
        send(303, ['Location' => $returnTo, 'Cache-Control' => 'no-store']);
    }
    if ($path === '/logout') {
        if (!empty($_COOKIE['dah_admin'])) { session_boot(); $_SESSION = []; session_destroy(); }
        setcookie('dah_admin', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => request_scheme() === 'https']);
        send(303, ['Location' => '/', 'Cache-Control' => 'no-store']);
    }

    if (str_starts_with($path, '/api/presenter/')) presenter_api($method, $path);
    if (str_starts_with($path, '/api/admin/')) {
        if (!is_admin()) json_out(['error' => 'Войдите в панель организатора.'], 403);
        admin_api($method, $path);
    }
    if ($path === '/api/register') {
        if ($method !== 'POST') json_out(['error' => 'Метод не поддерживается.'], 405);
        registration();
    }
    if ($path === '/register' && $get) {
        $c = content();
        html_out(render(array_merge($c, ['formTitle' => $c['qrTitle'], 'submitText' => $c['qrSubmitText'], 'successTitle' => $c['qrSuccessTitle'], 'successText' => $c['qrSuccessText']]), false, [], [], template('register'), 'qr'));
    }
    if ($path === '/' && $get) {
        $prizes = all('SELECT id,title,partner,description,image FROM prizes WHERE active=1 AND published=1 ORDER BY created_at,id');
        $c = content();
        $layout = get_block_layout($c);
        $sponsors = setting('sponsors', []);
        html_out(render($c, is_admin(), $prizes, is_array($sponsors) ? $sponsors : [], template('page'), 'site', $layout), ['Referrer-Policy' => 'strict-origin-when-cross-origin']);
    }
    text_out('Страница не найдена', 404);
}

function app_main(): void {
    try { handle(); }
    catch (Throwable $e) {
        error_log('[dialog-auto] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) json_out(['error' => 'Не удалось выполнить действие. Попробуйте ещё раз.'], 503);
    }
}
