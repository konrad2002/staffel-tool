<?php
declare(strict_types=1);

$baseDir = __DIR__;
$jobsDir = $baseDir . '/jobs';
$solverScript = $baseDir . '/solver.py';

$styleNames = [
	0 => 'Rücken',
	1 => 'Brust',
	2 => 'Schmetterling',
	3 => 'Freistil',
];

$conditionPresets = [
	'open' => 'Offen',
	'balanced_gender' => 'Ausgeglichenes Geschlechterverhältnis',
	'max_age_sum' => 'Maximale Alterssumme',
	'age_band_quota' => 'Altersband-Quote',
	'gender_age_sum' => 'Maximale Alterssumme je Geschlecht',
];

function jsonResponse(array $payload, int $status = 200): void
{
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

function ensureDirectory(string $path): void
{
	if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
		throw new RuntimeException('Unable to create directory: ' . $path);
	}
}

function readJsonBody(): array
{
	$raw = file_get_contents('php://input');
	if ($raw === false || trim($raw) === '') {
		return [];
	}

	$decoded = json_decode($raw, true);
	return is_array($decoded) ? $decoded : [];
}

function normalizeLayout(mixed $layout): array
{
	if (!is_array($layout)) {
		return [];
	}

	$result = [];
	foreach ($layout as $slot) {
		if (is_int($slot) || ctype_digit((string) $slot)) {
			$result[] = (int) $slot;
		}
	}

	return $result;
}

function normalizeAthletes(mixed $athletes, int $styleCount): array
{
	if (!is_array($athletes)) {
		return [];
	}

	$result = [];
	foreach ($athletes as $athlete) {
		if (!is_array($athlete)) {
			continue;
		}

		$times = [];
		if (isset($athlete['times']) && is_array($athlete['times'])) {
			foreach ($athlete['times'] as $time) {
				$times[] = trim((string) $time);
			}
		}

		while (count($times) < $styleCount) {
			$times[] = '';
		}

		$result[] = [
			'name' => trim((string) ($athlete['name'] ?? '')),
			'gender' => trim((string) ($athlete['gender'] ?? 'female')),
			'birthYear' => (int) ($athlete['birthYear'] ?? 0),
			'times' => array_slice($times, 0, $styleCount),
		];
	}

	return $result;
}

function validatePayload(array $payload, array $styleNames, array $conditionPresets): array
{
	$layout = normalizeLayout($payload['layout'] ?? []);
	$athletes = normalizeAthletes($payload['athletes'] ?? [], count($styleNames));
	$condition = (string) ($payload['condition'] ?? 'open');
	$conditionConfig = isset($payload['conditionConfig']) && is_array($payload['conditionConfig']) ? $payload['conditionConfig'] : [];

	if ($layout === []) {
		return [null, null, null, 'Die Staffelaufstellung ist leer oder ungültig.'];
	}

	if ($athletes === []) {
		return [null, null, null, 'Bitte mindestens einen Athleten eintragen.'];
	}

	if (!isset($conditionPresets[$condition])) {
		return [null, null, null, 'Unbekannte Regelvorlage.'];
	}

	foreach ($athletes as $index => $athlete) {
		if ($athlete['name'] === '') {
			return [null, null, null, 'Sportler #' . ($index + 1) . ' hat keinen Namen.'];
		}
		if (!in_array($athlete['gender'], ['male', 'female'], true)) {
			return [null, null, null, 'Sportler #' . ($index + 1) . ' hat ein ungültiges Geschlecht.'];
		}
		if ($athlete['birthYear'] <= 0) {
			return [null, null, null, 'Sportler #' . ($index + 1) . ' braucht ein Geburtsjahr.'];
		}
		foreach ($athlete['times'] as $styleIndex => $time) {
			if ($time === '') {
				return [null, null, null, 'Sportler #' . ($index + 1) . ' hat keine Zeit für Stil ' . ($styleIndex + 1) . '.'];
			}
		}
	}

	return [$layout, $athletes, ['id' => $condition, 'config' => $conditionConfig], null];
}

