<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  V-DEY AI CONTENT GENERATOR — v10.0 "ONE-FILE STUDIO"
 *  -----------------------------------------------------------------------------
 *  Satu file, tanpa library eksternal, siap upload ke shared hosting.
 *
 *  ALUR:
 *    1. Isi keyword -> pilih bahasa, tipe, tone, panjang, status.
 *    2. AI membuat BLUEPRINT (judul, SEO title, meta description, slug,
 *       kategori, tags, outline, FAQ, key takeaways).
 *    3. AI menulis ARTIKEL HTML lengkap berdasarkan blueprint.
 *    4. Validasi + sanitasi + cek duplikat + cek internal link.
 *    5. Simpan ke tabel posts CMS (draft/published) dengan kategori/tag.
 *
 *  FITUR:
 *    - Omabang AI Gateway (/v1/chat/completions), model auto-detect.
 *    - Bisa pakai cURL ATAU stream wrapper (fallback kalau curl tidak ada).
 *    - JSON mode + text-mode fallback + retry + repair JSON.
 *    - AI tetap dipakai saat mode otomatis; tidak ada template lokal.
 *    - Preview sebelum simpan? Opsional: generate langsung simpan.
 *    - Diagnostic ?diag=ai (tanpa membocorkan API key).
 *
 *  KEAMANAN:
 *    - Wajib login admin CMS (functions.php -> require_admin()).
 *    - CSRF token pada semua POST.
 *    - API key dibaca dari environment / file di luar web root / config.
 *    - HTML AI disanitasi (allowlist tag + validasi href).
 *    - Tidak ada eksekusi kode dari AI.
 * ========================================================================== */

/* ---------------------------------------------------------------------------
 *  BOOTSTRAP: bisa standalone atau diletakkan di root CMS.
 * ------------------------------------------------------------------------- */

// Cari root CMS: file ini bisa ada di root CMS, di subfolder, atau berdiri sendiri.
$projectDir = __DIR__;
$functionsFile = '';
foreach ([__DIR__, dirname(__DIR__), dirname(__DIR__, 2)] as $candidate) {
    if (is_file($candidate . '/functions.php')) {
        $projectDir = $candidate;
        $functionsFile = $candidate . '/functions.php';
        break;
    }
}

if (is_file($functionsFile)) {
    // Pakai session, DB, CSRF, dan auth CMS.
    require_once $functionsFile;
    require_admin();
} else {
    // Mode standalone: file diletakkan di luar root CMS. Tidak ada login CMS,
    // jadi akses dilindungi token sendiri agar kuota API tidak dipakai orang lain.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode', '1');
        session_set_cookie_params([
            'httponly' => true,
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    $standaloneKey = (string) (getenv('GENERATOR_ACCESS_KEY') ?: '');
    $providedKey = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
    $accessGranted = !empty($_SESSION['generator_access']);

    if (!$accessGranted && $standaloneKey !== '' && $providedKey !== '' && hash_equals($standaloneKey, $providedKey)) {
        $_SESSION['generator_access'] = true;
        $accessGranted = true;
    }

    if ($standaloneKey === '' || !$accessGranted) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "AI Content Generator butuh akses terautentikasi.\n\n"
            . "Cara pakai yang disarankan: letakkan file ini di root CMS (sefolder dengan functions.php)\n"
            . "agar login admin CMS dipakai otomatis.\n\n"
            . "Mode standalone (tanpa CMS): set environment GENERATOR_ACCESS_KEY lalu buka\n"
            . "ai-content-generator.php?key=NILAI_KEY satu kali (akses disimpan di sesi).\n";
        exit;
    }
}

set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('default_socket_timeout', '240');
@ini_set('memory_limit', '256M');

/* ---------------------------------------------------------------------------
 *  KONFIGURASI
 * ------------------------------------------------------------------------- */

$storageDir = $projectDir . '/storage/generator/';
$historyFile = $storageDir . 'history.json';

$ai = [
    'base_url' => rtrim((string) (getenv('OMABANG_BASE_URL') ?: 'https://ai.omabang.web.id/v1'), '/'),
    // Untuk produksi, utamakan OMABANG_API_KEY dari environment.
    // Fallback inline ini membuat file langsung jalan di shared hosting.
    'api_key'  => (string) (getenv('OMABANG_API_KEY') ?: 'oma_X8cCkDBjDYMJugxFT5HFEbIYhGAoNX_3C50q7yVmnOw'),
    'model'    => (string) (getenv('OMABANG_MODEL') ?: 'deepseek-v4.1-flash'),
    'timeout'  => 240,
    'max_tokens' => 10000,
    'temperature' => 0.72,
];

// Prioritas API key:
// 1) environment OMABANG_API_KEY
// 2) file config di luar web root: ../vdey-generator-config.php
// 3) file config lokal yang diblokir .htaccess: generator-config.php
// 4) fallback inline di atas (agar langsung jalan tanpa konfigurasi)
$configCandidates = [
    dirname($projectDir) . '/vdey-generator-config.php',
    $projectDir . '/generator-config.php',
];
foreach ($configCandidates as $cfgFile) {
    if (!is_file($cfgFile)) continue;
    $loaded = (static fn(string $path) => require $path)($cfgFile);
    if (!is_array($loaded)) continue;
    // File config menimpa fallback inline, jadi user bisa memakai key sendiri.
    if (is_string($loaded['api_key'] ?? null) && trim($loaded['api_key']) !== '') {
        $ai['api_key'] = trim($loaded['api_key']);
    }
    if ($ai['model'] === '' && is_string($loaded['model'] ?? null)) {
        $ai['model'] = $loaded['model'];
    }
    if (is_string($loaded['base_url'] ?? null)) {
        $ai['base_url'] = rtrim($loaded['base_url'], '/');
    }
    if (isset($loaded['timeout'])) $ai['timeout'] = max(30, min(600, (int) $loaded['timeout']));
    break;
}

$site = [
    'name' => function_exists('setting') ? setting('site_name', 'V-Dey') : 'V-Dey',
    'description' => function_exists('setting') ? setting('site_description', '') : '',
    'url' => function_exists('url') ? rtrim(url(), '/') : rtrim((string) (getenv('SITE_URL') ?: 'https://v-dey.com'), '/'),
    'context' => 'Blog penemuan video viral, trending, hiburan lokal Indonesia dan Malaysia.',
    'default_category' => 'Videy Viral',
];

$languages = ['id' => 'Indonesia', 'ms' => 'Melayu', 'en' => 'English'];
$contentTypes = [
    'guide' => 'Panduan / Explainer',
    'article' => 'Artikel SEO',
    'listicle' => 'Listicle',
    'comparison' => 'Perbandingan',
    'news' => 'Analisis Topik',
];
$tones = [
    'santai' => 'Santai & ramah',
    'informatif' => 'Informatif & profesional',
    'persuasif' => 'Persuasif & menarik',
];
$lengths = [
    'short' => ['label' => 'Ringkas (~700 kata)', 'words' => 700, 'sections' => 5, 'max_tokens' => 5500],
    'medium' => ['label' => 'Sedang (~1.100 kata)', 'words' => 1100, 'sections' => 6, 'max_tokens' => 7000],
    'long' => ['label' => 'Panjang (~1.600 kata)', 'words' => 1600, 'sections' => 8, 'max_tokens' => 9000],
];

$csrf = function_exists('csrf_token') ? csrf_token() : '';
if ($csrf === '') {
    if (empty($_SESSION['generator_csrf'])) {
        $_SESSION['generator_csrf'] = bin2hex(random_bytes(32));
    }
    $csrf = $_SESSION['generator_csrf'];
}

/* ---------------------------------------------------------------------------
 *  HELPERS UMUM
 * ------------------------------------------------------------------------- */

function h(?string $v): string
{
    return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function engine_text_len(string $v): int
{
    return function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v);
}

function engine_substr(string $v, int $start, int $len): string
{
    return function_exists('mb_substr') ? mb_substr($v, $start, $len, 'UTF-8') : substr($v, $start, $len);
}

function engine_lower(string $v): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
}

function engine_words(string $html): int
{
    // Buang isi <script>/<style> agar JSON-LD tidak ikut dihitung sebagai kata.
    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
    $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));
    $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return count($words);
}

function generator_slug(string $text): string
{
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($ascii) && $ascii !== '') $text = $ascii;
    }
    $text = engine_lower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-') ?: 'ai-content';
}

function generator_slug_unique(string $base, ?PDO $pdo, string $table = 'posts'): string
{
    // Nama tabel berasal dari kode (bukan input user), jadi aman diinterpolasi.
    $maxLen = 255;
    if ($table === 'categories' || $table === 'tags') $maxLen = 160;
    if ($table !== 'posts' && $table !== 'categories' && $table !== 'tags') $table = 'posts';
    $base = engine_substr(generator_slug($base), 0, $maxLen);
    if ($pdo === null || !function_exists('db')) return $base;
    $slug = $base;
    $i = 2;
    while (true) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE slug = ?');
        $stmt->execute([$slug]);
        if ((int) $stmt->fetchColumn() === 0) return $slug;
        $suffix = '-' . $i++;
        $slug = engine_substr($base, 0, $maxLen - strlen($suffix)) . $suffix;
    }
}

function generator_json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function generator_log(string $message): void
{
    error_log('[vdey-ai] ' . $message);
}

/* ---------------------------------------------------------------------------
 *  OMABANG CLIENT — cURL + stream fallback
 * ------------------------------------------------------------------------- */

final class GeneratorOmabangClient
{
    private array $cfg;
    private ?string $model;

