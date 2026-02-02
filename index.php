<?php
session_start();
error_reporting(0);

// --- 1. CONFIGURATION ---
define('SECRET_KEY', 'your-random-32-char-key'); 
$proxyBase = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
$cookieFile = 'temp/sess_' . hash('sha256', session_id() . SECRET_KEY) . '.txt';
if (!file_exists('temp')) { mkdir('temp', 0755); }

// --- 2. ROUTING ---
if (isset($_GET['u'])) {
    $target = base64_decode($_GET['u']);
    handleProxy($target, $proxyBase, $cookieFile);
} elseif (isset($_GET['url'])) {
    header("Location: $proxyBase?u=" . base64_encode($_GET['url']));
} else {
    renderLanding();
}

function handleProxy($target, $proxyBase, $cookieFile) {
    // Decrypt cookies for cURL
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
        CURLOPT_ENCODING       => '', // Handles GZIP/Compression
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = file_get_contents('php://input');
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    // Re-encrypt cookies
    if (file_exists($cookieFile)) {
        $raw = file_get_contents($cookieFile);
        $encrypted = openssl_encrypt($raw, 'aes-256-cbc', SECRET_KEY, 0, substr(hash('sha256', SECRET_KEY), 0, 16));
        file_put_contents($cookieFile, $encrypted);
    }

    // --- 3. DYNAMIC HEADER EMULATION ---
    // Pass original content-type (crucial for images/scripts)
    header('Content-Type: ' . $info['content_type']);
    header('Access-Control-Allow-Origin: *'); // Solve CORS issues
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

    if (strpos($info['content_type'], 'text/html') !== false) {
        echo rewriteHtml($response, $info['url'], $proxyBase);
    } else {
        // Return raw binary data for images/JS/CSS
        echo $response;
    }
}

function rewriteHtml($html, $realUrl, $proxyBase) {
    $parsed = parse_url($realUrl);
    $domain = $parsed['scheme'] . '://' . $parsed['host'];

    // Fix relative paths with <base> tag
    $html = preg_replace('/<head>/i', '<head><base href="'.$domain.'/">', $html, 1);

    // Rewrite href/src/action
    $html = preg_replace_callback('/(href|src|action)=["\']([^"\']+)["\']/i', function($m) use ($proxyBase, $domain, $realUrl) {
        $url = $m[2];
        if (preg_match('/^(data:|#|javascript:)/i', $url)) return $m[0];
        
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = (strpos($url, '/') === 0) ? $domain . $url : rtrim(dirname($realUrl), '/') . '/' . $url;
        }
        return $m[1] . '="' . $proxyBase . '?u=' . base64_encode($url) . '"';
    }, $html);

    // --- 4. THE JAVASCRIPT "MONKEY PATCH" ---
    // Intercepts fetch and XHR background requests
    $patch = '<script>
    (function() {
        const pBase = "'.$proxyBase.'?u=";
        const encode = (u) => u.startsWith("http") ? pBase + btoa(u) : u;

        // Patch Fetch
        const oldFetch = window.fetch;
        window.fetch = function(url, options) {
            if (typeof url === "string") url = encode(url);
            return oldFetch(url, options);
        };

        // Patch XMLHttpRequest
        const oldOpen = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function(method, url) {
            return oldOpen.apply(this, [method, encode(url), ...Array.from(arguments).slice(2)]);
        };
    })();
    </script>';

    return $html . $patch;
}

function renderLanding() { /* Same as previous step */ }
