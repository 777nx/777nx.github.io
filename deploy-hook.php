<?php
/**
 * 宝塔主站 Webhook：校验令牌后，将本目录（站点根）同步为 GitHub Pages 产物仓最新 main。
 *
 * 密钥文件（按优先级尝试；须落在 PHP open_basedir 内）：
 *   1) /home/hexo-deploy/webhook_secret  （推荐：宝塔 open_basedir 加上此目录）
 *   2) /tmp/hexo-deploy-webhook_secret   （兼容默认 open_basedir=/home/hexo/:/tmp/）
 *
 * 首次初始化与权限说明：tools/baota/README.md
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const SITE_ROOT = '/home/hexo';
const SECRET_CANDIDATES = [
    '/home/hexo-deploy/webhook_secret',
    '/tmp/hexo-deploy-webhook_secret',
];
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

function read_secret(): string
{
    $tried = [];
    foreach (SECRET_CANDIDATES as $path) {
        $tried[] = $path;
        // open_basedir 超限时 is_readable 会报警；用 @ 并靠后续错误信息提示
        if (@is_readable($path)) {
            $secret = trim((string) file_get_contents($path));
            if ($secret !== '') {
                return $secret;
            }
        }
    }
    json_out(500, [
        'ok' => false,
        'error' => 'secret_missing',
        'hint' => '在宝塔为站点 open_basedir 增加 /home/hexo-deploy/，或把密钥写入 /tmp/hexo-deploy-webhook_secret',
        'tried' => $tried,
    ]);
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
        'hint' => '按 tools/baota/README.md 将 /home/hexo 初始化为 777nx.github.io 的 clone',
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
            'git clean -fd',
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

json_out(200, [
    'ok' => true,
    'head' => $head,
    'branch' => PAGES_BRANCH,
    'source_sha' => $payload['source_sha'] ?? null,
    'run_id' => $payload['run_id'] ?? null,
]);