    public function __construct(array $cfg, ?string $model = null)
    {
        $this->cfg = $cfg + [
            'base_url' => 'https://ai.omabang.web.id/v1',
            'api_key' => '',
            'model' => '',
            'timeout' => 240,
        ];
        $this->model = $model ?: (is_string($this->cfg['model']) && $this->cfg['model'] !== '' ? $this->cfg['model'] : null);
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->cfg['api_key']) !== ''
            && trim((string) $this->cfg['base_url']) !== '';
    }

    public function model(): string
    {
        return $this->model ?: 'deepseek-v4.1-flash';
    }

    public function resolveModel(): string
    {
        if ($this->model !== null) return $this->model;
        $models = $this->fetchModels();
        if ($models !== []) {
            // Pilih model pertama yang aktif. Jangan pilih model embedding.
            foreach ($models as $m) {
                if (!is_string($m) || $m === '') continue;
                if (str_contains(engine_lower($m), 'embed')) continue;
                return $this->model = $m;
            }
        }
        return $this->model = 'deepseek-v4.1-flash';
    }

    public function fetchModels(): array
    {
        $url = $this->cfg['base_url'] . '/models';
        $body = $this->request('GET', $url, null, 30);
        if ($body === null) return [];
        $json = json_decode($body, true);
        if (!is_array($json)) return [];
        $data = $json['data'] ?? $json['models'] ?? [];
        if (!is_array($data)) return [];
        $ids = [];
        foreach ($data as $m) {
            if (is_string($m)) $ids[] = $m;
            elseif (is_array($m) && is_string($m['id'] ?? null)) $ids[] = $m['id'];
        }
        return array_values(array_unique($ids));
    }

    /**
     * Kirim chat completion dan kembalikan teks konten.
     * $jsonMode = true akan mencoba response_format json_object; jika gagal
     * otomatis fallback ke text mode.
     */
    public function chat(
        string $system,
        string $user,
        int $maxTokens = 8000,
        bool $jsonMode = false,
        float $temperature = 0.72
    ): ?string {
        if (!$this->isConfigured()) {
            generator_log('client not configured');
            return null;
        }

        $maxTokens = max(512, min(16384, $maxTokens));
        $modes = $jsonMode ? [true, false] : [false];

        foreach ($modes as $useJson) {
            // Kalau output terpotong karena token habis, ulangi sekali dengan
            // budget lebih besar (maksimum 16384) sebelum menyerah.
            $tokenTiers = [$maxTokens];
            if ($maxTokens < 16384) {
                $tokenTiers[] = min(16384, (int) round($maxTokens * 1.6));
            }

            foreach ($tokenTiers as $tierIndex => $tokens) {
                $payload = [
                    'model' => $this->resolveModel(),
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'temperature' => $temperature,
                    'max_tokens' => $tokens,
                ];
                if ($useJson) $payload['response_format'] = ['type' => 'json_object'];

                $body = $this->request('POST', $this->cfg['base_url'] . '/chat/completions', $payload, (int) $this->cfg['timeout']);
                if ($body === null) continue;

                $json = json_decode($body, true);
                if (!is_array($json)) {
                    generator_log('response is not JSON: ' . substr($body, 0, 250));
                    continue;
                }
                if (isset($json['error'])) {
                    $err = is_array($json['error']) ? json_encode($json['error'], JSON_UNESCAPED_UNICODE) : (string) $json['error'];
                    generator_log('API error: ' . substr((string) $err, 0, 300));
                    break; // coba mode berikutnya (mis. tanpa json mode)
                }

                $choice = $json['choices'][0] ?? [];
                $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
                $content = $message['content'] ?? null;
                $reasoning = $message['reasoning_content'] ?? null;
                $finish = (string) ($choice['finish_reason'] ?? '');

                if (is_array($content)) {
                    $bits = [];
                    foreach ($content as $part) {
                        if (is_string($part)) $bits[] = $part;
                        elseif (is_array($part) && is_string($part['text'] ?? null)) $bits[] = $part['text'];
                    }
                    $content = implode('', $bits);
                }

                if ((!is_string($content) || trim($content) === '') && is_string($reasoning) && trim($reasoning) !== '') {
                    generator_log('content empty, reasoning present (len=' . strlen($reasoning) . ')');
                }

                if (!is_string($content) || trim($content) === '') {
                    generator_log('empty content; finish=' . ($finish ?: 'unknown') . ' max_tokens=' . $tokens);
                    if ($finish === 'length') continue; // coba tier token lebih besar
                    break;
                }

                if ($finish === 'length') {
                    generator_log('truncated output; max_tokens=' . $tokens . ' chars=' . strlen($content));
                    if (isset($tokenTiers[$tierIndex + 1])) {
                        continue; // ulangi dengan token lebih besar
                    }
                }
                return $content;
            }
        }
        generator_log('chat failed for all modes');
        return null;
    }

    /** Kirim request HTTP dengan retry untuk error transien. */
    private function request(string $method, string $url, ?array $payload, int $timeout): ?string
    {
        $headers = [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (compatible; V-Dey-Generator/10.0; +https://v-dey.com)',
        ];
        if ($payload !== null) $headers[] = 'Content-Type: application/json';
        if (trim((string) $this->cfg['api_key']) !== '') {
            $headers[] = 'Authorization: Bearer ' . trim((string) $this->cfg['api_key']);
            $headers[] = 'x-api-key: ' . trim((string) $this->cfg['api_key']);
        }

        $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) return null;

        $attempts = 3;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if (function_exists('curl_init')) {
                [$response, $status, $errno, $error] = $this->sendCurl($method, $url, $headers, $body, $timeout);
            } else {
                [$response, $status, $errno, $error] = $this->sendStream($method, $url, $headers, $body, $timeout);
            }

            if ($status >= 200 && $status < 300 && is_string($response) && $response !== '') {
                return $response;
            }

            $transient = $errno === 28 || $errno === 7 || $errno === 6
                || in_array($status, [408, 425, 429, 500, 502, 503, 504, 520, 522, 524], true);

            generator_log(sprintf(
                'request attempt %d/%d: status=%d errno=%d response=%s',
                $attempt,
                $attempts,
                $status,
                $errno,
                is_string($response) ? substr($response, 0, 200) : '(none)'
            ));

            if ($attempt === $attempts || !$transient) {
                return null;
            }
            usleep($attempt === 1 ? 800000 : 1800000);
        }
        return null;
    }

    /** @return array{0:?string,1:int,2:int,3:string} response, status, errno, error */
    private function sendCurl(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) return [null, 0, 4, 'curl_init failed'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        return [is_string($response) ? $response : null, $status, $errno, $error];
    }

    /** @return array{0:?string,1:int,2:int,3:string} response, status, errno, error */
    private function sendStream(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return [null, 0, 4, 'curl and allow_url_fopen unavailable'];
        }
        $header = implode("\r\n", $headers);
        if ($body !== null) $header .= "\r\nContent-Length: " . strlen($body);
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $header,
                'content' => $body ?? '',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int) $m[1];
            }
        }
        if (!is_string($response) || $response === '') {
            return [null, $status, 7, 'empty response'];
        }
        return [$response, $status, 0, ''];
    }
}

/* ---------------------------------------------------------------------------
 *  JSON PARSER & NORMALIZER
 * ------------------------------------------------------------------------- */

function generator_parse_json(string $text): ?array
{
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;

    $decoded = json_decode(trim($text), true);
    if (is_array($decoded)) return $decoded;

    $start = strpos($text, '{');
    if ($start === false) return null;
    $end = strrpos($text, '}');
    $json = ($end !== false && $end > $start)
        ? substr($text, $start, $end - $start + 1)
        : substr($text, $start); // JSON terpotong: ambil sampai akhir

    $decoded = json_decode($json, true);
    if (is_array($decoded)) return $decoded;

    // Perbaikan bertahap: escape kontrol, buang trailing comma, tutup struktur.
    foreach ([
        static fn(string $j): string => generator_repair_json($j),
        static fn(string $j): string => generator_repair_json(generator_repair_json($j)),
    ] as $repair) {
        $fixed = $repair($json);
        $decoded = json_decode($fixed, true);
        if (is_array($decoded)) return $decoded;
    }

    return null;
}

/**
 * Perbaiki JSON yang rusak/terpotong tanpa mengubah isi yang sudah valid.
 * - escape newline/tab/control di dalam string
 * - buang trailing comma
 * - tutup string dan kurung yang belum selesai
 */
function generator_repair_json(string $json): string
{
    $out = '';
    $inString = false;
    $escaped = false;
    $stack = [];
    $len = strlen($json);

    for ($i = 0; $i < $len; $i++) {
        $ch = $json[$i];

        if ($inString) {
            if ($escaped) {
                $out .= $ch;
                $escaped = false;
                continue;
            }
            if ($ch === '\\') {
                $out .= '\\';
                $escaped = true;
                continue;
            }
            if ($ch === '"') {
                $out .= '"';
                $inString = false;
                continue;
            }
            $ord = ord($ch);
            if ($ch === "\n" || $ch === "\r" || $ch === "\t") {
                $out .= ' ';
            } elseif ($ord < 32 || $ord === 127) {
                $out .= ' ';
            } else {
                $out .= $ch;
            }
            continue;
        }

        if ($ch === '"') {
            $inString = true;
            $out .= '"';
            continue;
        }
        if ($ch === '{' || $ch === '[') {
            $stack[] = $ch === '{' ? '}' : ']';
            $out .= $ch;
            continue;
        }
        if ($ch === '}' || $ch === ']') {
            array_pop($stack);
            $out .= $ch;
            continue;
        }
        // Buang newline di luar string (JSON tidak mengizinkannya).
        if ($ch === "\n" || $ch === "\r" || $ch === "\t") {
            $out .= ' ';
            continue;
        }
        $out .= $ch;
    }

    // Trailing comma sebelum penutup.
    $out = preg_replace('/,\s*([}\]])/', '$1', $out) ?? $out;
    // Trailing comma di akhir teks (JSON terpotong setelah koma).
    $out = rtrim($out);
    if (str_ends_with($out, ',')) $out = substr($out, 0, -1);

    if ($inString) $out .= '"';

    // Tutup kurung yang belum selesai, dari yang paling dalam.
    while ($stack !== []) {
        $out .= array_pop($stack);
    }

    return $out;
}

function generator_find_key(array $data, array $keys): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) return $data[$key];
    }
    return null;
}

