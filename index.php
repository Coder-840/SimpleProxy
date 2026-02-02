<?php
$TEMP_DIR = 'temp';
if (!file_exists($TEMP_DIR)) {
    mkdir($TEMP_DIR, 0755, true);
}

error_reporting(0);

// --- 1. SESSION CLEAR LOGIC ---
if (isset($_REQUEST['clear']) && $_REQUEST['clear'] == 'clear') {
    clearTemp(true);
    header('Location: ' . $_SERVER['PHP_SELF']);
    die();
} else {
    clearTemp(false);
}

// --- 2. ROUTING LOGIC ---
if (isset($_REQUEST['url']) && !empty($_REQUEST['url'])) {
    $url = $_REQUEST['url'];
    $encode = (isset($_REQUEST['encode']) && $_REQUEST['encode'] == 'yes') ? 'yes' : 'no';
    $full = (isset($_REQUEST['full']) && $_REQUEST['full'] == 'yes') ? 'yes' : 'no';
    $fixhref = (isset($_REQUEST['fixhref']) && $_REQUEST['fixhref'] == 'yes') ? 'yes' : 'no';
    loadPage($url, $encode, $full, $fixhref);
} else {
    // RENDER LANDING PAGE FRONTEND
    renderFrontend();
}

// --- 3. FRONTEND RENDER FUNCTION ---
function renderFrontend() {
    global $TEMP_DIR;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>PHP Proxy Portal</title>
        <link href="https://cdn.jsdelivr.net" rel="stylesheet">
        <style>
            body { background: #f0f2f5; min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: sans-serif; }
            .proxy-card { background: #fff; padding: 3rem; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); width: 100%; max-width: 700px; }
            .brand { color: #0d6efd; font-weight: 800; font-size: 2.5rem; text-align: center; margin-bottom: 2rem; }
        </style>
    </head>
    <body>
        <div class="proxy-card">
            <h1 class="brand">PHP Proxy</h1>
            <form method="get" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                <div class="input-group input-group-lg mb-4">
                    <input type="text" name="url" class="form-control" placeholder="Enter website URL (e.g., wikipedia.org)" required>
                    <button class="btn btn-primary px-4" type="submit">Go</button>
                </div>
                
                <div class="row g-3 mb-4 text-secondary">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="full" value="yes" id="full" <?php echo is_dir($TEMP_DIR) ? '' : 'disabled'; ?>>
                            <label class="form-check-label" for="full">Proxy full resources (images/CSS)</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="encode" value="yes" id="encode" checked>
                            <label class="form-check-label" for="encode">Auto-fix encoding (UTF-8)</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="fixhref" value="yes" id="fixhref" checked>
                            <label class="form-check-label" for="fixhref">Rewriting links (Stay in proxy)</label>
                        </div>
                    </div>
                </div>

                <div class="text-center">
                    <button type="button" class="btn btn-link btn-sm text-danger" onclick="if(confirm('Clear all stored cookies and temp files?')){window.location.href='?clear=clear';}">Clear Session Data</button>
                </div>
            </form>
        </div>
    </body>
    </html>
    <?php
}

// --- 4. CORE PROXY LOGIC ---
function loadPage($targetUrl, $encode, $full, $fixhref) {
    global $TEMP_DIR;
    $localHttpProtocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') ? 'https://' : 'http://';
    
    if (!preg_match("/^(?:https?:\\/\\/).+/i", $targetUrl)) { $targetUrl = 'http://' . $targetUrl; }
    $targetUrl = preg_replace("/([^:\\/]+?)(\\/\\/+)/i", "$1/", $targetUrl);
    
    preg_match("/^(?:https?:\\/\\/)(?:.+\\/|.+)/i", $targetUrl, $m); $basicTargetUrl = $m[0];
    preg_match("/^(?:https?:\\/\\/)((?:(?!\\/).)+)[\\/]?/i", $basicTargetUrl, $m2); $veryBasicTargetLocalUrl = $m2[1];
    $proxyScriptUrl = $localHttpProtocol . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];

    $cookieFile = $TEMP_DIR . '/CURLCOOKIE_' . urlencode($veryBasicTargetLocalUrl) . ".txt";
    $UAChrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $targetUrl);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, $UAChrome);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Crucial for modern sites
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $html = curl_exec($ch);
    curl_close($ch);

    // --- RESOURCE & LINK FIXING ---
    if ($full == 'yes') {
        $resPattern = "/<.+?(?:src=|href=|url)['\"\\(]?((?:(?![>'\"]).)*?\\.(?:jpg|jpeg|png|gif|bmp|ico|js|css))['\"\\)]?.*?(?:\\/>|>)/i";
        preg_match_all($resPattern, $html, $matchReses);
        for ($i = 0; $i < count($matchReses[0]); $i++) {
            if (empty($matchReses[1][$i])) continue;
            $newResPath = downloadToTemp($matchReses[1][$i], $basicTargetUrl, $TEMP_DIR, $proxyScriptUrl);
            $html = str_replace($matchReses[1][$i], $newResPath, $html);
        }
    }

    if ($fixhref == 'yes') {
        $html = preg_replace('/href=([\'"])(?!(?:https?:\/\/|javascript:))/', 'href=$1' . $basicTargetUrl . '/', $html);
    }

    // Rewrite links to go through proxy
    $html = preg_replace('/href=[\'"](https?:\/\/[^\'"]+)[\'"]/', 'onclick="window.location.href=\'' . $proxyScriptUrl . '?url=\'+escape(\'$1\')+\'&encode=' . $encode . '&fixhref=' . $fixhref . '&full=' . $full . '\';return false;" href="$1"', $html);

    // --- INJECT ADDRESS BAR ---
    $navBar = '
    <div id="proxy-toolbar" style="position:fixed;top:0;left:0;width:100%;height:45px;background:#212529;color:#fff;z-index:2147483647;display:flex;align-items:center;padding:0 15px;box-shadow:0 2px 10px rgba(0,0,0,0.3);font-family:sans-serif;">
        <a href="' . $proxyScriptUrl . '" style="color:#0d6efd;font-weight:bold;text-decoration:none;margin-right:20px;">ProxyHome</a>
        <form method="get" action="' . $_SERVER['PHP_SELF'] . '" style="flex-grow:1;display:flex;align-items:center;">
            <input type="text" name="url" value="' . $targetUrl . '" style="flex-grow:1;height:28px;border-radius:4px;border:1px solid #444;background:#333;color:#fff;padding:0 10px;font-size:13px;">
            <input type="hidden" name="encode" value="' . $encode . '"><input type="hidden" name="full" value="' . $full . '"><input type="hidden" name="fixhref" value="' . $fixhref . '">
            <button type="submit" style="height:28px;margin-left:10px;background:#0d6efd;border:none;color:#fff;border-radius:4px;padding:0 15px;cursor:pointer;font-size:12px;">Browse</button>
        </form>
    </div><div style="height:45px;"></div>';

    header('Content-Security-Policy: upgrade-insecure-requests');
    echo $navBar;
    echo ($encode == 'yes') ? changeEncoding($html) : $html;
}

