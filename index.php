<?php
// =================================================================================
// Smart IPTV Panel - ULTRA Source Resolver - PRO MAX V6 (Series Fix & Direct Path)
// =================================================================================
error_reporting(0); ini_set('display_errors', 0); set_time_limit(0);
session_start();
ini_set('memory_limit', '-1'); 
header("Access-Control-Allow-Origin: *");

if (!file_exists('get.php')) file_put_contents('get.php', '<?php require "index.php"; ?>');
if (!file_exists('play.php')) file_put_contents('play.php', '<?php require "index.php"; ?>');
if (!file_exists('player_api.php')) file_put_contents('player_api.php', '<?php require "index.php"; ?>');

$htaccess = "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^(live|movie|series)/([^/]+)/([^/]+)/([^/]+)$ index.php?xtream_play=$1&u=$2&p=$3&id=$4 [QSA,L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^([^/]+)/([^/]+)/([^/]+)$ index.php?xtream_play=live&u=$1&p=$2&id=$3 [QSA,L]\n</IfModule>";
if (!file_exists('.htaccess')) file_put_contents('.htaccess', $htaccess);

function encode_base64_url($string) { return rtrim(strtr(base64_encode($string), '+/', '-_'), '='); }

function api_req($host, $user, $pass, $action) {
    $url = rtrim($host, '/') . "/player_api.php?username={$user}&password={$pass}&action={$action}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 0); 
    curl_setopt($ch, CURLOPT_ENCODING, "");
    $headers = ['User-Agent: VLC/3.0.16 LibVLC/3.0.16', 'Cookie: mac=' . urlencode($user)];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERAGENT, 'VLC/3.0.16 LibVLC/3.0.16');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch); curl_close($ch); 
    return json_decode($res, true) ?: [];
}

$db_servers = 'db_servers.json';
if (!file_exists($db_servers)) file_put_contents($db_servers, json_encode([]));
$servers = json_decode(file_get_contents($db_servers), true);

function check_connections($srv, $srv_id) {
    if (isset($srv['enforce_expiry']) && $srv['enforce_expiry'] == true) {
        if (time() > $srv['exp_date']) die("Error: Subscription Expired! تم انتهاء الاشتراك.");
    }
    if (isset($srv['enforce_conn']) && $srv['enforce_conn'] == true) {
        $max = (int)($srv['max_connections'] ?? 1);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $tracker = "conn_track_{$srv_id}.json";
        $data = file_exists($tracker) ? json_decode(file_get_contents($tracker), true) : [];
        if (!is_array($data)) $data = [];
        $now = time(); $active = [];
        foreach ($data as $track_ip => $time_last) {
            if ($now - $time_last < 45) { $active[$track_ip] = $time_last; }
        }
        $active[$ip] = $now;
        if (count($active) > $max) die("Error: Maximum connections reached ($max). تم الوصول للحد الأقصى للمتصلين.");
        file_put_contents($tracker, json_encode($active));
    }
}

// =================================================================================
// محاكاة واجهة Xtream Codes API (player_api.php)
// =================================================================================
if (strpos($_SERVER['REQUEST_URI'], 'player_api.php') !== false) {
    $c_user = $_GET['username'] ?? ''; $c_pass = $_GET['password'] ?? ''; $action = $_GET['action'] ?? '';
    $srv_id = null; $srv = null;
    foreach ($servers as $id => $s) {
        if (isset($s['c_user']) && $s['c_user'] === $c_user && $s['c_pass'] === $c_pass) {
            $srv_id = $id; $srv = $s; break;
        }
    }
    header('Content-Type: application/json');
    $m3u_comp = isset($srv['m3u_compression']) ? (int)$srv['m3u_compression'] : 0;

    function respond_api($data) {
        global $m3u_comp; $json = json_encode($data);
        if ($m3u_comp > 0) { header("Content-Encoding: gzip"); echo gzencode($json, $m3u_comp); } else { echo $json; }
        exit;
    }

    if (!$srv) respond_api(['user_info' => ['auth' => 0, 'status' => 'Inactive']]);
    if (isset($srv['enforce_expiry']) && $srv['enforce_expiry'] == true && time() > $srv['exp_date']) { respond_api(['user_info' => ['auth' => 0, 'status' => 'Expired']]); }

    $playlist_file = "playlist_{$srv_id}.json";
    $playlist = file_exists($playlist_file) ? json_decode(file_get_contents($playlist_file), true) : [];
    if (!is_array($playlist)) $playlist = [];
    
    $cat_ids = []; $cat_counter = 1; $cats = ['live' => [], 'movie' => [], 'series' => []];
    foreach($playlist as $item) {
        $cat_name = trim($item['category'] ?? 'General');
        if ($item['type'] === 'movie' && isset($srv['group_vod']) && $srv['group_vod']) $cat_name = "🎬 VOD - " . $cat_name;
        if ($item['type'] === 'series' && isset($srv['group_series']) && $srv['group_series']) {
            $parts = explode(' - S', $item['name']); $cat_name = "📺 " . trim($parts[0]);
        }
        if (!isset($cat_ids[$cat_name])) { $cat_ids[$cat_name] = (string)$cat_counter++; }
        $cats[$item['type']][$cat_name] = $cat_ids[$cat_name];
    }

    if (empty($action)) {
        $res = [
            'user_info' => [
                'username' => $c_user, 'password' => $c_pass, 'message' => '', 'auth' => 1, 'status' => 'Active',
                'exp_date' => (string)($srv['exp_date'] ?? strtotime('+1 year')), 'is_trial' => '0',
                'active_cons' => '0', 'created_at' => (string)($srv['created_at'] ?? time()),
                'max_connections' => isset($srv['max_connections']) ? (string)$srv['max_connections'] : "1",
                'allowed_output_formats' => ['ts', 'm3u8']
            ],
            'server_info' => [
                'url' => $_SERVER['HTTP_HOST'], 'port' => $_SERVER['SERVER_PORT'], 'https_port' => "443",
                'server_protocol' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http",
                'rtmp_port' => "0", 'timezone' => "Etc/UTC", 'timestamp_now' => time(), 'time_now' => date('Y-m-d H:i:s')
            ]
        ];
        respond_api($res);
    }
    
    if ($action === 'get_live_categories' || $action === 'get_vod_categories' || $action === 'get_series_categories') {
        $type = str_replace(['get_', '_categories'], '', $action);
        if ($type === 'vod') $type = 'movie';
        $res = []; foreach ($cats[$type] as $name => $id) { $res[] = ['category_id' => $id, 'category_name' => $name, 'parent_id' => 0]; }
        respond_api($res);
    }

    $live_ext_raw = isset($srv['proxy_ext']) ? $srv['proxy_ext'] : 'm3u8';
    $vod_ext_raw = isset($srv['vod_ext']) ? $srv['vod_ext'] : 'mp4';
    $series_ext_raw = isset($srv['series_ext']) ? $srv['series_ext'] : 'mp4';
    
    if ($action === 'get_live_streams') {
        $res = [];
        $req_cat = $_GET['category_id'] ?? null;
        foreach($playlist as $item) {
            if ($item['type'] === 'live') {
                $cat_name = trim($item['category'] ?? 'General');
                $c_id = $cat_ids[$cat_name] ?? '';
                if ($req_cat !== null && $req_cat != $c_id) continue;
                $res[] = ['num' => count($res) + 1, 'name' => $item['name'], 'stream_type' => 'live', 'stream_id' => $item['id'], 'stream_icon' => $item['logo'] ?? '', 'epg_channel_id' => '', 'added' => time(), 'category_id' => $c_id, 'custom_sid' => '', 'tv_archive' => 0, 'direct_source' => '', 'tv_archive_duration' => 0];
            }
        }
        respond_api($res);
    }

    if ($action === 'get_vod_streams') {
        $res = [];
        $req_cat = $_GET['category_id'] ?? null;
        foreach($playlist as $item) {
            if ($item['type'] === 'movie') {
                $cat_name = trim($item['category'] ?? 'General');
                if (isset($srv['group_vod']) && $srv['group_vod']) $cat_name = "🎬 VOD - " . $cat_name;
                $c_id = $cat_ids[$cat_name] ?? '';
                if ($req_cat !== null && $req_cat != $c_id) continue;
                $ext_val = ($vod_ext_raw === 'same_as_live') ? $live_ext_raw : (($vod_ext_raw === 'original') ? ($item['ext'] ?? 'mp4') : $vod_ext_raw);
                if ($ext_val === 'none' || $ext_val === 'none_no_live') $ext_val = '';
                $res[] = ['num' => count($res) + 1, 'name' => $item['name'], 'stream_type' => 'movie', 'stream_id' => $item['id'], 'stream_icon' => $item['logo'] ?? '', 'rating' => '5', 'rating_5based' => 2.5, 'added' => time(), 'is_adult' => '0', 'category_id' => $c_id, 'container_extension' => ltrim($ext_val, '.'), 'custom_sid' => '', 'direct_source' => ''];
            }
        }
        respond_api($res);
    }

    // [إصلاح المسلسلات] سحب صحيح مع الفلترة حسب category_id لتوافق Smarters
    if ($action === 'get_series') {
        $res = [];
        $added_series = [];
        $req_cat = $_GET['category_id'] ?? null;
        foreach($playlist as $item) {
            if ($item['type'] === 'series') {
                $sid = $item['series_id'] ?? $item['id']; 
                if (!isset($added_series[$sid])) {
                    $cat_name = trim($item['category'] ?? 'General');
                    if (isset($srv['group_series']) && $srv['group_series']) {
                        $parts = explode(' - S', $item['name']); $cat_name = "📺 " . trim($parts[0]);
                    }
                    $c_id = $cat_ids[$cat_name] ?? '';
                    
                    if ($req_cat !== null && $req_cat != $c_id) continue; // فلترة دقيقة للأقسام
                    
                    $added_series[$sid] = true;
                    $series_name = trim(explode(' - S', $item['name'])[0]);
                    $res[] = [
                        'num' => count($res) + 1, 
                        'name' => $series_name, 
                        'series_id' => (int)$sid, 
                        'cover' => $item['logo'] ?? '', 
                        'plot' => '', 'cast' => '', 'director' => '', 'genre' => '', 'releaseDate' => '', 
                        'last_modified' => time(), 'rating' => '5', 'rating_5based' => 2.5, 
                        'backdrop_path' => [], 'youtube_trailer' => '', 'episode_run_time' => '45', 
                        'category_id' => (string)$c_id
                    ];
                }
            }
        }
        respond_api($res);
    }

    if ($action === 'get_series_info' && isset($_GET['series_id'])) {
        $ep_id = $_GET['series_id'];
        $original_info = api_req($srv['host'], $srv['user'], $srv['pass'], "get_series_info&series_id={$ep_id}");
        if (isset($original_info['episodes']) && is_array($original_info['episodes'])) {
            foreach ($original_info['episodes'] as $season => &$episodes) {
                foreach ($episodes as &$ep) {
                    $ext_val = ($series_ext_raw === 'same_as_live') ? $live_ext_raw : (($series_ext_raw === 'original') ? ($ep['container_extension'] ?? 'mp4') : $series_ext_raw);
                    if ($ext_val === 'none' || $ext_val === 'none_no_live') $ext_val = '';
                    $ep['container_extension'] = ltrim($ext_val, '.');
                }
            }
        }
        respond_api($original_info ?: []);
    }
    
    if ($action === 'get_vod_info' && isset($_GET['vod_id'])) {
        $vod_id = $_GET['vod_id'];
        $original_info = api_req($srv['host'], $srv['user'], $srv['pass'], "get_vod_info&vod_id={$vod_id}");
        if (isset($original_info['movie_data'])) {
            $ext_val = ($vod_ext_raw === 'same_as_live') ? $live_ext_raw : (($vod_ext_raw === 'original') ? ($original_info['movie_data']['container_extension'] ?? 'mp4') : $vod_ext_raw);
            if ($ext_val === 'none' || $ext_val === 'none_no_live') $ext_val = '';
            $original_info['movie_data']['container_extension'] = ltrim($ext_val, '.');
        }
        respond_api($original_info ?: []);
    }
    respond_api([]);
}

