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
        redirect('admin.php');
    }

    if ($action === 'delete_child') {
        $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);
        $pdo->prepare('DELETE FROM bookings WHERE child_id = ?')->execute(array($id));
        $pdo->prepare('DELETE FROM children WHERE id = ?')->execute(array($id));
        set_flash('ok', 'Enfant supprimé. Le créneau éventuellement réservé a été libéré.');
        redirect('admin.php');
    }

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
        redirect('admin.php');
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
        redirect('admin.php');
    }

    if ($action === 'free_slot') {
        $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);
        $pdo->prepare('DELETE FROM bookings WHERE slot_id = ?')->execute(array($id));
        set_flash('ok', 'Créneau libéré.');
        redirect('admin.php');
    }

    redirect('admin.php');
}

$pdo = db();
$flash = take_flash();

$children = $pdo->query('SELECT * FROM children ORDER BY first_name ASC, last_initial ASC')->fetchAll();

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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Espace admin — Rendez-vous</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="page">

<?php if (!$logged) { ?>
    <div class="card login-card">
        <h1>🌸 Rendez-vous</h1>
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
        <h1>🌸 Rendez-vous parents</h1>
        <form method="post" action="admin.php">
            <input type="hidden" name="action" value="logout">
            <?php echo csrf_field(); ?>
            <button type="submit" class="btn btn-ghost">Déconnexion</button>
        </form>
    </header>

    <?php if ($flash) { ?><div class="flash flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['text']); ?></div><?php } ?>

    <div class="stats">
        <div class="stat"><span class="stat-num"><?php echo $total; ?></span><span class="stat-label">proposés</span></div>
        <div class="stat stat-free"><span class="stat-num"><?php echo $freeCount; ?></span><span class="stat-label">disponibles</span></div>
        <div class="stat stat-booked"><span class="stat-num"><?php echo $bookedCount; ?></span><span class="stat-label">réservés</span></div>
        <div class="stat stat-past"><span class="stat-num"><?php echo $pastCount; ?></span><span class="stat-label">passés</span></div>
    </div>

    <section class="card">
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
                $mailto = 'mailto:?subject=' . rawurlencode('Rendez-vous — ' . child_name($child['first_name'], $child['last_initial']))
                    . '&body=' . rawurlencode("Bonjour,\r\n\r\nVoici le lien pour réserver le créneau de rencontre de "
                        . child_name($child['first_name'], $child['last_initial']) . " :\r\n" . $link
                        . "\r\n\r\nChaque famille réserve un seul créneau : une fois réservé, il est bloqué pour les autres.\r\n"
                        . "Si vous ne pouvez finalement pas venir, vous pourrez libérer le créneau depuis ce même lien.\r\n\r\nMerci !");
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
                    <div class="link-row">
                        <input type="text" readonly value="<?php echo e($link); ?>" onclick="this.select()">
                        <button type="button" class="btn btn-secondary btn-small copy-btn" data-copy="<?php echo e($link); ?>">Copier</button>
                        <a class="btn btn-secondary btn-small" href="<?php echo e($mailto); ?>">✉ Email</a>
                    </div>
                </li>
            <?php } ?>
            </ul>
        <?php } ?>
    </section>

    <section class="card">
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

    <section class="card">
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
