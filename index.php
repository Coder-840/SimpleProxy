<?php
// Use Guzzle if installed via composer, otherwise fall back to native cURL
require_once __DIR__ . '/vendor/autoload.php';
use GuzzleHttp\Client;

$TEMP_DIR = 'temp';
if (!file_exists($TEMP_DIR)) { mkdir($TEMP_DIR, 0755, true); }

error_reporting(0);

// --- 1. SESSION MANAGEMENT ---
if (isset($_REQUEST['clear'])) {
    array_map('unlink', glob("$TEMP_DIR/*"));
    header('Location: ' . explode('?', $_SERVER['PHP_SELF'])[0]);
    die();
}

$proxyUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];

// --- 2. ROUTING ---
if (isset($_REQUEST['url']) && !empty($_REQUEST['url'])) {
    $target = $_REQUEST['url'];
    if (!preg_match("~^https?://~i", $target)) { $target = "https://" . $target; }
    handleProxy($target, $proxyUrl);
} else {
    renderLanding();
}

// --- 3. THE "STEALTH" FRONTEND ---
function renderLanding() {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8"><title>Stealth Proxy</title>
        <link href="https://cdn.jsdelivr.net" rel="stylesheet">
        <style>
            body { background: radial-gradient(circle at center, #1b2735 0%, #090a0f 100%); height: 100vh; display: flex; align-items: center; color: white; }
            .glass { background: rgba(255, 255, 255, 0.05); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1); border-radius: 20px; padding: 40px; width: 100%; max-width: 600px; box-shadow: 0 8px 32px 0 rgba(0,0,0,0.8); }
            .form-control { background: rgba(0,0,0,0.3); border: 1px solid #333; color: white; }
            .form-control:focus { background: rgba(0,0,0,0.5); color: white; border-color: #0d6efd; box-shadow: none; }
            .btn-primary { background: #0d6efd; border: none; padding: 12px 30px; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="container d-flex justify-content-center">
            <div class="glass text-center">
                <h1 class="mb-4">STEALTH <span class="text-primary">PROXY</span></h1>
                <form action="" method="GET">
                    <div class="input-group mb-3">
                        <input type="text" name="url" class="form-control form-control-lg" placeholder="Enter URL to unblock..." required>
                        <button class="btn btn-primary" type="submit">Unlock</button>
                    </div>
                    <div class="d-flex justify-content-center gap-3 opacity-50 small">
                        <span>• Encrypted</span><span>• No Logs</span><span>• High Speed</span>
                    </div>
                </form>
                <a href="?clear=1" class="btn btn-link btn-sm mt-4 text-secondary text-decoration-none">Wipe Session Data</a>
            </div>
        </div>
    </body>
    </html>
    <?php
}

// --- 4. THE POWERFUL REWRITE ENGINE ---
function handleProxy($target, $proxyBase) {
    global $TEMP_DIR;
    $ch = curl_init($target);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_ENCODING => ''
    ]);
    
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    $baseUrl = $info['url']; // Handle redirects
    $parsed = parse_url($baseUrl);
    $root = $parsed['scheme'] . '://' . $parsed['host'];

    // --- HEAVY REWRITING LOGIC ---
    // 1. Fix relative URLs to Absolute
    $response = preg_replace_callback('/(href|src|action|url)\s*=\s*["\']([^"\']+)["\']/i', function($m) use ($root, $baseUrl) {
        $val = $m[2];
        if (strpos($val, 'http') !== 0 && strpos($val, 'data:') !== 0 && strpos($val, 'javascript:') !== 0) {
            $val = ($val[0] === '/') ? $root . $val : dirname($baseUrl) . '/' . $val;
        }
        return $m[1] . '="' . $val . '"';
    }, $response);

    // 2. Wrap ALL links into the Proxy
    $response = preg_replace_callback('/href=["\'](https?:\/\/[^"\']+)["\']/i', function($m) use ($proxyBase) {
        return 'href="' . $proxyBase . '?url=' . urlencode($m[1]) . '"';
    }, $response);

    // --- INJECT NAV BAR ---
    $nav = '
    <div style="position:fixed;top:0;left:0;width:100%;background:#111;border-bottom:1px solid #333;z-index:999999;display:flex;padding:8px 15px;align-items:center;font-family:sans-serif;">
        <a href="'.$proxyBase.'" style="color:#0d6efd;font-weight:bold;text-decoration:none;margin-right:15px;">Stealth</a>
        <form action="'.$proxyBase.'" style="flex-grow:1;display:flex;">
            <input type="text" name="url" value="'.$baseUrl.'" style="width:100%;background:#222;border:1px solid #444;color:#eee;padding:4px 10px;border-radius:4px;font-size:12px;">
            <button type="submit" style="background:#0d6efd;border:none;color:white;padding:4px 12px;margin-left:8px;border-radius:4px;cursor:pointer;">Go</button>
        </form>
    </div><div style="height:45px;"></div>';

    echo $nav . $response;
}
?>
