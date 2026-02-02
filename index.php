<?php
session_start();
error_reporting(0);

// --- 1. CONFIGURATION ---
// IMPORTANT: Change 'SECRET_KEY' to a long random string
define('SECRET_KEY', 'your-random-32-char-secret-key-here'); 
$proxyBase = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];

// Unique encrypted path for this user's session cookies
$cookieFile = 'temp/sess_' . hash('sha256', session_id() . SECRET_KEY) . '.txt';
if (!file_exists('temp')) { mkdir('temp', 0755); }

// --- 2. ROUTING & DECODING ---
if (isset($_GET['u'])) {
    $target = base64_decode($_GET['u']);
    if (!filter_var($target, FILTER_VALIDATE_URL)) { die("Invalid URL"); }
    handleProxy($target, $proxyBase, $cookieFile);
} elseif (isset($_GET['url'])) {
    header("Location: $proxyBase?u=" . base64_encode($_GET['url']));
} elseif (isset($_GET['logout'])) {
    // Clear session and delete encrypted cookie file
    if (file_exists($cookieFile)) { unlink($cookieFile); }
    session_destroy();
    header("Location: $proxyBase");
} else {
    renderLanding();
}

// --- 3. THE PROXY ENGINE ---
function handleProxy($target, $proxyBase, $cookieFile) {
    // A. Decrypt cookies before cURL reads them
    if (file_exists($cookieFile)) {
        $encrypted = file_get_contents($cookieFile);
        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', SECRET_KEY, 0, substr(hash('sha256', SECRET_KEY), 0, 16));
        file_put_contents($cookieFile, $decrypted);
    }

    $ch = curl_init($target);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false, // Bypass SSL check for target
        CURLOPT_USERAGENT      => $_SERVER['HTTP_USER_AGENT'],
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_ENCODING       => '',
        CURLOPT_TIMEOUT        => 30
    ];

    // B. Handle Login Submissions (POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($_POST);
    }

    curl_setopt_array($ch, $options);
    $html = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    // C. Encrypt cookies again for storage
    if (file_exists($cookieFile)) {
        $raw = file_get_contents($cookieFile);
        $encrypted = openssl_encrypt($raw, 'aes-256-cbc', SECRET_KEY, 0, substr(hash('sha256', SECRET_KEY), 0, 16));
        file_put_contents($cookieFile, $encrypted);
    }

    renderProxiedContent($html, $info['url'], $proxyBase);
}

// --- 4. THE REWRITE & UI ENGINE ---
function renderProxiedContent($html, $realUrl, $proxyBase) {
    $parsed = parse_url($realUrl);
    $domain = $parsed['scheme'] . '://' . $parsed['host'];

    // Inject Base Tag to fix relative assets
    $html = preg_replace('/<head>/i', '<head><base href="'.$domain.'/">', $html, 1);

    // PHP Link Rewriting (href/src attributes)
    $html = preg_replace_callback('/(href|src)=["\']([^"\']+)["\']/i', function($m) use ($proxyBase, $domain, $realUrl) {
        $url = $m[2];
        if (preg_match('/^(data:|#|javascript:)/i', $url)) return $m[0];
        
        // Convert to absolute
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = (strpos($url, '/') === 0) ? $domain . $url : rtrim(dirname($realUrl), '/') . '/' . $url;
        }
        return $m[1] . '="' . $proxyBase . '?u=' . base64_encode($url) . '"';
    }, $html);

    // Persistent Nav Bar
    echo '<div style="position:fixed;top:0;left:0;width:100%;background:#0b0e14;border-bottom:1px solid #333;z-index:999999;display:flex;padding:8px 15px;align-items:center;height:45px;box-sizing:border-box;color:white;font-family:sans-serif;">
        <a href="'.$proxyBase.'?logout=1" style="color:#ff4b2b;text-decoration:none;font-weight:bold;margin-right:15px;font-size:12px;">CLEAR SESSION</a>
        <input type="text" readonly value="'.$realUrl.'" style="flex-grow:1;background:#1a202c;border:1px solid #333;color:#ccc;padding:4px 10px;border-radius:4px;font-size:11px;">
    </div><div style="height:45px;"></div>';

    // JS Safety Net: Intercepts JS-generated clicks
    echo '<script>
        document.addEventListener("click", function(e) {
            var a = e.target.closest("a");
            if (a && a.href && !a.href.includes("u=") && !a.href.startsWith("javascript:")) {
                e.preventDefault();
                window.location.href = "'.$proxyBase.'?u=" + btoa(a.href);
            }
        }, true);
    </script>';

    echo $html;
}

// --- 5. LANDING PAGE ---
function renderLanding() {
    echo '<!DOCTYPE html><body style="background:#0b0e14;color:white;font-family:sans-serif;display:flex;height:100vh;align-items:center;justify-content:center;">
    <form action="" method="GET" style="background:#1a202c;padding:40px;border-radius:15px;border:1px solid #333;text-align:center;">
        <h2 style="color:#00d2ff">STEALTH PORTAL</h2>
        <input type="text" name="url" placeholder="https://..." style="padding:10px;width:300px;border-radius:5px;border:none;">
        <button type="submit" style="padding:10px 20px;background:#00d2ff;border:none;border-radius:5px;cursor:pointer;font-weight:bold;">ENTER</button>
    </form></body></html>';
}
