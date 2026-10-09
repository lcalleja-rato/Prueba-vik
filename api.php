<?php
/* =====================================================================
   VIKY COELHO ACADEMIA · api.php
   Backend de la web y del panel de administración.
   - Guarda productos, stock, pedidos, formaciones, convocatorias y solicitudes
     en un archivo JSON dentro de una carpeta protegida (vc-data/).
   - La web pública lee precios, stock y fechas en vivo.
   - El panel (admin.html) gestiona todo con contraseña.
   - Avisa a Viky por Telegram (con botones para escribir a la clienta por WhatsApp) de pedidos, solicitudes y stock bajo.

   INSTALACIÓN: sube index.html, admin.html y api.php a la misma carpeta.
   Entra en admin.html y crea tu usuario y contraseña (solo la primera vez).
   ===================================================================== */

$CFG = [
  // Acceso al panel: déjalo vacío y créalo la primera vez que entres en admin.html
  // (o rellénalo aquí si prefieres fijarlo en el archivo)
  'admin_user'     => '',
  'admin_password' => '',
  // Dominios desde los que se permite llamar a la API (vacío = el mismo dominio)
  'origins'        => [],
  'data_dir'       => __DIR__ . '/vc-data',
  // Emails (opcional, para más adelante)
  'email_enabled'  => false,
  'from_email'     => 'pedidos@tudominio.com',
  'from_name'      => 'Viky Coelho Academia',
  'viky_email'     => 'Alvescoelho@icloud.com',
];