function generator_normalize_blueprint(array $raw, array $context): ?array
{
    $s = static function ($v, int $min, int $max): ?string {
        if (!is_scalar($v)) return null;
        $v = trim(strip_tags((string) $v));
        $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
        $v = trim($v, " \t\n\r\0\x0B\"“”");
        if ($v === '' || preg_match('/[<>]/', $v)) return null;
        $len = engine_text_len($v);
        return ($len >= $min && $len <= $max) ? $v : null;
    };

    $title = $s(generator_find_key($raw, ['title', 'h1', 'judul']), 20, 120);
    $seoTitle = $s(generator_find_key($raw, ['seo_title', 'meta_title']), 20, 100) ?: $title;
    $meta = $s(generator_find_key($raw, ['meta_description', 'description']), 60, 200);
    $slug = $s(generator_find_key($raw, ['slug']), 3, 120);
    $excerpt = $s(generator_find_key($raw, ['excerpt', 'summary']), 40, 300);

    if ($title === null) {
        generator_log('blueprint: title invalid');
        return null;
    }
    if ($meta === null) {
        generator_log('blueprint: meta_description invalid (' . engine_text_len((string) generator_find_key($raw, ['meta_description'])) . ' chars)');
        return null;
    }
    if ($slug === null) $slug = generator_slug($title);

    $focus = $s(generator_find_key($raw, ['focus_keyword', 'keyword']), 2, 100) ?: (string) $context['keyword'];
    $secondary = [];
    foreach ((array) generator_find_key($raw, ['secondary_keywords', 'related_keywords']) as $kw) {
        $k = $s($kw, 2, 80);
        if ($k !== null && engine_lower($k) !== engine_lower($focus)) $secondary[] = $k;
        if (count($secondary) >= 10) break;
    }

    $category = $s(generator_find_key($raw, ['category', 'kategori']), 2, 120) ?: 'Videy Viral';
    $tags = [];
    foreach ((array) generator_find_key($raw, ['tags']) as $tag) {
        $t = $s($tag, 2, 50);
        if ($t !== null) $tags[] = engine_lower($t);
        if (count($tags) >= 12) break;
    }

    $outline = [];
    foreach ((array) generator_find_key($raw, ['outline', 'sections']) as $sec) {
        if (!is_array($sec)) continue;
        $heading = $s($sec['heading'] ?? $sec['h2'] ?? null, 5, 120);
        if ($heading === null) continue;
        $points = [];
        foreach ((array) ($sec['points'] ?? []) as $p) {
            $pp = $s($p, 5, 160);
            if ($pp !== null) $points[] = $pp;
        }
        $outline[] = ['heading' => $heading, 'points' => $points];
        if (count($outline) >= 12) break;
    }
    if ($outline === []) {
        generator_log('blueprint: outline empty');
        return null;
    }

    $faq = [];
    foreach ((array) generator_find_key($raw, ['faq', 'questions']) as $item) {
        if (!is_array($item)) continue;
        $q = $s($item['question'] ?? $item['q'] ?? null, 8, 220);
        $a = $s($item['answer'] ?? $item['a'] ?? null, 20, 700);
        if ($q !== null && $a !== null) $faq[] = ['question' => $q, 'answer' => $a];
        if (count($faq) >= 6) break;
    }
    if ($faq === []) {
        generator_log('blueprint: faq empty');
        return null;
    }

    $takeaways = [];
    foreach ((array) generator_find_key($raw, ['key_takeaways', 'takeaways']) as $t) {
        $tt = $s($t, 10, 220);
        if ($tt !== null) $takeaways[] = $tt;
        if (count($takeaways) >= 6) break;
    }

    $imageAlt = $s(generator_find_key($raw, ['image_alt']), 5, 160) ?: $title;
    $angle = $s(generator_find_key($raw, ['angle']), 10, 400) ?: '';
    $social = $s(generator_find_key($raw, ['social_caption']), 10, 400) ?: '';

    $allText = engine_lower($title . ' ' . $meta . ' ' . $excerpt . ' ' . implode(' ', $takeaways));
    if (!str_contains($allText, 'videy')) {
        generator_log('blueprint: missing signature "videy"');
        return null;
    }

    return [
        'title' => $title,
        'seo_title' => $seoTitle ?? $title,
        'meta_description' => $meta,
        'slug' => generator_slug($slug),
        'excerpt' => $excerpt ?: $meta,
        'focus_keyword' => $focus,
        'secondary_keywords' => array_values(array_unique($secondary)),
        'category' => $category,
        'tags' => array_values(array_unique($tags)),
        'image_alt' => $imageAlt,
        'angle' => $angle,
        'outline' => $outline,
        'faq' => $faq,
        'key_takeaways' => $takeaways,
        'social_caption' => $social,
    ];
}

function generator_validate_article_html(string $html): array
{
    $errors = [];
    $html = trim($html);
    $html = preg_replace('/^```(?:html)?\s*|\s*```$/i', '', $html) ?? $html;
    $html = trim($html);

    if ($html === '') $errors[] = 'HTML kosong.';
    if (preg_match('/<h1\b/i', $html)) $errors[] = 'Artikel tidak boleh berisi <h1>.';
    if (preg_match('/<(script|iframe|object|embed|form|style|meta|link)\b/i', $html)) $errors[] = 'Tag terlarang ditemukan.';
    if (preg_match('/\*\*|```/u', $html)) $errors[] = 'Markdown terdeteksi.';
    if (preg_match('/sebagai ai|as an ai|i cannot|saya tidak bisa|lorem ipsum/i', $html)) $errors[] = 'Frasa meta AI ditemukan.';

    $h2 = preg_match_all('/<h2\b/i', $html);
    if ($h2 < 3) $errors[] = 'Minimal 3 heading H2.';

    $words = engine_words($html);
    if ($words < 450) $errors[] = 'Artikel terlalu pendek (' . $words . ' kata).';

    if (!preg_match('/(?<![\p{L}\p{N}])videy(?![\p{L}\p{N}])/iu', $html)) {
        $errors[] = 'Kata "videy" tidak ditemukan.';
    }
    if (!preg_match('/(?<![\p{L}\p{N}])(viral|tular)(?![\p{L}\p{N}])/iu', $html)) {
        $errors[] = 'Kata "viral/tular" tidak ditemukan.';
    }
    if (!preg_match('/(?<![\p{L}\p{N}])(lokal|local|tempatan|indo|malay|melayu|indonesia|malaysia)(?![\p{L}\p{N}])/iu', $html)) {
        $errors[] = 'Kata lokal/indo/malay/indonesia tidak ditemukan.';
    }

    return [$html, $errors];
}

function generator_sanitize_html(string $html, array $allowedUrls = []): string
{
    $html = trim($html);

    // Simpan blok JSON-LD (FAQ schema) supaya tidak ikut dibersihkan strip_tags,
    // tetapi hanya kalau isinya JSON valid. Selain itu dibuang.
    $schemaMap = [];
    $html = preg_replace_callback(
        '/<script\s+type="application\/ld\+json"[^>]*>(.*?)<\/script>/is',
        static function (array $m) use (&$schemaMap): string {
            $decoded = json_decode(trim((string) $m[1]), true);
            if (!is_array($decoded)) return '';
            $safe = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            if (!is_string($safe)) return '';
            $key = '@@SCHEMA' . count($schemaMap) . '@@';
            $schemaMap[$key] = '<script type="application/ld+json">' . $safe . '</script>';
            return "\n" . $key . "\n";
        },
        $html
    ) ?? $html;

    $allowed = '<p><h2><h3><h4><ul><ol><li><strong><b><em><i><blockquote><a><br>';
    $html = strip_tags($html, $allowed);

    // Validasi setiap pasangan <a>...</a>. Link yang diizinkan diganti dengan
    // placeholder agar tidak tersentuh pembersihan atribut/tag berikutnya.
    $linkMap = [];
    $html = preg_replace_callback('/<a\b([^>]*)>(.*?)<\/a>/is', static function (array $m) use ($allowedUrls, &$linkMap): string {
        $href = '';
        if (preg_match('/href\s*=\s*("|\')([^"\']+)\1/i', $m[1], $h)) {
            $href = trim($h[2]);
        }
        if ($href === '' || !generator_url_is_safe($href, $allowedUrls)) {
            return strip_tags($m[2]); // buang tag, teks tetap
        }
        $key = '@@LINK' . count($linkMap) . '@@';
        $linkMap[$key] = '<a href="' . h($href) . '" rel="noopener noreferrer">' . strip_tags($m[2]) . '</a>';
        return $key;
    }, $html) ?? $html;

    // Buang sisa tag <a> yang tidak berpasangan.
    $html = preg_replace('/<a\b[^>]*>/i', '', $html) ?? $html;
    $html = preg_replace('/<\/a>/i', '', $html) ?? $html;

    // Hapus semua atribut dari tag lain (link valid sudah jadi placeholder).
    $html = preg_replace_callback('/<([a-z0-9]+)[^>]*>/i', static function (array $m): string {
        $tag = engine_lower($m[1]);
        return $tag === 'br' ? '<br>' : '<' . $tag . '>';
    }, $html) ?? $html;

    // Kembalikan link valid dan blok JSON-LD.
    if ($linkMap !== [] || $schemaMap !== []) {
        $html = strtr($html, $linkMap + $schemaMap);
    }

    // Rapikan spasi/newline berlebih.
    $html = preg_replace('/\n{3,}/', "\n\n", $html) ?? $html;
    return trim($html);
}

function generator_url_is_safe(string $url, array $allowed): bool
{
    $url = trim($url);
    if ($url === '') return false;
    if (str_starts_with($url, '#')) return true;
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) return false;
    if (str_starts_with($url, '//')) return false;
    if ($allowed === []) return false;
    $norm = rtrim($url, '/');
    foreach ($allowed as $ok) {
        $ok = rtrim((string) $ok, '/');
        if ($norm === $ok || str_starts_with($norm, $ok . '/')) return true;
    }
    return false;
}