// ---------------------------------------------------------------------------------
// استقبال مسارات الاكستريم المباشرة وتوجيهها
// ---------------------------------------------------------------------------------
$request_uri = $_SERVER['REQUEST_URI'];

if (isset($_GET['xtream_play'])) {
    $type = $_GET['xtream_play']; $c_user = $_GET['u']; $c_pass = $_GET['p']; $id_with_ext = $_GET['id'];
    $id = pathinfo($id_with_ext, PATHINFO_FILENAME);
    
    $srv_id = null; $srv = null;
    foreach ($servers as $sid => $s) {
        if (isset($s['c_user']) && $s['c_user'] === $c_user && $s['c_pass'] === $c_pass) { $srv_id = $sid; $srv = $s; break; }
    }
    if (!$srv) die("Unauthorized");
    check_connections($srv, $srv_id);
    
    if ($type === 'movie') $type = 'movie'; elseif ($type === 'series') $type = 'series'; else $type = 'live';
    $_GET['id'] = "{$srv_id}---{$type}---{$id}";
    $_SERVER['REQUEST_URI'] = '/video'; 
    $request_uri = '/video';
}

// ---------------------------------------------------------------------------------
// المحرك الجديد لملف البروكسي
// ---------------------------------------------------------------------------------
$proxy_code = '<?php
error_reporting(0); ini_set("display_errors", 0); @ini_set("zlib.output_compression", "Off"); @ini_set("output_buffering", "Off"); @ini_set("implicit_flush", 1);
while (ob_get_level()) ob_end_clean();
header("Access-Control-Allow-Origin: *"); header("Access-Control-Allow-Methods: GET, OPTIONS"); header("Access-Control-Allow-Headers: *");
if ($_SERVER["REQUEST_METHOD"] == "OPTIONS") { http_response_code(200); exit; }

if(isset($_GET["data"])){
    $data = $_GET["data"];
    $url = base64_decode(str_pad(strtr($data, "-_", "+/"), strlen($data) % 4 === 0 ? 0 : 4 - (strlen($data) % 4), "=", STR_PAD_RIGHT));
    if(empty($url) || strpos($url, "http") !== 0) die("Error: Invalid Stream URL");

    if (strpos($url, ".ts") !== false) header("Content-Type: video/mp2t");
    else header("Content-Type: application/octet-stream");
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 0); curl_setopt($ch, CURLOPT_BUFFERSIZE, 1048576);
    
    $headers = ["User-Agent: VLC/3.0.16 LibVLC/3.0.16", "Referer: " . parse_url($url, PHP_URL_SCHEME) . "://" . parse_url($url, PHP_URL_HOST), "Connection: keep-alive"];
    if (isset($_SERVER["HTTP_RANGE"])) { $headers[] = "Range: " . $_SERVER["HTTP_RANGE"]; http_response_code(206); }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); curl_setopt($ch, CURLOPT_USERAGENT, "VLC/3.0.16 LibVLC/3.0.16");
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) {
        $len = strlen($header); $headerArr = explode(":", $header, 2);
        if (count($headerArr) >= 2) { $name = strtolower(trim($headerArr[0])); if (in_array($name, ["content-length", "content-range", "accept-ranges"])) header(trim($header)); }
        return $len;
    });
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) { echo $data; @ob_flush(); @flush(); return strlen($data); });
    curl_exec($ch); curl_close($ch);
}
?>';
file_put_contents('proxy_stream.php', $proxy_code);

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$base_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$base_url = rtrim($protocol . "://" . $_SERVER['HTTP_HOST'] . ($base_dir === '/' ? '' : $base_dir), '/');

// =================================================================================
// 1. صائد الروابط المطور
// =================================================================================
if ((strpos($request_uri, '/play.php') !== false || strpos($request_uri, '/video') !== false) && isset($_GET['id'])) {
    $parts = explode('---', $_GET['id']);
    if (count($parts) === 3) {
        $srv_id = $parts[0]; $type = $parts[1]; $id = $parts[2];
        if (isset($servers[$srv_id])) {
            $srv = $servers[$srv_id]; check_connections($srv, $srv_id);
            
            $host = rtrim($srv['host'], '/');
            $live_ext_raw = isset($srv['proxy_ext']) ? $srv['proxy_ext'] : 'm3u8';
            $live_ext = ($live_ext_raw === 'none' || $live_ext_raw === 'none_no_live') ? '' : '.' . $live_ext_raw;
            $vod_ext_raw = isset($srv['vod_ext']) ? $srv['vod_ext'] : 'mp4';
            $vod_ext = ($vod_ext_raw === 'same_as_live') ? $live_ext : (($vod_ext_raw === 'none' || $vod_ext_raw === 'original') ? '' : '.' . $vod_ext_raw);
            
            $series_ext_raw = isset($srv['series_ext']) ? $srv['series_ext'] : 'mp4';
            $series_ext = ($series_ext_raw === 'same_as_live') ? $live_ext : (($series_ext_raw === 'none' || $series_ext_raw === 'original') ? '' : '.' . $series_ext_raw);
            $stream_method = isset($srv['stream_method']) ? $srv['stream_method'] : 'full_proxy';
            
            // [تحديث] بناء رابط السورس بناءً على الاختيار المباشر أو العادي
            if ($type === 'live') {
                if ($live_ext_raw === 'none_no_live') {
                    $target = "{$host}/{$srv['user']}/{$srv['pass']}/{$id}"; // بدون live أو امتداد
                } else {
                    $target = "{$host}/live/{$srv['user']}/{$srv['pass']}/{$id}{$live_ext}";
                }
            } elseif ($type === 'movie') { 
                $target = "{$host}/movie/{$srv['user']}/{$srv['pass']}/{$id}{$vod_ext}";
            } elseif ($type === 'series') { 
                $target = "{$host}/series/{$srv['user']}/{$srv['pass']}/{$id}{$series_ext}";
            } else { 
                $target = "{$host}/{$srv['user']}/{$srv['pass']}/{$id}";
            }
            $source_url = $target;
            
            if (isset($srv['bypass_ip']) && $srv['bypass_ip'] == true) {
                $ch_bp = curl_init($target); curl_setopt($ch_bp, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch_bp, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch_bp, CURLOPT_MAXREDIRS, 5); curl_setopt($ch_bp, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch_bp, CURLOPT_USERAGENT, 'VLC/3.0.16 LibVLC/3.0.16'); curl_setopt($ch_bp, CURLOPT_TIMEOUT, 5); curl_setopt($ch_bp, CURLOPT_HTTPHEADER, ["Range: bytes=0-1"]); curl_setopt($ch_bp, CURLOPT_NOBODY, false); @curl_exec($ch_bp); $bp_url = curl_getinfo($ch_bp, CURLINFO_EFFECTIVE_URL); curl_close($ch_bp);
                if (!empty($bp_url)) $source_url = $bp_url;
            }

            if ($stream_method === 'redirect') { header("Location: " . $source_url); exit; }

            $accept_header = $_SERVER['HTTP_ACCEPT'] ?? '';
            if (strpos($accept_header, 'text/html') !== false && !isset($_GET['app'])) {
                $show_link_html = '';
                if (isset($srv['show_link']) && $srv['show_link'] == true) {
                    $show_link_html = '<div style="margin-top:20px; padding:15px; background:#161b22; border:1px solid #30363d; border-radius:8px; width:100%; max-width:800px; text-align:center; box-sizing:border-box;"><p style="color:#58a6ff; font-weight:bold; margin:0 0 10px 0;">الرابط المتصل</p><textarea readonly style="width:100%; background:#0d1117; color:#3fb950; border:1px solid #30363d; padding:10px; border-radius:6px; resize:none; font-family:monospace; direction:ltr;" rows="2" onclick="this.select()">' . htmlspecialchars($source_url) . '</textarea></div>';
                }
                $web_ext = $live_ext; if($type === 'movie') $web_ext = $vod_ext; if($type === 'series') $web_ext = $series_ext;
                echo '<!DOCTYPE html><html dir="rtl"><head><meta charset="utf-8"><title>شاشة العرض</title><meta name="viewport" content="width=device-width, initial-scale=1"><script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script><style>body{background:#000;margin:0;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh; padding:20px; box-sizing:border-box;} video{width:100%;max-width:800px;border-radius:10px;box-shadow:0 0 20px rgba(0,0,0,0.5);background:#000;} textarea:focus{outline:none;border-color:#58a6ff;}</style></head><body><video id="video" controls controlsList="nodownload" autoplay></video>' . $show_link_html . '<script>var video = document.getElementById("video"); var videoSrc = "'.$base_url.'/index.php/video'.$web_ext.'?id='.$_GET['id'].'&proxy=1"; if (Hls.isSupported()) { var hls = new Hls(); hls.loadSource(videoSrc); hls.attachMedia(video); hls.on(Hls.Events.MANIFEST_PARSED, function() { video.play(); }); } else if (video.canPlayType("application/vnd.apple.mpegurl")) { video.src = videoSrc; video.addEventListener("loadedmetadata", function() { video.play(); }); }</script></body></html>';
                exit;
            }

            $is_hls = (strpos($source_url, '.m3u8') !== false || $live_ext === '.m3u8' || $vod_ext === '.m3u8' || $series_ext === '.m3u8');
            $hls_processed = false;

            if ($is_hls) {
                $ch = curl_init($source_url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); $headers = ["User-Agent: VLC/3.0.16 LibVLC/3.0.16", "Referer: " . $host]; curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); curl_setopt($ch, CURLOPT_USERAGENT, 'VLC/3.0.16 LibVLC/3.0.16'); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $m3u8_content = curl_exec($ch);
                $parsed_src = parse_url($source_url); $host_only = $parsed_src['scheme'] . '://' . $parsed_src['host'] . (isset($parsed_src['port']) ? ':' . $parsed_src['port'] : ''); $base_stream_url = rtrim(dirname(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)), '/'); curl_close($ch);
                
                if (strpos($m3u8_content, '#EXTM3U') !== false) {
                    $hls_processed = true; $lines = explode("\n", $m3u8_content); $new_m3u8 = "";
                    foreach ($lines as $line) {
                        $line = trim($line); if (empty($line)) continue;
                        if (strpos($line, '#') === 0) { $new_m3u8 .= $line . "\n"; } else {
                            if (strpos($line, 'http') === 0) $chunk_url = $line; elseif (strpos($line, '/') === 0) $chunk_url = $host_only . $line; else $chunk_url = $base_stream_url . '/' . ltrim($line, '/');
                            if ($stream_method === 'full_proxy' || $stream_method === 'ultra_beast') { $encoded_chunk = encode_base64_url($chunk_url); $new_m3u8 .= $base_url . "/proxy_stream.php?s=" . urlencode($srv_id) . "&data=" . $encoded_chunk . "\n"; } elseif ($stream_method === 'm3u8_text_only') { $new_m3u8 .= $chunk_url . "\n"; }
                        }
                    }
                    header("Content-Type: application/vnd.apple.mpegurl"); echo $new_m3u8; exit;
                }
            } 
            
            if (!$hls_processed) {
                if ($stream_method === 'm3u8_text_only') { header("Location: " . $source_url); exit; }
                while (ob_get_level()) ob_end_clean();
                $ch = curl_init($source_url); $req_headers = ["User-Agent: VLC/3.0.16 LibVLC/3.0.16", "Referer: " . $host, "Connection: keep-alive"];
                if (isset($_SERVER['HTTP_RANGE'])) { $req_headers[] = "Range: " . $_SERVER['HTTP_RANGE']; http_response_code(206); }
                curl_setopt($ch, CURLOPT_HTTPHEADER, $req_headers); curl_setopt($ch, CURLOPT_USERAGENT, 'VLC/3.0.16 LibVLC/3.0.16'); curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); curl_setopt($ch, CURLOPT_TIMEOUT, 0);
                
                if ($stream_method === 'ultra_beast') {
                    curl_setopt($ch, CURLOPT_BUFFERSIZE, 1048576); curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) { echo $data; @ob_flush(); @flush(); return strlen($data); });
                } else { curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); }
                
                curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) {
                    $len = strlen($header); $hdr = explode(":", $header, 2);
                    if (count($hdr) >= 2) { $name = strtolower(trim($hdr[0])); if (in_array($name, ["content-type", "content-length", "content-range", "accept-ranges", "content-disposition"])) { header(trim($header)); } }
                    return $len;
                });
                curl_exec($ch); curl_close($ch); exit;
            }
        }
    }
    die("Error: Stream Not Found");
}

