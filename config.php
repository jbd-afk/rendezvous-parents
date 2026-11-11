<?php
/**
 * Configuration du site "Rendez-vous parents".
 * Modifiez uniquement les valeurs ci-dessous.
 */

// Mot de passe de l'espace admin (à changer impérativement !)
define('ADMIN_PASSWORD', 'changez-moi');

// Fuseau horaire
define('APP_TIMEZONE', 'Europe/Paris');

// Stockage : 'sqlite' (aucune configuration nécessaire) ou 'mysql'
define('DB_DRIVER', 'sqlite');

// --- Si DB_DRIVER = 'sqlite' -------------------------------------------------
define('SQLITE_PATH', __DIR__ . '/data/rendezvous.sqlite');

// --- Si DB_DRIVER = 'mysql' (Pages Perso Free) -------------------------------
// Créez d'abord la base dans la console Free (https://subscribe.free.fr/...),
// l'hôte MySQL est généralement sql.free.fr, le nom de la base = votre login.
define('MYSQL_HOST', 'sql.free.fr');
define('MYSQL_DB', '');      // nom de la base (= login Free en général)
define('MYSQL_USER', '');    // idem
define('MYSQL_PASS', '');    // mot de passe de la base

// Adresse publique du site, sans / final. Laissez vide pour détection auto.
// Exemple : https://monlogin.free.fr/rendezvous
define('BASE_URL', '');
