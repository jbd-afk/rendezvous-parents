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
