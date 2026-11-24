<?php
error_reporting(0);
$base = "https://www.decompiler.com";
$MAX = 50 * 1024 * 1024; // 50 MB

// ======== ZIP DOWNLOAD ========
if (isset($_GET["download"])) {
    $id = preg_replace("/[^a-f0-9]/", "", $_GET["download"]);
    $fname = basename($_GET["file"] ?? "file.apk");
    $csrf = $_GET["csrf"] ?? "";
    $cookieFile = sys_get_temp_dir() . "/dc_" . $id . ".txt";
    // Agar cookie file nahi hai to pehle result page kholo
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookieFile, CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_USERAGENT => "Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36",
        CURLOPT_TIMEOUT => 120,
    ]);
    // 5 dafa retry - ID zinda hai to haar mat maano
    for ($try=1; $try<=5; $try++) {
        $token = (string)round(microtime(true)*1000);
        curl_setopt_array($ch, [
            CURLOPT_URL => "$base/zip/$id/".urlencode($fname),
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Accept: */*", "Referer: $base/jar/$id/".urlencode($fname), "Origin: $base"],
            CURLOPT_POSTFIELDS => http_build_query(["csrfToken"=>$csrf, "download_token_value_name"=>$token]),
        ]);
        $zipData = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($http == 200 && strlen($zipData) > 1000 && substr($zipData,0,2) === "PK") {
            header("Content-Type: application/zip");
            header("Content-Disposition: attachment; filename=\"".$fname."_Decompiler.zip\"");
            header("Content-Length: ".strlen($zipData));
            echo $zipData; exit;
        }
        sleep(10); // 10 sec timeout - ID zinda hai, dobara try
    }
    curl_close($ch);
    http_response_code(500); die("ZIP abhi bhi khaali hai (22 byte). 2 min baad dobara try karo. ID: $id");
}

// ======== DECOMPILE - POST ========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    header('Content-Type: application/json');
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) { echo json_encode(["status"=>"error","msg"=>"Upload fail"]); exit; }
    if ($_FILES['file']['size'] > $MAX) { echo json_encode(["status"=>"error","msg"=>"File 50MB se bari hai! Max 50 MB allowed hai."]); exit; }
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'apk') { echo json_encode(["status"=>"error","msg"=>"Sirf .apk allowed hai"]); exit; }

    $apkFile = $_FILES['file']['tmp_name'];
    $origName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['file']['name']));
    $cookieFile = sys_get_temp_dir() . "/dc_" . uniqid() . ".txt";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_COOKIEJAR => $cookieFile, CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_USERAGENT => "Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36",
        CURLOPT_TIMEOUT => 120,
    ]);
    // Token
    curl_setopt($ch, CURLOPT_URL, "$base/");
    curl_setopt($ch, CURLOPT_POST, false);
    $html = curl_exec($ch);
    if (!preg_match('/name="csrfToken"\s+value="([^"]+)"/', $html, $m)) { echo json_encode(["status"=>"error","msg"=>"Token nahi mila"]); exit; }
    $csrfToken = $m[1];
    // Upload - Dropzone jaisa EXACT
    curl_setopt_array($ch, [
        CURLOPT_URL => "$base/upload", CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Accept: application/json","Cache-Control: no-cache","X-Requested-With: XMLHttpRequest","Referer: $base/","Origin: $base/"],
        CURLOPT_POSTFIELDS => ["csrfToken"=>$csrfToken, "file"=>new CURLFile($apkFile, "application/vnd.android.package-archive", $origName)]
    ]);
    $response = trim(curl_exec($ch));
    if (strpos($response, "/error") !== false) { echo json_encode(["status"=>"error","msg"=>"Decompiler ne /error diya"]); exit; }
    $resultUrl = $response;
    if (strpos($resultUrl, "http") !== 0) $resultUrl = $base . $resultUrl;
    // Result page
    curl_setopt_array($ch, [CURLOPT_URL=>$resultUrl, CURLOPT_POST=>false, CURLOPT_HTTPHEADER=>["Accept: text/html"]]);
    $resultHtml = curl_exec($ch);
    $serverName = basename(parse_url($resultUrl, PHP_URL_PATH)); // <-- SERVER wala naam, local nahi
    if (empty($serverName)) $serverName = $origName;
    $id = null;
    if (preg_match('/\/zip\/([a-f0-9]+)\//', $resultHtml, $im)) $id = $im[1];
    elseif (preg_match('/\/([a-f0-9]{32})/', $resultUrl, $im)) $id = $im[1];
    $zipCsrf = $csrfToken;
    if (preg_match('/name="csrfToken" value="([^"]+)"/', $resultHtml, $cm)) $zipCsrf = $cm[1];
    // Wait - zippingDone
    $ready = false;
    for ($i=0; $i<60; $i++) {
        curl_setopt_array($ch, [CURLOPT_URL=>"$base/zippingDone/$id", CURLOPT_POST=>false]);
        if (trim(curl_exec($ch)) === '1') { $ready = true; break; }
        sleep(3);
    }
    // Cookie ko temp se save karo taake download me kaam aaye
    $savedCookie = sys_get_temp_dir() . "/dc_" . $id . ".txt";
    @copy($cookieFile, $savedCookie);
    curl_close($ch);
    echo json_encode(["status"=>"ok","filename"=>$origName,"server_name"=>$serverName,"id"=>$id,"result_url"=>$resultUrl,"ready"=>$ready,"csrfToken"=>$zipCsrf], JSON_UNESCAPED_SLASHES);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>APK Decompiler - Max 50 MB</title>
<style>
*{box-sizing:border-box;font-family:Arial,sans-serif}
body{background:#0f172a;color:#fff;display:flex;justify-content:center;padding:20px;margin:0;min-height:100vh}
.box{background:#1e293b;width:100%;max-width:480px;padding:22px;border-radius:16px;margin-top:30px}
h2{text-align:center;margin:0 0 5px} p{text-align:center;color:#94a3b8;font-size:13px;margin:0 0 5px}
.badge{text-align:center;background:#7f1d1d;color:#fca5a5;padding:8px;border-radius:99px;font-size:13px;font-weight:bold;margin:12px 0}
.drop{border:2px dashed #38bdf8;border-radius:12px;padding:28px 10px;text-align:center;cursor:pointer;background:#0f172a}
.drop input{display:none} .drop b{color:#38bdf8}
#fileName{margin:10px 0;font-size:13px;color:#cbd5e1;text-align:center;min-height:18px}
button{width:100%;padding:13px;border:none;border-radius:10px;background:#0284c7;color:#fff;font-size:16px;font-weight:bold;cursor:pointer;margin-top:10px}
button:disabled{background:#475569}
#barWrap{background:#334155;height:10px;border-radius:99px;overflow:hidden;margin-top:15px;display:none}
#bar{height:100%;width:0%;background:#38bdf8}
#status{text-align:center;font-size:13px;margin-top:10px;color:#facc15;min-height:18px}
#result{display:none;margin-top:20px;background:#0f172a;border:1px solid #334155;border-radius:12px;padding:15px}
#result b{font-size:15px}#result span{font-size:12px;color:#94a3b8}
#dlBtn{background:#16a34a}
small{display:block;text-align:center;color:#64748b;font-size:11px;margin-top:12px}
</style></head>
<body><div class="box">
<h2>📦 APK Decompiler</h2>
<p>APK ko Java source me badlo</p>
<div class="badge">⚠️ MAX SIZE: 50 MB - Is se bari file reject ho jayegi</div>
<div class="drop" id="dropBox">APK yahan drop karo ya <b>Click karo</b><br><span style="font-size:12px;color:#94a3b8">Sirf .apk | Max 50 MB</span><input type="file" id="apkInput" accept=".apk"></div>
<div id="fileName"></div>
<button id="decodeBtn">🚀 Decompile Karo</button>
<div id="barWrap"><div id="bar"></div></div>
<div id="status"></div>
<div id="result">
<b id="rName"></b><br><span id="rId"></span><br><span id="rReady"></span><br>
<button id="dlBtn">⬇️ ZIP Download Karo (Max 50 MB)</button>
</div>
<small>Powered by Decompiler.com | Render pe hosted</small>
</div>
<script>
let cur=null;
const inp=document.getElementById('apkInput');
document.getElementById('dropBox').onclick=()=>inp.click();
inp.onchange=()=>{
  const f=inp.files[0]; if(!f) return;
  const mb=(f.size/1024/1024).toFixed(2);
  document.getElementById('fileName').textContent='📄 '+f.name+' ('+mb+' MB / Max 50 MB)';
  if(f.size > 50*1024*1024){ alert('❌ File '+mb+' MB hai! Max 50 MB allowed hai. Choti APK daalo.'); inp.value=''; document.getElementById('fileName').textContent=''; return; }
};
document.getElementById('decodeBtn').onclick=async()=>{
  const file=inp.files[0]; if(!file) return alert('Pehle APK select karo');
  if(file.size > 50*1024*1024) return alert('Max 50 MB!');
  const btn=document.getElementById('decodeBtn'); btn.disabled=true; btn.textContent='⏳ Upload ho raha hai...';
  document.getElementById('status').textContent='Decompiler.com pe upload ho raha hai... (Max 50 MB)';
  document.getElementById('barWrap').style.display='block';
  const fd=new FormData(); fd.append('file',file);
  let p=5; const timer=setInterval(()=>{p=Math.min(p+2,90); document.getElementById('bar').style.width=p+'%';},800);
  try{
    const res=await fetch('index.php',{method:'POST',body:fd});
    const data=await res.json(); clearInterval(timer); document.getElementById('bar').style.width='100%'; cur=data;
    if(data.status!=='ok'){ document.getElementById('status').textContent='❌ '+(data.msg||'Fail'); btn.disabled=false; btn.textContent='🚀 Dobara Try Karo'; return; }
    document.getElementById('status').textContent=data.ready?'✅ Ho gaya! Neeche se ZIP lo':'⚠️ ZIP ban raha hai, thodi der baad Download dabana';
    document.getElementById('result').style.display='block';
    document.getElementById('rName').textContent='📦 '+data.server_name;
    document.getElementById('rId').textContent='ID: '+data.id;
    document.getElementById('rReady').textContent='Ready: '+(data.ready?'Yes ✅':'No - 15 sec wait karke Download dabao');
    btn.disabled=false; btn.textContent='🚀 Dobara Decode Karo';
  }catch(e){ clearInterval(timer); document.getElementById('status').textContent='❌ '+e.message; btn.disabled=false; btn.textContent='🚀 Dobara Try Karo'; }
};
document.getElementById('dlBtn').onclick=()=>{
  if(!cur) return;
  document.getElementById('status').textContent='ZIP download ho raha hai, 5 dafa retry hoga agar khaali aaya...';
  window.location.href='index.php?download='+cur.id+'&file='+encodeURIComponent(cur.server_name)+'&csrf='+encodeURIComponent(cur.csrfToken);
};
</script></body></html>
