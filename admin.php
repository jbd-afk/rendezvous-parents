<?php

require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';

$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($action === 'logout') {
    $_SESSION = array();
    session_destroy();
    redirect('admin.php');
}

if ($action === 'login') {
    csrf_check();
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    if (hash_equals(ADMIN_PASSWORD, $password)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        redirect('admin.php');
    }
    set_flash('err', 'Mot de passe incorrect.');
    redirect('admin.php');
}

$logged = !empty($_SESSION['admin']);

if ($logged && $action !== '') {
    csrf_check();
    $pdo = db();

    /* ----- Enfants ----- */

    if ($action === 'add_child') {
        $first = trim(isset($_POST['first_name']) ? $_POST['first_name'] : '');
        $initial = trim(isset($_POST['last_initial']) ? $_POST['last_initial'] : '');
        if ($first === '' || $initial === '') {
            set_flash('err', 'Merci d\'indiquer le prénom et l\'initiale du nom.');
        } else {
            $initial = upper_initial($initial);
            $token = generate_token();
            $stmt = $pdo->prepare('INSERT INTO children (first_name, last_initial, token, created_at) VALUES (?, ?, ?, ?)');
            $stmt->execute(array($first, $initial, $token, db_now()));
            set_flash('ok', 'Enfant ajouté. Voici son lien personnalisé (à envoyer aux parents) : ' . child_link($token));
        }
        redirect('admin.php#enfants');
    }

    if ($action === 'delete_child') {
        $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);
        $pdo->prepare('DELETE FROM bookings WHERE child_id = ?')->execute(array($id));
        $pdo->prepare('DELETE FROM children WHERE id = ?')->execute(array($id));
        set_flash('ok', 'Enfant supprimé. Le créneau éventuellement réservé a été libéré.');
        redirect('admin.php#enfants');
    }

    if ($action === 'set_child_email') {
        $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);
        $email = trim(isset($_POST['email']) ? $_POST['email'] : '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('err', 'Adresse email invalide.');
        } else {
            $pdo->prepare('UPDATE children SET email = ? WHERE id = ?')->execute(array($email, $id));
            set_flash('ok', $email === '' ? 'Email retiré pour cet enfant.' : 'Email enregistré pour cet enfant : ' . $email);
        }
        redirect('admin.php#enfants');
    }

    if ($action === 'send_child_email') {
        $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);
        $stmt = $pdo->prepare('SELECT * FROM children WHERE id = ?');
        $stmt->execute(array($id));
        $child = $stmt->fetch();
        if (!$child) {
            set_flash('err', 'Enfant introuvable.');
        } elseif ($child['email'] === null || $child['email'] === '') {
            set_flash('err', 'Enregistrez d\'abord une adresse email pour ' . child_name($child['first_name'], $child['last_initial']) . ' (menu déroulant).');
        } else {
            $sent = send_parent_email($child['email'], $child);
            if ($sent) {
                set_flash('ok', 'Email envoyé à ' . $child['email'] . ' avec le lien de ' . child_name($child['first_name'], $child['last_initial']) . '.');
            } else {
                set_flash('err', 'L\'envoi à ' . $child['email'] . ' a échoué. Vérifiez l\'adresse expéditrice dans Réglages, ou utilisez le bouton « Brouillon ».');
            }
        }
        redirect('admin.php#enfants');
    }

    if ($action === 'send_all_emails') {
        $withEmail = $pdo->query("SELECT * FROM children WHERE email IS NOT NULL AND email != '' ORDER BY first_name ASC")->fetchAll();
        if (count($withEmail) === 0) {
            set_flash('err', 'Aucun enfant n\'a d\'adresse email enregistrée. Associez d\'abord les emails dans la section « Enfants ».');
        } else {
            $sent = 0;
            $failed = 0;
            foreach ($withEmail as $child) {
                if (send_parent_email($child['email'], $child)) {
                    $sent++;
                } else {
                    $failed++;
                }
            }
            $msg = $sent . ' email' . ($sent > 1 ? 's' : '') . ' envoyé' . ($sent > 1 ? 's' : '') . '.';
            if ($failed > 0) {
                $msg .= ' ' . $failed . ' échec' . ($failed > 1 ? 's' : '') . '.';
            }
            set_flash($failed > 0 ? 'err' : 'ok', $msg);
        }
        redirect('admin.php#emails');
    }

    /* ----- Créneaux ----- */

    if ($action === 'add_slots') {
        $date = trim(isset($_POST['date']) ? $_POST['date'] : '');
        $start = trim(isset($_POST['start_time']) ? $_POST['start_time'] : '');
        $end = trim(isset($_POST['end_time']) ? $_POST['end_time'] : '');
        $okDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
        $okStart = preg_match('/^\d{2}:\d{2}$/', $start);
        $okEnd = ($end === '') || preg_match('/^\d{2}:\d{2}$/', $end);
        if (!$okDate || !$okStart || !$okEnd || strtotime($date . ' ' . $start) === false) {
            set_flash('err', 'Date ou heure invalide.');
        } else {
            $from = strtotime($date . ' ' . $start);
            $to = ($end === '') ? ($from + 900) : strtotime($date . ' ' . $end);
            if ($to <= $from) {
                set_flash('err', 'L\'heure de fin doit être postérieure à l\'heure de début.');
            } else {
                $insert = $pdo->prepare('INSERT INTO slots (slot_date, slot_time, created_at) VALUES (?, ?, ?)');
                $added = 0;
                $skipped = 0;
                for ($t = $from; $t < $to; $t += 900) {
                    try {
                        $insert->execute(array(date('Y-m-d', $t), date('H:i:s', $t), db_now()));
                        $added++;
                    } catch (PDOException $ex) {
                        $skipped++;
                    }
                }
                $msg = $added . ' créneau' . ($added > 1 ? 'x' : '') . ' ajouté' . ($added > 1 ? 's' : '') . '.';
                if ($skipped > 0) {
                    $msg .= ' ' . $skipped . ' ignoré' . ($skipped > 1 ? 's' : '') . ' (déjà existant' . ($skipped > 1 ? 's' : '') . ').';
                }
                set_flash('ok', $msg);
            }
        }
        redirect('admin.php#creneaux');
    }

    if ($action === 'delete_slot') {
        $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);
        $check = $pdo->prepare('SELECT id FROM bookings WHERE slot_id = ?');
        $check->execute(array($id));
        if ($check->fetch()) {
            set_flash('err', 'Ce créneau est réservé : libérez-le d\'abord.');
        } else {
            $pdo->prepare('DELETE FROM slots WHERE id = ?')->execute(array($id));
            set_flash('ok', 'Créneau supprimé.');
        }
        redirect('admin.php#recap');
    }

    if ($action === 'free_slot') {
        $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);
        $pdo->prepare('DELETE FROM bookings WHERE slot_id = ?')->execute(array($id));
        set_flash('ok', 'Créneau libéré.');
        redirect('admin.php#recap');
    }

    /* ----- Contacts emails ----- */

    if ($action === 'add_contact') {
        $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
        $email = strtolower(trim(isset($_POST['email']) ? $_POST['email'] : ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('err', 'Adresse email invalide.');
        } else {
            try {
                $pdo->prepare('INSERT INTO contacts (name, email, created_at) VALUES (?, ?, ?)')->execute(array($name, $email, db_now()));
                set_flash('ok', 'Contact ajouté : ' . $email);
            } catch (PDOException $ex) {
                set_flash('err', 'Cette adresse est déjà dans la liste.');
            }
        }
        redirect('admin.php#emails');
    }

    if ($action === 'import_contacts') {
        $text = isset($_POST['emails']) ? $_POST['emails'] : '';
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $added = 0;
        $skipped = 0;
        $insert = $pdo->prepare('INSERT INTO contacts (name, email, created_at) VALUES (?, ?, ?)');
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $line, $m)) {
                $skipped++;
                continue;
            }
            $email = strtolower($m[0]);
            $name = trim(str_replace($m[0], '', $line), " \t;,:<>\"'");
            try {
                $insert->execute(array($name, $email, db_now()));
                $added++;
            } catch (PDOException $ex) {
                $skipped++;
            }
        }
        set_flash('ok', $added . ' contact' . ($added > 1 ? 's' : '') . ' importé' . ($added > 1 ? 's' : '') . '.'
            . ($skipped > 0 ? ' ' . $skipped . ' ignoré' . ($skipped > 1 ? 's' : '') . ' (déjà présent ou invalide).' : ''));
        redirect('admin.php#emails');
    }

    if ($action === 'delete_contact') {
        $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);
        $stmt = $pdo->prepare('SELECT email FROM contacts WHERE id = ?');
        $stmt->execute(array($id));
        $contact = $stmt->fetch();
        if ($contact) {
            $pdo->prepare('UPDATE children SET email = \'\' WHERE email = ?')->execute(array($contact['email']));
            $pdo->prepare('DELETE FROM contacts WHERE id = ?')->execute(array($id));
        }
        set_flash('ok', 'Contact supprimé.');
        redirect('admin.php#emails');
    }

    /* ----- Réglages ----- */

    if ($action === 'save_settings') {
        $title = trim(isset($_POST['site_title']) ? $_POST['site_title'] : '');
        set_setting('site_title', $title === '' ? 'Rendez-vous' : $title);

        $theme = isset($_POST['theme']) ? $_POST['theme'] : 'rose';
        $presets = theme_presets();
        set_setting('theme', isset($presets[$theme]) ? $theme : 'rose');

        $custom = '';
        $customInput = isset($_POST['custom_primary']) ? $_POST['custom_primary'] : '';
        if (isset($_POST['use_custom']) && $_POST['use_custom'] === '1' && preg_match('/^#[0-9a-fA-F]{6}$/', $customInput)) {
            $custom = strtolower($customInput);
        }
        set_setting('custom_primary', $custom);

        $sender = trim(isset($_POST['sender_email']) ? $_POST['sender_email'] : '');
        if ($sender !== '' && !filter_var($sender, FILTER_VALIDATE_EMAIL)) {
            set_flash('err', 'Adresse expéditrice invalide : les autres réglages sont enregistrés.');
        } else {
            set_setting('sender_email', $sender);
            set_flash('ok', 'Réglages enregistrés.');
        }
        redirect('admin.php#reglages');
    }

    /* ----- Réinitialisation ----- */

    if ($action === 'reset_slots') {
        $pdo->exec('DELETE FROM bookings');
        $pdo->exec('DELETE FROM slots');
        set_flash('ok', 'Tous les créneaux et réservations ont été effacés. Les enfants et leurs liens sont conservés.');
        redirect('admin.php#recap');
    }

    if ($action === 'reset_children') {
        $pdo->exec('DELETE FROM bookings');
        $pdo->exec('DELETE FROM children');
        set_flash('ok', 'Tous les enfants (et leurs liens) ont été effacés.');
        redirect('admin.php#enfants');
    }

    redirect('admin.php');
}

