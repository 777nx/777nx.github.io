<?php
/**
 * 宝塔主站 Webhook：校验令牌后，将本目录（站点根）同步为 GitHub Pages 产物仓最新 main，
 * 并在国内机房主动推送 baidu_urls.txt 到百度（失败不阻断部署）。
 *
 * 部署令牌（按优先级尝试；须落在 PHP open_basedir 内）：
 *   1) /home/hexo-deploy/webhook_secret
 *   2) /tmp/hexo-deploy-webhook_secret
 *
 * 百度推送 token（同上优先级）：
 *   1) /home/hexo-deploy/baidu_token
 *   2) /tmp/hexo-deploy-baidu_token
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const SITE_ROOT = '/home/hexo';
const SECRET_CANDIDATES = [
    '/home/hexo-deploy/webhook_secret',
    '/tmp/hexo-deploy-webhook_secret',
];
const BAIDU_TOKEN_CANDIDATES = [
    '/home/hexo-deploy/baidu_token',
    '/tmp/hexo-deploy-baidu_token',
];
// 百度后台示例常带 https://，实际 API 的 site 不能带协议，否则 site init fail
const BAIDU_SITE = 'www.777nx.cn';
const BAIDU_URLS_FILE = SITE_ROOT . '/baidu_urls.txt';
const LOCK_FILE = '/tmp/hexo-deploy.lock';
const PAGES_REMOTE = 'https://github.com/777nx/777nx.github.io.git';
const PAGES_BRANCH = 'main';
const MAX_ATTEMPTS = 5;
const RETRY_SLEEP_SEC = 3;

function json_out(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @param list<string> $candidates */
function read_first_file(array $candidates): ?string
{
    foreach ($candidates as $path) {
        if (@is_readable($path)) {
            $value = trim((string) file_get_contents($path));
            if ($value !== '') {
                return $value;
            }
        }
    }
    return null;
}

function read_secret(): string
{
    $secret = read_first_file(SECRET_CANDIDATES);
    if ($secret === null) {
        json_out(500, [
            'ok' => false,
            'error' => 'secret_missing',
            'hint' => '在宝塔为站点 open_basedir 增加 /home/hexo-deploy/，或把密钥写入 /tmp/hexo-deploy-webhook_secret',
            'tried' => SECRET_CANDIDATES,
        ]);
    }
    return $secret;
}

function run_cmd(string $cmd, string $cwd): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, $cwd, null);
    if (!is_resource($proc)) {
        return ['code' => 127, 'out' => '', 'err' => 'proc_open_failed'];
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return [
        'code' => $code,
        'out' => trim((string) $out),
        'err' => trim((string) $err),
    ];
}

/**
 * 用国内服务器 IP 推送最新链接；失败只记入返回字段，不让整次部署变红。
 *
 * @return array<string, mixed>
 */
function submit_baidu(): array
{
    if (!is_readable(BAIDU_URLS_FILE)) {
        return ['ok' => false, 'error' => 'urls_missing', 'path' => BAIDU_URLS_FILE];
    }
    $urls = trim((string) file_get_contents(BAIDU_URLS_FILE));
    if ($urls === '') {
        return ['ok' => false, 'error' => 'urls_empty'];
    }

    $baiduToken = read_first_file(BAIDU_TOKEN_CANDIDATES);
    if ($baiduToken === null) {
        return [
            'ok' => false,
            'error' => 'token_missing',
            'hint' => '写入 /home/hexo-deploy/baidu_token 或 /tmp/hexo-deploy-baidu_token',
            'tried' => BAIDU_TOKEN_CANDIDATES,
        ];
    }

    $api = 'http://data.zz.baidu.com/urls?site=' . rawurlencode(BAIDU_SITE)
        . '&token=' . rawurlencode($baiduToken);

    if (function_exists('curl_init')) {
        $ch = curl_init($api);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $urls,
            CURLOPT_HTTPHEADER => ['Content-Type: text/plain'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0) {
            return ['ok' => false, 'error' => 'curl_failed', 'detail' => $error];
        }
        $decoded = json_decode((string) $body, true);
        return [
            'ok' => $http === 200 && is_array($decoded) && !isset($decoded['error']),
            'http' => $http,
            'response' => $decoded !== null ? $decoded : $body,
            'url_count' => substr_count($urls, "\n") + 1,
        ];
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: text/plain\r\n",
            'content' => $urls,
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($api, false, $ctx);
    if ($body === false) {
        return ['ok' => false, 'error' => 'http_failed'];
    }
    $decoded = json_decode($body, true);
    return [
        'ok' => is_array($decoded) && !isset($decoded['error']),
        'response' => $decoded !== null ? $decoded : $body,
        'url_count' => substr_count($urls, "\n") + 1,
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$token = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';
$secret = read_secret();
if ($token === '' || !hash_equals($secret, $token)) {
    json_out(401, ['ok' => false, 'error' => 'unauthorized']);
}

if (!is_dir(SITE_ROOT . '/.git')) {
    json_out(500, [
        'ok' => false,
        'error' => 'site_not_git_repo',
        'hint' => '将 /home/hexo 初始化为 777nx.github.io 的 git clone（需含 .git）',
    ]);
}

$lock = fopen(LOCK_FILE, 'c+');
if ($lock === false) {
    json_out(500, ['ok' => false, 'error' => 'lock_open_failed', 'hint' => LOCK_FILE]);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    json_out(409, ['ok' => false, 'error' => 'deploy_in_progress']);
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = [];
}

$logs = [];
$ok = false;
$head = '';

try {
    for ($attempt = 1; $attempt <= MAX_ATTEMPTS; $attempt++) {
        $steps = [
            'git remote set-url origin ' . escapeshellarg(PAGES_REMOTE),
            'git fetch --prune origin',
            'git reset --hard ' . escapeshellarg('origin/' . PAGES_BRANCH),
            // 宝塔会生成带不可删属性的 .user.ini，clean 必须排除，否则 exit 1
            'git clean -fd -e .user.ini',
            'git rev-parse --short HEAD',
        ];
        $failed = false;
        foreach ($steps as $step) {
            $result = run_cmd($step, SITE_ROOT);
            $logs[] = [
                'attempt' => $attempt,
                'cmd' => $step,
                'code' => $result['code'],
                'out' => $result['out'],
                'err' => $result['err'],
            ];
            if ($result['code'] !== 0) {
                $failed = true;
                break;
            }
            if (strpos($step, 'git rev-parse') === 0) {
                $head = $result['out'];
            }
        }
        if (!$failed) {
            $ok = true;
            break;
        }
        if ($attempt < MAX_ATTEMPTS) {
            sleep(RETRY_SLEEP_SEC);
        }
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

if (!$ok) {
    json_out(500, [
        'ok' => false,
        'error' => 'git_sync_failed',
        'source_sha' => $payload['source_sha'] ?? null,
        'logs' => $logs,
    ]);
}

$baidu = submit_baidu();

json_out(200, [
    'ok' => true,
    'head' => $head,
    'branch' => PAGES_BRANCH,
    'source_sha' => $payload['source_sha'] ?? null,
    'run_id' => $payload['run_id'] ?? null,
    'baidu' => $baidu,
]);