/* ---------------------------------------------------------------- */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $CFG['origins'], true)) {
  header('Access-Control-Allow-Origin: ' . $origin);
  header('Access-Control-Allow-Headers: Content-Type, Authorization');
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

function out($data, $code = 200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail($msg, $code = 400) { out(['ok' => false, 'error' => $msg], $code); }

/* ---------- Almacenamiento ---------- */
$DIR = $CFG['data_dir'];
if (!is_dir($DIR)) { @mkdir($DIR, 0750, true); }
if (!file_exists($DIR . '/.htaccess')) { @file_put_contents($DIR . '/.htaccess', "Require all denied\n"); }
if (!file_exists($DIR . '/index.html')) { @file_put_contents($DIR . '/index.html', ''); }
$STORE = $DIR . '/store.json';

function emptyStore() {
  return ['products' => [], 'courses' => [], 'sessions' => [], 'orders' => [], 'leads' => [], 'reviews' => [],
          'settings' => ['shippingCost' => 5, 'freeShippingFrom' => 50, 'shipZone' => 'Península y Baleares', 'noShipZips' => '35,38', 'noShipTxt' => 'Canarias', 'payHours' => 24, 'deposit' => 50,
                         'holder' => 'Viky Coelho', 'iban' => '', 'whatsapp' => '34614404353',
                         'shippingTime' => '24–72 h laborables tras el pago', 'pickup' => 'Academia Viky Coelho · Cáceres',
                         'lowStockAlerts' => true, 'followUpDays' => 3, 'reviewUrl' => '', 'googleUrl' => 'https://share.google/zRtM4JBV8rFnpnxxW', 'googleRating' => '', 'googleCount' => '', 'telegramToken' => '', 'telegramChat' => '', 'siteUrl' => ''],
          '_auth' => ['user' => '', 'hash' => '', 'tokens' => []]];
}
function readStore() {
  global $STORE;
  $s = is_file($STORE) ? json_decode((string)file_get_contents($STORE), true) : null;
  $base = emptyStore();
  if (!is_array($s)) return $base;
  foreach ($base as $k => $v) if (!isset($s[$k])) $s[$k] = $v;
  foreach ($base['settings'] as $k => $v) if (!array_key_exists($k, $s['settings'])) $s['settings'][$k] = $v;
  unset($s['settings']['callmebot']);
  return $s;
}
/* Lectura-modificación-escritura atómica */
function tx(callable $fn) {
  global $DIR, $STORE;
  $lock = fopen($DIR . '/.lock', 'c');
  if (!$lock) fail('storage', 500);
  flock($lock, LOCK_EX);
  $s = readStore();
  $res = $fn($s);
  $tmp = $STORE . '.tmp';
  if (file_put_contents($tmp, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false || !rename($tmp, $STORE)) {
    flock($lock, LOCK_UN); fclose($lock); fail('storage', 500);
  }
  // copia de seguridad diaria
  $bk = $DIR . '/backup-' . date('Y-m-d') . '.json';
  if (!is_file($bk)) { @copy($STORE, $bk); foreach (glob($DIR . '/backup-*.json') ?: [] as $f) if (filemtime($f) < time() - 30 * 86400) @unlink($f); }
  flock($lock, LOCK_UN); fclose($lock);
  return $res;
}

/* ---------- Utilidades ---------- */
$raw = defined('VC_TEST_INPUT') ? VC_TEST_INPUT : file_get_contents('php://input');
if (strlen((string)$raw) > 2000000) fail('size', 413);
$IN = json_decode((string)$raw, true);
if (!is_array($IN)) $IN = [];
$A = $_GET['a'] ?? '';

function str($v, $max = 300) { $v = trim((string)$v); $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v); return mb_substr($v, 0, $max); }
function num($v) { return ($v === null || $v === '' || !is_numeric($v)) ? null : round((float)$v, 2); }
function eur($v) { return number_format((float)$v, 2, ',', '.') . ' €'; }
function findIdx($list, $key, $val) { foreach ($list as $i => $x) if (($x[$key] ?? null) === $val) return $i; return -1; }
function ipKey() { return md5(preg_replace('/[^0-9a-f:.]/i', '', $_SERVER['REMOTE_ADDR'] ?? 'x')); }
function rateLimit($name, $max, $secs) {
  global $DIR;
  $f = $DIR . '/rl_' . $name . '_' . ipKey();
  $hits = array_values(array_filter(json_decode((string)@file_get_contents($f), true) ?: [], fn($t) => $t > time() - $secs));
  if (count($hits) >= $max) fail('rate', 429);
  $hits[] = time(); @file_put_contents($f, json_encode($hits));
}
function waPhone($t) { $d = preg_replace('/\D/', '', (string)$t); $d = preg_replace('/^00/', '', $d); return preg_match('/^[6789]\d{8}$/', $d) ? '34' . $d : $d; }
/* ---------- Avisos a Viky por Telegram ---------- */
function tgCall($token, $method, $payload) {
  if (defined('VC_TEST_TG')) {                                         // solo en pruebas: se guarda el envío en un archivo
    file_put_contents(VC_TEST_TG, json_encode(['m' => $method, 'p' => $payload], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    if ($method === 'getUpdates') return ['ok' => true, 'result' => [['message' => ['chat' => ['id' => 123456, 'type' => 'private', 'first_name' => 'Viky']]]]];
    return ['ok' => true, 'result' => []];
  }
  $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
  if (function_exists('curl_init')) {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
    $r = curl_exec($c); $err = curl_error($c);
    if ($r === false) return ['ok' => false, 'description' => 'No se pudo conectar con Telegram' . ($err ? ' (' . $err . ')' : '')];
  } else {
    $r = @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'timeout' => 10, 'ignore_errors' => true]]));
    if ($r === false) return ['ok' => false, 'description' => 'No se pudo conectar con Telegram'];
  }
  $j = json_decode((string)$r, true);
  return is_array($j) ? $j : ['ok' => false, 'description' => 'Respuesta no válida de Telegram'];
}
function tgText($t) { return preg_replace('/\*([^*\n]+)\*/u', '<b>$1</b>', htmlspecialchars($t, ENT_NOQUOTES, 'UTF-8')); }
function tgUrlOk($u) {                                                 // Telegram solo acepta botones con direcciones públicas
  $p = parse_url($u); $h = strtolower($p['host'] ?? '');
  if (!$p || $h === '' || !in_array($p['scheme'] ?? '', ['http', 'https'], true)) return false;
  if ($h === 'localhost' || substr($h, -6) === '.local' || preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|0\.|169\.254\.)/', $h)) return false;
  return strpos($h, '.') !== false;
}
/* Envía un aviso a Viky. $buttons = [[texto, url], ...]. Devuelve true si algún canal lo aceptó; en $error deja el motivo. */
function notify($settings, $text, $buttons = [], &$error = null) {
  $token = trim((string)($settings['telegramToken'] ?? '')); $chat = trim((string)($settings['telegramChat'] ?? ''));
  if ($token !== '' && $chat !== '') {
    $kb = []; $t0 = $text;
    foreach ($buttons as $b) { if (tgUrlOk($b[1])) $kb[] = [['text' => $b[0], 'url' => $b[1]]]; else $t0 .= "\n\n" . $b[0] . ":\n" . $b[1]; }
    $payload = ['chat_id' => $chat, 'text' => tgText($t0), 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($kb) $payload['reply_markup'] = ['inline_keyboard' => $kb];
    $r = tgCall($token, 'sendMessage', $payload);
    if (empty($r['ok']) && $kb) {                                      // si Telegram rechaza algún botón, se reenvía con los enlaces dentro del texto
      $t1 = $t0; foreach ($kb as $row) $t1 .= "\n\n" . $row[0]['text'] . ":\n" . $row[0]['url'];
      $payload['text'] = tgText($t1); unset($payload['reply_markup']);
      $r = tgCall($token, 'sendMessage', $payload);
    }
    if (!empty($r['ok'])) return true;
    $error = (string)($r['description'] ?? 'Telegram no aceptó el mensaje');
  }
  if ($error === null) $error = 'Telegram no está configurado (falta el token o el ID del chat)';
  return false;
}

/* ---------- Autenticación ---------- */
function bearer() {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
  if (!$h && function_exists('getallheaders')) { foreach (getallheaders() as $k => $v) if (strtolower($k) === 'authorization') $h = $v; }
  return preg_match('/Bearer\s+([a-f0-9]{64})/i', $h, $m) ? $m[1] : '';
}
function requireAdmin() {
  $t = bearer(); if (!$t) fail('auth', 401);
  $s = readStore(); $h = hash('sha256', $t);
  if (empty($s['_auth']['tokens'][$h]) || $s['_auth']['tokens'][$h] < time()) fail('auth', 401);
}
function publicStore($s) { unset($s['_auth']); return $s; }

/* =================================================================
   ACCIONES PÚBLICAS
   ================================================================= */
function needsSetup($s) { return empty($s['_auth']['hash']) && $GLOBALS['CFG']['admin_password'] === ''; }
if ($A === 'ping') out(['ok' => true, 'setup' => needsSetup(readStore())]);

if ($A === 'setup') {
  rateLimit('login', 8, 900);
  $u = str($IN['user'] ?? '', 60); $pw = (string)($IN['password'] ?? '');
  if (mb_strlen($u) < 3) fail('user');
  if (strlen($pw) < 8) fail('weak');
  $t = tx(function (&$s) use ($u, $pw) {
    if (!needsSetup($s)) return null;
    $s['_auth']['user'] = $u; $s['_auth']['hash'] = password_hash($pw, PASSWORD_DEFAULT);
    $t = bin2hex(random_bytes(32)); $s['_auth']['tokens'] = [hash('sha256', $t) => time() + 14 * 86400];
    return $t;
  });
  if (!$t) fail('already', 403);
  out(['ok' => true, 'token' => $t]);
}

if ($A === 'public') {
  expireOrders();
  $s = readStore();
  $today = date('Y-m-d');
  $prods = array_values(array_map(fn($p) => [
      'id' => $p['id'], 'cat' => $p['cat'], 'name' => $p['name'], 'desc' => $p['desc'] ?? '', 'price' => $p['price'] ?? null,
      'stock' => $p['stock'] ?? null, 'minStock' => $p['minStock'] ?? 2, 'soldOut' => !empty($p['soldOut']), 'color' => $p['color'] ?? null, 'img' => $p['img'] ?? '', 'active' => true],
    array_filter($s['products'], fn($p) => ($p['active'] ?? true) !== false)));
  $sess = array_values(array_filter($s['sessions'], fn($x) => ($x['date'] ?? '') >= $today && ($x['status'] ?? 'abierta') !== 'cerrada'));
  $st = $s['settings'];
  out(['ok' => true, 'products' => $prods, 'courses' => $s['courses'], 'sessions' => $sess,
       'reviews' => array_values(array_filter($s['reviews'], fn($r) => ($r['active'] ?? true) !== false && !empty($r['text']))),
       'settings' => ['shippingCost' => $st['shippingCost'], 'freeShippingFrom' => $st['freeShippingFrom'], 'payHours' => $st['payHours'],
                      'deposit' => $st['deposit'], 'holder' => $st['holder'], 'whatsapp' => $st['whatsapp'],
                      'shippingTime' => $st['shippingTime'], 'pickup' => $st['pickup'], 'shipZone' => $st['shipZone'], 'noShipZips' => $st['noShipZips'], 'noShipTxt' => $st['noShipTxt'], 'googleUrl' => $st['googleUrl'], 'googleRating' => $st['googleRating'], 'googleCount' => $st['googleCount']]]);
}

if ($A === 'order') {
  expireOrders();
  rateLimit('order', 6, 600);
  if (!empty($IN['web'])) out(['ok' => true]);                       // bot
  $c = $IN['customer'] ?? [];
  $cust = ['name' => str($c['name'] ?? '', 120), 'phone' => str($c['phone'] ?? '', 30), 'email' => str($c['email'] ?? '', 160),
           'addr' => str($c['addr'] ?? '', 200), 'city' => str($c['city'] ?? '', 80), 'zip' => str($c['zip'] ?? '', 12), 'notes' => str($c['notes'] ?? '', 800)];
  $pickup = !empty($IN['pickup']);
  if (!$cust['name'] || !$cust['phone'] || !filter_var($cust['email'], FILTER_VALIDATE_EMAIL)) fail('customer');
  if (!$pickup && (!$cust['addr'] || !$cust['city'] || !$cust['zip'])) fail('address');
  if (!$pickup) {
    $zip = preg_replace('/\s/', '', $cust['zip']);
    if (!preg_match('/^\d{5}$/', $zip)) fail('zip');
    foreach (array_filter(array_map('trim', explode(',', (string)readStore()['settings']['noShipZips']))) as $pre) if (strpos($zip, $pre) === 0) fail('zone');
  }
  $reqItems = array_slice(is_array($IN['items'] ?? null) ? $IN['items'] : [], 0, 60);
  if (!$reqItems) fail('items');

  $result = tx(function (&$s) use ($cust, $pickup, $reqItems) {
    $byId = []; foreach ($s['products'] as $p) $byId[$p['id']] = $p;
    $items = []; $sub = 0.0; $unknown = false;
    foreach ($reqItems as $it) {
      $id = (string)($it['id'] ?? ''); $q = max(1, min(99, (int)($it['qty'] ?? 1)));
      if ($byId) {                                                     // catálogo gestionado desde el panel
        if (!isset($byId[$id]) || ($byId[$id]['active'] ?? true) === false) return ['err' => 'product'];
        $p = $byId[$id];
        if (!empty($p['soldOut']) || (($p['stock'] ?? null) !== null && $q > (int)$p['stock'])) return ['err' => 'stock', 'id' => $id];
        $price = $p['price'] ?? null; $name = $p['name']; $cat = $p['cat'];
      } else {                                                         // sin catálogo cargado: se acepta el de la web
        $price = null; $name = str($it['name'] ?? $id, 120); $cat = str($it['cat'] ?? '', 80);
      }
      if ($price === null) $unknown = true; else $sub += $price * $q;
      $items[] = ['id' => $id, 'name' => $name, 'cat' => $cat, 'qty' => $q, 'price' => $price];
    }
    $st = $s['settings'];
    $ship = $pickup ? 0.0 : $st['shippingCost'];
    if (!$pickup && $st['freeShippingFrom'] !== null && !$unknown && $sub >= $st['freeShippingFrom']) $ship = 0.0;
    $total = ($unknown || $ship === null) ? null : round($sub + $ship, 2);
    do { $code = 'VC-' . date('ymd') . '-' . substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 4); }
    while (findIdx($s['orders'], 'code', $code) >= 0);
    $order = ['code' => $code, 'date' => date('c'), 'status' => 'nuevo', 'customer' => $cust, 'pickup' => $pickup, 'items' => $items,
              'subtotal' => $unknown ? null : round($sub, 2), 'shipping' => $ship, 'total' => $total,
              'sentAt' => null, 'paidAt' => null, 'stockApplied' => false, 'notes' => '', 'rk' => bin2hex(random_bytes(6)),
              'history' => [['t' => date('c'), 'e' => 'Pedido recibido desde la web']]];
    array_unshift($s['orders'], $order);
    $low = syncStock($s, 0);
    return ['order' => $s['orders'][0], 'low' => $low, 'settings' => $st];
  });
  if (!empty($result['err'])) fail($result['err']);
  $o = $result['order']; $st = $result['settings'];
  if (!empty($result['low']) && !empty($st['lowStockAlerts'])) {
    $TL = ['*⚠️ Aviso de stock*', ''];
    foreach ($result['low'] as $pp) $TL[] = '• ' . $pp['name'] . ': ' . ((int)$pp['stock'] <= 0 ? '*AGOTADO*' : 'quedan ' . (int)$pp['stock']);
    notify($st, implode("\n", $TL), [['⚙️ Ver productos', baseUrl() . 'admin.html']]);
  }

  // Aviso a Viky con enlace para enviar el resumen a la clienta
  $L = ['*🛍️ Nuevo pedido · The Professional Edit*', 'Código: *' . $o['code'] . '*', ''];
  foreach ($o['items'] as $it) $L[] = '• ' . $it['qty'] . ' × ' . $it['name'] . ' (' . ($it['price'] === null ? 'A consultar' : eur($it['price'] * $it['qty'])) . ')';
  $L[] = ''; $L[] = 'Entrega: ' . ($o['pickup'] ? 'Recogida' : 'Envío · ' . ($o['shipping'] === null ? 'A confirmar' : eur($o['shipping'])));
  $L[] = '*Total: ' . ($o['total'] === null ? 'A confirmar' : eur($o['total'])) . '*'; $L[] = '';
  $L[] = '*Datos*'; $L[] = $o['customer']['name']; $L[] = '📞 ' . $o['customer']['phone']; $L[] = '✉️ ' . $o['customer']['email'];
  if (!$o['pickup']) $L[] = '📦 ' . $o['customer']['addr'] . ', ' . $o['customer']['zip'] . ' ' . $o['customer']['city'];
  if ($o['customer']['notes']) $L[] = '📝 ' . $o['customer']['notes'];
  $L[] = ''; $L[] = $o['total'] === null ? '⏳ Pon el importe y envíale el resumen a la clienta.' : '⏳ Envíale el resumen a la clienta: tendrá ' . $st['payHours'] . ' h para la transferencia.';
  $wa = notify($st, implode("\n", $L), [['📲 Enviar resumen a la clienta (WhatsApp)', shareUrl($o['rk'])], ['⚙️ Abrir en el panel', baseUrl() . 'admin.html#' . $o['code']]]);

  $mail = false;
  if ($GLOBALS['CFG']['email_enabled']) $mail = sendOrderMail($o, $st);
  out(['ok' => true, 'code' => $o['code'], 'total' => $o['total'], 'wa' => $wa, 'mail' => $mail]);
}

