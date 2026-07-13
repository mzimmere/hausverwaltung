/**
 * Sortierbare Tabellen + Live-Suche.
 * Wird automatisch auf jede <table class="sortable"> angewendet:
 *   - Klick auf einen Spaltenkopf sortiert danach (nochmal Klick = umgekehrt)
 *   - Lupe öffnet ein Suchfeld, das die Zeilen der Tabelle live filtert
 * Erkennt Datum (dd.mm.yyyy), deutsch formatierte Zahlen/Beträge und Text
 * automatisch – keine Konfiguration je Seite nötig.
 */
(function () {
    'use strict';

    function zellWert(zelle) {
        if (!zelle) return '';

        // Zellen mit Auswahlfeld (z.B. Zuordnung-Dropdown in Dokumente):
        // den Text der ausgewählten Option nehmen, nicht alle Optionen.
        var select = zelle.querySelector('select');
        if (select && select.selectedIndex >= 0) {
            return select.options[select.selectedIndex].text.trim().toLowerCase();
        }

        var text = zelle.textContent.trim();

        // Datum dd.mm.yyyy (optional mit Uhrzeit dahinter) -> sortierbar als yyyymmdd
        var datum = text.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})/);
        if (datum) {
            var tag = datum[1].padStart(2, '0');
            var monat = datum[2].padStart(2, '0');
            return datum[3] + monat + tag;
        }

        // Deutsch formatierte Zahl/Betrag, z.B. "1.234,56 €", "-50,00 €", "12,5 %", "3"
        var zahl = text.match(/^[+\-−]?\s*\d{1,3}(\.\d{3})*(,\d+)?/);
        if (zahl && /\d/.test(zahl[0])) {
            var roh = zahl[0].replace(/−/g, '-').replace(/\s/g, '').replace(/\./g, '').replace(',', '.');
            var num = parseFloat(roh);
            if (!isNaN(num)) return num;
        }

        return text.toLowerCase();
    }

    function sortiere(table, spalte, richtung) {
        var tbody = table.tBodies[0];
        if (!tbody) return;
        var faktor = richtung === 'asc' ? 1 : -1;
        var zeilen = Array.prototype.slice.call(tbody.rows).filter(function (r) {
            return r.cells.length > 1; // Platzhalter-Zeilen ("Keine Einträge", colspan) auslassen
        });
        zeilen.sort(function (a, b) {
            var av = zellWert(a.cells[spalte]);
            var bv = zellWert(b.cells[spalte]);
            if (av < bv) return -1 * faktor;
            if (av > bv) return 1 * faktor;
            return 0;
        });
        zeilen.forEach(function (z) { tbody.appendChild(z); });
    }

    function initSortierbar(table) {
        var head = table.tHead;
        if (!head || !head.rows.length) return;
        var headerZeile = head.rows[head.rows.length - 1];
        Array.prototype.forEach.call(headerZeile.cells, function (th, idx) {
            if (th.textContent.trim() === '') return; // Aktions-Spalten (Buttons) auslassen
            th.classList.add('th-sortierbar');
            th.setAttribute('role', 'button');
            th.setAttribute('tabindex', '0');
            var richtung = null;
            function ausloesen() {
                richtung = (richtung === 'asc') ? 'desc' : 'asc';
                Array.prototype.forEach.call(headerZeile.cells, function (h) {
                    h.classList.remove('sort-asc', 'sort-desc');
                });
                th.classList.add(richtung === 'asc' ? 'sort-asc' : 'sort-desc');
                sortiere(table, idx, richtung);
            }
            th.addEventListener('click', ausloesen);
            th.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); ausloesen(); }
            });
        });
    }

    function filtere(table, begriff) {
        var tbody = table.tBodies[0];
        if (!tbody) return;
        begriff = begriff.trim().toLowerCase();
        Array.prototype.forEach.call(tbody.rows, function (row) {
            if (!begriff) { row.style.display = ''; return; }
            row.style.display = row.textContent.toLowerCase().indexOf(begriff) !== -1 ? '' : 'none';
        });
    }

    function initSuche(table) {
        var wrap = table.closest('.table-wrap') || table.parentElement;
        if (!wrap || !wrap.parentNode) return;

        var leiste = document.createElement('div');
        leiste.className = 'tabellen-suche';
        leiste.innerHTML =
            '<button type="button" class="tabellen-suche-btn" aria-label="Tabelle durchsuchen" title="Suchen">🔍</button>' +
            '<input type="text" class="tabellen-suche-feld" placeholder="Suchen…">';
        wrap.parentNode.insertBefore(leiste, wrap);

        var btn = leiste.querySelector('.tabellen-suche-btn');
        var feld = leiste.querySelector('.tabellen-suche-feld');

        btn.addEventListener('click', function () {
            var offen = leiste.classList.toggle('offen');
            if (offen) {
                feld.focus();
            } else {
                feld.value = '';
                filtere(table, '');
            }
        });
        feld.addEventListener('input', function () {
            filtere(table, feld.value);
        });
        feld.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { btn.click(); }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('table.sortable').forEach(function (table) {
            initSortierbar(table);
            initSuche(table);
        });
    });
})();
