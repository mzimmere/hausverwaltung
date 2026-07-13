-- Für BESTEHENDE Installationen: einmalig in phpMyAdmin ausführen
USE hausverwaltung;

CREATE TABLE IF NOT EXISTS benutzer (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    benutzername  VARCHAR(100) NOT NULL UNIQUE,
    passwort      VARCHAR(255) NOT NULL,
    name          VARCHAR(200),
    rolle         ENUM('admin','leser') DEFAULT 'admin',
    aktiv         TINYINT(1) DEFAULT 1,
    letzter_login DATETIME,
    erstellt_am   DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Standard: Benutzer "admin", Passwort "hausverwaltung"
INSERT IGNORE INTO benutzer (benutzername, passwort, name, rolle)
VALUES ('admin', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin');
