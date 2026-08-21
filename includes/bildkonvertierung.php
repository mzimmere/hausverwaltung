<?php
/**
 * Hilfsfunktionen für Bildformate, die Browser nicht direkt anzeigen
 * können (HEIC/HEIF von iPhones, DNG-Rohdaten von Kameras). Für diese
 * wird beim Upload versucht, ein anzeigbares JPEG-Vorschaubild zu
 * erzeugen - das Original bleibt in voller Qualität zum Download
 * erhalten.
 *
 * Braucht die PHP-Erweiterung "imagick" mit HEIC/RAW-Unterstützung
 * auf dem Server. Ist das nicht vorhanden, schlägt die Erzeugung
 * einfach fehl (kein Fataler Fehler) - der Upload selbst funktioniert
 * trotzdem immer, nur eben ohne Vorschaubild.
 */

const NICHT_BROWSER_ANZEIGBARE_BILDFORMATE = ['heic', 'heif', 'dng'];

function istBrowserAnzeigbaresBild(string $dateiname): bool {
    $ext = strtolower(pathinfo($dateiname, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

/**
 * Versucht aus $quellPfad (HEIC/HEIF/DNG) ein JPEG unter $zielPfad zu
 * erzeugen. Gibt true bei Erfolg zurück, sonst false - wirft nie.
 */
function bildVorschauErzeugen(string $quellPfad, string $zielPfad): bool {
    if (!extension_loaded('imagick')) {
        return false;
    }
    try {
        $bild = new Imagick();
        $bild->readImage($quellPfad . '[0]'); // [0]: erstes/größtes Frame (bei DNG oft mehrere eingebettete Vorschauen)
        $bild->autoOrient();                  // EXIF-Drehung in die Pixel übernehmen, bevor Metadaten entfernt werden
        $bild->setImageFormat('jpeg');
        $bild->setImageCompressionQuality(85);
        $bild->stripImage();
        $erfolg = $bild->writeImage($zielPfad);
        $bild->clear();
        $bild->destroy();
        return $erfolg && is_file($zielPfad);
    } catch (\Throwable $e) {
        return false;
    }
}