function generator_add_links(string $html, array $allowedUrls): string
{
    // Pastikan minimal 1 tautan internal jika model tidak menyisipkan.
    if (preg_match('/href\s*=/i', $html)) return $html;
    $siteUrl = rtrim($allowedUrls[0] ?? '', '/');
    if ($siteUrl === '') return $html;
    $anchor = '<p>Mulai dari halaman <a href="' . h($siteUrl) . '" rel="noopener noreferrer">V-Dey</a> untuk melihat konten lain yang sedang ramai.</p>';
    return $html . "\n" . $anchor;
}

/* ---------------------------------------------------------------------------
 *  AI GENERATION — blueprint + article
 * ------------------------------------------------------------------------- */

function generator_ai_blueprint(
    GeneratorOmabangClient $client,
    array $context,
    array $site,
    array $lengthCfg,
    array $existingTitles,
    array $internalLinks,
    array $allowedUrls
): ?array {
    $lang = (string) $context['language'];
    $langEn = ['id' => 'Indonesian', 'ms' => 'Malay', 'en' => 'English'][$lang] ?? 'Indonesian';
    $titles = $existingTitles === [] ? '(belum ada)' : implode("\n", array_map(static fn($t) => '- ' . $t, array_slice($existingTitles, 0, 20)));
    $links = $internalLinks === [] ? '- ' . rtrim($site['url'], '/') . '/ (Beranda)' : implode("\n", array_map(
        static fn(array $l): string => '- ' . $l['url'] . ' (' . $l['title'] . ')',
        array_slice($internalLinks, 0, 15)
    ));
    $cat = $context['category_hint'] ?: 'Videy Viral';

    $system = <<<PROMPT
You are an elite SEO content strategist and editor for {$site['name']}, a blog about video discovery for Indonesian and Malaysian audiences.
Return ONLY one valid JSON object. No markdown, no code fences, no commentary.

HARD RULES:
- Never fabricate facts, statistics, dates, names, studies, or sources. Keep every claim general and defensible.
- No adult, gambling, piracy, cryptocurrency, medical, or harmful topics.
- Write entirely in {$langEn}. Do not mix languages, except proper nouns.
- Title: 45-65 characters, specific, honest, click-worthy, no clickbait.
- SEO title: 45-60 characters, contains the focus keyword naturally.
- Meta description: 140-158 characters, contains the focus keyword, promises a concrete benefit.
- Excerpt: 110-180 characters.
- Slug: 3-8 lowercase words, hyphens only.
- Do not use "sebagai AI", "saya tidak bisa", "lorem ipsum", or placeholder text.
- Every outline point must be specific and useful, not generic filler.

CONTENT TYPE: {$context['content_type']}
TONE: {$context['tone']}
AUDIENCE: {$context['audience']}
TARGET LENGTH: about {$lengthCfg['words']} words, {$lengthCfg['sections']} H2 sections.

SCHEMA (must match exactly):
{
  "title": "string",
  "seo_title": "string",
  "meta_description": "string",
  "slug": "string",
  "excerpt": "string",
  "focus_keyword": "string",
  "secondary_keywords": ["5-8 strings"],
  "category": "short category",
  "tags": ["5-8 strings"],
  "image_alt": "descriptive alt text",
  "angle": "one sentence",
  "outline": [{"heading": "H2 heading", "points": ["2-4 specific points"]}],
  "faq": [{"question": "string", "answer": "40-90 words"}],
  "key_takeaways": ["3-5 strings"],
  "social_caption": "120-180 characters"
}

Required: outline = {$lengthCfg['sections']} items, faq = 4 items, tags = 6-8 items, secondary_keywords = 5-8 items.
PROMPT;

    $user = <<<PROMPT
SITE: {$site['name']} — {$site['description']}
SITE CONTEXT: {$site['context']}
PRIMARY KEYWORD: {$context['keyword']}
LANGUAGE: {$langEn}
COUNTRY / REGION: {$context['country_name']}
SEARCH INTENT: {$context['intent_label']}
CATEGORY HINT: {$cat}
TARGET AUDIENCE: {$context['audience']}
EXTRA INSTRUCTIONS: {$context['notes']}

EXISTING TITLES TO AVOID (do not duplicate or closely paraphrase):
{$titles}

ALLOWED INTERNAL LINKS (do not invent other URLs):
{$links}

Create a blueprint for a genuinely useful article. The outline should answer the searcher's real problem, not repeat obvious definitions. FAQ answers must be complete and safe.

Return the JSON now.
PROMPT;

    $raw = $client->chat($system, $user, 7000, true, 0.65);
    if ($raw === null) {
        generator_log('blueprint: no response');
        return null;
    }
    $parsed = generator_parse_json($raw);
    if ($parsed === null) {
        generator_log('blueprint: JSON parse failed. head=' . substr($raw, 0, 200));
        return null;
    }
    return generator_normalize_blueprint($parsed, $context);
}

function generator_ai_article(
    GeneratorOmabangClient $client,
    array $blueprint,
    array $context,
    array $site,
    array $lengthCfg,
    array $allowedUrls
): ?string {
    $langEn = ['id' => 'Indonesian', 'ms' => 'Malay', 'en' => 'English'][$context['language']] ?? 'Indonesian';
    $plan = json_encode($blueprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($plan)) return null;

    $links = implode("\n", array_map(static fn($u) => '- ' . $u, $allowedUrls));

    $system = <<<PROMPT
You are a senior {$langEn} SEO writer and editor for {$site['name']}.
Write a complete, genuinely useful article in {$langEn}, following the blueprint exactly.
Return ONLY the article HTML fragment. No JSON, no markdown, no code fences, no commentary.

HARD RULES:
- Do NOT include an <h1>. The CMS renders the title separately.
- Start with 2-3 intro paragraphs, then use <h2> sections with <p>, <ul>, <ol>, <strong>, <em>, <blockquote>, <a>.
- Allowed tags only: p, h2, h3, ul, ol, li, strong, em, blockquote, a, br. No div, img, table, form, script, style, iframe, or HTML comments.
- Include the focus keyword naturally in the first 100 words and at a natural density. No keyword stuffing.
- Include 2-4 internal links using ONLY the exact URLs in the allowed list. Use natural anchor text. Never invent URLs.
- Include the supplied FAQ as an <h2>FAQ</h2> section, using <h3> for each question and <p> for answers.
- Include a <h2>Poin Penting</h2> section with the key takeaways as a list.
- Include a short conclusion section with a soft call to action to explore {$site['name']}.
- No fabricated facts, statistics, dates, names, studies, or specific events. Keep claims general and verifiable. No fake quotes.
- Tone: {$context['tone']}. Do not overpromise. Do not mention being an AI.
- Target length: about {$lengthCfg['words']} words ({$lengthCfg['words']} ± 20%).
- Match the language exactly: {$langEn}. Do not translate the blueprint into another language.
PROMPT;

    $user = <<<PROMPT
BLUEPRINT:
{$plan}

ALLOWED INTERNAL LINKS (use only these):
{$links}

Write the article HTML now. Do not include an H1, title, or meta description.
PROMPT;

    $raw = $client->chat($system, $user, (int) $lengthCfg['max_tokens'], false, 0.7);
    if ($raw === null) {
        generator_log('article: no response');
        return null;
    }
    $raw = trim($raw);
    $raw = preg_replace('/^```(?:html|markdown|md)?\s*|\s*```$/i', '', $raw) ?? $raw;
    $raw = trim($raw);
    return $raw === '' ? null : $raw;
}

function generator_ai_repair_article(
    GeneratorOmabangClient $client,
    string $broken,
    array $blueprint,
    array $context,
    array $site,
    array $lengthCfg,
    array $allowedUrls,
    array $errors
): ?string {
    $links = implode(', ', $allowedUrls);
    $errs = implode('; ', $errors);
    $system = "You repair SEO article HTML. Return ONLY corrected HTML, no commentary, no code fences. "
        . "Allowed tags: p,h2,h3,ul,ol,li,strong,em,blockquote,a,br. No h1, script, style, iframe, div, img. "
        . "Language: " . (['id' => 'Indonesian', 'ms' => 'Malay', 'en' => 'English'][$context['language']] ?? 'Indonesian') . ". "
        . "Use only these internal URLs: $links. No fabricated facts.";
    $user = "ERRORS TO FIX: $errs\n\nBLUEPRINT TITLE: " . $blueprint['title']
        . "\n\nFOCUS KEYWORD: " . $context['keyword']
        . "\n\nARTICLE TO REPAIR:\n" . engine_substr($broken, 0, 14000)
        . "\n\nReturn the repaired HTML now.";
    $raw = $client->chat($system, $user, (int) $lengthCfg['max_tokens'], false, 0.4);
    if ($raw === null) return null;
    $raw = preg_replace('/^```(?:html|markdown|md)?\s*|\s*```$/i', '', trim($raw)) ?? trim($raw);
    return trim($raw) ?: null;
}

/* ---------------------------------------------------------------------------
 *  PREVIEW IMAGE / ATTACHMENT
 * ------------------------------------------------------------------------- */