if (isset($_GET['proxy_logo_url'])) {
    $img_url = base64_decode(str_pad(strtr($_GET['proxy_logo_url'], '-_', '+/'), strlen($_GET['proxy_logo_url']) % 4 === 0 ? 0 : 4 - (strlen($_GET['proxy_logo_url']) % 4), '=', STR_PAD_RIGHT));
    if(filter_var($img_url, FILTER_VALIDATE_URL)) {
        $ch = curl_init($img_url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_TIMEOUT, 15); $img_data = curl_exec($ch); $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch);
        if ($img_data) { header("Content-Type: " . ($content_type ?: "image/jpeg")); header("Cache-Control: public, max-age=86400"); echo $img_data; exit; }
    }
    header("HTTP/1.0 404 Not Found"); exit;
}

// =================================================================================
// 2. المولد الأساسي للروابط (M3U & AJAX)
// =================================================================================
if (isset($_GET['username']) && isset($_GET['password']) && !isset($_GET['action'])) {
    $c_user = $_GET['username']; $c_pass = $_GET['password'];  $req_type = $_GET['type'] ?? 'm3u_plus';
    foreach ($servers as $srv_id => $srv) {
        if (isset($srv['c_user']) && $srv['c_user'] === $c_user && $srv['c_pass'] === $c_pass) {
            if (isset($srv['enforce_expiry']) && $srv['enforce_expiry'] == true && time() > $srv['exp_date']) {
                die("#EXTM3U\r\n#EXTINF:-1,Error: Subscription Expired\r\nhttp://error.localhost\r\n");
            }
            $live_ext_raw = isset($srv['proxy_ext']) ? $srv['proxy_ext'] : 'm3u8';
            $vod_ext_raw = isset($srv['vod_ext']) ? $srv['vod_ext'] : 'mp4';
            $series_ext_raw = isset($srv['series_ext']) ? $srv['series_ext'] : 'mp4';
            $logo_mode = isset($srv['logo_mode']) ? $srv['logo_mode'] : 'proxy'; 
            $link_format = isset($srv['link_format']) ? $srv['link_format'] : 'proxy';
            
            $playlist_file = "playlist_{$srv_id}.json";
            $playlist = file_exists($playlist_file) ? json_decode(file_get_contents($playlist_file), true) : [];
            $out = "#EXTM3U\r\n";
            
            foreach ($playlist as $item) {
                $name = str_replace(array("\r", "\n", ","), '', $item['name']);
                $encoded_id = "{$srv_id}---{$item['type']}---{$item['id']}";
                
                if ($item['type'] === 'live') { $ext_val = ($live_ext_raw === 'none_no_live') ? '' : $live_ext_raw; } 
                elseif ($item['type'] === 'movie') { $ext_val = ($vod_ext_raw === 'same_as_live') ? $live_ext_raw : (($vod_ext_raw === 'original') ? ($item['ext'] ?? 'mp4') : $vod_ext_raw); } 
                elseif ($item['type'] === 'series') { $ext_val = ($series_ext_raw === 'same_as_live') ? $live_ext_raw : (($series_ext_raw === 'original') ? ($item['ext'] ?? 'mp4') : $series_ext_raw); } 
                else { $ext_val = 'm3u8'; }
                
                $ext_path = ($ext_val === 'none' || empty($ext_val) || $ext_val === 'none_no_live') ? '' : ".{$ext_val}";

                // [تحديث] بناء الروابط الثابتة حسب اختيار الامتداد المباشر
                if ($link_format === 'standard') {
                    if ($item['type'] === 'live' && $live_ext_raw === 'none_no_live') {
                        $link = "{$base_url}/{$c_user}/{$c_pass}/{$item['id']}";
                    } else {
                        $path_type = ($item['type'] === 'movie') ? 'movie' : (($item['type'] === 'series') ? 'series' : 'live');
                        $link = "{$base_url}/{$path_type}/{$c_user}/{$c_pass}/{$item['id']}{$ext_path}";
                    }
                } else {
                    $link = "{$base_url}/index.php/video{$ext_path}?id={$encoded_id}";
                }

                $logo_url = '';
                if ($logo_mode !== 'none' && !empty($item['logo'])) {
                    if ($logo_mode === 'proxy') $logo_url = "{$base_url}/index.php?proxy_logo_url=" . rtrim(strtr(base64_encode($item['logo']), '+/', '-_'), '=');
                    else $logo_url = $item['logo'];
                }

                $cat_raw = $item['category'] ?? 'General';
                if ($item['type'] === 'movie' && isset($srv['group_vod']) && $srv['group_vod'] == true) { $cat_raw = "🎬 VOD - " . $cat_raw; } 
                elseif ($item['type'] === 'series' && isset($srv['group_series']) && $srv['group_series'] == true) {
                    $parts = explode(' - S', $item['name']); $cat_raw = "📺 " . trim($parts[0]);
                }
                $grp_raw = str_replace(array("\r", "\n", ","), '', $cat_raw);
                $grp = ' group-title="' . $grp_raw . '"';
                
                if ($req_type == 'm3u_plus') {
                    $logo_attr = !empty($logo_url) ? ' tvg-logo="' . $logo_url . '"' : '';
                    $out .= "#EXTINF:-1 tvg-id=\"\" tvg-name=\"{$name}\"{$logo_attr}{$grp},{$name}\r\n{$link}\r\n";
                } else { 
                    $out .= "#EXTINF:-1{$grp},{$name}\r\n{$link}\r\n";
                }
            }
            
            $m3u_compression = isset($srv['m3u_compression']) ? (int)$srv['m3u_compression'] : 0;
            if ($m3u_compression > 0) { $out = gzencode($out, $m3u_compression); header("Content-Encoding: gzip"); }
            header("Content-Type: application/vnd.apple.mpegurl; charset=utf-8"); header("Content-Disposition: attachment; filename=\"iptv_{$c_user}.m3u\""); header("Content-Length: " . strlen($out)); echo $out; exit;
        }
    }
    die("Unauthorized Account");
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    if ($_GET['ajax'] == 'edit_server') {
        $data = json_decode(file_get_contents('php://input'), true);
        $s_id = $_GET['s_id'];
        if (isset($servers[$s_id])) {
            $servers[$s_id]['host'] = $data['host'];
            $servers[$s_id]['user'] = $data['user'];
            $servers[$s_id]['pass'] = $data['pass'];
            if(!empty($data['c_user'])) $servers[$s_id]['c_user'] = $data['c_user'];
            if(!empty($data['c_pass'])) $servers[$s_id]['c_pass'] = $data['c_pass'];
            if(!empty($data['created_at'])) $servers[$s_id]['created_at'] = strtotime($data['created_at']);
            if(!empty($data['exp_date'])) $servers[$s_id]['exp_date'] = strtotime($data['exp_date']);
            file_put_contents($db_servers, json_encode($servers));
            file_put_contents("playlist_{$s_id}.json", json_encode([]));
            echo json_encode(['success' => true]);
        } else { echo json_encode(['error' => 'Server not found']); }
        exit;
    }

    if ($_GET['ajax'] == 'add_server') {
        $data = json_decode(file_get_contents('php://input'), true);
        $test = api_req($data['host'], $data['user'], $data['pass'], '');
        if (!isset($test['user_info'])) { echo json_encode(['error' => 'بيانات غير صحيحة أو السيرفر لا يستجيب عبر player_api']); exit; }
        $data['proxy_ext'] = 'm3u8'; $data['vod_ext'] = 'mp4'; $data['series_ext'] = 'mp4'; $data['m3u_compression'] = 0; $data['logo_mode'] = 'proxy'; $data['stream_method'] = 'full_proxy'; $data['link_format'] = 'proxy'; $data['bypass_ip'] = true; $data['show_link'] = false; $data['group_vod'] = false; $data['group_series'] = true; $data['max_connections'] = "1"; $data['enforce_expiry'] = false; $data['enforce_conn'] = false; $data['created_at'] = !empty($data['created_at']) ? strtotime($data['created_at']) : time(); $data['exp_date'] = !empty($data['exp_date']) ? strtotime($data['exp_date']) : strtotime('+1 year');
        $id = 's_' . time(); $servers[$id] = $data;
        file_put_contents($db_servers, json_encode($servers)); file_put_contents("playlist_{$id}.json", json_encode([])); echo json_encode(['success' => true, 'id' => $id]); exit;
    }
    
    if ($_GET['ajax'] == 'save_proxy_settings') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($servers[$_GET['s_id']])) {
            if (isset($data['ext'])) $servers[$_GET['s_id']]['proxy_ext'] = $data['ext']; if (isset($data['vod_ext'])) $servers[$_GET['s_id']]['vod_ext'] = $data['vod_ext']; if (isset($data['series_ext'])) $servers[$_GET['s_id']]['series_ext'] = $data['series_ext']; if (isset($data['m3u_compression'])) $servers[$_GET['s_id']]['m3u_compression'] = $data['m3u_compression']; if (isset($data['logo_mode'])) $servers[$_GET['s_id']]['logo_mode'] = $data['logo_mode']; if (isset($data['stream_method'])) $servers[$_GET['s_id']]['stream_method'] = $data['stream_method']; if (isset($data['link_format'])) $servers[$_GET['s_id']]['link_format'] = $data['link_format']; if (isset($data['bypass_ip'])) $servers[$_GET['s_id']]['bypass_ip'] = $data['bypass_ip']; if (isset($data['show_link'])) $servers[$_GET['s_id']]['show_link'] = $data['show_link']; if (isset($data['group_vod'])) $servers[$_GET['s_id']]['group_vod'] = $data['group_vod']; if (isset($data['group_series'])) $servers[$_GET['s_id']]['group_series'] = $data['group_series']; if (isset($data['max_connections'])) $servers[$_GET['s_id']]['max_connections'] = $data['max_connections']; if (isset($data['enforce_expiry'])) $servers[$_GET['s_id']]['enforce_expiry'] = $data['enforce_expiry']; if (isset($data['enforce_conn'])) $servers[$_GET['s_id']]['enforce_conn'] = $data['enforce_conn'];
            file_put_contents($db_servers, json_encode($servers));
        }
        echo json_encode(['success' => true]); exit;
    }

    if ($_GET['ajax'] == 'list_static') { $files = glob("*.m3u"); $res = []; foreach($files as $f) { $res[] = ['name' => $f, 'size' => round(filesize($f)/1024, 2) . ' KB']; } echo json_encode($res); exit; }
    if ($_GET['ajax'] == 'delete_static') { $data = json_decode(file_get_contents('php://input'), true); if (file_exists($data['file'])) unlink($data['file']); echo json_encode(['success' => true]); exit; }

    $srv = $servers[$_GET['s_id']] ?? null; if (!$srv && $_GET['ajax'] != 'list_static' && $_GET['ajax'] != 'delete_static') exit;

    if ($_GET['ajax'] == 'bulk_pull') {
        ini_set('memory_limit', '-1'); set_time_limit(0);
        $type = $_GET['t']; $playlist_file = "playlist_{$_GET['s_id']}.json";
        $playlist = file_exists($playlist_file) ? json_decode(file_get_contents($playlist_file), true) : [];
        $unique = []; if(is_array($playlist)) foreach($playlist as $v) $unique[$v['type'].'_'.$v['id']] = $v;
        
        if ($type == 'live') {
            $cats = api_req($srv['host'], $srv['user'], $srv['pass'], 'get_live_categories'); $cat_map = []; if(is_array($cats)) foreach($cats as $c) $cat_map[$c['category_id']] = $c['category_name'];
            $streams = api_req($srv['host'], $srv['user'], $srv['pass'], 'get_live_streams');
            if(is_array($streams)) foreach($streams as $st) { $id = $st['stream_id']; $unique['live_'.$id] = ['id' => $id, 'name' => $st['name'] ?? 'Unknown', 'logo' => $st['stream_icon'] ?? '', 'type' => 'live', 'category' => $cat_map[$st['category_id']] ?? 'Live', 'ext' => 'ts']; }
        } elseif ($type == 'vod') {
            $cats = api_req($srv['host'], $srv['user'], $srv['pass'], 'get_vod_categories'); $cat_map = []; if(is_array($cats)) foreach($cats as $c) $cat_map[$c['category_id']] = $c['category_name'];
            $streams = api_req($srv['host'], $srv['user'], $srv['pass'], 'get_vod_streams');
            if(is_array($streams)) foreach($streams as $st) { $id = $st['stream_id']; $unique['movie_'.$id] = ['id' => $id, 'name' => $st['name'] ?? 'Unknown', 'logo' => $st['stream_icon'] ?? '', 'type' => 'movie', 'category' => $cat_map[$st['category_id']] ?? 'VOD', 'ext' => $st['container_extension'] ?? 'mp4']; }
        } elseif ($type == 'series') {
            $cats = api_req($srv['host'], $srv['user'], $srv['pass'], 'get_series_categories'); $cat_map = []; if(is_array($cats)) foreach($cats as $c) $cat_map[$c['category_id']] = $c['category_name'];
            $streams = api_req($srv['host'], $srv['user'], $srv['pass'], 'get_series');
            if(is_array($streams)) foreach($streams as $st) {
                $info = api_req($srv['host'], $srv['user'], $srv['pass'], "get_series_info&series_id={$st['series_id']}");
                if(isset($info['episodes']) && is_array($info['episodes'])) {
                    foreach($info['episodes'] as $sn => $eps) {
                        foreach($eps as $ep) {
                            $id = $ep['id']; $name = ($st['name'] ?? 'Unknown') . " - S" . ($sn ?: "1") . " E" . ($ep['episode_num'] ?: "X");
                            $unique['series_'.$id] = ['id' => $id, 'series_id' => $st['series_id'], 'name' => $name, 'logo' => $st['cover'] ?? '', 'type' => 'series', 'category' => $cat_map[$st['category_id']] ?? 'Series', 'ext' => $ep['container_extension'] ?? 'mp4'];
                        }
                    }
                }
            }
        }
        file_put_contents($playlist_file, json_encode(array_values($unique))); echo json_encode(['success' => true]); exit;
    }

    if ($_GET['ajax'] == 'cats') { echo json_encode(api_req($srv['host'], $srv['user'], $srv['pass'], "get_{$_GET['t']}_categories")); exit; }
    
    if ($_GET['ajax'] == 'streams') {
        $t = $_GET['t']; $c_id = $_GET['c_id'];
        if ($t == 'series') {
            $series_list = api_req($srv['host'], $srv['user'], $srv['pass'], "get_series&category_id={$c_id}");
            $res = [];
            if(is_array($series_list)) {
                foreach($series_list as $s) {
                    $info = api_req($srv['host'], $srv['user'], $srv['pass'], "get_series_info&series_id={$s['series_id']}");
                    if(isset($info['episodes']) && is_array($info['episodes'])) {
                        foreach($info['episodes'] as $sn => $eps) {
                            foreach($eps as $ep) {
                                $res[] = ['id' => $ep['id'], 'series_id' => $s['series_id'], 'name' => ($s['name'] ?? 'Unknown') . " - S" . ($sn ?: "1") . " E" . ($ep['episode_num'] ?: "X"), 'cover' => $s['cover'] ?? '', 'container_extension' => $ep['container_extension'] ?? 'mp4'];
                            }
                        }
                    }
                }
            }
            echo json_encode($res); exit;
        } else {
            $act = ($t == 'live') ? "get_live_streams&category_id={$c_id}" : "get_vod_streams&category_id={$c_id}"; echo json_encode(api_req($srv['host'], $srv['user'], $srv['pass'], $act)); exit;
        }
    }
    
    if ($_GET['ajax'] == 'save') { $data = json_decode(file_get_contents('php://input'), true); file_put_contents("playlist_{$_GET['s_id']}.json", json_encode($data['list'])); echo json_encode(['status' => 'ok']); exit; }
    
    if ($_GET['ajax'] == 'export_static') {
        $playlist = file_exists("playlist_{$_GET['s_id']}.json") ? json_decode(file_get_contents("playlist_{$_GET['s_id']}.json"), true) : [];
        $live_ext_raw = isset($srv['proxy_ext']) ? $srv['proxy_ext'] : 'm3u8'; $vod_ext_raw = isset($srv['vod_ext']) ? $srv['vod_ext'] : 'mp4'; $series_ext_raw = isset($srv['series_ext']) ? $srv['series_ext'] : 'mp4'; $logo_mode = isset($srv['logo_mode']) ? $srv['logo_mode'] : 'proxy'; $link_format = isset($srv['link_format']) ? $srv['link_format'] : 'proxy'; $c_user = $srv['c_user']; $c_pass = $srv['c_pass'];
        $out = "#EXTM3U\r\n";
        foreach ($playlist as $item) {
            $name = str_replace(array("\r", "\n", ","), '', $item['name']);
            $encoded_id = "{$_GET['s_id']}---{$item['type']}---{$item['id']}";
            
            if ($item['type'] === 'live') { $ext_val = ($live_ext_raw === 'none_no_live') ? '' : $live_ext_raw; } 
            elseif ($item['type'] === 'movie') { $ext_val = ($vod_ext_raw === 'same_as_live') ? $live_ext_raw : (($vod_ext_raw === 'original') ? ($item['ext'] ?? 'mp4') : $vod_ext_raw); } 
            elseif ($item['type'] === 'series') { $ext_val = ($series_ext_raw === 'same_as_live') ? $live_ext_raw : (($series_ext_raw === 'original') ? ($item['ext'] ?? 'mp4') : $series_ext_raw); } 
            else { $ext_val = 'm3u8'; }
            
            $ext_path = ($ext_val === 'none' || empty($ext_val) || $ext_val === 'none_no_live') ? '' : ".{$ext_val}";

            if ($link_format === 'standard') {
                if ($item['type'] === 'live' && $live_ext_raw === 'none_no_live') {
                    $link = "{$base_url}/{$c_user}/{$c_pass}/{$item['id']}";
                } else {
                    $path_type = ($item['type'] === 'movie') ? 'movie' : (($item['type'] === 'series') ? 'series' : 'live');
                    $link = "{$base_url}/{$path_type}/{$c_user}/{$c_pass}/{$item['id']}{$ext_path}";
                }
            } else {
                $link = "{$base_url}/index.php/video{$ext_path}?id={$encoded_id}";
            }

            $logo_url = '';
            if ($logo_mode !== 'none' && !empty($item['logo'])) {
                if ($logo_mode === 'proxy') $logo_url = "{$base_url}/index.php?proxy_logo_url=" . rtrim(strtr(base64_encode($item['logo']), '+/', '-_'), '='); else $logo_url = $item['logo'];
            }
            
            $cat_raw = $item['category'] ?? 'General';
            if ($item['type'] === 'movie' && isset($srv['group_vod']) && $srv['group_vod'] == true) { $cat_raw = "🎬 VOD - " . $cat_raw; } elseif ($item['type'] === 'series' && isset($srv['group_series']) && $srv['group_series'] == true) { $parts = explode(' - S', $item['name']); $cat_raw = "📺 " . trim($parts[0]); }
            $grp_raw = str_replace(array("\r", "\n", ","), '', $cat_raw); $grp = ' group-title="' . $grp_raw . '"'; $logo_attr = !empty($logo_url) ? ' tvg-logo="' . $logo_url . '"' : '';
            $out .= "#EXTINF:-1 tvg-id=\"\" tvg-name=\"{$name}\"{$logo_attr}{$grp},{$name}\r\n{$link}\r\n";
        }
        $filename = "iptv_{$srv['c_user']}_" . time() . ".m3u"; file_put_contents($filename, $out); echo json_encode(['success' => true, 'link' => "{$base_url}/{$filename}", 'file' => $filename]); exit;
    }
}

