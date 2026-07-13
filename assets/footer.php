</main>
<footer class="footer">
    <p>
        <?= APP_NAME ?> v<?= htmlspecialchars($appVersion ?? (defined('APP_VERSION') ? APP_VERSION : '')) ?>
        &nbsp;|&nbsp; <?= date('d.m.Y') ?>
        &nbsp;|&nbsp; entwickelt von <strong>Matthias Zimmerer</strong>
    </p>
    <p style="margin-top:.4rem;font-size:.78rem;color:var(--muted);max-width:760px;margin-left:auto;margin-right:auto;line-height:1.55">
        Privat entwickelte Software zur Hausverwaltung. Alle Berechnungen, Abrechnungen und
        Auswertungen erfolgen ohne Gewähr auf Richtigkeit und Vollständigkeit und sind rechtlich
        unverbindlich. Sämtliche Ergebnisse – insbesondere Nebenkostenabrechnungen – sind vor
        einer Verwendung eigenverantwortlich auf ihre Richtigkeit zu prüfen. Eine Haftung für
        etwaige Fehler oder daraus entstehende Schäden wird ausgeschlossen.
        &copy; <?= date('Y') ?> Matthias Zimmerer. Alle Rechte vorbehalten.
    </p>
</footer>
<script src="<?= $basePath ?? '' ?>assets/js/tabellen.js?v=<?= urlencode($appVersion ?? '') ?>"></script>
</body>
</html>
