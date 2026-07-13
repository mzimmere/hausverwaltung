<?php
/**
 * Kosten-Tacho – kleine SVG-Gauge, die die bislang angefallenen laufenden
 * Kosten einer Wohnung der bislang geleisteten Vorauszahlung gegenüberstellt.
 * Datengrundlage: berechneLaufendeKosten() aus kostenberechnung.php.
 */

function kostenTachoHtml(float $kosten, float $vorauszahlung, float $prozent, string $titel = ''): string {
    $keineVorauszahlung = $vorauszahlung <= 0;
    $prozentAnzeige = round($prozent);

    // Skala geht bis 130%, nicht nur 100% - sonst haette die rote Zone (>105%)
    // keinen Platz auf dem Bogen und die Nadel wuerde bei alle Werten ueber
    // 105% immer an derselben Stelle (100%) haengenbleiben.
    $visMax   = 130;
    $gruenBis = 85;
    $gelbBis  = 105;

    if ($keineVorauszahlung) {
        $farbe = 'var(--muted)';
        $status = $kosten > 0 ? 'keine Vorauszahlung hinterlegt' : 'noch keine Daten';
    } elseif ($prozent < $gruenBis) {
        $farbe = 'var(--success)';
        $status = 'im Rahmen';
    } elseif ($prozent <= $gelbBis) {
        $farbe = '#e8a020';
        $status = 'grenzwertig';
    } else {
        $farbe = 'var(--danger)';
        $status = 'über Vorauszahlung';
    }

    $nadelProzent = $keineVorauszahlung ? 0 : max(0, min($visMax, $prozent));
    $winkel = -90 + ($nadelProzent / $visMax) * 180;
    $winkelFmt = number_format($winkel, 1, '.', '');

    // Feste Zonenfaerbung im Hintergrund-Bogen (grün/gelb/rot), damit die
    // Zone auch ohne Nadel/Zahl auf einen Blick erkennbar ist.
    $radius = 90;
    $cx = 100;
    $cy = 100;
    $zonenPunkt = static function (float $pct) use ($visMax, $radius, $cx, $cy): array {
        $frac = max(0, min(1, $pct / $visMax));
        $rad  = deg2rad(-180 + $frac * 180);
        return [$cx + $radius * cos($rad), $cy + $radius * sin($rad)];
    };
    $zonenBogen = static function (float $von, float $bis) use ($zonenPunkt, $radius): string {
        [$x1, $y1] = $zonenPunkt($von);
        [$x2, $y2] = $zonenPunkt($bis);
        return sprintf('M %.2F %.2F A %d %d 0 0 1 %.2F %.2F', $x1, $y1, $radius, $radius, $x2, $y2);
    };
    $bogenGruen = $zonenBogen(0, $gruenBis);
    $bogenGelb  = $zonenBogen($gruenBis, $gelbBis);
    $bogenRot   = $zonenBogen($gelbBis, $visMax);

    // Zwei Vergleichsbalken auf derselben Skala (bezahlt vs. verbraucht),
    // damit die Verhaeltnis-Prozentzahl vom Tacho zusaetzlich als Laenge
    // sichtbar wird - nicht nur als abstrakte Zahl.
    $maxBalkenWert = max($kosten, $vorauszahlung, 0.01);
    $balkenBezahlt   = (int)round(min(100, $vorauszahlung / $maxBalkenWert * 100));
    $balkenVerbraucht = (int)round(min(100, $kosten / $maxBalkenWert * 100));

    ob_start();
    ?>
    <div class="kosten-tacho">
        <?php if ($titel): ?><div class="kosten-tacho-titel"><?= htmlspecialchars($titel) ?></div><?php endif; ?>
        <svg viewBox="0 0 200 115" width="200" height="115" style="max-width:100%">
            <path class="kosten-tacho-zone" d="<?= $bogenGruen ?>" fill="none" stroke="var(--success)" stroke-width="16" stroke-linecap="round" opacity="0.35"/>
            <path class="kosten-tacho-zone" d="<?= $bogenGelb ?>" fill="none" stroke="#e8a020" stroke-width="16" stroke-linecap="round" opacity="0.35" style="animation-delay:.1s"/>
            <path class="kosten-tacho-zone" d="<?= $bogenRot ?>" fill="none" stroke="var(--danger)" stroke-width="16" stroke-linecap="round" opacity="0.35" style="animation-delay:.2s"/>
            <line class="kosten-tacho-nadel" x1="100" y1="100" x2="100" y2="28" stroke="<?= $farbe ?>" stroke-width="4" stroke-linecap="round"
                  style="--tacho-winkel:<?= $winkelFmt ?>deg;--tacho-glow:<?= $farbe ?>"/>
            <circle cx="100" cy="100" r="7" fill="#334155"/>
            <text x="100" y="90" text-anchor="middle" font-size="26" font-weight="700" fill="var(--text)"><?= $keineVorauszahlung ? '–' : $prozentAnzeige . '%' ?></text>
        </svg>
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