if ($A === 'lead') {
  rateLimit('lead', 6, 600);
  $type = in_array($IN['type'] ?? '', ['reserva', 'interes', 'privada', 'aviso'], true) ? $IN['type'] : 'interes';
  $L = ['id' => 'L' . date('ymdHis') . substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789'), 0, 3), 'date' => date('c'), 'type' => $type, 'status' => 'nuevo',
        'courseId' => str($IN['courseId'] ?? '', 60), 'course' => str($IN['course'] ?? '', 120), 'sessionId' => str($IN['sessionId'] ?? '', 60), 'session' => str($IN['session'] ?? '', 120),
        'name' => str($IN['name'] ?? '', 120), 'phone' => str($IN['phone'] ?? '', 30), 'email' => str($IN['email'] ?? '', 160),
        'availability' => str($IN['availability'] ?? '', 160), 'month' => str($IN['month'] ?? '', 60), 'msg' => str($IN['msg'] ?? '', 1000), 'notes' => '', 'rk' => bin2hex(random_bytes(6))];
  if (!$L['name'] || !$L['phone']) fail('customer');
  $st = tx(function (&$s) use ($L) { array_unshift($s['leads'], $L); return $s['settings']; });
  $title = ['reserva' => '🎓 Nueva pre-reserva de plaza', 'interes' => '📝 Nueva interesada', 'privada' => '✨ Solicitud de clase privada', 'aviso' => '🔔 Quiere que le avises cuando vuelva'][$type];
  $T = ['*' . $title . '*', '', ($type === 'aviso' ? 'Producto: *' : 'Formación: *') . ($L['course'] ?: 'Sin decidir') . '*'];
  if ($L['session']) $T[] = 'Fecha: ' . $L['session'];
  if ($L['availability']) $T[] = 'Disponibilidad: ' . $L['availability'];
  if ($L['month']) $T[] = 'Cuándo: ' . $L['month'];
  array_push($T, '', $L['name'], '📞 ' . $L['phone'], '✉️ ' . $L['email']);
  if ($L['msg']) $T[] = '📝 ' . $L['msg'];
  out(['ok' => true, 'wa' => notify($st, implode("\n", $T), [['📲 Escribirle por WhatsApp', shareUrl($L['rk'])], ['⚙️ Abrir en el panel', baseUrl() . 'admin.html']])]);
}

