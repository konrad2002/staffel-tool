# Relay Generator

Mit diesem Projekt kann die optimale Besetzung einer Schwimmstaffel berechnet werden. Dazu werden die Staffelstruktur, die verfügbaren Athleten und deren Zeiten in den jeweiligen Schwimmarten angegeben. Zusätzlich können Wettkampfregeln definiert werden, die bestimmte Kombinationen von Athleten einschränken, beispielsweise Altersgrenzen, Geschlechterverteilungen oder Altersklassenquoten.

## Eingabedaten

### Staffelaufbau

Eine Staffel besteht aus einer festen Anzahl von Startplätzen. Jeder Startplatz ist einer bestimmten Schwimmart zugeordnet.

Beispiel: Eine 8 × 50 m Lagenstaffel besteht aus folgenden Abschnitten:

* 50 m Rücken
* 50 m Brust
* 50 m Schmetterling
* 50 m Freistil
* 50 m Rücken
* 50 m Brust
* 50 m Schmetterling
* 50 m Freistil

Der Staffelaufbau wird als Array angegeben:

```json
[0, 1, 2, 3]
```

Dabei gilt:

| Wert | Schwimmart    |
| ---- | ------------- |
| 0    | Rücken        |
| 1    | Brust         |
| 2    | Schmetterling |
| 3    | Freistil      |

Das Beispiel einer 8 × 50 m Lagenstaffel würde somit wie folgt dargestellt werden:

```json
[0, 1, 2, 3, 0, 1, 2, 3]
```

### Athleten

Die Athleten werden tabellarisch erfasst.

| Name | Geschlecht | Jahrgang | Rücken | Brust | Schmetterling | Freistil |
| ---- | ---------- | -------- | ------ | ----- | ------------- | -------- |

Für jeden Athleten werden folgende Informationen hinterlegt:

* Name
* Geschlecht (männlich / weiblich)
* Jahrgang
* Bestzeit für jede Schwimmart

Zeiten werden im Format

```text
m:ss,hh
```

erfasst, wobei:

* `m` = Minuten
* `ss` = Sekunden
* `hh` = Hundertstelsekunden

Beispiel:

```text
0:34,52
```

Intern werden die Zeiten zur weiteren Verarbeitung in Millisekunden umgerechnet.

### Regeln (Bedingungen)

Viele Staffelwettbewerbe besitzen spezielle Teilnahmebedingungen. Beispiele sind:

* Mindestalter oder Höchstalter
* Maximale Alterssumme
* Feste Anzahl männlicher und weiblicher Teilnehmer
* Maximale Alterssumme je Geschlecht
* Vorgeschriebene Verteilung auf Altersklassen

Da sich diese Regeln je nach Wettkampf unterscheiden können, werden sie als fest implementierte Vorlagen bereitgestellt. Jede Vorlage enthält die Logik zur Prüfung, ob eine bestimmte Staffelbesetzung zulässig ist. Im Benutzerinterface kann anschließend die gewünschte Regelvorlage ausgewählt werden.

Für neue Wettbewerbe können zusätzliche Vorlagen ergänzt werden.

## Berechnung

Ziel der Berechnung ist es, eine gültige Staffelbesetzung zu finden, die alle definierten Regeln erfüllt und gleichzeitig die Gesamtzeit minimiert.

Dabei gelten folgende Bedingungen:

* Jeder Startplatz muss besetzt sein.
* Jeder Athlet darf höchstens einmal eingesetzt werden.
* Alle ausgewählten Athleten müssen die vorgegebenen Regeln erfüllen.
* Die Summe der Einzelzeiten soll möglichst klein sein.

Ursprünglich war die Verwendung eines ILP-Solvers (Integer Linear Programming) vorgesehen. Da die Ausführung externer Optimierungsbibliotheken insbesondere in PHP zusätzlichen Aufwand verursacht, verwendet das Projekt stattdessen einen eigenen Branch-and-Bound-Ansatz.

## Umsetzung

Die Anwendung besteht aus zwei Dateien:

* `index.php` – Benutzeroberfläche, Auftragserstellung und Fortschrittsabfrage
* `solver.py` – eigentliche Berechnung der optimalen Staffelbesetzung

### Funktionen

Die Weboberfläche unterstützt:

* Eingabe des Staffelaufbaus als JSON
* Verwaltung der Athleten in Tabellenform
* Export und Import der aktuellen Einstellungen als JSON-Datei
* Auswahl verschiedener Regelvorlagen
* Live-Anzeige des Berechnungsfortschritts

Das Python-Backend verwendet einen Branch-and-Bound-Algorithmus zur Suche nach der optimalen Lösung. Dadurch bleibt die Installation einfach, ohne auf externe Optimierungspakete angewiesen zu sein, während für typische Staffelgrößen dennoch optimale Ergebnisse gefunden werden können.

### Enthaltene Regelvorlagen

Aktuell stehen unter anderem folgende Vorlagen zur Verfügung:

* Keine Einschränkungen
* Ausgeglichenes Geschlechterverhältnis
* Maximale Alterssumme
* Altersklassenquote
* Maximale Alterssumme je Geschlecht

### Erweiterung für neue Wettbewerbe

Um zusätzliche Wettkampfregeln zu unterstützen, müssen:

1. die entsprechende Prüfungslogik in `solver.py` ergänzt werden,
2. die neue Vorlage in `index.php` zur Auswahl bereitgestellt werden.