if (isset($_GET['delete'])) { $id = $_GET['delete']; unset($servers[$id]); @unlink("playlist_{$id}.json"); file_put_contents($db_servers, json_encode($servers)); header("Location: index.php"); exit; }

$admin_user = "Moh8"; $admin_pass = "Moha8";
if (isset($_POST['login'])) { if ($_POST['u'] === $admin_user && $_POST['p'] === $admin_pass) $_SESSION['admin'] = true; }
if (isset($_GET['logout'])) { session_destroy(); header("Location: index.php"); exit; }

if (!isset($_SESSION['admin'])) {
    echo '<!DOCTYPE html><html dir="rtl"><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{background:#111;color:#fff;font-family:sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;}form{background:#222;padding:30px;border-radius:10px;width:90%;max-width:350px;}input,button{width:100%;padding:12px;margin:10px 0;box-sizing:border-box;border-radius:6px;border:none;}input{background:#000;color:#fff;border:1px solid #444;}button{background:#00aaff;color:#fff;font-weight:bold;cursor:pointer;}</style></head><body><form method="POST"><h2 style="text-align:center;">دخول اللوحة</h2><input type="text" name="u" placeholder="المستخدم"><input type="password" name="p" placeholder="المرور"><button name="login">دخول</button></form></body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التوجيه الذكي للمصدر</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --bg: #0d1117; --card: #161b22; --border: #30363d; --acc: #58a6ff; --text: #c9d1d9; --red: #f85149; --green: #3fb950; --org: #f1e05a; }
        body { background: var(--bg); color: var(--text); font-family: 'Segoe UI', Tahoma, sans-serif; margin: 0; }
        * { box-sizing: inherit; }
        header { background: var(--card); padding: 15px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap:10px;}
        header h2 { margin: 0; font-size: 1.2rem; color: var(--acc); }
        .container { padding: 15px; max-width: 1200px; margin: auto; }
        .btn { padding: 10px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; color: #fff; }
        .btn-acc { background: var(--acc); } .btn-red { background: var(--red); } .btn-green { background: var(--green); } .btn-org { background: var(--org); color:#000;} .btn-dark { background: #21262d; border: 1px solid var(--border); color:#fff; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 15px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 15px; }
        input[type="text"], input[type="password"], input[type="number"], input[type="date"], select { width: 100%; padding: 10px; margin-bottom: 10px; border-radius: 6px; border: 1px solid var(--border); background: #010409; color: var(--text); }
        #builder_view { display: none; }
        .builder-grid { display: grid; grid-template-columns: 1fr; gap: 15px; margin-top: 15px; }
        @media(min-width: 768px) { .builder-grid { grid-template-columns: 350px 1fr; } }
        .panel { background: #0d1117; border: 1px solid var(--border); border-radius: 8px; max-height: 70vh; overflow-y: auto; padding: 10px; }
        .tabs { display: flex; gap: 5px; margin-bottom: 10px; flex-wrap: wrap;}
        .tab { flex: 1; text-align: center; padding: 10px; cursor: pointer; background: #010409; border: 1px solid var(--border); border-radius: 6px; font-weight: bold; min-width:100px;}
        .tab.active { background: var(--acc); color: #fff; border-color: var(--acc); }
        .list-item { padding: 10px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; cursor: pointer; font-size: 0.9rem; }
        .channel-info { display: flex; align-items: center; gap: 10px; overflow: hidden; width: 75%;}
        .channel-info img { width: 30px; height: 30px; border-radius: 4px; background: #000; object-fit: contain;}
        .channel-info span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        input[type="checkbox"] { width: 20px; height: 20px; cursor: pointer; accent-color: var(--acc); }
        #toast { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background: var(--green); color: #fff; padding: 12px 25px; border-radius: 30px; font-weight: bold; display: none; z-index: 1000; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 200; align-items: center; justify-content: center; padding: 15px; }
        .modal-content { background: var(--card); padding: 20px; border-radius: 12px; width: 100%; max-width: 500px; border: 1px solid var(--border); max-height:90vh; overflow-y:auto; }
        .m3u-list-item { display:flex; justify-content:space-between; align-items:center; padding:10px; background:#010409; border:1px solid var(--border); border-radius:6px; margin-bottom:8px; }
        details.cat-group { background: #010409; margin-bottom: 10px; border-radius: 6px; border: 1px solid var(--border); transition: all 0.3s;}
        details.cat-group summary { background: #21262d; padding: 10px; border-radius: 6px; font-weight: bold; display: flex; justify-content: space-between; align-items: center; cursor: pointer; list-style: none;}
        details.cat-group summary::-webkit-details-marker { display: none; }
        details.cat-group[open] summary { border-bottom-left-radius: 0; border-bottom-right-radius: 0; border-bottom: 1px solid var(--border); }
    </style>
</head>
<body>

<header>
    <h2><i class="fas fa-satellite-dish"></i> لوحة القنوات وإعادة التوجيه (Ultra MAX V6)</h2>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button class="btn btn-org" onclick="openM3uManager()"><i class="fas fa-folder-open"></i> إدارة ملفات M3U</button>
        <button class="btn btn-acc" onclick="document.getElementById('add_modal').style.display='flex'"><i class="fas fa-plus"></i> إضافة سيرفر</button>
        <a href="?logout=1" class="btn btn-red"><i class="fas fa-power-off"></i></a>
    </div>
</header>

<div id="toast">نجاح!</div>

<div class="container" id="main_view">
    <div class="grid">
        <?php foreach ($servers as $id => $srv): 
            $count = count(json_decode(file_get_contents("playlist_{$id}.json"), true) ?: []);
            $p_ext = isset($srv['proxy_ext']) ? $srv['proxy_ext'] : 'm3u8';
            $vod_ext = isset($srv['vod_ext']) ? $srv['vod_ext'] : 'mp4';
            $series_ext = isset($srv['series_ext']) ? $srv['series_ext'] : 'mp4';
            $m3u_compression = isset($srv['m3u_compression']) ? (int)$srv['m3u_compression'] : 0;
            $logo_mode = isset($srv['logo_mode']) ? $srv['logo_mode'] : 'proxy';
            $stream_method = isset($srv['stream_method']) ? $srv['stream_method'] : 'full_proxy';
            $link_format = isset($srv['link_format']) ? $srv['link_format'] : 'proxy';
            $bypass_ip = isset($srv['bypass_ip']) ? $srv['bypass_ip'] : false;
            $show_link = isset($srv['show_link']) ? $srv['show_link'] : false;
            $group_vod  = isset($srv['group_vod']) ? $srv['group_vod'] : false;
            $group_series  = isset($srv['group_series']) ? $srv['group_series'] : true;
            $enforce_expiry = isset($srv['enforce_expiry']) ? $srv['enforce_expiry'] : false;
            $enforce_conn = isset($srv['enforce_conn']) ? $srv['enforce_conn'] : false;
            $created_date = date('Y-m-d', $srv['created_at'] ?? time());
            $exp_date = date('Y-m-d', $srv['exp_date'] ?? strtotime('+1 year'));
        ?>
        <div class="card">
            <h3 style="margin:0 0 10px 0; color:var(--acc);"><i class="fas fa-server"></i> <?php echo htmlspecialchars($srv['name']); ?></h3>
            
            <div style="background:#010409; padding:10px; border-radius:6px; margin-bottom:15px; font-size:0.85rem; border:1px solid var(--border);">
                <div>يوزر: <b><?php echo htmlspecialchars($srv['c_user']); ?></b> | باسورد: <b><?php echo htmlspecialchars($srv['c_pass']); ?></b></div>
                <div style="margin-top:5px; color:#8b949e;"><i class="fas fa-calendar-alt"></i> تاريخ الانتهاء: <b style="color:var(--red);"><?php echo $exp_date; ?></b></div>
                <div style="margin-top:5px; color:var(--green);"><i class="fas fa-check-circle"></i> القنوات المحددة: <b><?php echo $count; ?></b></div>
            </div>

            <div style="margin-bottom: 15px; padding:10px; border:1px solid var(--border); border-radius:6px; background:#0d1117;">
                
                <label style="font-size: 0.80rem; color: #8b949e; display:block; margin-bottom:5px;">امتداد روابط (البث المباشر):</label>
                <select id="proxy_ext_<?php echo $id; ?>" onchange="updateProxySettings('<?php echo $id; ?>')" style="padding: 6px; font-size:0.85rem;">
                    <option value="m3u8" <?php echo $p_ext=='m3u8'?'selected':''; ?>>m3u8 (الأكثر استقراراً)</option>
                    <option value="ts" <?php echo $p_ext=='ts'?'selected':''; ?>>ts</option>
                    <option value="mpd" <?php echo $p_ext=='mpd'?'selected':''; ?>>mpd</option>
                    <option value="none" <?php echo $p_ext=='none'?'selected':''; ?>>بدون امتداد (none)</option>
                    <option value="none_no_live" <?php echo $p_ext=='none_no_live'?'selected':''; ?> style="color:var(--org);font-weight:bold;">بدون امتداد وبدون مسار live (/user/pass/id)</option>
                </select>

                <div style="display:flex; gap:10px; margin-top:10px;">
                    <div style="flex:1;">
                        <label style="font-size: 0.80rem; color: #8b949e; display:block; margin-bottom:5px;">امتداد الأفلام (VOD):</label>
                        <select id="vod_ext_<?php echo $id; ?>" onchange="updateProxySettings('<?php echo $id; ?>')" style="padding: 6px; font-size:0.85rem;">
                            <option value="mp4" <?php echo $vod_ext=='mp4'?'selected':''; ?>>mp4 (افتراضي)</option>
                            <option value="mkv" <?php echo $vod_ext=='mkv'?'selected':''; ?>>mkv</option>
                            <option value="ts" <?php echo $vod_ext=='ts'?'selected':''; ?>>ts</option>
                            <option value="m3u8" <?php echo $vod_ext=='m3u8'?'selected':''; ?>>m3u8</option>
                            <option value="mpd" <?php echo $vod_ext=='mpd'?'selected':''; ?>>mpd</option>
                            <option value="original" <?php echo $vod_ext=='original'?'selected':''; ?>>نفس الامتداد الأصلي</option>
                            <option value="none" <?php echo $vod_ext=='none'?'selected':''; ?>>بدون</option>
                            <option value="same_as_live" <?php echo $vod_ext=='same_as_live'?'selected':''; ?>>مثل المباشر</option>
                        </select>
                    </div>
                    <div style="flex:1;">
                        <label style="font-size: 0.80rem; color: #8b949e; display:block; margin-bottom:5px;">امتداد المسلسلات:</label>
                        <select id="series_ext_<?php echo $id; ?>" onchange="updateProxySettings('<?php echo $id; ?>')" style="padding: 6px; font-size:0.85rem;">
                            <option value="mp4" <?php echo $series_ext=='mp4'?'selected':''; ?>>mp4 (افتراضي)</option>
                            <option value="mkv" <?php echo $series_ext=='mkv'?'selected':''; ?>>mkv</option>
                            <option value="ts" <?php echo $series_ext=='ts'?'selected':''; ?>>ts</option>
                            <option value="m3u8" <?php echo $series_ext=='m3u8'?'selected':''; ?>>m3u8</option>
                            <option value="mpd" <?php echo $series_ext=='mpd'?'selected':''; ?>>mpd</option>
                            <option value="original" <?php echo $series_ext=='original'?'selected':''; ?>>نفس الامتداد الأصلي</option>
                            <option value="none" <?php echo $series_ext=='none'?'selected':''; ?>>بدون</option>
                            <option value="same_as_live" <?php echo $series_ext=='same_as_live'?'selected':''; ?>>مثل المباشر</option>
                        </select>
                    </div>
                </div>

                <label style="font-size: 0.80rem; color: #8b949e; display:block; margin-bottom:5px; margin-top:10px;">عدد المتصلين (Max Connections):</label>
                <input type="number" id="max_conn_<?php echo $id; ?>" value="<?php echo isset($srv['max_connections']) ? htmlspecialchars($srv['max_connections']) : '1'; ?>" onchange="updateProxySettings('<?php echo $id; ?>')" style="padding:6px; width:100%; background:#010409; color:#fff; border:1px solid var(--border); border-radius:6px; margin-bottom:10px;">

                <label style="font-size: 0.80rem; color: #8b949e; display:block; margin-bottom:5px; margin-top:10px;">ضغط بيانات API & M3U (Gzip):</label>
                <select id="m3u_compression_<?php echo $id; ?>" onchange="updateProxySettings('<?php echo $id; ?>')" style="padding: 6px; font-size:0.85rem; border-color:var(--green);">
                    <option value="0" <?php echo $m3u_compression==0?'selected':''; ?>>بدون ضغط (افتراضي)</option>
                    <option value="1" <?php echo $m3u_compression==1?'selected':''; ?>>مستوى 1 (خفيف وسريع جداً)</option>
                    <option value="5" <?php echo $m3u_compression==5?'selected':''; ?>>مستوى 5 (متوازن)</option>
                    <option value="9" <?php echo $m3u_compression==9?'selected':''; ?>>مستوى 9 (أقصى ضغط)</option>
                </select>

                <label style="font-size: 0.80rem; color: #8b949e; display:block; margin-bottom:5px; margin-top:10px;">وضع صور القنوات (اللوجو):</label>
                <select id="logo_mode_<?php echo $id; ?>" onchange="updateProxySettings('<?php echo $id; ?>')" style="padding: 6px; font-size:0.85rem;">
                    <option value="proxy" <?php echo $logo_mode=='proxy'?'selected':''; ?>>بروكسي (إخفاء الرابط الأصلي)</option>
                    <option value="direct" <?php echo $logo_mode=='direct'?'selected':''; ?>>مباشر (بدون حماية)</option>
                    <option value="none" <?php echo $logo_mode=='none'?'selected':''; ?>>بدون صور نهائياً</option>
                </select>

                <label style="font-size: 0.80rem; color: #8b949e; display:block; margin-bottom:5px; margin-top:10px;">نظام التوجيه وحماية الروابط:</label>
                <select id="stream_method_<?php echo $id; ?>" onchange="updateProxySettings('<?php echo $id; ?>')" style="padding: 6px; font-size:0.85rem; border-color:var(--org);">
                    <option value="ultra_beast" <?php echo $stream_method=='ultra_beast'?'selected':''; ?>>بروكسي الوحش (أقصى استهلاك للباندويث)</option>
                    <option value="full_proxy" <?php echo $stream_method=='full_proxy'?'selected':''; ?>>بروكسي كامل (أعلى حماية - استهلاك متوازن)</option>
                    <option value="m3u8_text_only" <?php echo $stream_method=='m3u8_text_only'?'selected':''; ?>>بروكسي M3U8 فقط (صفر استهلاك)</option>
                    <option value="redirect" <?php echo $stream_method=='redirect'?'selected':''; ?>>إعادة توجيه Redirect (بدون حماية)</option>
                </select>
                
                <label style="font-size: 0.80rem; color: #8b949e; display:block; margin-bottom:5px; margin-top:10px;">شكل الروابط في القائمة (Link Format):</label>
                <select id="link_format_<?php echo $id; ?>" onchange="updateProxySettings('<?php echo $id; ?>')" style="padding: 6px; font-size:0.85rem; border-color:var(--acc);">
                    <option value="proxy" <?php echo $link_format=='proxy'?'selected':''; ?>>مخفي / بروكسي (index.php/video?id=...)</option>
                    <option value="standard" <?php echo $link_format=='standard'?'selected':''; ?>>Xtream الأصلي (مباشر /live/user/pass/id.ext)</option>
                </select>
                <hr style="border: 1px solid #222; margin: 15px 0;">
                
                <div style="margin-top:10px; display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" id="enforce_expiry_<?php echo $id; ?>" onchange="updateProxySettings('<?php echo $id; ?>')" <?php echo $enforce_expiry ? 'checked' : ''; ?>>
                    <label for="enforce_expiry_<?php echo $id; ?>" style="font-size: 0.80rem; color: #ff7b72; font-weight:bold;">تفعيل الانتهاء الصارم (إيقاف الاشتراك بعد التاريخ)</label>
                </div>

                <div style="margin-top:10px; display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" id="enforce_conn_<?php echo $id; ?>" onchange="updateProxySettings('<?php echo $id; ?>')" <?php echo $enforce_conn ? 'checked' : ''; ?>>
                    <label for="enforce_conn_<?php echo $id; ?>" style="font-size: 0.80rem; color: #ff7b72; font-weight:bold;">تفعيل نظام طرد المتصلين الحقيقي (Max Connections)</label>
                </div>

                <hr style="border: 1px solid #222; margin: 15px 0;">

                <div style="margin-top:10px; display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" id="bypass_ip_<?php echo $id; ?>" onchange="updateProxySettings('<?php echo $id; ?>')" <?php echo $bypass_ip ? 'checked' : ''; ?>>
                    <label for="bypass_ip_<?php echo $id; ?>" style="font-size: 0.80rem; color: var(--acc); font-weight:bold;">تخطي الهوست والاتصال بالـ IP الأصلي للسيرفر</label>
                </div>

                <div style="margin-top:10px; display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" id="group_vod_<?php echo $id; ?>" onchange="updateProxySettings('<?php echo $id; ?>')" <?php echo $group_vod ? 'checked' : ''; ?>>
                    <label for="group_vod_<?php echo $id; ?>" style="font-size: 0.80rem; color: #8b949e;">تجميع الأفلام داخل مجلد (VOD)</label>
                </div>

                <div style="margin-top:10px; display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" id="group_series_<?php echo $id; ?>" onchange="updateProxySettings('<?php echo $id; ?>')" <?php echo $group_series ? 'checked' : ''; ?>>
                    <label for="group_series_<?php echo $id; ?>" style="font-size: 0.80rem; color: #8b949e; font-weight:bold;">تجميع كل مسلسل في مجلد خاص وبداخله حلقاته</label>
                </div>

                <div style="margin-top:10px; display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" id="show_link_<?php echo $id; ?>" onchange="updateProxySettings('<?php echo $id; ?>')" <?php echo $show_link ? 'checked' : ''; ?>>
                    <label for="show_link_<?php echo $id; ?>" style="font-size: 0.80rem; color: #8b949e;">إظهار الرابط المصطاد في مشغل المتصفح</label>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; margin-bottom: 10px;" dir="ltr">
                <button class="btn btn-acc" onclick="copyListLink('<?php echo $srv['c_user']; ?>', '<?php echo $srv['c_pass']; ?>', 'm3u_plus')"><i class="fas fa-copy"></i> M3U Plus</button>
                <button class="btn btn-org" onclick="copyListLink('<?php echo $srv['c_user']; ?>', '<?php echo $srv['c_pass']; ?>', 'm3u')"><i class="fas fa-copy"></i> Normal</button>
            </div>
            
            <div id="export_res_<?php echo $id; ?>" style="margin-bottom:10px;"></div>
            <button class="btn btn-dark" style="width:100%; margin-bottom: 10px;" onclick="exportStaticM3U('<?php echo $id; ?>')"><i class="fas fa-file-export"></i> إنشاء M3U ثابت</button>
            <button class="btn btn-green" style="width:100%; margin-bottom: 10px;" onclick="openBuilder('<?php echo $id; ?>')"><i class="fas fa-link"></i> إدارة وتحديد القنوات (Builder)</button>
            <button class="btn btn-dark" style="width:100%; margin-bottom: 10px;" onclick="addAllXtream('<?php echo $id; ?>')"><i class="fas fa-bolt"></i> سحب كامل السيرفر (آمن وسريع)</button>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
                <button class="btn btn-org" onclick="openEditModal('<?php echo $id; ?>', '<?php echo htmlspecialchars($srv['host']); ?>', '<?php echo htmlspecialchars($srv['user']); ?>', '<?php echo htmlspecialchars($srv['pass']); ?>', '<?php echo htmlspecialchars($srv['c_user']); ?>', '<?php echo htmlspecialchars($srv['c_pass']); ?>', '<?php echo $created_date; ?>', '<?php echo $exp_date; ?>')"><i class="fas fa-edit"></i> تعديل المصدر</button>
                <a href="?delete=<?php echo $id; ?>" class="btn btn-red" onclick="return confirm('حذف الباقة نهائياً؟')"><i class="fas fa-trash"></i> حذف الباقة</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div id="edit_modal" class="modal">
    <div class="modal-content">
        <h3 style="margin-top:0; color:var(--acc);">تعديل المصدر (Xtream)</h3>
        
        <select id="e_login_method" onchange="toggleEditLoginType()" style="margin-bottom:15px;">
            <option value="xtream">التعديل يدوياً (هوست، يوزر، باس)</option>
            <option value="m3u">تحديث واستخراج عبر رابط M3U Plus</option>
        </select>

        <form onsubmit="submitEditForm(event)">
            <input type="hidden" id="e_id">
            
            <div id="e_m3u_inputs" style="display:none; background:#010409; padding:10px; border-radius:6px; border:1px solid var(--border); margin-bottom:10px;">
                <input type="text" id="e_m3u" placeholder="الصق رابط m3u أو m3u_plus الجديد هنا">
                <button type="button" class="btn btn-org" style="width:100%;" onclick="parseEditM3uInput()">استخراج وتحديث الحقول</button>
            </div>

            <div id="e_xtream_inputs">
                <input type="text" id="e_host" placeholder="رابط الهوست http://domain:port" required>
                <input type="text" id="e_user" placeholder="اليوزر الأصلي" required>
                <input type="text" id="e_pass" placeholder="الباسورد الأصلي" required>
            </div>

            <hr style="border:1px solid #333; margin:15px 0;">
            <p style="font-size:0.85rem; color:#8b949e; margin-bottom:5px;">بيانات العميل باللوحة (يمكنك التعديل للحماية):</p>
            <input type="text" id="e_cuser" placeholder="يوزر العميل" required>
            <input type="text" id="e_cpass" placeholder="باسورد العميل" required>
            
            <div style="display:flex; gap:10px; margin-bottom:10px; margin-top:10px;">
                <div style="flex:1;">
                    <label style="font-size: 0.8rem; color: #8b949e;">تاريخ الإنشاء:</label>
                    <input type="date" id="e_created_at" required>
                </div>
                <div style="flex:1;">
                    <label style="font-size: 0.8rem; color: #8b949e;">تاريخ الانتهاء:</label>
                    <input type="date" id="e_exp_date" required>
                </div>
            </div>

            <button type="submit" class="btn btn-acc" style="width:100%;" id="btn_edit">حفظ التعديلات</button>
            <button type="button" class="btn btn-dark" style="width:100%; margin-top:10px;" onclick="document.getElementById('edit_modal').style.display='none'">إلغاء</button>
        </form>
    </div>
</div>

<div id="m3u_modal" class="modal">
    <div class="modal-content">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h3 style="margin:0; color:var(--acc);"><i class="fas fa-folder-open"></i> إدارة ملفات M3U</h3>
            <button class="btn btn-red" style="padding:5px 10px;" onclick="document.getElementById('m3u_modal').style.display='none'"><i class="fas fa-times"></i></button>
        </div>
        <div id="m3u_files_container">جاري التحميل...</div>
    </div>
</div>

<div id="add_modal" class="modal">
    <div class="modal-content">
        <h3 style="margin-top:0; color:var(--acc);">إضافة بيانات الاشتراك</h3>
        
        <select id="login_method" onchange="toggleLoginType()" style="margin-bottom:15px;">
            <option value="xtream">تفاصيل Xtream (هوست، يوزر، باس)</option>
            <option value="m3u">استخراج من رابط M3U Plus</option>
            <option value="mac">إضافة عبر الماك ادريس (MAC)</option>
        </select>

        <form onsubmit="submitAddForm(event)">
            <input type="text" id="a_name" placeholder="اسم توضيحي للباقة" required>
            
            <div id="xtream_inputs">
                <input type="text" id="a_host" placeholder="رابط الهوست http://domain:port" required>
                <input type="text" id="a_user" placeholder="اليوزر الأصلي" required>
                <input type="text" id="a_pass" placeholder="الباسورد الأصلي" required>
            </div>

            <div id="m3u_inputs" style="display:none; background:#010409; padding:10px; border-radius:6px; border:1px solid var(--border); margin-bottom:10px;">
                <input type="text" id="a_m3u" placeholder="الصق رابط m3u أو m3u_plus هنا">
                <button type="button" class="btn btn-org" style="width:100%;" onclick="parseM3uInput()">استخراج البيانات</button>
            </div>

            <div id="mac_inputs" style="display:none; background:#010409; padding:10px; border-radius:6px; border:1px solid var(--border); margin-bottom:10px;">
                <p style="font-size:0.8rem; color:var(--green); margin-top:0;">* تم تحديث النظام ليحاكي أجهزة STB لضمان قبول الماك.</p>
                <input type="text" id="a_mac_host" placeholder="رابط البورتال http://domain:port">
                <input type="text" id="a_mac" placeholder="00:1A:79:XX:XX:XX">
            </div>

            <hr style="border:1px solid #333; margin:15px 0;">
            <p style="font-size:0.85rem; color:#8b949e; margin-bottom:5px;">بيانات العميل باللوحة:</p>
            <input type="text" id="a_cuser" placeholder="يوزر العميل الجديد" required>
            <input type="text" id="a_cpass" placeholder="باسورد العميل الجديد" required>
            
            <div style="display:flex; gap:10px; margin-bottom:10px;">
                <div style="flex:1;">
                    <label style="font-size: 0.8rem; color: #8b949e;">تاريخ الإنشاء:</label>
                    <input type="date" id="a_created_at" required value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div style="flex:1;">
                    <label style="font-size: 0.8rem; color: #8b949e;">تاريخ الانتهاء:</label>
                    <input type="date" id="a_exp_date" required value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-acc" style="width:100%;" id="btn_add">حفظ والاتصال</button>
            <button type="button" class="btn btn-dark" style="width:100%; margin-top:10px;" onclick="document.getElementById('add_modal').style.display='none'">إلغاء</button>
        </form>
    </div>
</div>

<div class="container" id="builder_view">
    <div style="background:var(--card); border:1px solid var(--border); border-radius:10px; padding:15px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h3 style="margin:0;"><i class="fas fa-tasks"></i> القنوات والروابط</h3>
            <div>
                <button class="btn btn-green" onclick="savePlaylist()"><i class="fas fa-save"></i> حفظ</button>
                <button class="btn btn-red" onclick="window.location.href='index.php'"><i class="fas fa-times"></i> رجوع</button>
            </div>
        </div>
        <div class="tabs">
            <div class="tab active" onclick="loadCats('live', this)">بث مباشر</div>
            <div class="tab" onclick="loadCats('vod', this)">أفلام</div>
            <div class="tab" onclick="loadCats('series', this)">مسلسلات</div>
            <div class="tab" style="background:var(--green); color:#fff; border-color:var(--green);" onclick="loadAddedItems(this)"><i class="fas fa-star"></i> المحتوى المضاف</div>
        </div>
        <div class="builder-grid">
            <div class="panel" id="cats_panel"> <div id="cats_list"></div> </div>
            <div class="panel" id="streams_panel" style="display:none;">
                <div style="display:flex; justify-content:space-between; margin-bottom:10px; border-bottom:1px solid var(--border); padding-bottom:10px;">
                    <button class="btn btn-dark" onclick="backToCats()">رجوع للأقسام</button>
                    <div><button class="btn btn-dark" onclick="checkAll(true)">الكل</button> <button class="btn btn-dark" onclick="checkAll(false)">إلغاء</button></div>
                </div>
                <div id="streams_list"></div>
            </div>
        </div>
    </div>
</div>

<script>
let cur_srv = ''; let cur_type = 'live'; let cur_cat_name = ''; let my_playlist = {}; let activeCatId = '';
const baseUrl = "<?php echo $base_url; ?>";
function showT(msg) { const t = document.getElementById('toast'); t.innerText = msg; t.style.display = 'block'; setTimeout(() => t.style.display = 'none', 4000); }
function copyListLink(u, p, type) { navigator.clipboard.writeText(`${baseUrl}/get.php?username=${u}&password=${p}&type=${type}`).then(() => showT("تم نسخ الرابط بنجاح!")); }

function toggleEditLoginType() {
    let t = document.getElementById('e_login_method').value;
    document.getElementById('e_m3u_inputs').style.display = t === 'm3u' ? 'block' : 'none';
}

function parseEditM3uInput() {
    try {
        let u = new URL(document.getElementById('e_m3u').value);
        document.getElementById('e_host').value = u.origin;
        document.getElementById('e_user').value = u.searchParams.get('username') || '';
        document.getElementById('e_pass').value = u.searchParams.get('password') || '';
        showT("تم استخراج البيانات وتحديث الحقول بنجاح!");
    } catch(e) { showT("رابط غير صالح"); }
}

function openEditModal(id, host, user, pass, cuser, cpass, created_at, exp_date) {
    document.getElementById('e_id').value = id;
    document.getElementById('e_host').value = host;
    document.getElementById('e_user').value = user;
    document.getElementById('e_pass').value = pass;
    document.getElementById('e_cuser').value = cuser;
    document.getElementById('e_cpass').value = cpass;
    document.getElementById('e_created_at').value = created_at;
    document.getElementById('e_exp_date').value = exp_date;
    document.getElementById('edit_modal').style.display = 'flex';
}

async function submitEditForm(e) {
    e.preventDefault();
    document.getElementById('btn_edit').innerHTML = 'جاري الحفظ...';
    const id = document.getElementById('e_id').value;
    const payload = {
        host: document.getElementById('e_host').value,
        user: document.getElementById('e_user').value,
        pass: document.getElementById('e_pass').value,
        c_user: document.getElementById('e_cuser').value,
        c_pass: document.getElementById('e_cpass').value,
        created_at: document.getElementById('e_created_at').value,
        exp_date: document.getElementById('e_exp_date').value
    };
    try {
        await fetch(`?ajax=edit_server&s_id=${id}`, { method: 'POST', body: JSON.stringify(payload) });
        showT("تم التعديل بنجاح!");
        setTimeout(() => window.location.reload(), 1500);
    } catch(err) {
        alert("حدث خطأ.");
        document.getElementById('btn_edit').innerHTML = 'حفظ التعديلات';
    }
}

async function updateProxySettings(srvId) { 
    const ext = document.getElementById('proxy_ext_' + srvId).value;
    const vod_ext = document.getElementById('vod_ext_' + srvId).value;
    const series_ext = document.getElementById('series_ext_' + srvId).value;
    const m3u_compression = document.getElementById('m3u_compression_' + srvId).value;
    const logo_mode = document.getElementById('logo_mode_' + srvId).value;
    const stream_method = document.getElementById('stream_method_' + srvId).value;
    const link_format = document.getElementById('link_format_' + srvId) ? document.getElementById('link_format_' + srvId).value : 'proxy';
    const chk_bypass = document.getElementById('bypass_ip_' + srvId).checked;
    const chk_link = document.getElementById('show_link_' + srvId).checked;
    const group_vod = document.getElementById('group_vod_' + srvId) ? document.getElementById('group_vod_' + srvId).checked : false;
    const group_series = document.getElementById('group_series_' + srvId) ? document.getElementById('group_series_' + srvId).checked : false;
    const max_conn = document.getElementById('max_conn_' + srvId).value;
    const enforce_expiry = document.getElementById('enforce_expiry_' + srvId) ? document.getElementById('enforce_expiry_' + srvId).checked : false;
    const enforce_conn = document.getElementById('enforce_conn_' + srvId) ? document.getElementById('enforce_conn_' + srvId).checked : false;
    
    await fetch(`?ajax=save_proxy_settings&s_id=${srvId}`, { 
        method: 'POST', body: JSON.stringify({ 
            ext: ext, vod_ext: vod_ext, series_ext: series_ext, m3u_compression: m3u_compression,
            logo_mode: logo_mode, stream_method: stream_method, link_format: link_format, bypass_ip: chk_bypass,
            show_link: chk_link, group_vod: group_vod, group_series: group_series, max_connections: max_conn,
            enforce_expiry: enforce_expiry, enforce_conn: enforce_conn
        }) 
    });
    showT("تم حفظ الإعدادات بنجاح!"); 
}

function toggleLoginType() {
    let t = document.getElementById('login_method').value;
    document.getElementById('m3u_inputs').style.display = t === 'm3u' ? 'block' : 'none';
    document.getElementById('xtream_inputs').style.display = (t === 'xtream' || t === 'm3u') ? 'block' : 'none';
    document.getElementById('mac_inputs').style.display = t === 'mac' ? 'block' : 'none';
    
    document.getElementById('a_host').required = (t !== 'mac');
    document.getElementById('a_user').required = (t !== 'mac');
    document.getElementById('a_pass').required = (t !== 'mac');
}

function parseM3uInput() {
    try {
        let u = new URL(document.getElementById('a_m3u').value);
        document.getElementById('a_host').value = u.origin;
        document.getElementById('a_user').value = u.searchParams.get('username') || '';
        document.getElementById('a_pass').value = u.searchParams.get('password') || '';
        showT("تم استخراج البيانات بنجاح!");
    } catch(e) { showT("رابط غير صالح"); }
}

async function exportStaticM3U(srvId) {
    showT("جاري الإنشاء..."); const res = await fetch(`?ajax=export_static&s_id=${srvId}`);
    const data = await res.json();
    if(data.success) {
        document.getElementById('export_res_' + srvId).innerHTML = `<div style="display:flex; gap:5px; margin-bottom:10px;"><a href="${data.file}" download class="btn btn-green" style="flex:1;"><i class="fas fa-download"></i> تحميل الآن</a><button class="btn btn-org" style="flex:1;" onclick="navigator.clipboard.writeText('${data.link}'); showT('تم النسخ');"><i class="fas fa-copy"></i> الرابط</button></div>`;
        showT("تم الإنشاء والنسخ!");
    }
}

async function openM3uManager() {
    document.getElementById('m3u_modal').style.display='flex';
    const container = document.getElementById('m3u_files_container'); container.innerHTML = 'جاري التحميل...';
    const files = await (await fetch('?ajax=list_static')).json();
    if(files.length === 0) { container.innerHTML = '<p style="text-align:center;">لا يوجد ملفات حالياً</p>'; return; }
    let html = '';
    files.forEach(f => { html += `<div class="m3u-list-item"><div><b style="color:var(--acc);">${f.name}</b><br><span style="color:#8b949e; font-size:0.75rem;">${f.size}</span></div><div style="display:flex; gap:5px;"><button class="btn btn-org" style="padding:6px; font-size:12px;" onclick="navigator.clipboard.writeText('${baseUrl}/${f.name}'); showT('تم النسخ');"><i class="fas fa-link"></i></button><a href="${f.name}" download class="btn btn-green" style="padding:6px; font-size:12px;"><i class="fas fa-download"></i></a><button class="btn btn-red" style="padding:6px; font-size:12px;" onclick="deleteStatic('${f.name}')"><i class="fas fa-trash"></i></button></div></div>`; });
    container.innerHTML = html;
}
async function deleteStatic(filename) { if(!confirm('متأكد؟')) return; await fetch('?ajax=delete_static', { method: 'POST', body: JSON.stringify({file: filename}) }); showT("تم الحذف"); openM3uManager(); }

function copySingleLink(id, type) {
    const extVal = document.getElementById('proxy_ext_' + cur_srv).value;
    const extPath = (extVal === 'none' || extVal === 'none_no_live') ? '' : `.${extVal}`;
    navigator.clipboard.writeText(`${baseUrl}/index.php/video${extPath}?id=${cur_srv}---${type}---${id}`).then(() => showT("تم النسخ!"));
}

async function submitAddForm(e) {
    e.preventDefault(); document.getElementById('btn_add').innerHTML = 'جاري الاتصال...';
    let h='', u='', p='';
    if (document.getElementById('login_method').value === 'mac') {
        h = document.getElementById('a_mac_host').value; u = document.getElementById('a_mac').value; p = u; 
    } else {
        h = document.getElementById('a_host').value; u = document.getElementById('a_user').value; p = document.getElementById('a_pass').value;
    }
    const payload = { 
        name: document.getElementById('a_name').value, host: h, user: u, pass: p, 
        c_user: document.getElementById('a_cuser').value, c_pass: document.getElementById('a_cpass').value,
        created_at: document.getElementById('a_created_at').value, exp_date: document.getElementById('a_exp_date').value
    };
    try {
        const data = await (await fetch('?ajax=add_server', { method: 'POST', body: JSON.stringify(payload) })).json();
        if (data.error) { 
            alert(data.error); document.getElementById('btn_add').innerHTML = 'حفظ والاتصال';
        } else { 
            document.getElementById('add_modal').style.display = 'none'; window.location.href = "?open_builder=" + data.id;
        }
    } catch(err) { alert("حدث خطأ في الاتصال، ربما البيانات خاطئة."); document.getElementById('btn_add').innerHTML = 'حفظ والاتصال'; }
}

async function addAllXtream(srvId) {
    if(!confirm("تم تفعيل خاصية Gzip السرية لسحب السيرفرات الضخمة في ثوانٍ. استمرار؟")) return;
    try { 
        showT("جاري سحب البث المباشر بسرعة جبارة..."); await fetch(`?ajax=bulk_pull&t=live&s_id=${srvId}`);
        showT("جاري سحب الأفلام (بسرعة فائقة)..."); await fetch(`?ajax=bulk_pull&t=vod&s_id=${srvId}`);
        showT("جاري سحب المسلسلات واستخراج الحلقات..."); await fetch(`?ajax=bulk_pull&t=series&s_id=${srvId}`);
        showT("تم سحب كامل المحتوى بنجاح!");
        setTimeout(() => window.location.reload(), 2000); 
    } catch(err) { showT("خطأ أثناء السحب."); }
}

async function openBuilder(srvId) {
    cur_srv = srvId; document.getElementById('main_view').style.display = 'none'; document.getElementById('builder_view').style.display = 'block';
    try { const arr = await (await fetch(`playlist_${srvId}.json?t=${Date.now()}`)).json(); my_playlist = {}; arr.forEach(i => my_playlist[`${i.type}_${i.id}`] = i); } catch(e) { my_playlist = {}; }
    document.querySelector('.tab').click();
}

async function loadCats(type, el) {
    cur_type = type; document.querySelectorAll('.tab').forEach(t => t.classList.remove('active')); el.classList.add('active');
    const list = document.getElementById('cats_list');
    list.innerHTML = '<center>جاري التحميل...</center>'; document.getElementById('cats_panel').style.display = 'block'; document.getElementById('streams_panel').style.display = 'none';
    const cats = await (await fetch(`?ajax=cats&s_id=${cur_srv}&t=${type}`)).json();
    let html = `<div style="display:flex; gap:10px; margin-bottom:10px; padding-bottom:10px; border-bottom:1px solid var(--border);">
        <button class="btn btn-acc" style="flex:1;" onclick="pullVisibleCats()"><i class="fas fa-check-double"></i> تحديد كل المعروض</button>
        <button class="btn btn-dark" style="flex:1;" onclick="removeAllVisibleCats()"><i class="fas fa-times"></i> إلغاء الكل</button>
    </div>`;
    
    if(cats && cats.length > 0) {
        cats.forEach(c => { html += `<div class="list-item cat-row" data-id="${c.category_id}" data-name="${encodeURIComponent(c.category_name)}"><span style="flex:1" onclick="selectCat('${c.category_id}', '${encodeURIComponent(c.category_name)}')">${c.category_name}</span> <button class="btn btn-acc pull-btn" style="padding:4px 8px; font-size:10px;" onclick="addAll('${c.category_id}', '${encodeURIComponent(c.category_name)}', this)">إضافة</button></div>`; });
    } else { html += `<center>لا توجد أقسام هنا</center>`; }
    list.innerHTML = html;
}

async function pullVisibleCats() {
    const btns = document.querySelectorAll('.pull-btn');
    if(!confirm(`هل أنت متأكد من سحب وتحديد ${btns.length} قسم؟`)) return;
    for(let btn of btns) { await btn.click(); }
    showT("تم الانتهاء من تحديد الكل!");
}

async function removeAllVisibleCats() {
    if(!confirm(`إزالة جميع هذه الأقسام من القائمة المضافة؟`)) return;
    const rows = document.querySelectorAll('.cat-row');
    rows.forEach(r => {
        const cName = decodeURIComponent(r.getAttribute('data-name'));
        Object.keys(my_playlist).forEach(k => { if(my_playlist[k].type === cur_type && my_playlist[k].category === cName) delete my_playlist[k]; });
        r.querySelector('.pull-btn').innerText = 'إضافة'; r.querySelector('.pull-btn').classList.replace('btn-green','btn-acc');
    });
    await savePlaylistSilently(); showT("تم الإلغاء!");
}

function selectCat(catId, encodedCatName) { activeCatId = catId; loadStreams(catId, decodeURIComponent(encodedCatName)); }

async function addAll(catId, encodedCatName, btnEl) {
    btnEl.innerText = '...'; const catName = decodeURIComponent(encodedCatName);
    const streams = await (await fetch(`?ajax=streams&s_id=${cur_srv}&t=${cur_type}&c_id=${catId}`)).json();
    if(streams) { 
        streams.forEach(st => { const id = st.stream_id || st.series_id || st.id; const ext = st.container_extension || 'mp4'; 
        const extra_data = cur_type === 'series' ? { series_id: st.series_id } : {};
        my_playlist[`${cur_type}_${id}`] = Object.assign({ id: id, name: st.name || st.title || 'Unknown', logo: st.stream_icon || st.cover || '', type: cur_type, category: catName, ext: ext }, extra_data); });
        savePlaylistSilently(); btnEl.innerText = 'تم'; btnEl.classList.replace('btn-acc','btn-green'); 
    }
}

function backToCats() { document.getElementById('streams_panel').style.display = 'none'; document.getElementById('cats_panel').style.display = 'block'; }

async function loadStreams(catId, catName) {
    cur_cat_name = catName; const list = document.getElementById('streams_list'); list.innerHTML = '<center>جاري التحميل...</center>';
    document.getElementById('cats_panel').style.display = 'none'; document.getElementById('streams_panel').style.display = 'block';
    if(cur_type === 'series') showT("جاري سحب الحلقات للمسلسلات، قد يستغرق الأمر ثواني معدودة...");
    const streams = await (await fetch(`?ajax=streams&s_id=${cur_srv}&t=${cur_type}&c_id=${catId}`)).json(); list.innerHTML = '';
    if(!streams || streams.length === 0) { list.innerHTML = '<center>فارغ</center>'; return; }
    
    let streamHtml = [];
    streams.forEach(st => {
        const id = st.stream_id || st.series_id || st.id; const name = st.name || st.title || 'Unknown'; const logo = st.stream_icon || st.cover || ''; const ext = st.container_extension || 'mp4'; const key = `${cur_type}_${id}`; const checked = my_playlist[key] ? 'checked' : '';
        const data_series_id = cur_type === 'series' ? `data-series-id="${st.series_id}"` : '';
        streamHtml.push(`<div class="list-item"><div class="channel-info" onclick="this.parentElement.querySelector('input').click()"><img src="${logo || 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs='}" onerror="this.style.display='none'"><span title="${name}">${name}</span></div><div style="display:flex; gap:10px; align-items:center;"><button class="btn btn-org" style="padding:4px 8px; font-size:10px;" onclick="copySingleLink('${id}', '${cur_type}')"><i class="fas fa-link"></i></button><input type="checkbox" value="${id}" data-n="${encodeURIComponent(name)}" data-l="${encodeURIComponent(logo)}" data-ext="${ext}" ${data_series_id} ${checked} onchange="toggleCh(this)"></div></div>`);
    });
    list.innerHTML = streamHtml.join('');
}

function toggleCh(chk) { 
    const key = `${cur_type}_${chk.value}`;
    if (chk.checked) {
        let payload = { id: chk.value, name: decodeURIComponent(chk.getAttribute('data-n')), logo: decodeURIComponent(chk.getAttribute('data-l')), type: cur_type, category: cur_cat_name, ext: chk.getAttribute('data-ext') };
        if(cur_type === 'series') payload.series_id = chk.getAttribute('data-series-id');
        my_playlist[key] = payload;
    } else { delete my_playlist[key]; }
}
function checkAll(status) { document.querySelectorAll('#streams_list input[type="checkbox"]').forEach(chk => { if(chk.checked !== status) chk.click(); }); savePlaylistSilently(); }

function loadAddedItems(el) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active')); el.classList.add('active');
    document.getElementById('streams_panel').style.display = 'none'; document.getElementById('cats_panel').style.display = 'block';
    const list = document.getElementById('cats_list'); list.innerHTML = '';
    
    const groups = {};
    Object.values(my_playlist).forEach(item => { if(!groups[item.category]) groups[item.category] = []; groups[item.category].push(item); });
    if(Object.keys(groups).length === 0) { list.innerHTML = '<center>لا توجد قنوات مضافة بعد</center>'; return; }
    
    let htmlArr = [];
    for(const [cat, items] of Object.entries(groups)) {
        htmlArr.push(`<details class="cat-group"><summary onclick="if(event.target.tagName==='BUTTON' || event.target.tagName==='I') event.preventDefault();"><span style="flex:1;">${cat} <small style="color:var(--green);">(${items.length})</small></span> <button class="btn btn-red" style="padding:4px 8px; font-size:10px;" onclick="delSavedCat('${encodeURIComponent(cat)}')"><i class="fas fa-trash"></i> حذف القسم</button></summary><div style="padding:10px; max-height:400px; overflow-y:auto; background:#0d1117;">`);
        let itemsHtml = [];
        items.forEach(i => { itemsHtml.push(`<div style="display:flex; justify-content:space-between; margin-bottom:5px; border-bottom:1px solid #222; padding-bottom:5px;"><span style="font-size:0.85rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:80%;">${i.name}</span><button class="btn btn-red" style="padding:2px 6px; font-size:10px;" onclick="delSavedItem('${i.type}_${i.id}')"><i class="fas fa-times"></i></button></div>`); });
        htmlArr.push(itemsHtml.join('')); htmlArr.push(`</div></details>`);
    }
    list.innerHTML = htmlArr.join('');
}

function delSavedCat(encodedCat) { const cat = decodeURIComponent(encodedCat); if(!confirm(`حذف قسم ${cat} بالكامل؟`)) return; Object.keys(my_playlist).forEach(k => { if(my_playlist[k].category === cat) delete my_playlist[k]; }); savePlaylistSilently().then(() => loadAddedItems(document.querySelector('.tab[onclick*="loadAddedItems"]'))); }
function delSavedItem(key) { delete my_playlist[key]; savePlaylistSilently().then(() => loadAddedItems(document.querySelector('.tab[onclick*="loadAddedItems"]'))); }
async function savePlaylistSilently() { await fetch(`?ajax=save&s_id=${cur_srv}`, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({list: Object.values(my_playlist)}) }); }
async function savePlaylist() { await savePlaylistSilently(); showT("تم حفظ القائمة!"); }

window.onload = function() {
    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.has('open_builder')) { openBuilder(urlParams.get('open_builder')); window.history.replaceState({}, document.title, window.location.pathname); }
}
</script></body></html>