function loadJobState(string $jobsDir, string $jobId): array
{
	$safeJobId = basename($jobId);
	$jobDir = $jobsDir . '/' . $safeJobId;
	$statusPath = $jobDir . '/status.json';
	$resultPath = $jobDir . '/result.json';

	if (!is_file($statusPath)) {
		return ['error' => 'Unbekannte Job-ID.'];
	}

	$status = json_decode((string) file_get_contents($statusPath), true);
	$result = is_file($resultPath) ? json_decode((string) file_get_contents($resultPath), true) : null;

	return [
		'status' => is_array($status) ? $status : [],
		'result' => is_array($result) ? $result : null,
	];
}

function startJob(string $baseDir, string $jobsDir, string $solverScript, array $styleNames, array $conditionPresets): array
{
	if (!is_file($solverScript)) {
		return ['error' => 'solver.py wurde nicht gefunden.'];
	}

	$payload = readJsonBody();
	[$layout, $athletes, $condition, $error] = validatePayload($payload, $styleNames, $conditionPresets);
	if ($error !== null) {
		return ['error' => $error];
	}

	ensureDirectory($jobsDir);
	$jobId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
	$jobDir = $jobsDir . '/' . $jobId;
	ensureDirectory($jobDir);

	$inputPath = $jobDir . '/input.json';
	$statusPath = $jobDir . '/status.json';
	$resultPath = $jobDir . '/result.json';
	$logPath = $jobDir . '/run.log';

	$input = [
		'layout' => $layout,
		'athletes' => $athletes,
		'condition' => $condition['id'],
		'conditionConfig' => $condition['config'],
		'competitionYear' => (int) ($payload['competitionYear'] ?? date('Y')),
	];

	file_put_contents($inputPath, json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	file_put_contents($statusPath, json_encode([
		'done' => false,
		'phase' => 'queued',
		'progress' => 0,
		'message' => 'Job in die Warteschlange gestellt.',
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

	$command = 'nohup python3 ' . escapeshellarg($solverScript) . ' ' . escapeshellarg($inputPath) . ' ' . escapeshellarg($statusPath) . ' ' . escapeshellarg($resultPath) . ' > ' . escapeshellarg($logPath) . ' 2>&1 & echo $!';
	$pid = trim((string) shell_exec($command));

	return ['jobId' => $jobId, 'pid' => $pid];
}

$action = (string) ($_GET['action'] ?? '');

if ($action === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$response = startJob($baseDir, $jobsDir, $solverScript, $styleNames, $conditionPresets);
	if (isset($response['error'])) {
		jsonResponse(['ok' => false, 'error' => $response['error']], 400);
	}

	jsonResponse(['ok' => true, 'jobId' => $response['jobId'], 'pid' => $response['pid']]);
}

if ($action === 'status') {
	$jobId = (string) ($_GET['id'] ?? '');
	if ($jobId === '') {
		jsonResponse(['ok' => false, 'error' => 'Missing job id.'], 400);
	}

	$state = loadJobState($jobsDir, $jobId);
	if (isset($state['error'])) {
		jsonResponse(['ok' => false, 'error' => $state['error']], 404);
	}

	jsonResponse(['ok' => true] + $state);
}

function h(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function athleteRow(array $styleNames, array $data = []): string
{
	$name = h((string) ($data['name'] ?? ''));
	$gender = (string) ($data['gender'] ?? 'female');
	$birthYear = h((string) ($data['birthYear'] ?? ''));
	$times = $data['times'] ?? array_fill(0, count($styleNames), '');

	$html = '<tr class="athlete-row">';
	$html .= '<td><input class="field-name" type="text" value="' . $name . '" placeholder="Name"></td>';
	$html .= '<td><select class="field-gender"><option value="female"' . ($gender === 'female' ? ' selected' : '') . '>W</option><option value="male"' . ($gender === 'male' ? ' selected' : '') . '>M</option></select></td>';
	$html .= '<td><input class="field-birthyear" type="number" min="1900" max="2100" value="' . $birthYear . '" placeholder="2011"></td>';
	foreach ($styleNames as $index => $styleName) {
		$time = h((string) ($times[$index] ?? ''));
		$html .= '<td><input class="field-time" type="text" value="' . $time . '" placeholder="0:32,10"></td>';
	}
	$html .= '<td><button type="button" class="remove-row">Löschen</button></td>';
	$html .= '</tr>';

	return $html;
}

$sampleAthletes = [
	['name' => 'Anna', 'gender' => 'female', 'birthYear' => 2011, 'times' => ['0:33,10', '0:37,20', '0:31,80', '0:29,90']],
	['name' => 'Ben', 'gender' => 'male', 'birthYear' => 2010, 'times' => ['0:31,90', '0:36,80', '0:30,40', '0:28,95']],
	['name' => 'Clara', 'gender' => 'female', 'birthYear' => 2012, 'times' => ['0:34,25', '0:38,60', '0:32,10', '0:30,10']],
	['name' => 'David', 'gender' => 'male', 'birthYear' => 2009, 'times' => ['0:32,05', '0:35,90', '0:29,85', '0:28,50']],
	['name' => 'Eva', 'gender' => 'female', 'birthYear' => 2010, 'times' => ['0:33,75', '0:37,40', '0:31,00', '0:29,35']],
	['name' => 'Finn', 'gender' => 'male', 'birthYear' => 2011, 'times' => ['0:32,60', '0:36,55', '0:30,70', '0:28,70']],
	['name' => 'Greta', 'gender' => 'female', 'birthYear' => 2009, 'times' => ['0:34,05', '0:38,10', '0:32,55', '0:30,20']],
	['name' => 'Hugo', 'gender' => 'male', 'birthYear' => 2012, 'times' => ['0:31,55', '0:35,80', '0:29,95', '0:28,40']],
];
?>
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Relay Generator</title>
	<link rel="stylesheet" href="style.css?v=1.11">
</head>
<body>
<div class="wrap">
	<div class="hero">
		<div class="eyebrow">Staffelaufstellung berechnen</div>
		<h1>Staffel-Tool</h1>
		<p class="lead">Staffelaufstellung eingeben, Sportlerinnen und Sportler in der Tabelle eintragen, eine Regelvorlage wählen und den Solver starten. Es wird die beste Zuordnung gesucht.</p>
	</div>

	<div class="grid">
		<section class="card">
			<div class="card-body form-grid">
				<div class="split">
					<div>
						<label for="layout">Staffelaufstellung</label>
						<textarea id="layout">[0,1,2,3,0,1,2,3]</textarea>
						<div class="hint">0 = Rücken, 1 = Brust, 2 = Schmetterling, 3 = Freistil.</div>
					</div>
					<div>
						<label for="condition">Regelvorlage</label>
						<select id="condition">
							<?php foreach ($conditionPresets as $value => $label): ?>
								<option value="<?php echo h($value); ?>"><?php echo h($label); ?></option>
							<?php endforeach; ?>
						</select>
						<div style="height: 10px"></div>
						<label for="competitionYear">Wettkampfjahr</label>
						<input id="competitionYear" type="number" value="<?php echo h((string) date('Y')); ?>">
					</div>
				</div>

				<div>
					<label for="conditionConfig">Regel-Konfiguration als JSON</label>
					<textarea id="conditionConfig">{}</textarea>
					<div class="hint">Beispiele: {"maxAgeSum": 88}, {"bands": [{"minAge": 12, "maxAge": 14, "count": 2}]}, {"maleMaxAgeSum": 44, "femaleMaxAgeSum": 44}</div>
				</div>

				<div>
					<div class="toolbar" style="margin-bottom: 10px;">
						<div>
							<h2>Sportler</h2>
						</div>
						<div class="toolbar">
							<button type="button" class="ghost" id="resetSample">Beispieldaten laden</button>
							<button type="button" class="ghost" id="addRow">Zeile hinzufügen</button>
						</div>
					</div>
					<div class="table-wrap">
						<table id="athletesTable">
							<thead>
								<tr>
									<th>Name</th>
									<th>m/w</th>
									<th>Jahr</th>
									<?php foreach ($styleNames as $styleName): ?>
										<th><?php echo h($styleName); ?></th>
									<?php endforeach; ?>
									<th></th>
								</tr>
							</thead>
							<tbody id="athletesBody"></tbody>
						</table>
					</div>
				</div>

				<div class="toolbar">
					<button class="primary" id="solveButton" type="button">Staffel berechnen</button>
					<div class="hint" id="formHint">Der Solver minimiert die Gesamtzeit unter Beachtung der gewählten Regelvorlage.</div>
				</div>
			</div>
		</section>

		<aside class="card">
			<div class="card-body status">
				<div class="status-head">
					<h2>Fortschritt</h2>
					<div class="hint" id="progressLabel">Bereit</div>
				</div>
				<progress id="progressBar" value="0" max="100"></progress>
				<div id="progressMessage" class="hint">Warte auf Berechnung.</div>
				<div class="result">
					<h2>Ergebnis</h2>
					<div id="resultView" class="result-view">
						<div class="result-empty">Noch keine Berechnung gestartet.</div>
					</div>
					<details class="raw-result">
						<summary>Rohdaten</summary>
						<pre id="resultJson">{}</pre>
					</details>
				</div>
			</div>
		</aside>
	</div>

	<footer class="footer">
		<span>powered by <a href="https://swimresults.de" target="_blank" rel="noopener noreferrer">SwimResults</a></span>
	</footer>
</div>

<script>
const styleCount = <?php echo (int) count($styleNames); ?>;
const athletesBody = document.getElementById('athletesBody');
const layoutInput = document.getElementById('layout');
const conditionSelect = document.getElementById('condition');
const conditionConfigInput = document.getElementById('conditionConfig');
const competitionYearInput = document.getElementById('competitionYear');
const solveButton = document.getElementById('solveButton');
const addRowButton = document.getElementById('addRow');
const resetSampleButton = document.getElementById('resetSample');
const progressBar = document.getElementById('progressBar');
const progressLabel = document.getElementById('progressLabel');
const progressMessage = document.getElementById('progressMessage');
const resultView = document.getElementById('resultView');
const resultJson = document.getElementById('resultJson');
let pollTimer = null;

const sampleAthletes = <?php echo json_encode($sampleAthletes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

function escapeHtml(value) {
	return String(value)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#39;');
}

function setProgress(percent, label, message) {
	progressBar.value = Math.max(0, Math.min(100, percent));
	progressLabel.textContent = label;
	progressMessage.textContent = message;
}

function escapeHtmlText(value) {
	return String(value)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#39;');
}

function formatTime(ms) {
	if (!Number.isFinite(ms)) {
		return '-';
	}
	const minutes = Math.floor(ms / 60000);
	const seconds = Math.floor((ms % 60000) / 1000);
	const hundredths = Math.floor((ms % 1000) / 10);
	return `${minutes}:${String(seconds).padStart(2, '0')},${String(hundredths).padStart(2, '0')}`;
}

function rowMarkup(data = {}) {
	const times = Array.isArray(data.times) ? data.times : Array(styleCount).fill('');
	let cells = '';
	cells += `<td><input class="name" type="text" value="${escapeHtml(data.name || '')}" placeholder="Name"></td>`;
	cells += `<td><select class="gender"><option value="female"${data.gender === 'male' ? '' : ' selected'}>W</option><option value="male"${data.gender === 'male' ? ' selected' : ''}>M</option></select></td>`;
	cells += `<td><input class="birthYear" type="number" min="1900" max="2100" value="${escapeHtml(data.birthYear || '')}" placeholder="2011"></td>`;
	for (let i = 0; i < styleCount; i++) {
		cells += `<td><input class="time" type="text" value="${escapeHtml(times[i] || '')}" placeholder="0:32,10"></td>`;
	}
	cells += '<td><button type="button" class="remove-row">Löschen</button></td>';
	return `<tr class="athlete-row">${cells}</tr>`;
}

function bindRowRemovers() {
	athletesBody.querySelectorAll('.remove-row').forEach((button) => {
		button.onclick = () => {
			if (athletesBody.querySelectorAll('tr').length > 1) {
				button.closest('tr').remove();
			}
		};
	});
}

function loadRows(rows) {
	athletesBody.innerHTML = rows.map((row) => rowMarkup(row)).join('');
	bindRowRemovers();
}

function collectAthletes() {
	return Array.from(athletesBody.querySelectorAll('tr')).map((row) => ({
		name: row.querySelector('.name').value.trim(),
		gender: row.querySelector('.gender').value,
		birthYear: row.querySelector('.birthYear').value.trim(),
		times: Array.from(row.querySelectorAll('.time')).map((input) => input.value.trim()),
	}));
}

async function startSolve() {
	if (pollTimer) {
		clearInterval(pollTimer);
		pollTimer = null;
	}

	let layout;
	let conditionConfig;

	try {
		layout = JSON.parse(layoutInput.value.trim());
	} catch (error) {
		setProgress(0, 'Ungültige Eingabe', 'Die Staffelaufstellung muss gültiges JSON sein.');
		return;
	}

	try {
		conditionConfig = JSON.parse(conditionConfigInput.value.trim() || '{}');
	} catch (error) {
		setProgress(0, 'Ungültige Eingabe', 'Die Regel-Konfiguration muss gültiges JSON sein.');
		return;
	}

	const payload = {
		layout,
		athletes: collectAthletes(),
		condition: conditionSelect.value,
		conditionConfig,
		competitionYear: Number(competitionYearInput.value),
	};

	solveButton.disabled = true;
	setProgress(5, 'Sende Daten', 'Die Anfrage wird an den Hintergrundsolver gesendet.');

	try {
		const response = await fetch('?action=start', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(payload),
		});
		const data = await response.json();
		if (!response.ok || !data.ok) {
			throw new Error(data.error || 'Der Solver konnte nicht gestartet werden.');
		}

		setProgress(10, 'In Warteschlange', 'Hintergrundjob gestartet.');
		pollJob(data.jobId);
	} catch (error) {
		solveButton.disabled = false;
		setProgress(0, 'Fehler', error.message);
	}
}

function renderResult(result) {
	resultJson.textContent = JSON.stringify(result, null, 2);
	if (!result || result.ok === false) {
		resultView.innerHTML = `<div class="result-empty result-error">${escapeHtmlText(result?.message || 'Es konnte keine gültige Staffel gefunden werden.')}</div>`;
		return;
	}

	const assignments = Array.isArray(result.assignments) ? result.assignments : [];
	const rows = assignments.map((entry) => `
		<tr>
			<td>${entry.slot + 1}</td>
			<td>${escapeHtmlText(entry.styleName || '')}</td>
			<td>${escapeHtmlText(entry.athlete?.name || '')}</td>
			<td>${escapeHtmlText(entry.athlete?.gender === 'male' ? 'männlich' : 'weiblich')}</td>
			<td>${escapeHtmlText(String(entry.athlete?.birthYear ?? ''))}</td>
			<td>${escapeHtmlText(entry.time || '')}</td>
		</tr>
	`).join('');

	const perGender = assignments.reduce((accumulator, entry) => {
		const key = entry.athlete?.gender === 'male' ? 'männlich' : 'weiblich';
		accumulator[key] = (accumulator[key] || 0) + 1;
		return accumulator;
	}, {});
	const elapsedLabel = result.elapsedLabel || (Number.isFinite(result.elapsedMs) ? formatTime(result.elapsedMs) : '-');

	resultView.innerHTML = `
		<div class="summary-grid">
			<div class="summary-card accent">
				<div class="summary-label">Gesamtzeit</div>
				<div class="summary-value">${escapeHtmlText(result.totalTime || '-')}</div>
			</div>
			<div class="summary-card">
				<div class="summary-label">Berechnungszeit</div>
				<div class="summary-value">${escapeHtmlText(elapsedLabel)}</div>
			</div>
			<div class="summary-card">
				<div class="summary-label">Zugeordnete Starts</div>
				<div class="summary-value">${assignments.length}</div>
			</div>
			<div class="summary-card">
				<div class="summary-label">Erkundete Knoten</div>
				<div class="summary-value">${Number(result.nodesExplored || 0).toLocaleString('de-DE')}</div>
			</div>
		</div>
		<div class="detail-grid">
			<div class="detail-card">
				<div class="summary-label">Geschlechterverteilung</div>
				<div class="detail-list">${Object.entries(perGender).map(([label, count]) => `<div><strong>${escapeHtmlText(label)}</strong><span>${count}</span></div>`).join('')}</div>
			</div>
			<div class="detail-card">
				<div class="summary-label">Status</div>
				<div class="detail-text">Optimale Zuordnung gefunden. Berechnungszeit: ${escapeHtmlText(elapsedLabel)}.</div>
			</div>
		</div>
		<div class="table-card">
			<table class="result-table">
				<thead>
					<tr>
						<th>Start</th>
						<th>Stil</th>
						<th>Sportler</th>
						<th>Geschlecht</th>
						<th>Jahrgang</th>
						<th>Zeit</th>
					</tr>
				</thead>
				<tbody>${rows}</tbody>
			</table>
		</div>
	`;
}

function pollJob(jobId) {
	let pulse = 10;
	pollTimer = window.setInterval(async () => {
		try {
			const response = await fetch(`?action=status&id=${encodeURIComponent(jobId)}`, { cache: 'no-store' });
			const data = await response.json();
			if (!response.ok || !data.ok) {
				throw new Error(data.error || 'Could not read job status.');
			}

			const status = data.status || {};
			const result = data.result || null;
			const elapsedLabel = status.elapsedLabel || result?.elapsedLabel || (Number.isFinite(status.elapsedMs) ? formatTime(status.elapsedMs) : '');
			setProgress(
				typeof status.progress === 'number' ? status.progress : pulse,
				status.phase === 'searching' ? 'Suche' : (status.phase === 'preparing' ? 'Vorbereitung' : (status.phase === 'finished' ? 'Fertig' : (status.phase === 'error' ? 'Fehler' : 'Läuft'))),
				elapsedLabel ? `${status.message || 'Läuft...'} · ${elapsedLabel}` : (status.message || 'Läuft...')
			);

			if (status.done) {
				clearInterval(pollTimer);
				pollTimer = null;
				solveButton.disabled = false;
				setProgress(100, 'Fertig', result?.elapsedLabel ? `Die Berechnung ist abgeschlossen. Dauer: ${result.elapsedLabel}.` : (status.message || 'Die Berechnung ist abgeschlossen.'));
				renderResult(result || {});
			} else {
				pulse = Math.min(95, pulse + 2);
			}
		} catch (error) {
			clearInterval(pollTimer);
			pollTimer = null;
			solveButton.disabled = false;
			setProgress(0, 'Fehler', error.message);
		}
	}, 900);
}

addRowButton.addEventListener('click', () => {
	athletesBody.insertAdjacentHTML('beforeend', rowMarkup());
	bindRowRemovers();
});

resetSampleButton.addEventListener('click', () => loadRows(sampleAthletes));
solveButton.addEventListener('click', startSolve);

loadRows(sampleAthletes);
setProgress(0, 'Bereit', 'Warte auf einen Lauf.');
</script>

</body>
</html>
