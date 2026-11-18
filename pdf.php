<?php
/**
 * Génération du PDF récapitulatif des rendez-vous, semaine par semaine.
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

$joursFull = array(1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche');
$jours = array(1 => 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.');
$mois = array(1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
              'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre');

function pdf_date_long($ts, $withYear = false)
{
    global $joursFull, $mois;
    $label = $joursFull[intval(date('N', $ts))] . ' ' . date('j', $ts) . ' ' . $mois[intval(date('n', $ts))];
    if ($withYear) {
        $label .= ' ' . date('Y', $ts);
    }
    return $label;
}

function pdf_date_court($ts)
{
    global $jours, $mois;
    return $jours[intval(date('N', $ts))] . ' ' . date('j', $ts) . ' ' . $mois[intval(date('n', $ts))];
}

function pdf_heure($time)
{
    $ts = strtotime($time);
    return date('G', $ts) . 'h' . date('i', $ts);
}

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

$colors = theme_colors();
list($r, $g, $b) = hex_to_rgb($colors['primary']);
list($dr, $dg, $db) = hex_to_rgb($colors['dark']);
list($sr, $sg, $sb) = hex_to_rgb($colors['soft']);

$GLOBALS['docTitle'] = '';
$GLOBALS['inTable'] = false;

function pdf_table_header($pdf)
{
    global $r, $g, $b;
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFillColor($r, $g, $b);
    $pdf->Cell(55, 9, pdf_enc('Jour'), 1, 0, 'L', true);
    $pdf->Cell(30, 9, pdf_enc('Heure'), 1, 0, 'C', true);
    $pdf->Cell(0, 9, pdf_enc('Enfant'), 1, 1, 'L', true);
    $pdf->SetTextColor(60, 45, 50);
    $pdf->SetFont('Helvetica', '', 11);
}

class PDF extends FPDF
{
    function Header()
    {
        if ($this->PageNo() === 1) {
            return;
        }
        /* En-tête des pages suivantes : rappel du titre (+ colonnes si dans un tableau) */
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetTextColor(80, 60, 70);
        $this->Cell(0, 8, pdf_enc($GLOBALS['docTitle'] . ' (suite)'), 0, 1, 'L');
        $this->Ln(2);
        if (!empty($GLOBALS['inTable'])) {
            pdf_table_header($this);
        }
    }

    function Footer()
    {
        $this->SetY(-14);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(150, 140, 145);
        $this->Cell(0, 6, pdf_enc('Page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
    }
}

$GLOBALS['docTitle'] = site_title() . ' — Rendez-vous';
$pdf = new PDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetTitle(pdf_enc($GLOBALS['docTitle']));
$pdf->SetAuthor(pdf_enc(site_title()));
$pdf->SetMargins(15, 16, 15);
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();

/* Titre principal */
$pdf->SetFont('Helvetica', 'B', 17);
$pdf->SetTextColor($dr, $dg, $db);
$pdf->Cell(0, 10, pdf_enc($GLOBALS['docTitle']), 0, 1, 'L');
$pdf->SetFont('Helvetica', '', 10);
$pdf->SetTextColor(120, 105, 112);
$pdf->Cell(0, 6, pdf_enc('Récapitulatif des rendez-vous, semaine par semaine — édité le ' . date('d/m/Y')), 0, 1, 'L');
$pdf->Ln(4);

if (count($weeks) === 0) {
    $pdf->SetFont('Helvetica', 'I', 12);
    $pdf->SetTextColor(120, 105, 112);
    $pdf->Cell(0, 10, pdf_enc('Aucun rendez-vous réservé pour le moment.'), 0, 1, 'L');
} else {
    $firstWeek = true;
    foreach ($weeks as $weekStartKey => $items) {
        $weekStart = strtotime($weekStartKey);
        $weekEnd = $weekStart + 6 * 86400;

        /* Nouvelle page si la place manque avant un nouveau groupe de semaine */
        if ($pdf->GetY() > 240) {
            $GLOBALS['inTable'] = false;
            $pdf->AddPage();
            $firstWeek = true;
        }

        /* Titre de la semaine */
        if (!$firstWeek) {
            $pdf->Ln(3);
        }
        $firstWeek = false;
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetTextColor($dr, $dg, $db);
        $pdf->SetFillColor($sr, $sg, $sb);
        $label = 'Semaine du ' . pdf_date_long($weekStart) . ' au ' . pdf_date_long($weekEnd, true);
        $pdf->Cell(0, 9, pdf_enc($label), 0, 1, 'L', true);
        $pdf->Ln(1.5);

        pdf_table_header($pdf);
        $GLOBALS['inTable'] = true;

        foreach ($items as $item) {
            if ($pdf->GetY() > 268) {
                $pdf->AddPage();
            }
            $ts = strtotime($item['slot_date']);
            $pdf->SetFont('Helvetica', '', 11);
            $pdf->SetTextColor(60, 45, 50);
            $pdf->Cell(55, 8, pdf_enc(pdf_date_court($ts)), 1, 0, 'L');
            $pdf->Cell(30, 8, pdf_enc(pdf_heure($item['slot_time'])), 1, 0, 'C');
            $pdf->SetFont('Helvetica', 'B', 11);
            $pdf->Cell(0, 8, pdf_enc(child_name($item['child_first'], $item['child_initial'])), 1, 1, 'L');
        }
        $GLOBALS['inTable'] = false;
    }

    /* Total */
    $pdf->Ln(6);
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetTextColor($dr, $dg, $db);
    $pdf->Cell(0, 8, pdf_enc('Total : ' . count($rows) . ' rendez-vous'), 0, 1, 'L');
}

$filename = 'rendez-vous-' . date('Y-m-d') . '.pdf';
$pdf->Output('D', $filename);
exit;