function generator_pick_image(string $projectDir, string $siteUrl): string
{
    $dirs = [$projectDir . '/uploads', $projectDir . '/images'];
    $images = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;
        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') continue;
            if (!in_array(strtolower((string) pathinfo($file, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) continue;
            if (!is_file($dir . $file)) continue;
            $images[] = $dir . '/' . $file;
        }
    }
    if ($images === []) return '';
    $pick = $images[array_rand($images)];
    $relative = str_replace($projectDir, '', $pick);
    if (function_exists('url')) {
        return rtrim(url(ltrim($relative, '/')), '/');
    }
    return rtrim($siteUrl, '/') . '/' . ltrim($relative, '/');
}

function generator_default_category(): string
{
    return 'Videy Viral';
}

/* ---------------------------------------------------------------------------
 *  DATABASE SAVE
 * ------------------------------------------------------------------------- */

function generator_save_post(array $article, array $blueprint, array $options, string $imageUrl): array
{
    if (!function_exists('db')) {
        throw new RuntimeException('Fungsi DB tidak tersedia. Jalankan generator dari dalam CMS atau isi config DB.');
    }
    $pdo = db();
    $user = function_exists('current_user') ? current_user() : null;
    $authorId = (int) ($user['id'] ?? 1);

    $title = engine_substr(trim((string) $article['title']), 0, 255);
    if ($title === '') throw new RuntimeException('Judul artikel kosong.');
    $slug = generator_slug_unique((string) ($article['slug'] ?: $title), $pdo);
    $status = $options['status'] === 'published' ? 'published' : 'draft';
    $publishedAt = $status === 'published' ? date('Y-m-d H:i:s') : null;

    // Batasi panjang agar sesuai kolom DB (VARCHAR 255/320).
    $metaTitle = engine_substr(trim((string) ($blueprint['seo_title'] ?? '')), 0, 255) ?: $title;
    $metaDescription = engine_substr(trim((string) ($blueprint['meta_description'] ?? '')), 0, 320);

    $excerpt = trim((string) ($article['excerpt'] ?? ''));
    if ($excerpt === '') {
        $excerpt = engine_substr(trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $article['html']))), 0, 180);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO posts
            (author_id, title, slug, body, excerpt, thumbnail, meta_title, meta_description, status, featured, views, published_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, NOW(), NOW())');
        $stmt->execute([
            $authorId,
            $title,
            $slug,
            (string) $article['html'],
            $excerpt,
            $imageUrl !== '' ? engine_substr($imageUrl, 0, 500) : null,
            $metaTitle,
            $metaDescription,
            $status,
            $publishedAt,
        ]);
        $postId = (int) $pdo->lastInsertId();

        // Kategori
        $catName = engine_substr(trim((string) ($blueprint['category'] ?? generator_default_category())), 0, 120);
        if ($catName === '') $catName = generator_default_category();
        $catStmt = $pdo->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1');
        $catStmt->execute([$catName]);
        $catId = $catStmt->fetchColumn();
        if ($catId === false) {
            $catSlug = generator_slug_unique($catName, $pdo, 'categories');
            $ins = $pdo->prepare('INSERT INTO categories (name, slug, created_at) VALUES (?, ?, NOW())');
            $ins->execute([$catName, $catSlug]);
            $catId = (int) $pdo->lastInsertId();
        }
        $pdo->prepare('INSERT IGNORE INTO post_categories (post_id, category_id) VALUES (?, ?)')->execute([$postId, (int) $catId]);

        // Tags
        $tags = array_values(array_filter(array_map('trim', (array) ($blueprint['tags'] ?? []))));
        if ($tags === []) $tags = ['videy', 'viral'];
        foreach (array_slice($tags, 0, 8) as $tagName) {
            if ($tagName === '' || engine_text_len($tagName) > 120) continue;
            $tagStmt = $pdo->prepare('SELECT id FROM tags WHERE name = ? LIMIT 1');
            $tagStmt->execute([$tagName]);
            $tagId = $tagStmt->fetchColumn();
            if ($tagId === false) {
                $tagSlug = generator_slug_unique($tagName, $pdo, 'tags');
                $ins = $pdo->prepare('INSERT INTO tags (name, slug) VALUES (?, ?)');
                $ins->execute([$tagName, $tagSlug]);
                $tagId = (int) $pdo->lastInsertId();
            }
            $pdo->prepare('INSERT IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)')->execute([$postId, (int) $tagId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    if (function_exists('log_activity')) {
        @log_activity('generator.create', $title);
    }
    return ['id' => $postId, 'slug' => $slug, 'status' => $status];
}

/* ---------------------------------------------------------------------------
 *  HISTORY / DEDUPLICATION
 * ------------------------------------------------------------------------- */

function generator_history_load(string $file): array
{
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data['items'] ?? null) ? $data['items'] : [];
}

function generator_history_save(string $file, array $items): void
{
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) return;
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\n");
    $json = json_encode(['items' => array_slice($items, -100)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) return;
    @file_put_contents($file, $json, LOCK_EX);
}

function generator_profile(string $html): array
{
    $text = engine_lower(trim((string) preg_replace('/\s+/', ' ', strip_tags($html))));
    $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $shingles = [];
    for ($i = 0, $n = count($words) - 5; $i <= $n; $i++) {
        $shingles[hash('crc32b', implode(' ', array_slice($words, $i, 5)))] = true;
    }
    return ['shingles' => array_keys($shingles), 'title' => engine_lower($text)];
}

function generator_similarity(array $a, array $b): float
{
    $left = array_fill_keys($a['shingles'] ?? [], true);
    $right = array_fill_keys($b['shingles'] ?? [], true);
    $union = count($left + $right);
    return $union > 0 ? count(array_intersect_key($left, $right)) / $union : 0.0;
}

/* ---------------------------------------------------------------------------
 *  DIAGNOSTIC
 * ------------------------------------------------------------------------- */

if (($_GET['diag'] ?? '') === 'ai') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "=== V-DEY AI GENERATOR — DIAGNOSTIC v10.0 ===\n\n";
    echo 'PHP: ' . PHP_VERSION . "\n";
    echo 'curl: ' . (function_exists('curl_init') ? 'YES' : 'NO') . "\n";
    echo 'allow_url_fopen: ' . ini_get('allow_url_fopen') . "\n";
    echo 'base_url: ' . $ai['base_url'] . "\n";
    echo 'model configured: ' . ($ai['model'] ?: '(auto)') . "\n";
    echo 'api_key: ' . ($ai['api_key'] !== '' ? 'SET (hidden)' : 'MISSING') . "\n";
    echo 'timeout: ' . $ai['timeout'] . "s\n\n";
    $client = new GeneratorOmabangClient($ai);
    if (!$client->isConfigured()) {
        echo "❌ API key belum dikonfigurasi.\n";
        echo "Set OMABANG_API_KEY atau buat generator-config.php (lihat komentar di file ini).\n";
        exit;
    }
    $models = $client->fetchModels();
    echo "MODELS: " . ($models === [] ? '(gagal / kosong)' : implode(', ', $models)) . "\n";
    echo 'MODEL USED: ' . $client->model() . "\n\n";
    echo "Testing chat (mohon tunggu)...\n";
    $t0 = microtime(true);
    $out = $client->chat(
        'Return ONLY this exact JSON: {"ok":true,"msg":"diagnostic"}',
        'Return the JSON now.',
        300,
        true,
        0.1
    );
    echo 'Elapsed: ' . round(microtime(true) - $t0, 2) . "s\n";
    echo 'Result: ' . ($out === null ? '❌ NULL (cek error_log [vdey-ai])' : "✅ " . substr($out, 0, 600)) . "\n";
    exit;
}

/* ---------------------------------------------------------------------------
 *  ACTION HANDLING
 * ------------------------------------------------------------------------- */