/* ---------- Enlace corto "pulsa y envía" ---------- */
function baseUrl() {
  $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
  $site = trim((string)(readStore()['settings']['siteUrl'] ?? ''));
  if ($site !== '') return rtrim($site, '/') . '/';                    // dirección fijada a mano en Ajustes
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
  $cands = [];
  if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) $cands[] = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'https') ?: 'https') . '://' . trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
  if (!empty($_SERVER['HTTP_ORIGIN'])) $cands[] = $_SERVER['HTTP_ORIGIN'];          // la web desde la que llega la petición
  if (!empty($_SERVER['HTTP_REFERER'])) { $r = parse_url($_SERVER['HTTP_REFERER']); if (!empty($r['host'])) $cands[] = ($r['scheme'] ?? 'https') . '://' . $r['host'] . (isset($r['port']) ? ':' . $r['port'] : ''); }
  $cands[] = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
  foreach ($cands as $c) if (tgUrlOk($c)) return rtrim($c, '/') . $dir . '/';   // la primera dirección pública
  return rtrim(end($cands), '/') . $dir . '/';
}
function shareUrl($rk) { return baseUrl() . basename($_SERVER['SCRIPT_NAME'] ?? 'api.php') . '?a=r&k=' . $rk; }

function buyerMessage($o, $st) {
  $first = explode(' ', trim($o['customer']['name']))[0];
  $L = ['Hola ' . $first . ' 💅 Soy Viky. ¡Gracias por tu pedido! Aquí tienes el resumen:', '', '*Pedido ' . $o['code'] . '*', ''];
  foreach ($o['items'] as $it) $L[] = '• ' . $it['qty'] . ' × ' . $it['name'] . ($it['price'] === null ? '' : ' — ' . eur($it['price'] * $it['qty']));
  $L[] = '';
  if ($o['subtotal'] !== null) $L[] = 'Subtotal: ' . eur($o['subtotal']);
  $L[] = $o['pickup'] ? 'Entrega: recogida en ' . $st['pickup'] : 'Envío: ' . ($o['shipping'] === null ? '___ €' : ((float)$o['shipping'] == 0 ? 'gratis' : eur($o['shipping'])));
  $L[] = '*Total: ' . ($o['total'] === null ? '___ €' : eur($o['total'])) . '*'; $L[] = '';
  if (!$o['pickup']) { $L[] = '📦 Envío a: ' . $o['customer']['addr'] . ', ' . $o['customer']['zip'] . ' ' . $o['customer']['city']; $L[] = ''; }
  $L[] = '*Para confirmar tu compra*';
  $L[] = 'Haz la transferencia en las próximas *' . (int)$st['payHours'] . ' h* desde este mensaje, con el concepto *' . $o['code'] . '*, y envíame el justificante por aquí. Si tu banco lo permite, mejor transferencia inmediata.';
  $L[] = 'Si no se recibe el pago en ese plazo, el pedido se anula.'; $L[] = '';
  if (!empty($st['iban'])) { $L[] = '*Datos para la transferencia*'; $L[] = 'Titular: ' . $st['holder']; $L[] = 'IBAN: ' . $st['iban']; $L[] = 'Concepto: ' . $o['code']; }
  else $L[] = 'Te paso los datos bancarios 👇';
  return implode("\n", $L);
}
function sesRangeTxt($x) {
  $a = date('d/m/Y', strtotime($x['date']));
  if (!empty($x['endDate']) && $x['endDate'] !== $x['date']) $a .= ' al ' . date('d/m/Y', strtotime($x['endDate']));
  if (!empty($x['days'])) $a .= ' (' . $x['days'] . ')';
  return $a;
}
function leadMessage($l, $s) {
  $st = $s['settings']; $first = explode(' ', trim($l['name']))[0];
  $ci = findIdx($s['courses'], 'id', $l['courseId'] ?? ''); $c = $ci >= 0 ? $s['courses'][$ci] : null;
  $si = findIdx($s['sessions'], 'id', $l['sessionId'] ?? ''); $ses = $si >= 0 ? $s['sessions'][$si] : null;
  $cn = $l['course'] ?: ($c['name'] ?? 'tu formación');
  if (($l['type'] ?? '') === 'aviso') return 'Hola ' . $first . ' 💅 Soy Viky. ¡Ya ha vuelto *' . $cn . '*! ¿Quieres que te reserve uno? Puedes pedirlo en la web o contestarme por aquí.';
  if (($l['type'] ?? '') === 'privada') return 'Hola ' . $first . ' 💅 Soy Viky. He recibido tu solicitud de clase privada de *' . $cn . '*. ¿Te vendría bien el día ___ a las ___? El precio es ' . (isset($c['privatePrice']) && $c['privatePrice'] !== null && $c['privatePrice'] !== '' ? eur($c['privatePrice']) : '___ €') . '.';
  if (($l['type'] ?? '') === 'reserva' || $ses) {
    $dep = (isset($c['deposit']) && $c['deposit'] !== null && $c['deposit'] !== '') ? $c['deposit'] : $st['deposit'];
    $R = ['Hola ' . $first . ' 💅 Soy Viky. ' . (($l['type'] ?? '') === 'reserva' ? '¡Gracias por reservar!' : '¡Ya hay fecha!'), '', '*' . $cn . '*' . ($ses ? ' · ' . sesRangeTxt($ses) . (!empty($ses['time']) ? ', ' . $ses['time'] : '') : ''), '',
          'Para confirmar tu plaza, haz una transferencia de *' . eur($dep) . '* (señal) en las próximas *' . (int)$st['payHours'] . ' h* con el concepto *Reserva ' . $first . '*, y envíame el justificante por aquí. La señal se descuenta del precio total.'];
    if (!empty($st['iban'])) array_push($R, '', '*Datos para la transferencia*', 'Titular: ' . $st['holder'], 'IBAN: ' . $st['iban']); else array_push($R, '', 'Te paso los datos bancarios 👇');
    return implode("\n", $R);
  }
  return 'Hola ' . $first . ' 💅 Soy Viky. Gracias por tu interés en *' . $cn . '*. Te escribo en cuanto abra el próximo grupo. ¡Muy pronto!';
}

