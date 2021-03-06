# MailReport

Das Modul verschickt regelmäßig die geloggten aggregierten Daten einer Variablen via E-Mail

### Inhalstverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [WebFront](#6-webfront)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

* Verschicke E-Mails mit aggregierten Daten einer Variablen
  * E-Mails werden nach jedem Abschluß des gewählten Zeitintervalls (Täglich, Wöchentlich oder Monatlich)
  * E-Mails beinhalten aggregierte Daten, welche auch in einem entsprechenden Graphen dargestellt würden (Stündlich bei täglichem Zeitintervall, Täglich bei wöchentlichem oder monatlichem Zeitintervall)
  * Die E-Mail enthält eine CSV-Datei, welche analog zum CSV-Export von Graphen formatiert ist.

### 2. Voraussetzungen

- IP-Symcon ab Version 5.5
- Eingerichtete SMTP-Instanz zum Versenden von E-Mails

### 3. Software-Installation

Über den Module Store kann unter der Kategorie "Informationen"=>"Aufbereitung" oder direkt über die Suche nach "Report Modul" das Modul gefunden werden. Durch den Knopf "Installieren" wird das Modul IP-Symcon zur Verfügung gestellt.

### 4. Einrichten der Instanzen in IP-Symcon

- Über "Instanz hinzufügen" kann das 'Report(Mail)'-Modul mithilfe des Schnellfilters gefunden werden.
    - Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Name                      | Beschreibung
------------------------- | ---------------------------------
E-Mail SMTP               | Auswahl der SMTP-Instanz über welche die E-Mails verschickt werden
Aggregierte Variable      | Auswahl der Variablen wessen aggregierte Daten verschickt werden sollen
Intervall der Nachrichten | Auswahl des Zeitintervalls in welchen die Nachrichten verschickt werden sollen
Sende Daten               | Betätigen um eine Info-Mail über das letzte abgeschlossene Zeitintervall zu verschicken

### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

##### Statusvariablen

Name              | Typ      | Beschreibung
----------------- | -------- | ----------------
Mail Report aktiv | Variable | Aktiviert bzw. deaktiviert das Modul

### 6. WebFront

Über das WebFront kann das Modul aktiviert oder deaktiviert werden.

### 7. PHP-Befehlsreferenz

`boolean MR_SendInfo(integer $InstanzID);`  
Verschickt eine Info-Mail über das letzte abgeschlossene Zeitintervall  
Beispiel:  
`MR_SendInfo(12345);`

`boolean MR_SetActive(integer $InstanzID, boolean $Active);`  
Aktiviert oder deaktiviert das Modul in Abhängigkeit von $Active  
Beispiel:  
`// Aktiviere das Modul  
MR_SetActive(12345, true);`
