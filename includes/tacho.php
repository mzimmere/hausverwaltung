<?php
/**
 * Kosten-Tacho – Material-Design-Fortschrittsring, der die bislang
 * angefallenen laufenden Kosten einer Wohnung der bislang geleisteten
 * Vorauszahlung gegenüberstellt, plus zwei Vergleichsbalken (bezahlt/
 * verbraucht) auf derselben Skala.
 * Datengrundlage: berechneLaufendeKosten() aus kostenberechnung.php.
 */

function kostenTachoHtml(float $kosten, float $vorauszahlung, float $prozent, string $titel = ''): string {
    $keineVorauszahlung = $vorauszahlung <= 0;
    $prozentAnzeige = round($prozent);

    if ($keineVorauszahlung) {
        $farbe = 'var(--muted)';
        $status = $kosten > 0 ? 'keine Vorauszahlung hinterlegt' : 'noch keine Daten';
    } elseif ($prozent < 85) {
        $farbe = 'var(--success)';
        $status = 'im Rahmen';
    } elseif ($prozent <= 105) {
        $farbe = 'var(--accent)';
        $status = 'grenzwertig';
    } else {
        $farbe = 'var(--danger)';
        $status = 'über Vorauszahlung';
    }

    // Fortschrittsring (M3 circular progress indicator): Kreisumfang wird
    // per stroke-dasharray/-dashoffset anteilig eingefärbt. Bei >100%
    // bleibt der Ring voll gefüllt (die Zahl in der Mitte zeigt den
    // tatsächlichen, ungedeckelten Wert).
    $ringRadius  = 44;
    $ringUmfang  = 2 * M_PI * $ringRadius;
    $ringAnteil  = $keineVorauszahlung ? 0 : max(0, min(100, $prozent));
    $ringOffset  = $ringUmfang * (1 - $ringAnteil / 100);
    $ringUmfangFmt = number_format($ringUmfang, 2, '.', '');
    $ringOffsetFmt = number_format($ringOffset, 2, '.', '');

    // Zwei Vergleichsbalken auf derselben Skala (bezahlt vs. verbraucht),
    // damit die Verhaeltnis-Prozentzahl zusaetzlich als Laenge sichtbar
    // wird - nicht nur als abstrakte Zahl.
    $maxBalkenWert = max($kosten, $vorauszahlung, 0.01);
    $balkenBezahlt    = (int)round(min(100, $vorauszahlung / $maxBalkenWert * 100));
    $balkenVerbraucht  = (int)round(min(100, $kosten / $maxBalkenWert * 100));

    ob_start();
    ?>
    <div class="kosten-tacho">
        <?php if ($titel): ?><div class="kosten-tacho-titel"><?= htmlspecialchars($titel) ?></div><?php endif; ?>
        <div style="position:relative;width:104px;height:104px;margin:0 auto">
            <svg viewBox="0 0 104 104" width="104" height="104" style="transform:rotate(-90deg)">
                <circle cx="52" cy="52" r="<?= $ringRadius ?>" fill="none" stroke="var(--card-bg-high)" stroke-width="10"/>
                <circle class="kosten-tacho-ring-value" cx="52" cy="52" r="<?= $ringRadius ?>" fill="none" stroke="<?= $farbe ?>" stroke-width="10" stroke-linecap="round"
                        style="--tacho-ring-umfang:<?= $ringUmfangFmt ?>px" stroke-dasharray="<?= $ringUmfangFmt ?>" stroke-dashoffset="<?= $ringOffsetFmt ?>"/>
            </svg>
            <span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:1.3rem;font-weight:500;color:<?= $farbe ?>">
                <?= $keineVorauszahlung ? '–' : $prozentAnzeige . '%' ?>
            </span>
        </div>
        <div class="kosten-tacho-status" style="color:<?= $farbe ?>"><?= htmlspecialchars($status) ?></div>
        <div class="kosten-tacho-balken">
            <div class="kosten-tacho-balken-zeile">
                <span class="kosten-tacho-balken-label">Bezahlt</span>
                <span class="kosten-tacho-balken-spur">
                    <span class="kosten-tacho-balken-fuellung" style="--balken-breite:<?= $balkenBezahlt ?>%;--balken-farbe:var(--primary)"></span>
                </span>
                <span class="kosten-tacho-balken-wert"><?= number_format($vorauszahlung,2,',','.') ?> €</span>
            </div>
            <div class="kosten-tacho-balken-zeile">
                <span class="kosten-tacho-balken-label">Verbraucht</span>
                <span class="kosten-tacho-balken-spur">
                    <span class="kosten-tacho-balken-fuellung" style="--balken-breite:<?= $balkenVerbraucht ?>%;--balken-farbe:<?= $farbe ?>"></span>
                </span>
                <span class="kosten-tacho-balken-wert"><?= number_format($kosten,2,',','.') ?> €</span>
            </div>
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
