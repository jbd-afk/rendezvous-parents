<?php

function db()
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $opts = array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    );

    if (DB_DRIVER === 'mysql') {
        $pdo = new PDO(
            'mysql:host=' . MYSQL_HOST . ';dbname=' . MYSQL_DB . ';charset=utf8',
            MYSQL_USER,
            MYSQL_PASS,
            $opts
        );
    } else {
        $dir = dirname(SQLITE_PATH);
        if (!is_dir($dir)) {
            @mkdir($dir, 0705, true);
        }
        $pdo = new PDO('sqlite:' . SQLITE_PATH, null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    init_db($pdo);
    return $pdo;
}

function init_db($pdo)
{
    $isMysql = (DB_DRIVER === 'mysql');

    if ($isMysql) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS children (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name VARCHAR(80) NOT NULL,
            last_initial VARCHAR(2) NOT NULL DEFAULT '',
            token CHAR(32) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_children_token (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        $pdo->exec("CREATE TABLE IF NOT EXISTS slots (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slot_date DATE NOT NULL,
            slot_time TIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_slots_slot (slot_date, slot_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        $pdo->exec("CREATE TABLE IF NOT EXISTS bookings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slot_id INT UNSIGNED NOT NULL,
            child_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_bookings_slot (slot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS children (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            first_name TEXT NOT NULL,
            last_initial TEXT NOT NULL DEFAULT '',
            token TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS slots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slot_date TEXT NOT NULL,
            slot_time TEXT NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE (slot_date, slot_time)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS bookings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slot_id INTEGER NOT NULL UNIQUE,
            child_id INTEGER NOT NULL,
            created_at TEXT NOT NULL
        )");
    }
}

function db_now()
{
    return date('Y-m-d H:i:s');
}