// --- HELPER FUNCTIONS ---
function downloadToTemp($fileUrl, $basicTargetUrl, $tempDir, $proxyScriptUrl) {
    if (preg_match("/^\\/\\//", $fileUrl)) { $fileUrl = 'http:' . $fileUrl; }
    elseif (!preg_match("/^https?:/i", $fileUrl)) { $fileUrl = rtrim($basicTargetUrl, '/') . '/' . ltrim($fileUrl, '/'); }
    
    $ext = pathinfo(parse_url($fileUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'tmp';
    $tempFilename = md5($fileUrl) . '.' . $ext;
    $localPath = $tempDir . '/' . $tempFilename;

    if (!file_exists($localPath)) {
        $content = @file_get_contents($fileUrl);
        if ($content) { file_put_contents($localPath, $content); }
        else { return $fileUrl; }
    }
    return dirname($proxyScriptUrl) . '/' . $localPath;
}

function clearTemp($clearCookies) {
    global $TEMP_DIR;
    $files = glob($TEMP_DIR . '/*');
    foreach ($files as $file) {
        if (strpos($file, "CURLCOOKIE_") !== false && !$clearCookies) continue;
        @unlink($file);
    }
}

function changeEncoding($text) {
    $type = mb_detect_encoding($text, array('UTF-8', 'ASCII', 'GBK', 'ISO-8859-1'));
    return ($type == 'UTF-8') ? $text : mb_convert_encoding($text, "UTF-8", $type);
}
?>
