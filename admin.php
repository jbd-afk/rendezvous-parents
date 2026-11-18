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
            set_flash('ok', 'Enfant ajouté. Voici son lien personnalisé : ' . child_link($token));
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
        $which = intval(isset($_POST['which']) ? $_POST['which'] : 1);
        if ($which !== 1 && $which !== 2) {
            $which = 1;
        }
        $col = $which === 1 ? 'email1' : 'email2';
        $email = trim(isset($_POST['email']) ? $_POST['email'] : '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('err', 'Adresse email invalide.');
        } else {
            $pdo->prepare('UPDATE children SET ' . $col . ' = ? WHERE id = ?')->execute(array($email, $id));
            set_flash('ok', 'Email du Parent ' . $which . ' enregistré' . ($email === '' ? ' (retiré)' : ' : ' . $email) . '.');
        }
        redirect('admin.php#enfants');
    }

    if ($action === 'send_child_email') {
        $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);
        $which = intval(isset($_POST['which']) ? $_POST['which'] : 1);
        if ($which !== 1 && $which !== 2) {
            $which = 1;
        }
        $emailCol = $which === 1 ? 'email1' : 'email2';
        $stampCol = $which === 1 ? 'mail_sent_at1' : 'mail_sent_at2';
        $stmt = $pdo->prepare('SELECT * FROM children WHERE id = ?');
        $stmt->execute(array($id));
        $child = $stmt->fetch();
        if (!$child) {
            set_flash('err', 'Enfant introuvable.');
        } elseif ($child[$emailCol] === null || $child[$emailCol] === '') {
            set_flash('err', 'Choisissez d\'abord l\'email du Parent ' . $which . ' pour ' . child_name($child['first_name'], $child['last_initial']) . ' (menu déroulant puis OK).');
        } else {
            $result = send_parent_email($child[$emailCol], $child);
            if ($result['ok']) {
                $pdo->prepare('UPDATE children SET ' . $stampCol . ' = ? WHERE id = ?')->execute(array(db_now(), $id));
                set_flash('ok', 'Email envoyé au Parent ' . $which . ' (' . $child[$emailCol] . ') avec le lien de ' . child_name($child['first_name'], $child['last_initial']) . '.');
            } else {
                $detail = ($result['error'] !== '') ? 'Raison renvoyée par le serveur : « ' . $result['error'] . ' ». ' : '';
                set_flash('err', 'Envoi au Parent ' . $which . ' (' . $child[$emailCol] . ') impossible. ' . $detail
                    . 'Rappel : l\'expéditeur (From) dans Réglages doit être l\'adresse @free.fr du compte qui héberge le site, sinon le filtre antispam de Free bloque (TO_BLOCK_SPAM).');
            }
        }
        redirect('admin.php#enfants');
    }

    if ($action === 'send_all_emails') {
        /* Limites Free : 20 appels mail() max par script → envoi par paquets de 6 */
        $all = $pdo->query('SELECT * FROM children ORDER BY id ASC')->fetchAll();
        $recipients = array();
        foreach ($all as $child) {
            foreach (array(1 => 'email1', 2 => 'email2') as $which => $col) {
                $email = $child[$col];
                if ($email !== null && $email !== '') {
                    $recipients[] = array('child' => $child, 'which' => $which, 'email' => $email);
                }
            }
        }
        if (count($recipients) === 0) {
            set_flash('err', 'Aucun email parent enregistré. Associez d\'abord les emails (Parent 1 / Parent 2) dans la section « Enfants ».');
            redirect('admin.php#emails');
        }

        $batchSize = 6;
        $offset = intval(get_setting('bulk_offset', '0'));
        if ($offset < 0 || $offset >= count($recipients)) {
            $offset = 0;
        }
        $batch = array_slice($recipients, $offset, $batchSize);

        $sentN = 0;
        $failed = 0;
        $lastError = '';
        foreach ($batch as $r) {
            usleep(500000); /* éviter les envois trop rapprochés (filtre antispam Free) */
            $result = send_parent_email($r['email'], $r['child']);
            if ($result['ok']) {
                $stampCol = $r['which'] === 1 ? 'mail_sent_at1' : 'mail_sent_at2';
                $pdo->prepare('UPDATE children SET ' . $stampCol . ' = ? WHERE id = ?')->execute(array(db_now(), $r['child']['id']));
                $sentN++;
            } else {
                $failed++;
                $lastError = $result['error'];
            }
        }

        $newOffset = $offset + count($batch);
        $remaining = count($recipients) - $newOffset;
        set_setting('bulk_offset', $remaining > 0 ? (string) $newOffset : '0');

        $msg = $sentN . ' email' . ($sentN > 1 ? 's' : '') . ' envoyé' . ($sentN > 1 ? 's' : '');
        if ($remaining > 0) {
            $msg .= ' — il en reste ' . $remaining . ' : recliquez sur « Envoyer les emails à tous » pour continuer (paquets de ' . $batchSize . ', limites Free).';
        } else {
            $msg .= '. Tout le monde a reçu son message.';
        }
        if ($failed > 0) {
            $msg .= ' ' . $failed . ' échec' . ($failed > 1 ? 's' : '') . '.';
            if ($lastError !== '') {
                $msg .= ' Dernière raison : « ' . $lastError . ' ».';
            }
            set_flash('err', $msg);
        } else {
            set_flash('ok', $msg);
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
            $pdo->prepare("UPDATE children SET email1 = '' WHERE email1 = ?")->execute(array($contact['email']));
            $pdo->prepare("UPDATE children SET email2 = '' WHERE email2 = ?")->execute(array($contact['email']));
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

        $mailSubject = isset($_POST['mail_subject']) ? $_POST['mail_subject'] : '';
        $mailBody = isset($_POST['mail_body']) ? $_POST['mail_body'] : '';
        set_setting('mail_subject', $mailSubject);
        set_setting('mail_body', $mailBody);

        $sender = trim(isset($_POST['sender_email']) ? $_POST['sender_email'] : '');
        if ($sender !== '' && !filter_var($sender, FILTER_VALIDATE_EMAIL)) {
            set_flash('err', 'Adresse expéditrice invalide : les autres réglages sont enregistrés.');
        } else {
            set_setting('sender_email', $sender);
            $reply = trim(isset($_POST['reply_email']) ? $_POST['reply_email'] : '');
            if ($reply !== '' && !filter_var($reply, FILTER_VALIDATE_EMAIL)) {
                set_flash('err', 'Adresse de réponse invalide : les autres réglages sont enregistrés.');
            } else {
                set_setting('reply_email', $reply);
                set_flash('ok', 'Réglages enregistrés.');
            }
        }
        redirect('admin.php#reglages');
    }

    /* ----- Test d'envoi d'email (Réglages) ----- */

    if ($action === 'test_email') {
        $to = trim(isset($_POST['test_email']) ? $_POST['test_email'] : '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            set_flash('err', 'Adresse de test invalide.');
        } else {
            $fakeChild = array('first_name' => 'Test', 'last_initial' => 'X', 'token' => generate_token());
            $result = send_parent_email($to, $fakeChild);
            if ($result['ok']) {
                set_flash('ok', 'Email de test envoyé à ' . $to . ' (lien fictif). Vérifiez qu\'il arrive et qu\'il n\'est pas en spam.');
            } else {
                $detail = ($result['error'] !== '') ? 'Raison : « ' . $result['error'] . ' ». ' : '';
                set_flash('err', 'Échec de l\'envoi de test à ' . $to . '. ' . $detail
                    . 'Vérifiez que l\'expéditeur (From) est l\'adresse @free.fr du compte hébergeant le site.');
            }
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

$bookingByChild = array();
$bkRows = $pdo->query(
    'SELECT b.child_id, s.slot_date, s.slot_time
     FROM bookings b
     JOIN slots s ON s.id = b.slot_id'
)->fetchAll();
foreach ($bkRows as $row) {
    $bookingByChild[$row['child_id']] = $row;
}

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
$replyEmail = get_setting('reply_email', '');
$mailSubjectCur = get_setting('mail_subject', '');
$mailBodyCur = get_setting('mail_body', '');
$mailDefaults = mail_template_defaults();
$presets = theme_presets();
$colors = theme_colors();
$childrenWithEmail = 0;
foreach ($children as $c) {
    if (($c['email1'] !== null && $c['email1'] !== '') || ($c['email2'] !== null && $c['email2'] !== '')) {
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
        <a href="#enfants" data-target="enfants">👶 Enfants</a>
        <a href="#creneaux" data-target="creneaux">📅 Créneaux</a>
        <a href="#recap" data-target="recap">📋 Récapitulatif</a>
        <a href="#emails" data-target="emails">✉ Emails</a>
        <a href="#reglages" data-target="reglages">⚙ Réglages</a>
    </nav>

    <div class="stats">
        <div class="stat"><span class="stat-num"><?php echo $total; ?></span><span class="stat-label">proposés</span></div>
        <div class="stat stat-free"><span class="stat-num"><?php echo $freeCount; ?></span><span class="stat-label">disponibles</span></div>
        <div class="stat stat-booked"><span class="stat-num"><?php echo $bookedCount; ?></span><span class="stat-label">réservés</span></div>
        <div class="stat stat-past"><span class="stat-num"><?php echo $pastCount; ?></span><span class="stat-label">passés</span></div>
    </div>

    <?php /* ----- Onglet Enfants ----- */ ?>
    <section class="card admin-section" id="enfants">
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
                $email1 = ($child['email1'] !== null) ? $child['email1'] : '';
                $curBooking = isset($bookingByChild[$child['id']]) ? $bookingByChild[$child['id']] : null;
                $mailto = 'mailto:' . rawurlencode($email1)
                    . '?subject=' . rawurlencode(mail_subject($child))
                    . '&body=' . rawurlencode(parent_email_body($child, true));
            ?>
                <li class="child-item">
                    <div class="child-head">
                        <span class="child-title">
                            <strong><?php echo e(child_name($child['first_name'], $child['last_initial'])); ?></strong>
                            <?php if ($curBooking) { ?>
                                <span class="badge badge-booked">📌 <?php echo e(format_slot($curBooking['slot_date'], $curBooking['slot_time'])); ?></span>
                            <?php } else { ?>
                                <span class="badge badge-free">Pas encore de créneau</span>
                            <?php } ?>
                        </span>
                        <span class="child-tools">
                            <button type="button" class="btn btn-secondary btn-small copy-btn" data-copy="<?php echo e($link); ?>">🔗 Copier le lien</button>
                            <a class="btn btn-secondary btn-small" href="<?php echo e($mailto); ?>">Brouillon</a>
                            <form method="post" action="admin.php" class="form-inline"
                                  onsubmit="return confirm('Supprimer cet enfant ? Son créneau réservé éventuel sera libéré.');">
                                <input type="hidden" name="action" value="delete_child">
                                <input type="hidden" name="id" value="<?php echo intval($child['id']); ?>">
                                <?php echo csrf_field(); ?>
                                <button type="submit" class="btn btn-danger btn-small">Supprimer</button>
                            </form>
                        </span>
                    </div>
                    <?php for ($which = 1; $which <= 2; $which++) {
                        $emailCol = 'email' . $which;
                        $stampCol = 'mail_sent_at' . $which;
                        $val = ($child[$emailCol] !== null) ? $child[$emailCol] : '';
                        $sentAt = ($child[$stampCol] !== null && $child[$stampCol] !== '') ? date('d/m/Y à G\hi', strtotime($child[$stampCol])) : '';
                    ?>
                    <div class="parent-row">
                        <span class="parent-label">Parent <?php echo $which; ?></span>
                        <form method="post" action="admin.php" class="parent-form">
                            <input type="hidden" name="action" value="set_child_email">
                            <input type="hidden" name="which" value="<?php echo $which; ?>">
                            <input type="hidden" name="id" value="<?php echo intval($child['id']); ?>">
                            <?php echo csrf_field(); ?>
                            <select name="email">
                                <option value="">— Choisir un email… —</option>
                                <?php
                                $seen = array();
                                if ($val !== '') {
                                    echo '<option value="' . e($val) . '" selected>' . e($val) . '</option>';
                                    $seen[$val] = true;
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
                        <?php if ($sentAt !== '') { ?>
                            <span class="mail-status" title="Email envoyé via le serveur">✉ envoyé le <?php echo e($sentAt); ?></span>
                        <?php } ?>
                    </div>
                    <?php } ?>
                </li>
            <?php } ?>
            </ul>
        <?php } ?>
    </section>

    <?php /* ----- Onglet Créneaux ----- */ ?>
    <section class="card admin-section" id="creneaux">
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

    <?php /* ----- Onglet Récapitulatif ----- */ ?>
    <section class="card admin-section" id="recap">
        <h2>📋 Récapitulatif des créneaux</h2>
        <div class="recap-actions">
            <a class="btn btn-secondary" href="pdf.php">📄 Télécharger le PDF (semaine par semaine)</a>
            <span class="muted small">Une page : chaque semaine en ligne, les jours en colonnes, l'heure et le nom de l'enfant.</span>
        </div>
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

    <?php /* ----- Onglet Emails ----- */ ?>
    <section class="card admin-section" id="emails">
        <h2>✉ Emails des parents</h2>
        <p class="muted">Enregistrez les adresses (une par une, ou collez toute la liste d'un coup), puis associez-les à chaque enfant (Parent 1 / Parent 2) dans la section « Enfants ». Le bouton « ✉ Envoyer » envoie le lien personnel par email via le serveur Free.</p>

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
                    onclick="return confirm('Envoyer le lien personnalisé par email à tous les parents enregistrés (<?php echo $childrenWithEmail; ?> familles) ?');">
                ✉ Envoyer les emails à tous (<?php echo $childrenWithEmail; ?> familles)
            </button>
        </form>
        <p class="muted small">Chaque parent reçoit son propre email avec le lien unique de l'enfant. L'envoi se fait par paquets (limites Free) : cliquez plusieurs fois jusqu'à ce que tout soit parti. Objet et contenu des emails se règlent dans Réglages.</p>
    </section>

    <?php /* ----- Onglet Réglages ----- */ ?>
    <section class="card admin-section" id="reglages">
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
                    <small class="muted">De préférence l'adresse @free.fr du site.</small>
                </label>

                <label>Adresse de réponse (Reply-To)
                    <input type="text" name="reply_email" value="<?php echo e($replyEmail); ?>" placeholder="prenom.nom@ac-xxx.fr" maxlength="190">
                    <small class="muted">Les réponses des parents arrivent vers cette adresse.</small>
                </label>

                <label class="wide-label">Tester l'envoi d'un email
                    <div class="form-inline">
                        <input type="text" name="test_email" placeholder="votre@email.fr" style="flex:1; min-width:220px;">
                        <button type="submit" class="btn btn-secondary" name="action" value="test_email" onclick="return confirm('Envoyer un email de test (avec un lien fictif) à l\'adresse indiquée ?');">Envoyer le test</button>
                    </div>
                    <small class="muted">Indiquez votre propre adresse et vérifiez la réception (et le dossier spam). Le message : « TO_BLOCK_SPAM » signifie que l'expéditeur (From) n'est pas l'adresse @free.fr du compte.</small>
                </label>

                <label class="wide-label">Objet des emails
                    <input type="text" name="mail_subject" value="<?php echo e($mailSubjectCur); ?>" placeholder="<?php echo e($mailDefaults['subject']); ?>" maxlength="200">
                    <small class="muted">Variables : {titre}, {enfant}. Vide = modèle par défaut.</small>
                </label>

                <label class="wide-label">Contenu des emails
                    <textarea name="mail_body" rows="8" placeholder="Vide = modèle par défaut."><?php echo e($mailBodyCur); ?></textarea>
                    <small class="muted">Variables disponibles : <strong>{enfant}</strong> (prénom + initiale), <strong>{lien}</strong> (lien personnalisé), <strong>{titre}</strong>. Vide = modèle par défaut.</small>
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
    /*Copier le lien */
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

    /* Onglets */
    document.body.className += ' js';
    var tabs = document.querySelectorAll('.secnav a');

    function showTab(id) {
        var sections = document.querySelectorAll('.admin-section');
        for (var i = 0; i < sections.length; i++) {
            var cl = sections[i].className.replace(/ ?current/, '');
            if (sections[i].id === id) {
                cl += ' current';
            }
            sections[i].className = cl;
        }
        for (var j = 0; j < tabs.length; j++) {
            var tcl = tabs[j].className.replace(/ ?current/, '');
            if (tabs[j].getAttribute('data-target') === id) {
                tcl += ' current';
            }
            tabs[j].className = tcl;
        }
        try { sessionStorage.setItem('rvTab', id); } catch (err) {}
    }

    for (var k = 0; k < tabs.length; k++) {
        (function (tab) {
            tab.addEventListener('click', function (e) {
                e.preventDefault();
                showTab(tab.getAttribute('data-target'));
            });
        })(tabs[k]);
    }

    var initial = null;
    if (location.hash && location.hash.length > 1) {
        initial = location.hash.substring(1);
    } else {
        try { initial = sessionStorage.getItem('rvTab'); } catch (err) {}
    }
    var valid = ['enfants', 'creneaux', 'recap', 'emails', 'reglages'];
    if (initial && valid.join(',').indexOf(initial) !== -1) {
        showTab(initial);
    } else if (tabs.length > 0) {
        showTab('enfants');
    }
})();
</script>
</body>
</html>
