<?php

require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';

$token = isset($_REQUEST['token']) ? strtolower(preg_replace('/[^a-f0-9]/i', '', $_REQUEST['token'])) : '';
$action = isset($_POST['action']) ? $_POST['action'] : '';

$pdo = db();
$child = null;
if ($token !== '') {
    $stmt = $pdo->prepare('SELECT * FROM children WHERE token = ?');
    $stmt->execute(array($token));
    $child = $stmt->fetch();
}

$flash = take_flash();

if ($child && $action !== '') {
    csrf_check();

    if ($action === 'book') {
        $slotId = intval(isset($_POST['slot_id']) ? $_POST['slot_id'] : 0);
        $stmt = $pdo->prepare('SELECT * FROM slots WHERE id = ?');
        $stmt->execute(array($slotId));
        $slot = $stmt->fetch();

        if (!$slot) {
            set_flash('err', 'Ce créneau n\'existe plus.');
        } elseif (is_past($slot['slot_date'], $slot['slot_time'])) {
            set_flash('err', 'Ce créneau est déjà passé.');
        } else {
            $already = $pdo->prepare('SELECT id FROM bookings WHERE child_id = ?');
            $already->execute(array($child['id']));
            if ($already->fetch()) {
                set_flash('err', 'Vous avez déjà un rendez-vous. Utilisez le bouton « Empêchement » pour le libérer avant d\'en choisir un autre.');
            } else {
                try {
                    $pdo->beginTransaction();
                    $check = $pdo->prepare('SELECT id FROM bookings WHERE slot_id = ?');
                    $check->execute(array($slotId));
                    if ($check->fetch()) {
                        $pdo->rollBack();
                        set_flash('err', 'Désolé, ce créneau vient d\'être réservé par une autre famille.');
                    } else {
                        $insert = $pdo->prepare('INSERT INTO bookings (slot_id, child_id, created_at) VALUES (?, ?, ?)');
                        $insert->execute(array($slotId, $child['id'], db_now()));
                        $pdo->commit();
                        set_flash('ok', 'Votre rendez-vous est confirmé ! 🎉');
                    }
                } catch (PDOException $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    set_flash('err', 'Désolé, ce créneau vient d\'être réservé par une autre famille.');
                }
            }
        }
        redirect('parent.php?token=' . rawurlencode($token));
    }

    if ($action === 'release') {
        $pdo->prepare('DELETE FROM bookings WHERE child_id = ?')->execute(array($child['id']));
        set_flash('ok', 'Votre créneau a été libéré. Vous pouvez en réserver un autre si besoin.');
        redirect('parent.php?token=' . rawurlencode($token));
    }

    redirect('parent.php?token=' . rawurlencode($token));
}

$booking = null;
$available = array();

if ($child) {
    $stmt = $pdo->prepare(
        'SELECT s.id, s.slot_date, s.slot_time
         FROM bookings b
         JOIN slots s ON s.id = b.slot_id
         WHERE b.child_id = ?
         LIMIT 1'
    );
    $stmt->execute(array($child['id']));
    $booking = $stmt->fetch();

    if (!$booking) {
        $rows = $pdo->query(
            'SELECT s.id, s.slot_date, s.slot_time
             FROM slots s
             LEFT JOIN bookings b ON b.slot_id = s.id
             WHERE b.id IS NULL
             ORDER BY s.slot_date ASC, s.slot_time ASC'
        )->fetchAll();
        foreach ($rows as $row) {
            if (!is_past($row['slot_date'], $row['slot_time'])) {
                $available[] = $row;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Rendez-vous<?php echo $child ? ' — ' . e(child_name($child['first_name'], $child['last_initial'])) : ''; ?></title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="page">

<?php if (!$child) { ?>
    <div class="card login-card">
        <h1>🌸 Rendez-vous</h1>
        <p class="muted">Ce lien est invalide ou incomplet.<br>Vérifiez le lien reçu par email (il doit contenir votre identifiant personnel).</p>
    </div>

<?php } else { ?>

    <header class="topbar">
        <h1>🌸 Rendez-vous</h1>
        <span class="child-chip"><?php echo e(child_name($child['first_name'], $child['last_initial'])); ?></span>
    </header>

    <?php if ($flash) { ?><div class="flash flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['text']); ?></div><?php } ?>

    <?php if ($booking) { ?>
        <section class="card booking-card">
            <h2>✅ Votre rendez-vous est confirmé</h2>
            <p class="booking-date"><?php echo e(format_slot($booking['slot_date'], $booking['slot_time'])); ?></p>
            <p class="muted">Durée : 15 minutes. Ce créneau est réservé pour vous et bloqué pour les autres familles.</p>
            <form method="post" action="parent.php?token=<?php echo e($token); ?>"
                  onsubmit="return confirm('Confirmer votre empêchement ? Le créneau sera libéré pour les autres familles.');">
                <input type="hidden" name="action" value="release">
                <input type="hidden" name="token" value="<?php echo e($token); ?>">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn btn-danger">⚠ J'ai un empêchement — libérer le créneau</button>
            </form>
            <p class="muted small">En cas d'empêchement, ce bouton libère votre créneau ; vous pourrez ensuite en réserver un autre depuis ce même lien.</p>
        </section>
    <?php } else { ?>
        <section class="card">
            <h2>Bonjour 👋</h2>
            <p>Choisissez ci-dessous le créneau de rencontre de <strong><?php echo e(child_name($child['first_name'], $child['last_initial'])); ?></strong> (15 minutes).</p>
            <p class="muted">Une fois réservé, le créneau est bloqué pour les autres familles. Conservez ce lien : il vous rappellera votre rendez-vous.</p>

            <?php if (count($available) === 0) { ?>
                <p class="empty-note">Aucun créneau disponible pour le moment. Revenez bientôt ! 🌷</p>
            <?php } else {
                $byDate = array();
                foreach ($available as $row) {
                    $byDate[$row['slot_date']][] = $row;
                }
                foreach ($byDate as $date => $rows) { ?>
                    <div class="day">
                        <h3 class="day-title"><?php echo e(ucfirst(preg_replace('/ à .*$/', '', format_slot($date, '00:00')))); ?></h3>
                        <div class="slots-grid">
                        <?php foreach ($rows as $row) { ?>
                            <form method="post" action="parent.php?token=<?php echo e($token); ?>" class="form-inline">
                                <input type="hidden" name="action" value="book">
                                <input type="hidden" name="token" value="<?php echo e($token); ?>">
                                <input type="hidden" name="slot_id" value="<?php echo intval($row['id']); ?>">
                                <?php echo csrf_field(); ?>
                                <button type="submit" class="btn btn-slot"><?php echo e(date('G\hi', strtotime($row['slot_time']))); ?></button>
                            </form>
                        <?php } ?>
                        </div>
                    </div>
                <?php } ?>
            <?php } ?>
        </section>
    <?php } ?>

    <footer class="footer">Un seul créneau par famille · votre lien personnel vous rappelle toujours votre rendez-vous.</footer>

<?php } ?>
</div>
</body>
</html>