$error = '';
$notice = '';
$result = null;
$lastForm = [
    'keyword' => 'videy viral lokal indo malay',
    'language' => 'id',
    'type' => 'guide',
    'tone' => 'santai',
    'length' => 'medium',
    'audience' => 'Penonton Indonesia dan Malaysia usia 18-40 yang mencari video viral lokal',
    'notes' => '',
    'status' => 'draft',
    'category' => '',
    'image_mode' => 'auto',
    'image_url' => '',
    'auto_save' => false,
    'internal_links' => true,
    'faq_schema' => true,
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $isJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        && (($_POST['action'] ?? '') === 'generate');

    // CSRF
    $token = (string) ($_POST['csrf'] ?? $_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf, $token)) {
        if ($isJson) generator_json_out(['ok' => false, 'error' => 'Sesi formulir tidak valid. Muat ulang halaman.'], 403);
        $error = 'Sesi formulir tidak valid. Muat ulang halaman dan coba lagi.';
    } elseif (($_POST['action'] ?? '') === 'save') {
        // Simpan hasil review yang dikirim balik dari form.
        // Menerima dua gaya: field individual (form review) atau payload JSON.
        try {
            $allowedUrls = [];
            $rawUrls = (string) ($_POST['allowed_urls'] ?? '');
            if ($rawUrls !== '') {
                $decodedUrls = json_decode($rawUrls, true);
                if (is_array($decodedUrls)) {
                    $allowedUrls = array_values(array_filter($decodedUrls, 'is_string'));
                } elseif (str_contains($rawUrls, 'http')) {
                    $allowedUrls = array_values(array_filter(array_map('trim', explode(',', $rawUrls)), 'is_string'));
                }
            }

            $bp = [];
            $rawBp = (string) ($_POST['blueprint'] ?? '');
            if ($rawBp !== '') {
                $decodedBp = json_decode($rawBp, true);
                if (is_array($decodedBp)) $bp = $decodedBp;
            }

            $payload = json_decode((string) ($_POST['payload'] ?? ''), true);
            if (!is_array($payload)) $payload = [];
            if ($bp === [] && is_array($payload['blueprint'] ?? null)) $bp = $payload['blueprint'];
            if ($allowedUrls === [] && is_array($payload['allowed_urls'] ?? null)) {
                $allowedUrls = array_values(array_filter($payload['allowed_urls'], 'is_string'));
            }
            $payloadArticle = is_array($payload['article'] ?? null) ? $payload['article'] : [];

            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title === '') $title = trim((string) ($payloadArticle['title'] ?? ($bp['title'] ?? '')));
            $html = (string) ($_POST['html'] ?? '');
            if ($html === '') $html = (string) ($payloadArticle['html'] ?? '');
            if ($title === '' || $html === '') throw new RuntimeException('Artikel kosong.');

            $slug = trim((string) ($_POST['slug'] ?? ''));
            if ($slug === '') $slug = (string) ($payloadArticle['slug'] ?? ($bp['slug'] ?? ''));
            $metaDescription = trim((string) ($_POST['meta_description'] ?? ''));
            if ($metaDescription === '') {
                $metaDescription = (string) ($payloadArticle['meta_description'] ?? ($bp['meta_description'] ?? ''));
            }
            $tagsRaw = (string) ($_POST['tags'] ?? '');
            $tags = $tagsRaw !== ''
                ? array_values(array_filter(array_map('trim', explode(',', $tagsRaw)), static fn(string $t): bool => $t !== ''))
                : (array) ($bp['tags'] ?? []);

            // Field hasil edit menimpa nilai blueprint.
            $bp['title'] = $title;
            $bp['slug'] = $slug;
            $bp['meta_description'] = $metaDescription;
            $bp['tags'] = $tags;
            if (!isset($bp['category']) || !is_string($bp['category']) || trim($bp['category']) === '') {
                $bp['category'] = generator_default_category();
            }

            $seoTitle = trim((string) ($bp['seo_title'] ?? ''));
            if ($seoTitle === '') $seoTitle = trim((string) ($payloadArticle['seo_title'] ?? '')) ?: $title;
            $bp['seo_title'] = $seoTitle;
            $excerpt = trim((string) ($bp['excerpt'] ?? ''));
            if ($excerpt === '') $excerpt = trim((string) ($payloadArticle['excerpt'] ?? ''));
            $bp['excerpt'] = $excerpt;

            $article = [
                'title' => $title,
                'slug' => $slug,
                'seo_title' => $seoTitle,
                'meta_description' => $metaDescription,
                'excerpt' => $excerpt,
                'html' => generator_sanitize_html($html, $allowedUrls),
                'word_count' => engine_words($html),
                'focus_keyword' => (string) ($bp['focus_keyword'] ?? ''),
            ];

            $options = [
                'status' => ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft',
            ];
            $imageUrl = (string) ($_POST['thumbnail'] ?? '');
            if ($imageUrl !== '' && !filter_var($imageUrl, FILTER_VALIDATE_URL)) $imageUrl = '';
            $saved = generator_save_post($article, $bp, $options, $imageUrl);
            $notice = 'Tersimpan sebagai ' . $saved['status'] . ' — post #' . $saved['id'] . ' (slug: ' . $saved['slug'] . ').';
            $lastForm['keyword'] = (string) ($_POST['keyword'] ?? $lastForm['keyword']);
            // Setelah simpan, tampilkan ringkasan hasil (tanpa tombol simpan lagi).
            $result = [
                'article' => $article,
                'blueprint' => $bp,
                'allowed_urls' => $allowedUrls,
                'image_url' => $imageUrl,
                'saved' => $saved,
                'form' => $lastForm,
            ];
        } catch (Throwable $e) {
            $error = 'Gagal menyimpan: ' . $e->getMessage();
        }
    } elseif (($_POST['action'] ?? '') === 'generate') {
        try {
            foreach ($lastForm as $k => $v) {
                if (!array_key_exists($k, $_POST)) continue;
                $lastForm[$k] = is_bool($v) ? (bool) $_POST[$k] : trim((string) $_POST[$k]);
            }
            $lastForm['keyword'] = engine_substr(trim((string) ($_POST['keyword'] ?? '')), 0, 120);
            $lastForm['language'] = in_array($_POST['language'] ?? '', array_keys($languages), true) ? (string) $_POST['language'] : 'id';
            $lastForm['type'] = in_array($_POST['type'] ?? '', array_keys($contentTypes), true) ? (string) $_POST['type'] : 'guide';
            $lastForm['tone'] = in_array($_POST['tone'] ?? '', array_keys($tones), true) ? (string) $_POST['tone'] : 'santai';
            $lastForm['length'] = in_array($_POST['length'] ?? '', array_keys($lengths), true) ? (string) $_POST['length'] : 'medium';
            $lastForm['status'] = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
            $lastForm['auto_save'] = isset($_POST['auto_save']);
            $lastForm['internal_links'] = isset($_POST['internal_links']);
            $lastForm['faq_schema'] = isset($_POST['faq_schema']);

            $keyword = $lastForm['keyword'];
            if ($keyword === '') throw new RuntimeException('Keyword wajib diisi.');
            if (engine_text_len($keyword) > 120) throw new RuntimeException('Keyword maksimal 120 karakter.');

            $client = new GeneratorOmabangClient($ai);
            if (!$client->isConfigured()) throw new RuntimeException('API key Omabang belum dikonfigurasi. Isi OMABANG_API_KEY atau generator-config.php.');

            $lengthCfg = $lengths[$lastForm['length']] ?? $lengths['medium'];
            $context = [
                'keyword' => $keyword,
                'language' => $lastForm['language'],
                'country_name' => 'Indonesia / Malaysia',
                'intent_label' => 'Informational',
                'content_type' => $contentTypes[$lastForm['type']] ?? $contentTypes['guide'],
                'tone' => $tones[$lastForm['tone']] ?? $tones['santai'],
                'audience' => $lastForm['audience'] ?: 'Pembaca umum Indonesia dan Malaysia',
                'notes' => $lastForm['notes'] ?: '(tidak ada)',
                'category_hint' => $lastForm['category'] ?: '',
            ];

            // Existing titles + internal links
            $existingTitles = [];
            $internalLinks = [];
            if (function_exists('db')) {
                try {
                    $rows = db()->query('SELECT title FROM posts ORDER BY id DESC LIMIT 30')->fetchAll();
                    foreach ($rows as $r) {
                        if (is_array($r) && is_string($r['title'] ?? null)) $existingTitles[] = $r['title'];
                    }
                    if ($lastForm['internal_links']) {
                        $rows = db()->query('SELECT title, slug, published_at, created_at FROM posts WHERE status="published" ORDER BY published_at DESC LIMIT 12')->fetchAll();
                        foreach ($rows as $r) {
                            if (!is_array($r)) continue;
                            $date = (string) ($r['published_at'] ?: $r['created_at']);
                            $internalLinks[] = [
                                'title' => (string) ($r['title'] ?? ''),
                                'url' => rtrim($site['url'], '/') . '/' . date('Y/m/', strtotime($date)) . rawurlencode((string) ($r['slug'] ?? '')) . '.html',
                            ];
                        }
                    }
                } catch (Throwable $e) {
                    generator_log('context query failed: ' . $e->getMessage());
                }
            }
            $internalLinks[] = ['title' => 'Beranda ' . $site['name'], 'url' => rtrim($site['url'], '/') . '/'];
            $internalLinks[] = ['title' => 'Label Viral', 'url' => rtrim($site['url'], '/') . '/search/label/viral'];
            $internalLinks = array_values(array_filter($internalLinks, static fn($l) => is_array($l) && is_string($l['url'] ?? null)));
            $allowedUrls = array_values(array_unique(array_map(static fn($l) => rtrim($l['url'], '/'), $internalLinks)));

            // 1. Blueprint
            $blueprint = generator_ai_blueprint($client, $context, $site, $lengthCfg, $existingTitles, $internalLinks, $allowedUrls);
            if ($blueprint === null) {
                throw new RuntimeException('AI gagal membuat blueprint. Cek ?diag=ai dan error_log [vdey-ai]. Coba lagi dengan keyword/panjang yang lebih sederhana.');
            }

            // 2. Article
            $articleHtml = generator_ai_article($client, $blueprint, $context, $site, $lengthCfg, $allowedUrls);
            if ($articleHtml === null) {
                throw new RuntimeException('AI gagal menulis artikel. Coba lagi atau kurangi panjang artikel.');
            }

            // 3. Validate + repair (max 1 repair)
            [$clean, $errors] = generator_validate_article_html($articleHtml);
            if ($errors !== []) {
                generator_log('article validation errors: ' . implode(' | ', $errors));
                $repaired = generator_ai_repair_article($client, $clean, $blueprint, $context, $site, $lengthCfg, $allowedUrls, $errors);
                if ($repaired !== null) {
                    [$clean, $errors] = generator_validate_article_html($repaired);
                }
            }
            if ($errors !== []) {
                // Tetap butuh HTML layak; jangan simpan sampah.
                throw new RuntimeException('Artikel AI tidak lolos validasi: ' . implode(' ', $errors));
            }

            $clean = generator_sanitize_html($clean, $allowedUrls);
            $clean = generator_add_links($clean, $allowedUrls);

            // Optional FAQ JSON-LD (aman, disisipkan setelah sanitasi)
            if ($lastForm['faq_schema'] && !empty($blueprint['faq'])) {
                $schema = [
                    '@context' => 'https://schema.org',
                    '@type' => 'FAQPage',
                    'mainEntity' => array_map(static fn(array $f): array => [
                        '@type' => 'Question',
                        'name' => (string) $f['question'],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string) $f['answer']],
                    ], $blueprint['faq']),
                ];
                $clean .= "\n" . '<script type="application/ld+json">'
                    . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
                    . '</script>';
            }

            // 4. Duplicate check against history + existing titles
            $profile = generator_profile($clean);
            $history = generator_history_load($historyFile);
            $maxSim = 0.0;
            foreach ($history as $h) {
                if (!is_array($h)) continue;
                $maxSim = max($maxSim, generator_similarity($profile, $h));
            }
            foreach ($existingTitles as $t) {
                if (engine_lower($t) === engine_lower($blueprint['title'])) $maxSim = 1.0;
            }
            if ($maxSim >= 0.55) {
                throw new RuntimeException('Konten terlalu mirip dengan artikel sebelumnya (kemiripan ' . round($maxSim * 100) . '%). Ganti keyword atau tambahkan sudut pandang baru di catatan.');
            }

            // 5. Word count + image
            $wordCount = engine_words($clean);
            $imageUrl = '';
            if ($lastForm['image_mode'] === 'manual' && $lastForm['image_url'] !== '') {
                if (filter_var($lastForm['image_url'], FILTER_VALIDATE_URL)) $imageUrl = (string) $lastForm['image_url'];
            } elseif ($lastForm['image_mode'] === 'auto') {
                $imageUrl = generator_pick_image($projectDir, $site['url']);
            }

            $article = [
                'title' => $blueprint['title'],
                'slug' => $blueprint['slug'],
                'seo_title' => $blueprint['seo_title'],
                'meta_description' => $blueprint['meta_description'],
                'excerpt' => $blueprint['excerpt'],
                'html' => $clean,
                'word_count' => $wordCount,
                'image_alt' => $blueprint['image_alt'],
                'qa' => [
                    'focus_keyword' => $blueprint['focus_keyword'],
                    'secondary_keywords' => $blueprint['secondary_keywords'],
                    'duplicate_score' => round($maxSim, 3),
                ],
            ];

            // Auto-save or preview
            $saved = null;
            if ($lastForm['auto_save']) {
                $saved = generator_save_post($article, $blueprint, ['status' => $lastForm['status']], $imageUrl);
                $notice = 'AI selesai dan konten disimpan sebagai ' . $saved['status'] . ' — post #' . $saved['id'] . '.';
            } else {
                $notice = 'AI selesai. Periksa hasil, lalu klik Simpan.';
            }

            // Save history
            $history[] = [
                'title' => $blueprint['title'],
                'keyword' => $keyword,
                'shingles' => $profile['shingles'],
                'created_at' => gmdate(DATE_ATOM),
            ];
            generator_history_save($historyFile, $history);

            $result = [
                'article' => $article,
                'blueprint' => $blueprint,
                'allowed_urls' => $allowedUrls,
                'image_url' => $imageUrl,
                'saved' => $saved,
                'form' => $lastForm,
                'context' => $context,
                'usage' => [],
            ];

            if ($isJson) generator_json_out(['ok' => true, 'result' => $result]);
        } catch (Throwable $e) {
            generator_log('generate failed: ' . $e->getMessage());
            if ($isJson) generator_json_out(['ok' => false, 'error' => $e->getMessage()], 422);
            $error = $e->getMessage();
        }
    }
}

