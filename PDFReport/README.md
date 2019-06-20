# Report Modul
Dieses Modul bietet die Funktion Archivwerte als Bericht in einer PDF zusammenzufassen. 

### Inhaltverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [WebFront](#6-webfront)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

* Ermöglicht das Erstellen und Download von erstellter PDF.
* Einstellung via Instanzkonfiguration
* Logoauswahl für Header ist möglich
* Einstellbare Anzahl Datensätze, Aggregationsstärke, Min- und Max-Werte der ausgewählten Variablen


### 2. Voraussetzungen

- IP-Symcon ab Version 5.1

### 3. Software-Installation

Über den Modul-Store kann unter der Kategorie "Informationen"=>"Aufbereitung" oder direkt über die Suche nach "Report Modul" das Modul gefunden werden. Durch den Knopf "Installieren" wird das Modul IP-Symcon zur Verfügung gestellt.

### 4. Einrichten der Instanzen in IP-Symcon

- Über "Instanz hinzufügen" ist das 'Report(PDF)'-Modul in der Schnellfiltersuche auffindbar und kann über "OK" hinzugefügt werden.  

__Konfigurationsseite__:

Name             | Beschreibung
---------------- | ---------------------------------
Logo             | Auswählbare Grafik, welche auf der PDF oben Links positioniert wird
Company          | Firmenname
Title            | Titel der PDF
Footer           | Fußzeile
Data Source      | Variable aus der die Datensätze erstellt werden
Data Aggregation | Definiert Aggregationsstufe der aufgelisteten Datensätze (Stunde - Jahr)
Data Count       | Anzahl der aufgelisteten Datensätze
DataSkip         | Der unvollständige erste Datensatz wird verworfen (true)
Data Limit (Min) | Akzeptierter Minimalwert der aggregierten Datensätze
Data Limit (Max) | Akzeptierter Maximalwert der aggregierten Datensätze

### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

##### Statusvariablen
Die PDF wird als "Report (PDF)" generiert.

Name         | Typ    | Beschreibung
------------ | ------ | ----------------
Report (PDF) | Medien | Erstellte PDF, welche im Objektbaum bei Doppelklick ein Popup zum Download der fertigen PDF öffnet


##### Profile:

Es werden keine zusätzlichen Profile erstellt.

### 6. WebFront

Das WebFront hat für dieses Modul keinerlei Funktionalität.

### 7. PHP-Befehlsreferenz

`boolean RAC_GenerateReport(integer $InstanzID);`  
Generiert ein PDF mit den im Modul mit der InstanzID $InstanzID angegebenen Werten. Die PDF steht dann als Mediendatei zur Verfügung. 
Die Funktion liefert keinerlei Rückgabewert.  
Beispiel:  
`RAC_GenerateReport(12345);`