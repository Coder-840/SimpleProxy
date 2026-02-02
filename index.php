<?php
session_start();
error_reporting(0);

// --- 1. CONFIG ---
define('SECRET_KEY', 'C0re_St3alth_99_X!'); // Change this for real encryption
$proxyBase = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
$cookieFile = 'temp/sess_' . hash('sha256', session_id() . SECRET_KEY) . '.txt';
if (!file_exists('temp')) { mkdir('temp', 0755, true); }

// --- 2. ROUTING ---
if (isset($_GET['u'])) {
    $target = base64_decode($_GET['u']);
    if (!filter_var($target, FILTER_VALIDATE_URL)) { die("Invalid URL"); }
    handleProxy($target, $proxyBase, $cookieFile);
} elseif (isset($_GET['url'])) {
    header("Location: $proxyBase?u=" . base64_encode($_GET['url']));
} elseif (isset($_GET['logout'])) {
    if (file_exists($cookieFile)) { unlink($cookieFile); }
    session_destroy();
    header("Location: $proxyBase");
} else {
    renderLanding($proxyBase);
}

// --- 3. THE ENGINE ---
function handleProxy($target, $proxyBase, $cookieFile) {
    // Decrypt Cookie Jar
    if (file_exists($cookieFile)) {
        $encrypted = file_get_contents($cookieFile);
        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', SECRET_KEY, 0, substr(hash('sha256', SECRET_KEY), 0, 16));
        file_put_contents($cookieFile, $decrypted);
    }

    $ch = curl_init($target);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => $_SERVER['HTTP_USER_AGENT'],
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_ENCODING       => '', 
        CURLOPT_TIMEOUT        => 30
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = file_get_contents('php://input');
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    // Re-encrypt Cookie Jar
    if (file_exists($cookieFile)) {
        $raw = file_get_contents($cookieFile);
        $encrypted = openssl_encrypt($raw, 'aes-256-cbc', SECRET_KEY, 0, substr(hash('sha256', SECRET_KEY), 0, 16));
        file_put_contents($cookieFile, $encrypted);
    }

    // Pass Correct Content-Type & Fix CORS for Games/Assets
    header('Content-Type: ' . $info['content_type']);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

    if (strpos($info['content_type'], 'text/html') !== false) {
        echo rewriteHtml($response, $info['url'], $proxyBase);
    } else {
        echo $response;
    }
}

// --- 4. REWRITE & JS PATCH ---
function rewriteHtml($html, $realUrl, $proxyBase) {
    $parsed = parse_url($realUrl);
    $domain = $parsed['scheme'] . '://' . $parsed['host'];

    // Base Tag for relative assets
    $html = preg_replace('/<head>/i', '<head><base href="'.$domain.'/">', $html, 1);

    // Attribute Rewriter
    $html = preg_replace_callback('/(href|src|action)=["\']([^"\']+)["\']/i', function($m) use ($proxyBase, $domain, $realUrl) {
        $attr = $m[1]; $url = $m[2];
        if (preg_match('/^(data:|#|javascript:)/i', $url)) return $m[0];
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = (strpos($url, '/') === 0) ? $domain . $url : rtrim(dirname($realUrl), '/') . '/' . $url;
        }
        return $attr . '="' . $proxyBase . '?u=' . base64_encode($url) . '"';
    }, $html);

    // UI Bar + JS Monkey Patch for background Game Requests (Fetch/XHR)
    $injection = '
    <div style="position:fixed;top:0;left:0;width:100%;background:#0b0e14;border-bottom:1px solid #333;z-index:999999;display:flex;padding:5px 15px;align-items:center;height:40px;box-sizing:border-box;color:white;font-family:sans-serif;">
        <a href="'.$proxyBase.'?logout=1" style="color:#00d2ff;text-decoration:none;font-weight:bold;margin-right:15px;font-size:11px;letter-spacing:1px;">EXIT PORTAL</a>
        <input type="text" readonly value="'.$realUrl.'" style="flex-grow:1;background:#1a202c;border:1px solid #333;color:#888;padding:4px 10px;border-radius:4px;font-size:10px;outline:none;">
    </div><div style="height:40px;"></div>
    <script>
    (function() {
        const pBase = "'.$proxyBase.'?u=";
        const encode = (u) => (typeof u === "string" && u.startsWith("http") && !u.includes("u=")) ? pBase + btoa(u) : u;

        // Catch background Game Data
        const oFetch = window.fetch;
        window.fetch = function(u, o) { return oFetch(encode(u), o); };
        const oOpen = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function(m, u) { 
            return oOpen.apply(this, [m, encode(u), ...Array.from(arguments).slice(2)]); 
        };

        // Catch Dynamic Link Clicks
        document.addEventListener("click", e => {
            const a = e.target.closest("a");
            if (a && a.href && !a.href.includes("u=") && a.href.startsWith("http")) {
                e.preventDefault(); window.location.href = encode(a.href);
            }
        }, true);
    })();
    </script>';

    return $html . $injection;
}

// --- 5. THE FRONTEND ---
function renderLanding($proxyBase) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8"><title>StealthCore | Portal</title>
        <style>
            :root { --accent: #00d2ff; --bg: #0b0e14; }
            body { background: var(--bg); color: white; height: 100vh; margin: 0; display: flex; align-items: center; justify-content: center; font-family: "Segoe UI", sans-serif; overflow: hidden; }
            body::before { content: ""; position: absolute; width: 200%; height: 200%; background: radial-gradient(circle at 50% 50%, #1a202c 0%, transparent 50%); animation: rot 20s linear infinite; z-index: -1; }
            @keyframes rot { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
            .glass { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(15px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 24px; padding: 3rem; width: 450px; text-align: center; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); }
            h1 { font-weight: 900; letter-spacing: -1px; margin-bottom: 2rem; font-size: 2.2rem; }
            h1 span { color: var(--accent); text-shadow: 0 0 15px var(--accent); }
            .box { background: rgba(0,0,0,0.3); border-radius: 12px; padding: 6px; border: 1px solid #333; display: flex; }
            input { background: transparent; border: none; color: white; padding: 10px 15px; flex-grow: 1; outline: none; font-size: 14px; }
            button { background: var(--accent); border: none; border-radius: 8px; font-weight: bold; padding: 10px 20px; cursor: pointer; transition: 0.3s; }
            button:hover { background: white; transform: scale(1.05); }
        </style>
    </head>
    <body>
        <div class="glass">
            <h1>STEALTH<span>CORE</span></h1>
            <form action="" method="GET">
                <div class="box">
                    <input type="text" name="url" placeholder="Enter target URL..." required>
                    <button type="submit">GO</button>
                </div>
            </form>
            <p style="font-size: 11px; color: #666; margin-top: 20px;">Session Cookies Encrypted (AES-256). JS-Network Patch Active.</p>
        </div>
    </body>
    </html>
    <?php
}