if ($A === 'r') {                                   // GET ?a=r&k=TOKEN  → abre WhatsApp con el mensaje preparado
  rateLimit('r', 40, 600);
  $k = preg_replace('/[^a-f0-9]/', '', (string)($_GET['k'] ?? ''));
  if (strlen($k) !== 12) fail('link', 404);
  $url = tx(function (&$s) use ($k) {
    $st = $s['settings'];
    $i = findIdx($s['orders'], 'rk', $k);
    if ($i >= 0) {
      $o = &$s['orders'][$i];
      $msg = buyerMessage($o, $st); $phone = waPhone($o['customer']['phone']);
      if ($o['status'] === 'nuevo' && $o['total'] !== null) {          // al enviar el resumen empieza el plazo de pago
        $o['status'] = 'datos_enviados'; $o['sentAt'] = date('c'); $o['history'][] = ['t' => date('c'), 'e' => 'Resumen enviado a la clienta (enlace)'];
      }
      return 'https://api.whatsapp.com/send?phone=' . $phone . '&text=' . rawurlencode($msg);
    }
    $i = findIdx($s['leads'], 'rk', $k);
    if ($i >= 0) {
      $l = &$s['leads'][$i];
      if (($l['status'] ?? '') === 'nuevo') $l['status'] = ($l['type'] ?? '') === 'reserva' ? 'reservada' : 'contactado';
      return 'https://api.whatsapp.com/send?phone=' . waPhone($l['phone']) . '&text=' . rawurlencode(leadMessage($l, $s));
    }
    return null;
  });
  if (!$url) fail('link', 404);
  if (defined('VC_TEST_INPUT')) out(['ok' => true, 'url' => $url]);      // solo en pruebas
  header_remove('Content-Type'); header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
  header('Location: ' . $url, true, 302); exit;
}

if ($A === 'login') {
  rateLimit('login', 8, 900);
  $pw = (string)($IN['password'] ?? ''); $user = mb_strtolower(trim((string)($IN['user'] ?? '')));
  $res = tx(function (&$s) use ($pw, $user) {
    if (needsSetup($s)) return 'setup';
    if ($s['_auth']['hash']) $okPw = $user === mb_strtolower($s['_auth']['user']) && password_verify($pw, $s['_auth']['hash']);
    else $okPw = $user === mb_strtolower($GLOBALS['CFG']['admin_user']) && $pw !== '' && hash_equals($GLOBALS['CFG']['admin_password'], $pw);
    if (!$okPw) return null;
    $t = bin2hex(random_bytes(32));
    $s['_auth']['tokens'] = array_filter($s['_auth']['tokens'], fn($exp) => $exp > time());
    $s['_auth']['tokens'][hash('sha256', $t)] = time() + 14 * 86400;
    return $t;
  });
  if ($res === 'setup') fail('setup', 403);
  if (!$res) fail('password', 401);
  out(['ok' => true, 'token' => $res]);
}

/* =================================================================
   ACCIONES DEL PANEL (requieren sesión)
   ================================================================= */
requireAdmin();

if ($A === 'all') { expireOrders(); $st = readStore(); $d = publicStore($st); $d['user'] = $st['_auth']['user'] ?: $CFG['admin_user']; out(['ok' => true, 'data' => $d]); }

if ($A === 'logout') {
  $h = hash('sha256', bearer());
  tx(function (&$s) use ($h) { unset($s['_auth']['tokens'][$h]); });
  out(['ok' => true]);
}

if ($A === 'password') {
  $cur = (string)($IN['current'] ?? ''); $new = (string)($IN['new'] ?? ''); $newUser = str($IN['user'] ?? '', 60);
  if (strlen($new) < 8) fail('weak');
  if ($newUser !== '' && mb_strlen($newUser) < 3) fail('user');
  $me = hash('sha256', bearer());
  $ok = tx(function (&$s) use ($cur, $new, $me, $newUser) {
    $okPw = $s['_auth']['hash'] ? password_verify($cur, $s['_auth']['hash']) : hash_equals($GLOBALS['CFG']['admin_password'], $cur);
    if (!$okPw) return false;
    $s['_auth']['hash'] = password_hash($new, PASSWORD_DEFAULT);
    if ($newUser !== '') $s['_auth']['user'] = $newUser; elseif (!$s['_auth']['user']) $s['_auth']['user'] = $GLOBALS['CFG']['admin_user'];
    $s['_auth']['tokens'] = isset($s['_auth']['tokens'][$me]) ? [$me => $s['_auth']['tokens'][$me]] : [];   // cierra otras sesiones
    return true;
  });
  $ok ? out(['ok' => true]) : fail('password', 401);
}