/* ---------------------------------------------------------------------------
 *  VIEW
 * ------------------------------------------------------------------------- */

$pageTitle = 'AI Content Generator — ' . $site['name'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($pageTitle) ?></title>
<link rel="stylesheet" href="<?= h(function_exists('url') ? url('assets/admin.css') : '') ?>">
<style>
:root { --ink:#172033; --muted:#65718a; --line:#e2e8f0; --blue:#175cd3; --blue2:#0e7490; --green:#157f3d; --amber:#b45309; --red:#b42318; }
* { box-sizing:border-box; }
body { margin:0; background:#f4f7fb; color:var(--ink); font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif; }
.wrap { max-width:1180px; margin:0 auto; padding:28px 18px 70px; }
.hero { display:flex; justify-content:space-between; gap:20px; align-items:flex-end; margin-bottom:20px; }
.hero h1 { margin:0; font-size:30px; letter-spacing:-.03em; }
.hero p { margin:4px 0 0; color:var(--muted); }
.badges { display:flex; gap:8px; flex-wrap:wrap; }
.pill { display:inline-flex; gap:6px; align-items:center; background:#e8f1ff; color:#175cd3; border-radius:999px; padding:5px 10px; font-size:12px; font-weight:700; }
.pill.green { background:#e8f7ee; color:var(--green); }
.pill.amber { background:#fff4e5; color:var(--amber); }
.card { background:#fff; border:1px solid var(--line); border-radius:14px; box-shadow:0 10px 30px rgba(15,23,42,.05); padding:22px; margin-bottom:18px; }
.grid { display:grid; grid-template-columns:repeat(12,minmax(0,1fr)); gap:14px; }
.field { display:flex; flex-direction:column; gap:6px; font-size:13px; font-weight:700; color:#334155; }
.field small { font-weight:400; color:var(--muted); }
input,select,textarea { width:100%; border:1px solid #cbd5e1; border-radius:9px; padding:10px 11px; font:inherit; background:#fff; color:var(--ink); }
textarea { min-height:76px; resize:vertical; }
input:focus,select:focus,textarea:focus { outline:3px solid #dbeafe; border-color:#60a5fa; }
.span-12{grid-column:span 12}.span-8{grid-column:span 8}.span-6{grid-column:span 6}.span-4{grid-column:span 4}.span-3{grid-column:span 3}
.checks { display:flex; flex-wrap:wrap; gap:14px 22px; margin:16px 0 4px; }
.check { display:flex; align-items:center; gap:8px; font-size:13px; color:#334155; }
.check input { width:auto; }
.btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; border:0; border-radius:9px; padding:12px 18px; background:linear-gradient(135deg,#175cd3,#0e7490); color:#fff; font:inherit; font-weight:800; cursor:pointer; text-decoration:none; }
.btn:hover { filter:brightness(1.04); }
.btn.secondary { background:#eef2f7; color:#243b53; }
.btn.green { background:linear-gradient(135deg,#15803d,#16a34a); }
.btn.ghost { background:transparent; border:1px solid #cbd5e1; color:#243b53; }
.btn.small { padding:8px 12px; font-size:13px; }
.actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:18px; }
.error { background:#fff1f0; border:1px solid #ffd1cc; color:var(--red); border-radius:10px; padding:14px; margin-bottom:16px; white-space:pre-wrap; }
.notice { background:#eafaf0; border:1px solid #c7f0d8; color:#166534; border-radius:10px; padding:14px; margin-bottom:16px; }
.tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px; }
.tab { border:1px solid var(--line); background:#fff; border-radius:999px; padding:7px 12px; font-size:13px; font-weight:700; cursor:pointer; color:#475569; }
.tab.active { background:#175cd3; border-color:#175cd3; color:#fff; }
.tabpane { display:none; }
.tabpane.active { display:block; }
.code { width:100%; min-height:260px; font:12.5px/1.55 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; background:#0f172a; color:#dbeafe; border-radius:12px; padding:14px; border:1px solid #1e293b; }
.preview { border:1px solid var(--line); border-radius:12px; padding:20px; background:#fff; max-height:620px; overflow:auto; }
.preview h2 { margin-top:26px; font-size:22px; }
.preview h3 { margin-top:20px; font-size:17px; }
.preview p { margin:0 0 14px; }
.preview a { color:#175cd3; }
.result-meta { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px; margin:12px 0 18px; }
.meta-box { background:#f8fafc; border:1px solid var(--line); border-radius:10px; padding:12px; }
.meta-box .label { font-size:11px; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); font-weight:800; }
.meta-box .value { margin-top:4px; font-weight:650; overflow-wrap:anywhere; }
.save-box { display:grid; grid-template-columns:1fr 1fr; gap:14px; align-items:end; }
.hint { font-size:12.5px; color:var(--muted); }
.toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:#0f172a; color:#fff; padding:12px 18px; border-radius:10px; font-weight:700; opacity:0; transition:.2s; pointer-events:none; }
.toast.show { opacity:1; }
@media (max-width:800px){ .grid{grid-template-columns:1fr;} .span-12,.span-8,.span-6,.span-4,.span-3{grid-column:span 1;} .hero{flex-direction:column;align-items:flex-start;} .save-box{grid-template-columns:1fr;} }
</style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <div>
            <div class="badges">
                <span class="pill">v10.0 · ONE-FILE STUDIO</span>
                <span class="pill green">AI FIRST</span>
                <span class="pill amber">OMABANG /v1</span>
            </div>
            <h1>AI Content Generator</h1>
            <p>Judul, meta SEO, outline, dan isi artikel dibuat AI. Validasi, sanitasi, cek duplikat, lalu simpan ke CMS.</p>
        </div>
        <div class="actions" style="margin:0;">
            <a class="btn ghost small" href="?diag=ai" target="_blank" rel="noopener">Tes AI</a>
            <?php if (function_exists('admin_url')): ?><a class="btn secondary small" href="<?= h(admin_url('posts')) ?>">Postingan</a><?php endif; ?>
        </div>
    </div>

    <?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?>

    <div class="card">
        <form method="post" id="genForm">
            <input type="hidden" name="action" value="generate">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <div class="grid">
                <label class="field span-8">Keyword utama
                    <input name="keyword" required maxlength="120" value="<?= h($lastForm['keyword']) ?>" placeholder="Contoh: videy viral lokal indo malay">
                    <small>Fokus topik. Boleh 1–6 kata; hindari topik terlarang.</small>
                </label>
                <label class="field span-4">Bahasa
                    <select name="language">
                        <?php foreach ($languages as $k => $v): ?><option value="<?= h($k) ?>" <?= $lastForm['language'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="field span-4">Jenis konten
                    <select name="type">
                        <?php foreach ($contentTypes as $k => $v): ?><option value="<?= h($k) ?>" <?= $lastForm['type'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="field span-4">Tone
                    <select name="tone">
                        <?php foreach ($tones as $k => $v): ?><option value="<?= h($k) ?>" <?= $lastForm['tone'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="field span-4">Panjang
                    <select name="length">
                        <?php foreach ($lengths as $k => $cfg): ?><option value="<?= h($k) ?>" <?= $lastForm['length'] === $k ? 'selected' : '' ?>><?= h($cfg['label']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="field span-6">Audiens
                    <input name="audience" value="<?= h($lastForm['audience']) ?>" maxlength="180">
                    <small>Siapa yang akan membaca? Semakin spesifik semakin baik.</small>
                </label>
                <label class="field span-6">Kategori (opsional)
                    <input name="category" value="<?= h($lastForm['category']) ?>" maxlength="120" placeholder="Videy Viral">
                    <small>Kosongkan untuk memakai kategori dari AI.</small>
                </label>
                <label class="field span-12">Catatan tambahan (opsional)
                    <textarea name="notes" maxlength="1000" placeholder="Contoh: tekankan cara menjelajah dengan aman, hindari klaim angka, sertakan checklist."><?= h($lastForm['notes']) ?></textarea>
                </label>
            </div>
            <div class="checks">
                <label class="check"><input type="checkbox" name="internal_links" value="1" <?= $lastForm['internal_links'] ? 'checked' : '' ?>> Sertakan tautan internal dari artikel yang sudah ada</label>
                <label class="check"><input type="checkbox" name="faq_schema" value="1" <?= $lastForm['faq_schema'] ? 'checked' : '' ?>> Sisipkan FAQ JSON-LD</label>
                <label class="check"><input type="checkbox" name="auto_save" value="1" <?= $lastForm['auto_save'] ? 'checked' : '' ?>> Langsung simpan setelah generate</label>
            </div>
            <div class="grid" style="margin-top:14px;">
                <label class="field span-4">Status simpan
                    <select name="status">
                        <option value="draft" <?= $lastForm['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="published" <?= $lastForm['status'] === 'published' ? 'selected' : '' ?>>Published</option>
                    </select>
                </label>
                <label class="field span-4">Gambar unggulan
                    <select name="image_mode">
                        <option value="auto" <?= $lastForm['image_mode'] === 'auto' ? 'selected' : '' ?>>Otomatis dari uploads/images</option>
                        <option value="none" <?= $lastForm['image_mode'] === 'none' ? 'selected' : '' ?>>Tanpa gambar</option>
                        <option value="manual" <?= $lastForm['image_mode'] === 'manual' ? 'selected' : '' ?>>URL manual</option>
                    </select>
                </label>
                <label class="field span-4">URL gambar (manual)
                    <input name="image_url" value="<?= h($lastForm['image_url']) ?>" placeholder="https://...">
                </label>
            </div>
            <div class="actions">
                <button class="btn" type="submit">Generate dengan AI</button>
                <span class="hint">Biasanya 30–120 detik. Jangan tutup halaman saat proses berjalan.</span>
            </div>
        </form>
        <details style="margin-top:16px;border-top:1px solid var(--line);padding-top:14px;">
            <summary style="cursor:pointer;font-weight:800;color:#334155;">Panduan singkat &amp; konfigurasi API key</summary>
            <div class="hint" style="margin-top:10px;line-height:1.7;">
                <p><strong>Alur:</strong> isi keyword → AI membuat blueprint (judul, meta, slug, outline, FAQ, tags) → AI menulis artikel HTML → validasi + sanitasi + cek duplikat → preview/edit → simpan ke tabel <code>posts</code>.</p>
                <p>File ini menyertakan <em>fallback</em> API key di dalam source agar langsung jalan saat diupload. Untuk produksi, sebaiknya override lewat salah satu cara berikut (prioritas dari atas): <code>OMABANG_API_KEY</code> (environment), <code>../vdey-generator-config.php</code> (di luar web root, paling aman), atau <code>generator-config.php</code> (di root CMS, sudah diblokir <code>.htaccess</code>). Contoh isi file config:</p>
                <pre style="white-space:pre-wrap;background:#0f172a;color:#dbeafe;border-radius:8px;padding:10px;font-size:12px;margin:6px 0;">&lt;?php
return [
    'api_key'  =&gt; 'oma_xxx',
    'model'    =&gt; 'deepseek-v4.1-flash',
    'base_url' =&gt; 'https://ai.omabang.web.id/v1',
    'timeout'  =&gt; 240,
];</pre>
                <p>Kalau key belum diset, tombol <strong>Tes AI</strong> (<code>?diag=ai</code>) akan memberi tahu. Riwayat generate disimpan di <code>storage/generator/history.json</code> (dilindungi <code>.htaccess</code>) dan dipakai untuk deteksi duplikat.</p>
            </div>
        </details>
    </div>

    <?php if (is_array($result) && isset($result['article'], $result['blueprint'])): ?>
        <?php
        $article = $result['article'];
        $bp = $result['blueprint'];
        $saved = $result['saved'] ?? null;
        ?>
        <div class="card">
            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
                <div>
                    <h2 style="margin:0;">Hasil AI</h2>
                    <p class="hint" style="margin:4px 0 0;">Review sebelum dipublikasikan. Kamu bisa edit HTML dan metadata di bawah.</p>
                </div>
                <div class="badges">
                    <span class="pill green"><?= (int) $article['word_count'] ?> kata</span>
                    <span class="pill">duplikat: <?= (int) round(((float) ($article['qa']['duplicate_score'] ?? 0)) * 100) ?>%</span>
                    <span class="pill amber"><?= h((string) ($bp['focus_keyword'] ?? ($article['focus_keyword'] ?? ''))) ?></span>
                </div>
            </div>

            <div class="result-meta">
                <div class="meta-box"><div class="label">Judul</div><div class="value"><?= h($article['title']) ?></div></div>
                <div class="meta-box"><div class="label">SEO title</div><div class="value"><?= h($article['seo_title']) ?></div></div>
                <div class="meta-box"><div class="label">Slug</div><div class="value"><?= h($article['slug']) ?></div></div>
                <div class="meta-box"><div class="label">Kategori</div><div class="value"><?= h($bp['category']) ?></div></div>
            </div>

            <div class="tabs">
                <button type="button" class="tab active" data-tab="tab-preview">Preview</button>
                <button type="button" class="tab" data-tab="tab-html">HTML</button>
                <button type="button" class="tab" data-tab="tab-seo">SEO &amp; Blueprint</button>
            </div>
            <div id="tab-preview" class="tabpane active"><div class="preview"><?= $article['html'] ?></div></div>
            <div id="tab-html" class="tabpane">
                <form method="post" id="saveForm">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="keyword" value="<?= h($result['form']['keyword'] ?? '') ?>">
                    <input type="hidden" name="allowed_urls" value="<?= h(json_encode($result['allowed_urls'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                    <input type="hidden" name="blueprint" value="<?= h(json_encode($bp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                    <textarea class="code" id="articleHtml" name="html" spellcheck="false"><?= h($article['html']) ?></textarea>
                    <div class="actions"><button type="button" class="btn secondary small" onclick="copyFrom('articleHtml', this)">Copy HTML</button></div>
                </form>
            </div>
            <div id="tab-seo" class="tabpane">
                <div class="grid">
                    <label class="field span-12">Judul
                        <input id="postTitle" name="title" form="saveForm" value="<?= h($article['title']) ?>">
                    </label>
                    <label class="field span-12">Meta description
                        <textarea id="metaDescription" name="meta_description" form="saveForm" rows="3"><?= h($article['meta_description']) ?></textarea>
                        <small id="metaCount"><?= engine_text_len($article['meta_description']) ?> karakter (target 140–158)</small>
                    </label>
                    <label class="field span-12">Slug
                        <input id="postSlug" name="slug" form="saveForm" value="<?= h($article['slug']) ?>">
                    </label>
                    <label class="field span-12">Tags
                        <input id="tagInput" name="tags" form="saveForm" value="<?= h(implode(', ', (array) ($bp['tags'] ?? []))) ?>">
                    </label>
                </div>
                <pre style="white-space:pre-wrap;background:#f8fafc;border:1px solid var(--line);border-radius:10px;padding:14px;font-size:12.5px;max-height:380px;overflow:auto;"><?= h(json_encode($bp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></pre>
            </div>

            <?php if ($saved): ?>
                <div class="notice" style="margin-top:16px;">
                    ✅ Disimpan sebagai <strong><?= h($saved['status']) ?></strong> — post #<?= (int) $saved['id'] ?>.
                    <?php if (function_exists('admin_url')): ?><a href="<?= h(admin_url('posts', ['edit' => (int) $saved['id']])) ?>">Edit postingan</a><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="save-box" style="margin-top:18px;">
                    <label class="field">Thumbnail URL
                        <input name="thumbnail" form="saveForm" value="<?= h($result['image_url'] ?? '') ?>" placeholder="https://...">
                    </label>
                    <label class="field">Status
                        <select name="status" form="saveForm">
                            <option value="draft" <?= ($result['form']['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="published" <?= ($result['form']['status'] ?? 'draft') === 'published' ? 'selected' : '' ?>>Published</option>
                        </select>
                    </label>
                </div>
                <div class="actions" style="margin-top:12px;">
                    <button class="btn green" type="submit" form="saveForm">Simpan ke CMS</button>
                    <form method="post" id="regenForm" style="margin:0;">
                        <input type="hidden" name="action" value="generate">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="keyword" value="<?= h($result['form']['keyword'] ?? '') ?>">
                        <input type="hidden" name="language" value="<?= h($result['form']['language'] ?? 'id') ?>">
                        <input type="hidden" name="type" value="<?= h($result['form']['type'] ?? 'guide') ?>">
                        <input type="hidden" name="tone" value="<?= h($result['form']['tone'] ?? 'santai') ?>">
                        <input type="hidden" name="length" value="<?= h($result['form']['length'] ?? 'medium') ?>">
                        <input type="hidden" name="audience" value="<?= h($result['form']['audience'] ?? '') ?>">
                        <input type="hidden" name="notes" value="<?= h($result['form']['notes'] ?? '') ?>">
                        <input type="hidden" name="category" value="<?= h($result['form']['category'] ?? '') ?>">
                        <input type="hidden" name="status" value="<?= h($result['form']['status'] ?? 'draft') ?>">
                        <input type="hidden" name="image_mode" value="<?= h($result['form']['image_mode'] ?? 'auto') ?>">
                        <input type="hidden" name="image_url" value="<?= h($result['form']['image_url'] ?? '') ?>">
                        <?php if (!empty($result['form']['internal_links'])): ?><input type="hidden" name="internal_links" value="1"><?php endif; ?>
                        <?php if (!empty($result['form']['faq_schema'])): ?><input type="hidden" name="faq_schema" value="1"><?php endif; ?>
                        <button class="btn secondary" type="submit">Generate ulang</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<div class="toast" id="toast"></div>
<script>
function copyFrom(id, btn) {
    const el = document.getElementById(id);
    if (!el) return;
    const value = el.value !== undefined ? el.value : el.textContent;
    const done = () => {
        const toast = document.getElementById('toast');
        toast.textContent = 'Berhasil disalin';
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 1600);
        if (btn) { const old = btn.textContent; btn.textContent = 'Tersalin'; setTimeout(() => btn.textContent = old, 1400); }
    };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(value).then(done).catch(() => { el.select(); document.execCommand('copy'); done(); });
    else { el.select(); document.execCommand('copy'); done(); }
}
document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tabpane').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        const pane = document.getElementById(tab.dataset.tab);
        if (pane) pane.classList.add('active');
    });
});
const meta = document.getElementById('metaDescription');
const metaCount = document.getElementById('metaCount');
if (meta && metaCount) {
    const update = () => { metaCount.textContent = meta.value.length + ' karakter (target 140–158)'; };
    meta.addEventListener('input', update); update();
}
</script>
</body>
</html>