$pdo = db();
$flash = take_flash();

$children = $pdo->query('SELECT * FROM children ORDER BY first_name ASC, last_initial ASC')->fetchAll();
$contacts = $pdo->query('SELECT * FROM contacts ORDER BY name ASC, email ASC')->fetchAll();

$slots = $pdo->query(
    'SELECT s.id, s.slot_date, s.slot_time,
            b.id AS booking_id,
            c.first_name AS child_first, c.last_initial AS child_initial
     FROM slots s
     LEFT JOIN bookings b ON b.slot_id = s.id
     LEFT JOIN children c ON c.id = b.child_id
     ORDER BY s.slot_date ASC, s.slot_time ASC'
)->fetchAll();

$total = count($slots);
$bookedCount = 0;
$pastCount = 0;
$freeCount = 0;
foreach ($slots as $s) {
    if ($s['booking_id'] !== null) {
        $bookedCount++;
    } elseif (is_past($s['slot_date'], $s['slot_time'])) {
        $pastCount++;
    } else {
        $freeCount++;
    }
}

$siteTitle = site_title();
$themeKey = get_setting('theme', 'rose');
$customPrimary = get_setting('custom_primary', '');
$senderEmail = get_setting('sender_email', '');
$presets = theme_presets();
$colors = theme_colors();
$childrenWithEmail = 0;
foreach ($children as $c) {
    if ($c['email'] !== null && $c['email'] !== '') {
        $childrenWithEmail++;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo e($siteTitle); ?> — Espace admin</title>
<link rel="stylesheet" href="style.css">
<?php echo theme_style_tag(); ?>
</head>
<body>
<div class="page">

<?php if (!$logged) { ?>
    <div class="card login-card">
        <h1>🌸 <?php echo e($siteTitle); ?></h1>
        <p class="muted">Espace réservé à l'administration.</p>
        <?php if ($flash) { ?><div class="flash flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['text']); ?></div><?php } ?>
        <form method="post" action="admin.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="login">
            <label for="password">Mot de passe</label>
            <input type="password" id="password" name="password" autofocus required>
            <button type="submit" class="btn btn-primary btn-block">Se connecter</button>
        </form>
    </div>

<?php } else { ?>

    <header class="topbar">
        <h1>🌸 <?php echo e($siteTitle); ?> <span class="admin-tag">admin</span></h1>
        <form method="post" action="admin.php">
            <input type="hidden" name="action" value="logout">
            <?php echo csrf_field(); ?>
            <button type="submit" class="btn btn-ghost">Déconnexion</button>
        </form>
    </header>

    <?php if ($flash) { ?><div class="flash flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['text']); ?></div><?php } ?>

    <nav class="secnav">
        <a href="#enfants">👶 Enfants</a>
        <a href="#creneaux">📅 Créneaux</a>
        <a href="#recap">📋 Récapitulatif</a>
        <a href="#emails">✉ Emails</a>
        <a href="#reglages">⚙ Réglages</a>
    </nav>

    <div class="stats">
        <div class="stat"><span class="stat-num"><?php echo $total; ?></span><span class="stat-label">proposés</span></div>
        <div class="stat stat-free"><span class="stat-num"><?php echo $freeCount; ?></span><span class="stat-label">disponibles</span></div>
        <div class="stat stat-booked"><span class="stat-num"><?php echo $bookedCount; ?></span><span class="stat-label">réservés</span></div>
        <div class="stat stat-past"><span class="stat-num"><?php echo $pastCount; ?></span><span class="stat-label">passés</span></div>
    </div>

    <section class="card" id="enfants">
        <h2>👶 Enfants &amp; liens parents</h2>
        <form method="post" action="admin.php" class="inline-form">
            <input type="hidden" name="action" value="add_child">
            <?php echo csrf_field(); ?>
            <input type="text" name="first_name" placeholder="Prénom" required maxlength="80">
            <input type="text" name="last_initial" placeholder="Initiale du nom" required maxlength="2" size="6">
            <button type="submit" class="btn btn-primary">Ajouter l'enfant</button>
        </form>

        <?php if (count($children) === 0) { ?>
            <p class="muted">Aucun enfant pour le moment. Ajoutez-en un pour générer son lien unique.</p>
        <?php } else { ?>
            <ul class="child-list">
            <?php foreach ($children as $child) {
                $link = child_link($child['token']);
                $mailto = 'mailto:' . rawurlencode($child['email'] !== null ? $child['email'] : '')
                    . '?subject=' . rawurlencode(site_title() . ' — Rendez-vous de ' . child_name($child['first_name'], $child['last_initial']))
                    . '&body=' . rawurlencode(parent_email_body($child, true));
                $childEmail = ($child['email'] !== null && $child['email'] !== '') ? $child['email'] : '';
            ?>
                <li class="child-item">
                    <div class="child-head">
                        <strong><?php echo e(child_name($child['first_name'], $child['last_initial'])); ?></strong>
                        <form method="post" action="admin.php" class="form-inline"
                              onsubmit="return confirm('Supprimer cet enfant ? Son créneau réservé éventuel sera libéré.');">
                            <input type="hidden" name="action" value="delete_child">
                            <input type="hidden" name="id" value="<?php echo intval($child['id']); ?>">
                            <?php echo csrf_field(); ?>
                            <button type="submit" class="btn btn-danger btn-small">Supprimer</button>
                        </form>
                    </div>
                    <form method="post" action="admin.php" class="email-row">
                        <input type="hidden" name="action" value="set_child_email">
                        <input type="hidden" name="id" value="<?php echo intval($child['id']); ?>">
                        <?php echo csrf_field(); ?>
                        <select name="email">
                            <option value="">— Email du parent… —</option>
                            <?php
                            $seen = array();
                            if ($childEmail !== '') {
                                echo '<option value="' . e($childEmail) . '" selected>' . e($childEmail) . '</option>';
                                $seen[$childEmail] = true;
                            }
                            foreach ($contacts as $ct) {
                                if (isset($seen[$ct['email']])) { continue; }
                                echo '<option value="' . e($ct['email']) . '">' . e(($ct['name'] !== '' ? $ct['name'] . ' — ' : '') . $ct['email']) . '</option>';
                            }
                            ?>
                        </select>
                        <button type="submit" class="btn btn-secondary btn-small">OK</button>
                        <button type="submit" class="btn btn-primary btn-small" name="action" value="send_child_email">✉ Envoyer</button>
                    </form>
                    <div class="link-row">
                        <input type="text" readonly value="<?php echo e($link); ?>" onclick="this.select()">
                        <button type="button" class="btn btn-secondary btn-small copy-btn" data-copy="<?php echo e($link); ?>">Copier</button>
                        <a class="btn btn-secondary btn-small" href="<?php echo e($mailto); ?>">Brouillon</a>
                    </div>
                </li>
            <?php } ?>
            </ul>
        <?php } ?>
    </section>

    <section class="card" id="creneaux">
        <h2>📅 Créneaux (15 minutes)</h2>
        <form method="post" action="admin.php" class="inline-form">
            <input type="hidden" name="action" value="add_slots">
            <?php echo csrf_field(); ?>
            <label>Date <input type="date" name="date" required></label>
            <label>Heure début <input type="time" name="start_time" required></label>
            <label>Heure fin <input type="time" name="end_time"> <small>(vide = 1 créneau)</small></label>
            <button type="submit" class="btn btn-primary">Ajouter</button>
        </form>
        <p class="muted">Exemple : 14h00 → 16h00 génère 8 créneaux de 15 minutes.</p>
    </section>

    <section class="card" id="recap">
        <h2>📋 Récapitulatif des créneaux</h2>
        <?php if ($total === 0) { ?>
            <p class="muted">Aucun créneau proposé pour le moment.</p>
        <?php } else { ?>
            <div class="table-wrap">
            <table class="slots-table">
                <thead>
                    <tr><th>Date</th><th>Heure</th><th>Statut</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($slots as $s) {
                    $past = is_past($s['slot_date'], $s['slot_time']);
                    $booked = $s['booking_id'] !== null;
                    $rowClass = $past ? 'past' : ($booked ? 'booked' : 'free');
                ?>
                    <tr class="<?php echo $rowClass; ?>">
                        <td><?php echo e(format_slot($s['slot_date'], $s['slot_time'])); ?></td>
                        <td class="time-cell"><?php echo e(date('G\hi', strtotime($s['slot_time']))); ?></td>
                        <td>
                            <?php if ($booked) { ?>
                                <span class="badge badge-booked">Réservé · <?php echo e(child_name($s['child_first'], $s['child_initial'])); ?></span>
                            <?php } elseif ($past) { ?>
                                <span class="badge badge-past">Passé</span>
                            <?php } else { ?>
                                <span class="badge badge-free">Disponible</span>
                            <?php } ?>
                        </td>
                        <td class="actions-cell">
                            <?php if ($booked) { ?>
                                <form method="post" action="admin.php" class="form-inline">
                                    <input type="hidden" name="action" value="free_slot">
                                    <input type="hidden" name="id" value="<?php echo intval($s['id']); ?>">
                                    <?php echo csrf_field(); ?>
                                    <button type="submit" class="btn btn-secondary btn-small">Libérer</button>
                                </form>
                            <?php } else { ?>
                                <form method="post" action="admin.php" class="form-inline"
                                      onsubmit="return confirm('Supprimer ce créneau ?');">
                                    <input type="hidden" name="action" value="delete_slot">
                                    <input type="hidden" name="id" value="<?php echo intval($s['id']); ?>">
                                    <?php echo csrf_field(); ?>
                                    <button type="submit" class="btn btn-danger btn-small">Supprimer</button>
                                </form>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
            </div>
        <?php } ?>
    </section>

    <section class="card" id="emails">
        <h2>✉ Emails des parents</h2>
        <p class="muted">Enregistrez les adresses (une par une, ou collez toute la liste d'un coup), puis associez une adresse à chaque enfant dans la section « Enfants ». Le bouton « ✉ Envoyer » envoie le lien personnel par email via le serveur Free.</p>

        <h3>Ajouter une adresse</h3>
        <form method="post" action="admin.php" class="inline-form">
            <input type="hidden" name="action" value="add_contact">
            <?php echo csrf_field(); ?>
            <input type="text" name="name" placeholder="Nom (facultatif)" maxlength="120">
            <input type="text" name="email" placeholder="parent@exemple.fr" required maxlength="190">
            <button type="submit" class="btn btn-primary">Ajouter</button>
        </form>

        <h3>Importer une liste (coller)</h3>
        <form method="post" action="admin.php">
            <input type="hidden" name="action" value="import_contacts">
            <?php echo csrf_field(); ?>
            <textarea name="emails" rows="4" placeholder="Une adresse par ligne, par exemple :&#10;Marie Dupont &lt;marie@exemple.fr&gt;&#10;Paul; paul@exemple.fr&#10;julie@exemple.fr"></textarea>
            <button type="submit" class="btn btn-primary">Importer</button>
        </form>

        <?php if (count($contacts) > 0) { ?>
            <h3>Carnet d'adresses (<?php echo count($contacts); ?>)</h3>
            <div class="contact-list">
            <?php foreach ($contacts as $ct) { ?>
                <span class="contact-chip">
                    <?php echo e(($ct['name'] !== '' ? $ct['name'] . ' · ' : '') . $ct['email']); ?>
                    <form method="post" action="admin.php" class="form-inline">
                        <input type="hidden" name="action" value="delete_contact">
                        <input type="hidden" name="id" value="<?php echo intval($ct['id']); ?>">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="chip-delete" title="Supprimer ce contact">×</button>
                    </form>
                </span>
            <?php } ?>
            </div>
        <?php } ?>

        <form method="post" action="admin.php" class="send-all">
            <input type="hidden" name="action" value="send_all_emails">
            <?php echo csrf_field(); ?>
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('Envoyer le lien personnalisé par email à tous les enfants qui ont une adresse enregistrée (<?php echo $childrenWithEmail; ?>) ?');">
                ✉ Envoyer les liens à tous (<?php echo $childrenWithEmail; ?>)
            </button>
        </form>
        <p class="muted small">Chaque famille reçoit son propre email avec son lien unique. Indiquez votre adresse @free.fr comme expéditeur dans Réglages pour une meilleure délivrabilité.</p>
    </section>

    <section class="card" id="reglages">
        <h2>⚙ Réglages</h2>
        <form method="post" action="admin.php">
            <input type="hidden" name="action" value="save_settings">
            <?php echo csrf_field(); ?>

            <div class="settings-grid">
                <label>Titre du site
                    <input type="text" name="site_title" value="<?php echo e($siteTitle); ?>" maxlength="60">
                </label>

                <label>Thème de couleurs
                    <select name="theme">
                        <?php foreach ($presets as $key => $preset) { ?>
                            <option value="<?php echo e($key); ?>"<?php echo $key === $themeKey ? ' selected' : ''; ?>><?php echo e($preset['label']); ?></option>
                        <?php } ?>
                    </select>
                </label>

                <label class="check-label">
                    <input type="checkbox" name="use_custom" value="1"<?php echo $customPrimary !== '' ? ' checked' : ''; ?>>
                    Couleur principale personnalisée
                    <input type="color" name="custom_primary" value="<?php echo e($customPrimary !== '' ? $customPrimary : $colors['primary']); ?>">
                </label>

                <label>Email expéditeur (From)
                    <input type="text" name="sender_email" value="<?php echo e($senderEmail); ?>" placeholder="vous@free.fr" maxlength="190">
                </label>
            </div>

            <button type="submit" class="btn btn-primary">Enregistrer les réglages</button>
        </form>

        <div class="danger-zone">
            <h3>🔄 Nouvelle session</h3>
            <p class="muted">Pour préparer une deuxième session plus tard dans l'année :</p>
            <form method="post" action="admin.php" class="form-inline">
                <input type="hidden" name="action" value="reset_slots">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn btn-danger"
                        onclick="return confirm('Effacer TOUS les créneaux et réservations ? Les enfants et leurs liens seront conservés.');">
                    Effacer tous les créneaux
                </button>
            </form>
            <form method="post" action="admin.php" class="form-inline">
                <input type="hidden" name="action" value="reset_children">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn btn-danger"
                        onclick="return confirm('Effacer TOUS les enfants et leurs liens ? Les créneaux seront aussi effacés.');">
                    Effacer tous les enfants
                </button>
            </form>
        </div>
    </section>

    <footer class="footer">Fait avec ❤ — chaque famille réserve un seul créneau via son lien personnel.</footer>

<?php } ?>
</div>
<script>
(function () {
    var buttons = document.querySelectorAll('.copy-btn');
    for (var i = 0; i < buttons.length; i++) {
        buttons[i].addEventListener('click', function () {
            var text = this.getAttribute('data-copy');
            var done = (function (btn) {
                return function () {
                    var old = btn.textContent;
                    btn.textContent = 'Copié ✓';
                    setTimeout(function () { btn.textContent = old; }, 1500);
                };
            })(this);
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done);
            } else {
                var input = document.createElement('textarea');
                input.value = text;
                document.body.appendChild(input);
                input.select();
                try { document.execCommand('copy'); done(); } catch (err) {}
                document.body.removeChild(input);
            }
        });
    }
})();
</script>
</body>
</html>