$COLS = ['products' => 'id', 'courses' => 'id', 'sessions' => 'id', 'orders' => 'code', 'leads' => 'id', 'reviews' => 'id'];

if ($A === 'import') {
  tx(function (&$s) use ($IN) {
    foreach (['products', 'courses', 'reviews'] as $col) if (!empty($IN[$col]) && is_array($IN[$col]) && empty($s[$col])) $s[$col] = array_values($IN[$col]);
  });
  out(['ok' => true, 'data' => publicStore(readStore())]);
}

if ($A === 'restore') {
  $d = $IN['data'] ?? null;
  if (!is_array($d)) fail('data');
  tx(function (&$s) use ($d, $COLS) {
    foreach (array_keys($COLS) as $col) if (isset($d[$col]) && is_array($d[$col])) $s[$col] = array_values($d[$col]);
    if (isset($d['settings']) && is_array($d['settings'])) $s['settings'] = array_merge($s['settings'], $d['settings']);
  });
  out(['ok' => true, 'data' => publicStore(readStore())]);
}

if ($A === 'upsert') {
  $col = $IN['col'] ?? ''; $item = $IN['item'] ?? null;
  if (!isset($COLS[$col]) || !is_array($item) || empty($item[$COLS[$col]])) fail('bad');
  $key = $COLS[$col];
  $saved = tx(function (&$s) use ($col, $item, $key) {
    $i = findIdx($s[$col], $key, $item[$key]);
    if ($col === 'reviews') {                                          // limpieza de lo que viene del panel
      foreach (['name' => 80, 'text' => 800, 'courseId' => 60, 'source' => 30, 'when' => 40] as $f => $max) if (array_key_exists($f, $item)) $item[$f] = str($item[$f], $max);
      if (array_key_exists('rating', $item)) $item['rating'] = max(1, min(5, (int)$item['rating']));
    }
    if ($col === 'orders') {                                           // estado y stock solo por order_status
      if ($i < 0) return null;
      $log = isset($item['logEvent']) ? str($item['logEvent'], 200) : '';
      unset($item['status'], $item['stockApplied'], $item['paidAt'], $item['history'], $item['logEvent'], $item['date'], $item['customer']);
      if (isset($item['rating'])) $item['rating'] = max(0, min(5, (int)$item['rating']));
      if (isset($item['feedback'])) $item['feedback'] = str($item['feedback'], 1000);
      if ($log) $s[$col][$i]['history'][] = ['t' => date('c'), 'e' => $log];
    }
    if ($col === 'leads' && $i >= 0) leadSeats($s, $s[$col][$i], $item);
    if ($col === 'leads' && $i < 0) leadSeats($s, ['type' => '', 'status' => '', 'sessionId' => ''], $item);
    if ($i >= 0) { $s[$col][$i] = array_merge($s[$col][$i], $item); return $s[$col][$i]; }
    if (in_array($col, ['products', 'courses', 'sessions'], true)) { $s[$col][] = $item; return $item; }   // catálogo: al final, sin desordenar
    array_unshift($s[$col], $item); return $item;
  });
  $saved ? out(['ok' => true, 'item' => $saved]) : fail('notfound', 404);
}

if ($A === 'delete') {
  $col = $IN['col'] ?? ''; $id = (string)($IN['id'] ?? '');
  if (!isset($COLS[$col]) || $id === '') fail('bad');
  tx(function (&$s) use ($col, $id, $COLS) { $s[$col] = array_values(array_filter($s[$col], fn($x) => ($x[$COLS[$col]] ?? '') !== $id)); });
  out(['ok' => true]);
}

if ($A === 'settings') {
  $in = is_array($IN['settings'] ?? null) ? $IN['settings'] : [];
  $st = tx(function (&$s) use ($in) {
    foreach (['shippingCost', 'freeShippingFrom', 'deposit', 'payHours', 'followUpDays'] as $k) if (array_key_exists($k, $in)) $s['settings'][$k] = num($in[$k]);
    foreach (['holder', 'iban', 'whatsapp', 'shippingTime', 'pickup', 'reviewUrl', 'googleUrl', 'googleRating', 'googleCount', 'telegramToken', 'telegramChat', 'siteUrl', 'shipZone', 'noShipZips', 'noShipTxt'] as $k) if (array_key_exists($k, $in)) $s['settings'][$k] = str($in[$k], 200);
    if (array_key_exists('lowStockAlerts', $in)) $s['settings']['lowStockAlerts'] = (bool)$in['lowStockAlerts'];
    return $s['settings'];
  });
  out(['ok' => true, 'settings' => $st]);
}

if ($A === 'notify_test') {
  $err = null;
  if (notify(readStore()['settings'], "✅ *Prueba de avisos*\nSi ves este mensaje, los avisos de la web funcionan.", [['⚙️ Abrir el panel', baseUrl() . 'admin.html']], $err)) out(['ok' => true]);
  out(['ok' => false, 'error' => 'tg:' . ($err ?: 'Sin canal de avisos')], 400);
}

if ($A === 'telegram_detect') {                                        // encuentra tu chat: primero escribe /start a tu bot
  $token = trim((string)($IN['token'] ?? '')) ?: trim((string)readStore()['settings']['telegramToken']);
  if (!preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token)) out(['ok' => false, 'error' => 'tg:El token no tiene el formato correcto'], 400);
  $r = tgCall($token, 'getUpdates', ['limit' => 30, 'timeout' => 0]);
  if (empty($r['ok'])) out(['ok' => false, 'error' => 'tg:' . ($r['description'] ?? 'Token no válido')], 400);
  $chats = [];
  foreach ($r['result'] as $u) {
    $c = $u['message']['chat'] ?? $u['my_chat_member']['chat'] ?? $u['channel_post']['chat'] ?? null;
    if ($c && !isset($chats[$c['id']])) $chats[$c['id']] = ['id' => (string)$c['id'], 'type' => $c['type'] ?? '', 'name' => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: ($c['title'] ?? ($c['username'] ?? 'Chat'))];
  }
  out(['ok' => true, 'chats' => array_values($chats)]);
}

