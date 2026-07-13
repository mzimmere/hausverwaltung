<?php
/**
 * Kosten-Tacho – kleine SVG-Gauge, die die bislang angefallenen laufenden
 * Kosten einer Wohnung der bislang geleisteten Vorauszahlung gegenüberstellt.
 * Datengrundlage: berechneLaufendeKosten() aus kostenberechnung.php.
 */

function kostenTachoHtml(float $kosten, float $vorauszahlung, float $prozent, string $titel = ''): string {
    $keineVorauszahlung = $vorauszahlung <= 0;
    $prozentAnzeige = round($prozent);
    $prozentCapped  = max(0, min(100, $keineVorauszahlung ? 0 : $prozent));
    $winkel = -90 + ($prozentCapped / 100) * 180;

    if ($keineVorauszahlung) {
        $farbe = 'var(--muted)';
        $status = $kosten > 0 ? 'keine Vorauszahlung hinterlegt' : 'noch keine Daten';
    } elseif ($prozent < 85) {
        $farbe = 'var(--success)';
        $status = 'im Rahmen';
    } elseif ($prozent <= 105) {
        $farbe = '#e8a020';
        $status = 'grenzwertig';
    } else {
        $farbe = 'var(--danger)';
        $status = 'über Vorauszahlung';
    }

    $radius = 90;
    $boglaengeGesamt = M_PI * $radius;
    $boglaenge = $boglaengeGesamt * ($prozentCapped / 100);
    $boglaengeGesamtFmt = number_format($boglaengeGesamt, 2, '.', '');
    $dashoffsetZielFmt = number_format($boglaengeGesamt - $boglaenge, 2, '.', '');
    $winkelFmt = number_format($winkel, 1, '.', '');

    ob_start();
    ?>
    <div class="kosten-tacho">
        <?php if ($titel): ?><div class="kosten-tacho-titel"><?= htmlspecialchars($titel) ?></div><?php endif; ?>
        <svg viewBox="0 0 200 115" width="200" height="115" style="max-width:100%">
            <path d="M 10 100 A 90 90 0 0 1 190 100" fill="none" stroke="var(--border,#e2e8f0)" stroke-width="16" stroke-linecap="round"/>
            <path class="kosten-tacho-bogen" d="M 10 100 A 90 90 0 0 1 190 100" fill="none" stroke="<?= $farbe ?>" stroke-width="16" stroke-linecap="round"
                  style="--tacho-boglaenge-gesamt:<?= $boglaengeGesamtFmt ?>;--tacho-dashoffset-ziel:<?= $dashoffsetZielFmt ?>"/>
            <line class="kosten-tacho-nadel" x1="100" y1="100" x2="100" y2="28" stroke="<?= $farbe ?>" stroke-width="4" stroke-linecap="round"
                  style="--tacho-winkel:<?= $winkelFmt ?>deg;--tacho-glow:<?= $farbe ?>"/>
            <circle cx="100" cy="100" r="7" fill="#334155"/>
            <text x="100" y="90" text-anchor="middle" font-size="26" font-weight="700" fill="var(--text)"><?= $keineVorauszahlung ? '–' : $prozentAnzeige . '%' ?></text>
        </svg>
        <div class="kosten-tacho-status" style="color:<?= $farbe ?>"><?= htmlspecialchars($status) ?></div>
        <div class="kosten-tacho-details">
            <?= number_format($kosten,2,',','.') ?> € laufende Kosten<br>
            von <?= number_format($vorauszahlung,2,',','.') ?> € geleisteter Vorauszahlung
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function kostenTachoHinweis(): string {
    return '<p style="color:var(--muted);font-size:.82rem;margin-top:.5rem">'
        . 'ℹ️ Diese Kosten enthalten noch <strong>keine Heizkostenabrechnung</strong> (die kommt erst mit dem '
        . 'Heizkosten-Import zur echten Jahresabrechnung dazu) und betreffen nur die laufenden, umlagefähigen '
        . 'Kosten seit Jahresbeginn – keine verbindliche Abrechnung, nur eine Orientierung.'
        . '</p>';
}
