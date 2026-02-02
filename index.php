<?php
error_reporting(0);
$TEMP_DIR = 'temp';
if (!file_exists($TEMP_DIR)) { mkdir($TEMP_DIR, 0755, true); }

$proxyBase = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];

// --- 1. ROUTING & OBFUSCATION ---
if (isset($_GET['u'])) {
    $target = base64_decode($_GET['u']); // Obfuscation bypass
    if (!filter_var($target, FILTER_VALIDATE_URL)) { die("Invalid URL"); }
    handleProxy($target, $proxyBase);
} elseif (isset($_GET['url'])) {
    // If user typed in the landing page, redirect to the obfuscated version
    header("Location: $proxyBase?u=" . base64_encode($_GET['url']));
    die();
} else {
    renderLanding();
}

// --- 2. THE "PERFECT CENTER" FRONTEND ---
function renderLanding() {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Stealth Portal</title>
        <link href="https://cdn.jsdelivr.net" rel="stylesheet">
        <style>
            :root { --accent: #00d2ff; --bg: #0b0e14; }
            body { 
                background: var(--bg); color: white; height: 100vh; margin: 0;
                display: flex; align-items: center; justify-content: center;
                font-family: 'Inter', sans-serif; overflow: hidden;
            }
            /* Animated Background */
            body::before {
                content: ""; position: absolute; width: 200%; height: 200%;
                background: radial-gradient(circle at 50% 50%, #1a202c 0%, transparent 50%);
                animation: rotate 20s linear infinite; z-index: -1;
            }
            @keyframes rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

            .glass-card {
                background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(15px);
                border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 24px;
                padding: 3rem; width: 100%; max-width: 550px; text-align: center;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            }
            .brand { font-size: 2.5rem; font-weight: 900; letter-spacing: -1px; margin-bottom: 2rem; }
            .brand span { color: var(--accent); text-shadow: 0 0 20px var(--accent); }
            
            .input-group { background: rgba(0,0,0,0.2); border-radius: 12px; padding: 5px; border: 1px solid #333; }
            .form-control { background: transparent; border: none; color: white; padding-left: 15px; }
            .form-control:focus { background: transparent; color: white; box-shadow: none; }
            .btn-primary { background: var(--accent); border: none; border-radius: 8px; font-weight: bold; padding: 10px 25px; }
            .btn-primary:hover { background: #00b0d8; }
        </style>
    </head>
    <body>
        <div class="glass-card">
            <h1 class="brand">STEALTH<span>CORE</span></h1>
            <form action="" method="GET">
                <div class="input-group mb-3">
                    <input type="text" name="url" class="form-control form-control-lg" placeholder="https://..." required>
                    <button class="btn btn-primary" type="submit">ENTER</button>
                </div>
                <p class="text-secondary small">URLs are encrypted to bypass network filters.</p>
            </form>
        </div>
    </body>
    </html>
    <?php
}

// --- 3. THE REWRITE ENGINE ---
function handleProxy($target, $proxyBase) {
    $ch = curl_init($target);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/121.0.0.0',
        CURLOPT_ENCODING => ''
    ]);
    
    $html = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    $realUrl = $info['url'];
    $parsed = parse_url($realUrl);
    $domain = $parsed['scheme'] . '://' . $parsed['host'];

    // Inject Base Tag to fix 90% of broken relative links (images, css)
    $headPos = strpos($html, '<head>');
    if ($headPos !== false) {
        $html = substr_replace($html, '<head><base href="'.$domain.'/">', $headPos, 6);
    }

    // Obfuscate every 'href' to point back to our proxy with Base64 encoding
    $html = preg_replace_callback('/href=["\'](https?:\/\/[^"\']+)["\']/i', function($m) use ($proxyBase) {
        return 'href="' . $proxyBase . '?u=' . base64_encode($m[1]) . '"';
    }, $html);

    // Persistent Address Bar
    $nav = '
    <div style="position:fixed;top:0;left:0;width:100%;background:#0b0e14;border-bottom:1px solid #333;z-index:999999;display:flex;padding:5px 15px;align-items:center;height:40px;">
        <a href="'.$proxyBase.'" style="color:#00d2ff;text-decoration:none;font-weight:bold;margin-right:15px;font-family:sans-serif;font-size:12px;">EXIT</a>
        <form action="'.$proxyBase.'" style="flex-grow:1;display:flex;">
            <input type="text" name="url" value="'.$realUrl.'" style="width:100%;background:#1a202c;border:1px solid #333;color:#ccc;padding:2px 10px;border-radius:4px;font-size:11px;">
        </form>
    </div><div style="height:40px;"></div>';

    echo $nav . $html;
}