if ($A === 'order_create') {                                           // pedido creado a mano desde el panel
  $c = is_array($IN['customer'] ?? null) ? $IN['customer'] : [];
  $cust = ['name' => str($c['name'] ?? '', 120), 'phone' => str($c['phone'] ?? '', 30), 'email' => str($c['email'] ?? '', 160),
           'addr' => str($c['addr'] ?? '', 200), 'city' => str($c['city'] ?? '', 80), 'zip' => str($c['zip'] ?? '', 12), 'notes' => str($c['notes'] ?? '', 800)];
  if (!$cust['name']) fail('customer');
  $pickup = !empty($IN['pickup']);
  $items = [];
  foreach (array_slice(is_array($IN['items'] ?? null) ? $IN['items'] : [], 0, 80) as $it) {
    $name = str($it['name'] ?? '', 120); if ($name === '') continue;
    $items[] = ['id' => str($it['id'] ?? '', 80), 'name' => $name, 'cat' => str($it['cat'] ?? '', 80), 'qty' => max(1, min(999, (int)($it['qty'] ?? 1))), 'price' => num($it['price'] ?? null)];
  }
  if (!$items) fail('items');
  $status = in_array($IN['status'] ?? 'nuevo', ['nuevo', 'datos_enviados', 'pagado', 'enviado', 'entregado'], true) ? $IN['status'] : 'nuevo';
  $noStock = !empty($IN['noStock']);
  $dateIn = (string)($IN['date'] ?? ''); $back = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateIn) && strtotime($dateIn) && $dateIn < date('Y-m-d');
  $dateIso = $back ? date('c', strtotime($dateIn . ' 12:00:00')) : date('c');
  $shipIn = array_key_exists('shipping', $IN) ? num($IN['shipping']) : null;
  $res = tx(function (&$s) use ($cust, $pickup, $items, $status, $noStock, $back, $dateIso, $shipIn, $IN) {
    $unknown = false; $sub = 0.0;
    foreach ($items as $it) { if ($it['price'] === null) $unknown = true; else $sub += $it['price'] * $it['qty']; }
    $ship = $pickup ? 0.0 : $shipIn;
    $total = ($unknown || $ship === null) ? null : round($sub + $ship, 2);
    do { $code = 'VC-' . date('ymd', strtotime($dateIso)) . '-' . substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 4); } while (findIdx($s['orders'], 'code', $code) >= 0);
    $order = ['code' => $code, 'date' => $dateIso, 'status' => 'nuevo', 'customer' => $cust, 'pickup' => $pickup, 'items' => $items,
              'subtotal' => $unknown ? null : round($sub, 2), 'shipping' => $ship, 'total' => $total, 'sentAt' => null, 'paidAt' => null,
              'stockApplied' => false, 'noStock' => $noStock, 'manual' => true, 'notes' => str($IN['notes'] ?? '', 800), 'rk' => bin2hex(random_bytes(6)),
              'history' => [['t' => $dateIso, 'e' => 'Pedido creado a mano desde el panel']]];
    array_unshift($s['orders'], $order);
    $low = syncStock($s, 0);
    if ($status !== 'nuevo') {
      $low = uniqLow(array_merge($low, applyOrderStatus($s, 0, $status)));
      if ($back) foreach (['sentAt', 'paidAt', 'shippedAt', 'deliveredAt'] as $k) if (!empty($s['orders'][0][$k])) $s['orders'][0][$k] = $dateIso;
    }
    return ['order' => $s['orders'][0], 'low' => $low, 'settings' => $s['settings']];
  });
  if ($res['low'] && $res['settings']['lowStockAlerts']) {
    $T = ['*⚠️ Aviso de stock*', ''];
    foreach ($res['low'] as $p) $T[] = '• ' . $p['name'] . ': ' . ((int)$p['stock'] <= 0 ? '*AGOTADO*' : 'quedan ' . (int)$p['stock']);
    notify($res['settings'], implode("\n", $T), [['⚙️ Ver productos', baseUrl() . 'admin.html']]);
  }
  out(['ok' => true, 'order' => $res['order'], 'low' => $res['low']]);
}

if ($A === 'order_status') {
  $code = (string)($IN['code'] ?? ''); $status = (string)($IN['status'] ?? '');
  $valid = ['nuevo', 'datos_enviados', 'pagado', 'enviado', 'entregado', 'cancelado'];
  if (!in_array($status, $valid, true)) fail('status');
  $res = tx(function (&$s) use ($code, $status) {
    $i = findIdx($s['orders'], 'code', $code); if ($i < 0) return null;
    $low = applyOrderStatus($s, $i, $status);
    return ['order' => $s['orders'][$i], 'low' => $low, 'settings' => $s['settings']];
  });
  if (!$res) fail('notfound', 404);
  if ($res['low'] && $res['settings']['lowStockAlerts']) {
    $T = ['*⚠️ Aviso de stock*', ''];
    foreach ($res['low'] as $p) $T[] = '• ' . $p['name'] . ': ' . ((int)$p['stock'] <= 0 ? '*AGOTADO*' : 'quedan ' . (int)$p['stock']);
    notify($res['settings'], implode("\n", $T), [['⚙️ Ver productos', baseUrl() . 'admin.html']]);
  }
  out(['ok' => true, 'order' => $res['order'], 'low' => $res['low']]);
}

fail('action', 404);

/* =================================================================
   LÓGICA DE NEGOCIO
   ================================================================= */
/* Cambia el estado de un pedido y ajusta el stock:
   al pasar a pagado/enviado/entregado se descuenta; al cancelar o volver atrás se repone. */
