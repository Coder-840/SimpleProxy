<?php
declare(strict_types=1);
session_start();
error_reporting(0);

/* ---------------- CONFIG ---------------- */

const SECRET_KEY = 'CHANGE_ME';
const COOKIE_DIR = '/tmp';

$SELF = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://')
      . $_SERVER['HTTP_HOST']
      . strtok($_SERVER['REQUEST_URI'], '?');

$cookieFile = COOKIE_DIR . '/proxy_' . hash('sha256', session_id() . SECRET_KEY);

/* ---------------- ROUTING ---------------- */

if (isset($_GET['url'])) {
    header('Location: ' . $SELF . '?u=' . base64_encode($_GET['url']));
    exit;
}

if (!isset($_GET['u'])) {
    renderHome();
    exit;
}

$target = base64_decode($_GET['u'], true);
if (!$target || !filter_var($target, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit('Invalid URL');
}

proxy($target);

/* ---------------- CORE ---------------- */

function proxy(string $url): void
{
    global $cookieFile, $SELF;

    decryptCookies();

    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Dest: document'
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_ENCODING       => '',
        CURLOPT_TIMEOUT        => 30
    ]);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
    }

    $raw = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    encryptCookies();

    $headerSize = $info['header_size'];
    $headersRaw = substr($raw, 0, $headerSize);
    $body       = substr($raw, $headerSize);

    if (in_array($info['http_code'], [301,302,303,307,308])) {
        if (preg_match('/Location:\s*(.+)/i', $headersRaw, $m)) {
            $loc = trim($m[1]);
            if (!preg_match('#^https?://#', $loc)) {
                $loc = resolveUrl($url, $loc);
            }
            header('Location: ' . $SELF . '?u=' . base64_encode($loc));
            exit;
        }
    }

    header_remove('Content-Security-Policy');
    header_remove('X-Frame-Options');
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: ' . ($info['content_type'] ?? 'text/plain'));

    if (stripos($info['content_type'] ?? '', 'text/html') !== false) {
        echo rewriteHtml($body, $url);
    } else {
        echo $body;
    }
}

/* ---------------- REWRITE ---------------- */

function rewriteHtml(string $html, string $base): string
{
    global $SELF;

    $html = preg_replace_callback(
        '/(href|src|action)=["\']([^"\']+)["\']/i',
        function ($m) use ($base, $SELF) {
            $u = $m[2];
            if (preg_match('#^(data:|javascript:|#)#i', $u)) return $m[0];
            $abs = preg_match('#^https?://#i', $u) ? $u : resolveUrl($base, $u);
            return $m[1] . '="' . $SELF . '?u=' . base64_encode($abs) . '"';
        },
        $html
    );

    return $html . injectJS();
}

/* ---------------- JS ---------------- */

function injectJS(): string
{
    global $SELF;
    return <<<HTML
<script>
(()=> {
  const P="{$SELF}?u=";
  const e=u=>typeof u==="string"&&u.startsWith("http")?P+btoa(u):u;

  const hook=(o,p)=>{
    const d=Object.getOwnPropertyDescriptor(o.prototype,p);
    if(!d||!d.set)return;
    Object.defineProperty(o.prototype,p,{
      set(v){d.set.call(this,e(v));},
      get(){return d.get.call(this);}
    });
  };

  hook(HTMLAnchorElement,"href");
  hook(HTMLFormElement,"action");
  hook(HTMLIFrameElement,"src");
  hook(HTMLImageElement,"src");
  hook(HTMLScriptElement,"src");

  const f=fetch;
  fetch=(u,o)=>f(e(u),o);

  const o=XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open=function(m,u){return o.call(this,m,e(u),...arguments)};

  history.pushState=((p)=>function(s,t,u){return p.call(this,s,t,e(u))})(history.pushState);
  history.replaceState=((p)=>function(s,t,u){return p.call(this,s,t,e(u))})(history.replaceState);

  location.assign=((a)=>u=>a.call(location,e(u)))(location.assign);
})();
</script>
HTML;
}

/* ---------------- UTILS ---------------- */

function resolveUrl(string $base, string $rel): string
{
    $p = parse_url($base);
    return ($p['scheme'] ?? 'http') . '://' . $p['host'] . '/' . ltrim($rel, '/');
}

function encryptCookies(): void
{
    global $cookieFile;
    if (!file_exists($cookieFile)) return;
    file_put_contents(
        $cookieFile,
        openssl_encrypt(file_get_contents($cookieFile), 'aes-256-cbc', SECRET_KEY, 0, substr(hash('sha256', SECRET_KEY), 0, 16))
    );
}

function decryptCookies(): void
{
    global $cookieFile;
    if (!file_exists($cookieFile)) return;
    file_put_contents(
        $cookieFile,
        openssl_decrypt(file_get_contents($cookieFile), 'aes-256-cbc', SECRET_KEY, 0, substr(hash('sha256', SECRET_KEY), 0, 16))
    );
}

function renderHome(): void
{
    echo <<<HTML
<!doctype html>
<html>
<body style="background:#0b0e14;color:white;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh">
<form>
<input name="url" placeholder="https://example.com" style="padding:10px;width:260px">
<button>Go</button>
</form>
</body>
</html>
HTML;
}
