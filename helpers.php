<?php

if (session_status() === PHP_SESSION_NONE) {
    $savePath = ini_get('session.save_path');
    if ($savePath !== false && $savePath !== '' && !is_dir($savePath)) {
        @mkdir($savePath, 0700, true);
    }
    session_start();
}

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Paris');

function e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function generate_token()
{
    if (function_exists('openssl_random_pseudo_bytes')) {
        $bytes = openssl_random_pseudo_bytes(16);
        if ($bytes !== false) {
            return bin2hex($bytes);
        }
    }
    return md5(uniqid(mt_rand(), true));
}

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = generate_token();
    }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check()
{
    $sent = isset($_POST['csrf']) ? $_POST['csrf'] : '';
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        die('Requête invalide (jeton de sécurité manquant). Rechargez la page.');
    }
}

function require_admin()
{
    if (empty($_SESSION['admin'])) {
        header('Location: admin.php');
        exit;
    }
}

function base_url()
{
    if (defined('BASE_URL') && BASE_URL !== '') {
        return rtrim(BASE_URL, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $scheme . '://' . $host . $dir;
}

function child_link($token)
{
    return base_url() . '/parent.php?token=' . rawurlencode($token);
}

function child_name($first_name, $last_initial)
{
    return $first_name . ' ' . upper_initial($last_initial) . '.';
}

function upper_initial($initial)
{
    $initial = trim($initial);
    if ($initial === '') {
        return '';
    }
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        return mb_strtoupper(mb_substr($initial, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return strtoupper(substr($initial, 0, 1));
}

function is_past($date, $time)
{
    return strtotime($date . ' ' . $time) < time();
}

function format_slot($date, $time)
{
    $jours = array(1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche');
    $mois = array(1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
                  'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre');
    $ts = strtotime($date . ' ' . $time);
    if ($ts === false) {
        return $date . ' ' . substr($time, 0, 5);
    }
    $label = $jours[intval(date('N', $ts))] . ' ' . date('j', $ts) . ' ' . $mois[intval(date('n', $ts))] . date(' Y');
    $heure = date('G', $ts) . 'h' . date('i', $ts);
    return ucfirst($label) . ' à ' . $heure;
}

function set_flash($type, $text)
{
    $_SESSION['flash'] = array('type' => $type, 'text' => $text);
}

function take_flash()
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

/* ----- Réglages (stockés en base) ----- */

function get_setting($key, $default = '')
{
    static $loaded = null;
    if ($loaded === null) {
        $loaded = array();
        try {
            $rows = db()->query('SELECT skey, svalue FROM settings')->fetchAll();
            foreach ($rows as $row) {
                $loaded[$row['skey']] = $row['svalue'];
            }
        } catch (PDOException $ex) {}
    }
    return (isset($loaded[$key]) && $loaded[$key] !== '') ? $loaded[$key] : $default;
}

function set_setting($key, $value)
{
    $pdo = db();
    $pdo->prepare('DELETE FROM settings WHERE skey = ?')->execute(array($key));
    $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)')->execute(array($key, $value));
}

function site_title()
{
    return get_setting('site_title', 'Rendez-vous');
}

/* ----- Thèmes de couleurs ----- */

function theme_presets()
{
    return array(
        'rose' => array('label' => 'Rose (par défaut)', 'primary' => '#d6336c', 'dark' => '#b02a5b', 'soft' => '#fdeef4', 'border' => '#f3cfe0', 'bg1' => '#fff5f8', 'bg2' => '#ffe9f0'),
        'bleu' => array('label' => 'Bleu ciel', 'primary' => '#1971c2', 'dark' => '#1864ab', 'soft' => '#e7f5ff', 'border' => '#c5def6', 'bg1' => '#f4f9ff', 'bg2' => '#e7f1ff'),
        'vert' => array('label' => 'Vert tendre', 'primary' => '#2f9e44', 'dark' => '#2b8a3e', 'soft' => '#ebfbee', 'border' => '#c3e6c9', 'bg1' => '#f4fbf4', 'bg2' => '#e6f7e9'),
        'violet' => array('label' => 'Violet', 'primary' => '#7048e8', 'dark' => '#5f3dc4', 'soft' => '#f3f0ff', 'border' => '#dcd6f7', 'bg1' => '#faf8ff', 'bg2' => '#f0ebff'),
        'ambre' => array('label' => 'Orange doux', 'primary' => '#e8590c', 'dark' => '#d9480f', 'soft' => '#fff0e6', 'border' => '#ffd8b3', 'bg1' => '#fff8f0', 'bg2' => '#ffefe2'),
        'turquoise' => array('label' => 'Turquoise', 'primary' => '#0c8599', 'dark' => '#0b7285', 'soft' => '#e3fafc', 'border' => '#c0ebf1', 'bg1' => '#f2fbfc', 'bg2' => '#e3fafc'),
    );
}

function hex_to_rgb($hex)
{
    return array(hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
}

function rgb_to_hex($r, $g, $b)
{
    return sprintf('#%02x%02x%02x', max(0, min(255, intval(round($r)))), max(0, min(255, intval(round($g)))), max(0, min(255, intval(round($b)))));
}

function tint_hex($hex, $amount)
{
    $c = hex_to_rgb($hex);
    return rgb_to_hex($c[0] + (255 - $c[0]) * $amount, $c[1] + (255 - $c[1]) * $amount, $c[2] + (255 - $c[2]) * $amount);
}

function shade_hex($hex, $amount)
{
    $c = hex_to_rgb($hex);
    return rgb_to_hex($c[0] * (1 - $amount), $c[1] * (1 - $amount), $c[2] * (1 - $amount));
}

function theme_colors()
{
    $presets = theme_presets();
    $key = get_setting('theme', 'rose');
    $colors = isset($presets[$key]) ? $presets[$key] : $presets['rose'];
    $custom = get_setting('custom_primary', '');
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $custom)) {
        $custom = strtolower($custom);
        $colors['primary'] = $custom;
        $colors['dark'] = shade_hex($custom, 0.25);
        $colors['soft'] = tint_hex($custom, 0.92);
        $colors['border'] = tint_hex($custom, 0.78);
    }
    return $colors;
}

function theme_style_tag()
{
    $c = theme_colors();
    return '<style>:root{--rose:' . $c['primary'] . ';--rose-dark:' . $c['dark'] . ';--rose-soft:' . $c['soft'] . ';--rose-border:' . $c['border'] . ';--bg-1:' . $c['bg1'] . ';--bg-2:' . $c['bg2'] . ';}</style>';
}

/* ----- Emails ----- */

function parent_email_body($child, $crlf = false)
{
    $nl = $crlf ? "\r\n" : "\n";
    $name = child_name($child['first_name'], $child['last_initial']);
    $link = child_link($child['token']);
    return 'Bonjour,' . $nl . $nl
        . 'Voici le lien pour réserver le créneau de rencontre de ' . $name . ' :' . $nl . $nl
        . $link . $nl . $nl
        . 'Chaque famille réserve un seul créneau de 15 minutes : une fois réservé, il est bloqué pour les autres.' . $nl
        . 'Si vous ne pouvez finalement pas venir, vous pourrez libérer le créneau depuis ce même lien.' . $nl . $nl
        . 'À bientôt !';
}

function send_parent_email($to, $child)
{
    $subject = site_title() . ' — Rendez-vous de ' . child_name($child['first_name'], $child['last_initial']);
    $body = parent_email_body($child);
    $headers = 'MIME-Version: 1.0' . "\r\n"
        . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
        . 'Content-Transfer-Encoding: 8bit' . "\r\n";
    $sender = get_setting('sender_email', '');
    if ($sender !== '') {
        $headers .= 'From: ' . $sender . "\r\n";
    }
    $reply = get_setting('reply_email', '');
    if ($reply !== '') {
        $headers .= 'Reply-To: ' . $reply . "\r\n";
    }
    return (bool) @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}
