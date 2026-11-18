<?php
/**
 * Génération du PDF récapitulatif des rendez-vous : UNE page A4 paysage.
 * Une ligne par semaine ("Semaine du ... au ..."), colonnes = jours de la
 * semaine, chaque case contenant l'heure et le nom de l'enfant.
 * Accès réservé à l'admin. Ouvrir : pdf.php (depuis le bouton dans l'admin).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/fpdf.php';

require_admin();

$pdo = db();

/* Récupération des rendez-vous réservés, triés par date et heure */
$rows = $pdo->query(
    'SELECT s.slot_date, s.slot_time,
            c.first_name AS child_first, c.last_initial AS child_initial
     FROM bookings b
     JOIN slots s ON s.id = b.slot_id
     JOIN children c ON c.id = b.child_id
     ORDER BY s.slot_date ASC, s.slot_time ASC'
)->fetchAll();

/* Regroupement par semaine (lundi -> dimanche) */
$weeks = array();
foreach ($rows as $row) {
    $ts = strtotime($row['slot_date']);
    $dayIndex = intval(date('N', $ts));
    $weekStart = $ts - ($dayIndex - 1) * 86400;
    $weekKey = date('Y-m-d', $weekStart);
    if (!isset($weeks[$weekKey])) {
        $weeks[$weekKey] = array();
    }
    $weeks[$weekKey][] = $row;
}
ksort($weeks);

/* Par jour dans chaque semaine */
foreach ($weeks as $weekKey => $items) {
    $perDay = array();
    foreach ($items as $item) {
        $perDay[$item['slot_date']][] = $item;
    }
    $weeks[$weekKey] = $perDay;
}

$jours = array(1 => 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.');
$joursFull = array(1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche');
$mois = array(1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
              'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre');

function pdf_enc($s)
{
    $s = (string) $s;
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
        if ($converted !== false) {
            return $converted;
        }
    }
    return utf8_encode($s);
}

function pdf_heure($time)
{
    $ts = strtotime($time);
    return date('G', $ts) . 'h' . date('i', $ts);
}

$colors = theme_colors();
list($r, $g, $b) = hex_to_rgb($colors['primary']);
list($dr, $dg, $db) = hex_to_rgb($colors['dark']);
list($sr, $sg, $sb) = hex_to_rgb($colors['soft']);

$GLOBALS['majText'] = 'Mis à jour le ' . date('d/m/Y à G\hi');
$GLOBALS['totalCount'] = count($rows);

class PDF extends FPDF
{
    function Footer()
    {
        $this->SetY(-9);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(150, 140, 145);
        $this->Cell(0, 5, pdf_enc($GLOBALS['majText'] . '  —  Total : ' . $GLOBALS['totalCount'] . ' rendez-vous'), 0, 0, 'C');
    }
}

$pdf = new PDF('L', 'mm', 'A4');
$pdf->SetTitle(pdf_enc(site_title() . ' — Rendez-vous'));
$pdf->SetAuthor(pdf_enc(site_title()));
$pdf->SetMargins(12, 12, 12);
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

$colW = 39;   /* (297 - 2 x 12) / 7  = 39 mm par jour */
$x0 = 12;

/* Titre + mention "Mis à jour le ..." */
$pdf->SetFont('Helvetica', 'B', 14);
$pdf->SetTextColor($dr, $dg, $db);
$pdf->Cell(0, 8, pdf_enc(site_title() . ' — Rendez-vous'), 0, 1, 'L');
$pdf->SetFont('Helvetica', 'I', 9);
$pdf->SetTextColor(120, 105, 112);
$pdf->Cell(0, 5, pdf_enc($GLOBALS['majText']), 0, 1, 'L');
$pdf->Ln(2);

if (count($weeks) === 0) {
    $pdf->SetFont('Helvetica', 'I', 11);
    $pdf->SetTextColor(120, 105, 112);
    $pdf->Cell(0, 10, pdf_enc('Aucun rendez-vous réservé pour le moment.'), 0, 1, 'L');
} else {
    foreach ($weeks as $weekKey => $perDay) {
        $weekStart = strtotime($weekKey);
        $weekEnd = $weekStart + 6 * 86400;

        /* Bandeau "Semaine du ... au ..." */
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetTextColor($dr, $dg, $db);
        $pdf->SetFillColor($sr, $sg, $sb);
        $label = 'Semaine du ' . $joursFull[intval(date('N', $weekStart))] . ' ' . date('j', $weekStart)
            . ' ' . $mois[intval(date('n', $weekStart))] . date(' Y')
            . ' au ' . $joursFull[intval(date('N', $weekEnd))] . ' ' . date('j', $weekEnd)
            . ' ' . $mois[intval(date('n', $weekEnd))] . date(' Y');
        $pdf->Cell(0, 6.5, pdf_enc($label), 0, 1, 'L', true);

        /* En-tête des colonnes de jours */
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFillColor($r, $g, $b);
        for ($d = 0; $d < 7; $d++) {
            $ts = $weekStart + $d * 86400;
            $pdf->SetXY($x0 + $d * $colW, $pdf->GetY());
            $pdf->Cell($colW, 5.5, pdf_enc($jours[intval(date('N', $ts))] . ' ' . date('j', $ts)), 1, 0, 'C', true);
        }
        $y0 = $pdf->GetY() + 5.5;

        /* Hauteur de la ligne : au moins 2 lignes de contenu */
        $maxLines = 0;
        foreach ($perDay as $entries) {
            $maxLines = max($maxLines, count($entries));
        }
        $maxLines = max($maxLines, 2);
        $lineH = 4.2;
        $h = $maxLines * $lineH + 2;

        /* Cadre + séparateurs de colonnes */
        $pdf->SetDrawColor(120, 105, 112);
        $pdf->Rect($x0, $y0, $colW * 7, $h);
        for ($i = 1; $i < 7; $i++) {
            $lx = $x0 + $i * $colW;
            $pdf->Line($lx, $y0, $lx, $y0 + $h);
        }

        /* Contenu de chaque jour : heure (gras) + nom de l'enfant */
        for ($d = 0; $d < 7; $d++) {
            $ts = $weekStart + $d * 86400;
            $dayKey = date('Y-m-d', $ts);
            if (!isset($perDay[$dayKey])) {
                continue;
            }
            foreach ($perDay[$dayKey] as $j => $item) {
                $pdf->SetXY($x0 + $d * $colW + 1, $y0 + 1 + $j * $lineH);
                $pdf->SetFont('Helvetica', 'B', 8);
                $pdf->SetTextColor(60, 45, 50);
                $pdf->Cell(11, 3.8, pdf_enc(pdf_heure($item['slot_time'])), 0, 0, 'L');
                $pdf->SetFont('Helvetica', '', 8);
                $pdf->Cell($colW - 13, 3.8, pdf_enc(child_name($item['child_first'], $item['child_initial'])), 0, 0, 'L');
            }
        }

        $pdf->SetY($y0 + $h + 1.5);
        $pdf->SetDrawColor($r, $g, $b);
    }

    /* Total */
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetTextColor($dr, $dg, $db);
    $pdf->Cell(0, 7, pdf_enc('Total : ' . count($rows) . ' rendez-vous'), 0, 1, 'L');
}

$filename = 'rendez-vous-' . date('Y-m-d') . '.pdf';
$pdf->Output('D', $filename);
exit;
