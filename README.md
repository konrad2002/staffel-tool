# Relay Generator

For a swimming competition I want to calculate the optimal relay setup (which athletes participate in which style). Therefore I want to give the layout of the relay, the athletes and their times in each style. Also there can be rules that limit the combination of athletes (like maximum age, minimal age, max age sum, fixed amounts of genders, max age sum for each gender, different age ranges with fixed amount of participants, etc)

# Input

## Relay Layout

A relay always consists of a fixed number of slots and each slot has a fixed style. For example 8 times 50 meters (8x50m) IM. which is:
- 50m backstroke
- 50m breaststroke
- 50m butterfly
- 50m freestyle
- 50m backstroke
- 50m breaststroke
- 50m butterfly
- 50m freestyle

The layout of a relay should be given as an array like [0,1,2,3], where 0 == backstroke, 1 == breaststroke, 2 == butterfly, 3 == freestyle.
the example above would be [0,1,2,3,0,1,2,3].

## Athletes

The athletes should be added in a table-style input layout. First column name, second column gender (dropdown male/female), third column age group (like 2011), then for columns for each styles time. the time should be entered as string: m:ss,ii (m=minute, ss=seconds, ii=1/100 secs (milli)).
the time string must internally be converted to a number (milliseconds).

## Rules (Conditions)

Those can be quite difficult to define. We should come up with a way of simply writing them in code and allow the ui user to select the specific conditions code to use. (so for adding a new competition I will hard code the conditions (basically a function that says if a given relay setup is valid or not) and they have a label so the user can choose them)

# Calculation

the calculation should use an ILP solver perhaps to solve the problem. the conditions should therefore be written as linear equations for the ILP. running the ILP might be a bad idea in php and maybe a python script on the server can be used instead.

the goal of the calculation is to find a valid (conditions fullfilled) permutation of athletes onto the relay slots so that no athlete has more than one start, all slots are filled and the sum of the times of the athletes for each style are as small as possible.

# Umsetzung

Die Umsetzung besteht aus zwei Dateien:
- `index.php` rendert die Seite, startet den Auftrag und fragt den Fortschritt ab
- `solver.py` führt die eigentliche Suche im Hintergrund aus

Die Seite unterstützt:
- Staffelaufstellung als JSON
- Athletenbearbeitung in einer Tabelle
- eine Auswahl an hart codierten Regelvorlagen
- Live-Fortschritt während der Berechnung

Das Python-Backend verwendet Branch-and-Bound statt eines externen ILP-Pakets. Dadurch bleibt das Setup einfach und die optimale Zuordnung kann trotzdem für übliche Wettkampfstaffeln gefunden werden.

Unterstützte Beispielvorlagen:
- offen
- ausgeglichenes Geschlechterverhältnis
- maximale Alterssumme
- Altersband-Quote
- maximale Alterssumme je Geschlecht

Für einen neuen Wettbewerb müssen die Vorlagenlogik in `solver.py` und die passende Auswahl in `index.php` ergänzt werden.