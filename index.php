<?php
session_start();
error_reporting(0);

// --- CONFIGURATION ---
define('SECRET_KEY', getenv('PROXY_KEY') ?: 'CHANGE_ME_IN_RAILWAY_VARS'); 
$proxyBase = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];

// Railway uses an ephemeral filesystem; /tmp is ideal for session cookies
$cookieFile = '/tmp/sess_' . hash('sha256', session_id() . SECRET_KEY) . '.txt';

// --- ROUTING ---
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

function handleProxy($target, $proxyBase, $cookieFile) {
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
        CURLOPT_AUTOREFERER    => true
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = file_get_contents('php://input');
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if (file_exists($cookieFile)) {
        $raw = file_get_contents($cookieFile);
        $encrypted = openssl_encrypt($raw, 'aes-256-cbc', SECRET_KEY, 0, substr(hash('sha256', SECRET_KEY), 0, 16));
        file_put_contents($cookieFile, $encrypted);
    }

    header_remove('X-Frame-Options');
    header_remove('Content-Security-Policy');
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: ' . $info['content_type']);

    if (strpos($info['content_type'], 'text/html') !== false) {
        echo rewriteHtml($response, $info['url'], $proxyBase);
    } else {
        echo $response;
    }
}

function rewriteHtml($html, $realUrl, $proxyBase) {
    $parsed = parse_url($realUrl);
    $domain = $parsed['scheme'] . '://' . $parsed['host'];
    $html = preg_replace('/<head>/i', '<head><base href="'.$domain.'/">', $html, 1);
    $html = preg_replace_callback('/(href|src|action)=["\']([^"\']+)["\']/i', function($m) use ($proxyBase, $domain, $realUrl) {
        $url = $m[2];
        if (preg_match('/^(data:|#|javascript:)/i', $url)) return $m[0];
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = (strpos($url, '/') === 0) ? $domain . $url : rtrim(dirname($realUrl), '/') . '/' . $url;
        }
        return $m[1] . '="' . $proxyBase . '?u=' . base64_encode($url) . '"';
    }, $html);

    $ui = '
    <div style="position:fixed;top:0;left:0;width:100%;background:#0b0e14;border-bottom:1px solid #333;z-index:999999;display:flex;padding:5px 15px;align-items:center;height:40px;box-sizing:border-box;color:white;font-family:sans-serif;">
        <a href="'.$proxyBase.'?logout=1" style="color:#00d2ff;text-decoration:none;font-weight:bold;margin-right:15px;font-size:11px;">EXIT</a>
        <input type="text" readonly value="'.$realUrl.'" style="flex-grow:1;background:#1a202c;border:1px solid #333;color:#888;padding:4px 10px;border-radius:4px;font-size:10px;">
    </div><div style="height:40px;"></div>
    <script>
    (function() {
        const pBase = "'.$proxyBase.'?u=";
        const encode = (u) => (typeof u === "string" && u.startsWith("http") && !u.includes("u=")) ? pBase + btoa(u) : u;
        const desc = Object.getOwnPropertyDescriptor(HTMLIFrameElement.prototype, "src");
        Object.defineProperty(HTMLIFrameElement.prototype, "src", {
            set: function(v) { desc.set.call(this, encode(v)); },
            get: function() { return desc.get.call(this); }
        });
        const oFetch = window.fetch; window.fetch = (u, o) => oFetch(encode(u), o);
        const oOpen = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function(m, u) { 
            return oOpen.apply(this, [m, encode(u), ...Array.from(arguments).slice(2)]); 
        };
    })();
    </script>';
    return $html . $ui;
}

function renderLanding($proxyBase) {
    ?>
    <!DOCTYPE html>
    <html>
    <head><title>Portal</title><style>
        body { background: #0b0e14; color: white; height: 100vh; margin: 0; display: flex; align-items: center; justify-content: center; font-family: sans-serif; }
        .glass { background: rgba(255,255,255,0.03); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1); border-radius: 20px; padding: 2rem; text-align: center; }
        input { background: #1a202c; border: 1px solid #333; color: white; padding: 10px; border-radius: 5px; width: 250px; }
        button { background: #00d2ff; border: none; padding: 10px 20px; border-radius: 5px; font-weight: bold; cursor: pointer; }
    </style></head>
    <body><div class="glass"><h1>STEALTH<span>CORE</span></h1>
    <form action="" method="GET"><input type="text" name="url" placeholder="https://..." required><button type="submit">GO</button></form>
    </div></body></html>
    <?php
}
