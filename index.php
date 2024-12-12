<?php

use GuzzleHttp\Client;

require_once 'vendor/autoload.php';

// 執行 shell 指令
function run(array $commands = []): void
{
    foreach ($commands as $command) {
        echo shell_exec($command);
    }
}

// 從環境變數讀取設定並賦值到變數中
$assignments = [
    'GIT_NAME', // git author name
    'GIT_EMAIL', // git author email
    'CI_API_V4_URL', // GitLab API v4 root URL
    'GITLAB_PAT', // GitLab Personal Access Token，須有 "read_repository"、"write_repository" 權限
];

foreach ($assignments as $assignment) {
    $$assignment = getenv($assignment);

    if (empty($$assignment)) {
        throw new RuntimeException("Missing environment variable \"$assignment\".");
    }
}

// 初始化 Guzzle Http
$http = new Client([
    'base_uri' => sprintf('%s/', rtrim($CI_API_V4_URL, '/')),
    'connect_timeout' => 3.0,
    'timeout' => 5.0,
    'force_ip_resolve' => 'v4',
    'version' => '2.0',
    'headers' => [
        'PRIVATE-TOKEN' => $GITLAB_PAT,
        'User-Agent' => 'Wabow/1.0 SCA (system@wabow.com; +https://www.waca.net/)',
    ],
]);

// 檢查檔案是否存在並且可讀
$file = 'repo.json';

if (! is_file($file) || ! is_readable($file)) {
    throw new RuntimeException("\"$file\" does not exist.");
}

// 讀取檔案並確認是 JSON 格式
$content = file_get_contents($file);

if (! json_validate($content)) {
    throw new RuntimeException("\"$file\" is not a valid JSON.");
}

$repositories = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

// 初始化同步 branch
run([
    "git config user.name \"$GIT_NAME\"",
    "git config user.email \"$GIT_EMAIL\"",
    'git switch --discard-changes develop',
    'git reset --hard HEAD',
]);

// 透過 GitLab API 取得最新套件 lock 檔
foreach ($repositories as ['repo' => $repo, 'branch' => $branch, 'locks' => $locks]) {
    foreach ($locks as $lock) {
        $uri = sprintf(
            'projects/%s/repository/files/%s/raw',
            rawurlencode($repo),
            rawurlencode($lock),
        );

        $path = sprintf('%s/%s/%s', __DIR__, $repo, $lock);

        $dir = dirname($path);

        if (! is_dir($dir) && ! mkdir($dir, recursive: true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create directory: \"$repo\"");
        }

        $payload = $http->get($uri, [
            'query' => ['ref' => $branch],
            'sink' => $path,
        ]);
    }
}

$cleanup = [
    '.gitattributes',
    '.gitlab-ci.yml',
    'composer.json',
    'index.php',
    'repo.json',
];

array_map(static fn (string $path) => @unlink($path), $cleanup);

run([
    'git add --all',
    "git commit --amend --message \"chore: init repo\" --author=\"$GIT_NAME <$GIT_EMAIL>\"",
    'git remote remove ci_origin || true',
    "git remote add ci_origin \"https://oauth2:$GITLAB_PAT@gitlab.wabow.com/waca/sca.git\"",
    'git push --force ci_origin HEAD:develop',
]);