/* El stock se RESERVA en cuanto se hace el pedido y se libera solo si el pedido se cancela. Devuelve los productos que quedan con poco stock. */
function syncStock(&$s, $i) {
  $o = &$s['orders'][$i]; $low = [];
  if (!empty($o['noStock'])) return $low;
  $hold = ($o['status'] ?? 'nuevo') !== 'cancelado';
  if ($hold && empty($o['stockApplied'])) {
    foreach ($o['items'] as $it) {
      $pi = findIdx($s['products'], 'id', $it['id']);
      if ($pi >= 0 && ($s['products'][$pi]['stock'] ?? null) !== null) {
        $before = (int)$s['products'][$pi]['stock'];
        $s['products'][$pi]['stock'] = $before - (int)$it['qty'];
        $min = (int)($s['products'][$pi]['minStock'] ?? 2);
        if (($s['products'][$pi]['stock'] <= $min && $before > $min) || ($s['products'][$pi]['stock'] <= 0 && $before > 0)) $low[] = $s['products'][$pi];
      }
    }
    $o['stockApplied'] = true;
  } elseif (!$hold && !empty($o['stockApplied'])) {
    foreach ($o['items'] as $it) {
      $pi = findIdx($s['products'], 'id', $it['id']);
      if ($pi >= 0 && ($s['products'][$pi]['stock'] ?? null) !== null) $s['products'][$pi]['stock'] = (int)$s['products'][$pi]['stock'] + (int)$it['qty'];
    }
    $o['stockApplied'] = false;
  }
  return uniqLow($low);
}
function uniqLow($low) { $seen = []; return array_values(array_filter($low, function ($p) use (&$seen) { if (isset($seen[$p['id']])) return false; $seen[$p['id']] = 1; return true; })); }
/* Cambia el estado de un pedido (hacia delante o hacia atrás) y ajusta fechas y stock. */
function applyOrderStatus(&$s, $i, $status) {
  $o = &$s['orders'][$i]; $now = date('c');
  $labels = ['nuevo' => 'Nuevo', 'datos_enviados' => 'Resumen enviado a la clienta', 'pagado' => 'Pago recibido', 'enviado' => 'Pedido enviado', 'entregado' => 'Venta finalizada', 'cancelado' => 'Pedido cancelado'];
  $o['status'] = $status;
  $low = syncStock($s, $i);
  if (in_array($status, ['pagado', 'enviado', 'entregado'], true)) { if (empty($o['paidAt'])) $o['paidAt'] = $now; }
  elseif ($status !== 'cancelado') $o['paidAt'] = null;
  if ($status === 'nuevo') $o['sentAt'] = null;
  if ($status === 'datos_enviados') $o['sentAt'] = $now;
  if ($status === 'enviado') $o['shippedAt'] = $now;
  if ($status === 'entregado') { $o['deliveredAt'] = $now; if (empty($o['followUp'])) $o['followUp'] = 'pendiente'; }
  elseif (in_array($status, ['nuevo', 'datos_enviados', 'pagado', 'enviado'], true)) { $o['deliveredAt'] = null; $o['followUp'] = null; $o['archived'] = false; }
  $o['history'][] = ['t' => $now, 'e' => $labels[$status]];
  return $low;
}
/* Si el plazo de pago vence, el pedido se cancela solo y el stock vuelve a estar disponible. */
function expireOrders() {
  $st = readStore(); $limit = max(1, (int)($st['settings']['payHours'] ?? 24)) * 3600; $now = time(); $due = false;
  foreach ($st['orders'] as $o) if (($o['status'] ?? '') === 'datos_enviados' && !empty($o['sentAt']) && strtotime($o['sentAt']) + $limit < $now) { $due = true; break; }
  if (!$due) return;
  $res = tx(function (&$s) use ($limit, $now) {
    $done = [];
    foreach ($s['orders'] as $i => $o) {
      if (($o['status'] ?? '') === 'datos_enviados' && !empty($o['sentAt']) && strtotime($o['sentAt']) + $limit < $now) {
        applyOrderStatus($s, $i, 'cancelado');
        $n = count($s['orders'][$i]['history']); $s['orders'][$i]['history'][$n - 1]['e'] = 'Plazo de pago vencido: pedido cancelado y stock liberado';
        $done[] = $s['orders'][$i]['code'];
      }
    }
    return ['done' => $done, 'settings' => $s['settings']];
  });
  if ($res['done']) notify($res['settings'], "⏰ *Plazo de pago vencido*\n" . implode(', ', $res['done']) . ": cancelado y stock liberado.", [['⚙️ Abrir el panel', baseUrl() . 'admin.html']]);
}
/* Una reserva marcada como pagada ocupa plaza en su convocatoria */
function leadSeats(&$s, $old, $new) {
  $sid = $new['sessionId'] ?? ($old['sessionId'] ?? '');
  if (!$sid || ($old['type'] ?? '') !== 'reserva' && ($new['type'] ?? '') !== 'reserva') return;
  $was = ($old['status'] ?? '') === 'pagada'; $is = ($new['status'] ?? ($old['status'] ?? '')) === 'pagada';
  $oldSid = $old['sessionId'] ?? '';
  if ($was && (!$is || $oldSid !== $sid)) { $j = findIdx($s['sessions'], 'id', $oldSid); if ($j >= 0) $s['sessions'][$j]['booked'] = max(0, (int)$s['sessions'][$j]['booked'] - 1); }
  if ($is && (!$was || $oldSid !== $sid)) { $j = findIdx($s['sessions'], 'id', $sid); if ($j >= 0) $s['sessions'][$j]['booked'] = (int)$s['sessions'][$j]['booked'] + 1; }
}
/* Email de confirmación (se activa con email_enabled) */
function sendOrderMail($o, $st) {
  global $CFG;
  $rows = '';
  foreach ($o['items'] as $it) $rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #eadfd3">' . (int)$it['qty'] . ' × ' . htmlspecialchars($it['name']) . '</td><td align="right" style="padding:8px 0;border-bottom:1px solid #eadfd3">' . ($it['price'] === null ? 'A consultar' : eur($it['price'] * $it['qty'])) . '</td></tr>';
  $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#2B211D"><h2 style="font-family:Georgia,serif;font-weight:300">Gracias por tu pedido</h2>'
        . '<p>Código: <b>' . $o['code'] . '</b></p><table width="100%" style="font-size:14px">' . $rows . '</table>'
        . '<p style="font-size:18px"><b>Total: ' . ($o['total'] === null ? 'te lo confirma Viky' : eur($o['total'])) . '</b></p>'
        . '<p>Viky te enviará por WhatsApp los datos bancarios. Tendrás ' . (int)$st['payHours'] . ' h desde su mensaje para hacer la transferencia con el concepto <b>' . $o['code'] . '</b>.</p></div>';
  $h = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: =?UTF-8?B?" . base64_encode($CFG['from_name']) . '?= <' . $CFG['from_email'] . ">\r\nReply-To: " . $CFG['viky_email'] . "\r\n";
  $a = @mail($o['customer']['email'], '=?UTF-8?B?' . base64_encode('Tu pedido ' . $o['code']) . '?=', $html, $h);
  $b = @mail($CFG['viky_email'], '=?UTF-8?B?' . base64_encode('Nuevo pedido ' . $o['code']) . '?=', $html, $h);
  return $a && $b;
}
