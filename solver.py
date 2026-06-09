#!/usr/bin/env python3
from __future__ import annotations

import json
import math
import sys
import time
import threading
from dataclasses import dataclass
from pathlib import Path
from typing import List, Optional, Sequence, Tuple


STYLE_NAMES = {
    0: 'backstroke',
    1: 'breaststroke',
    2: 'butterfly',
    3: 'freestyle',
}


@dataclass(frozen=True)
class Athlete:
    name: str
    gender: str
    birth_year: int
    times: Tuple[int, ...]


def write_json(path: str | Path, payload: dict) -> None:
    target = Path(path)
    temp_path = target.with_suffix(target.suffix + '.tmp')
    temp_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding='utf-8')
    temp_path.replace(target)


def parse_time(value: str) -> int:
    text = value.strip()
    if not text:
        raise ValueError('empty time value')

    if ':' in text:
        minutes_text, rest = text.split(':', 1)
        minutes = int(minutes_text)
        if ',' in rest:
            seconds_text, hundredths_text = rest.split(',', 1)
        else:
            seconds_text, hundredths_text = rest, '0'
        seconds = int(seconds_text)
        hundredths = int((hundredths_text + '00')[:2])
        return minutes * 60_000 + seconds * 1_000 + hundredths * 10

    return int(float(text) * 1000)


def format_time(milliseconds: int) -> str:
    minutes, remainder = divmod(milliseconds, 60_000)
    seconds, remainder = divmod(remainder, 1_000)
    hundredths = remainder // 10
    return f'{minutes}:{seconds:02d},{hundredths:02d}'


def load_job(input_path: str | Path) -> dict:
    payload = json.loads(Path(input_path).read_text(encoding='utf-8'))
    payload.setdefault('competitionYear', time.localtime().tm_year)
    payload.setdefault('condition', 'open')
    payload.setdefault('conditionConfig', {})
    return payload


def normalize_athletes(raw_athletes: Sequence[dict]) -> List[Athlete]:
    athletes: List[Athlete] = []
    for athlete in raw_athletes:
        times = tuple(parse_time(str(value)) for value in athlete.get('times', []))
        athletes.append(
            Athlete(
                name=str(athlete.get('name', '')).strip(),
                gender=str(athlete.get('gender', '')).strip().lower(),
                birth_year=int(athlete.get('birthYear', 0)),
                times=times,
            )
        )
    return athletes


def age_of(birth_year: int, competition_year: int) -> int:
    return max(0, competition_year - birth_year)


def permutation_count(n_items: int, n_slots: int) -> int:
    if n_slots > n_items:
        return 0
    total = 1
    for value in range(n_items, n_items - n_slots, -1):
        total *= value
    return total


def build_band_info(config: dict, athletes: Sequence[Athlete], competition_year: int) -> Tuple[List[dict], List[List[int]]]:
    bands = []
    membership: List[List[int]] = []
    for band in config.get('bands', []) or []:
        bands.append({
            'minAge': int(band.get('minAge', 0)),
            'maxAge': int(band.get('maxAge', 10_000)),
            'count': int(band.get('count', 0)),
        })
        membership.append([])

    for athlete_index, athlete in enumerate(athletes):
        age = age_of(athlete.birth_year, competition_year)
        for band_index, band in enumerate(bands):
            if band['minAge'] <= age <= band['maxAge']:
                membership[band_index].append(athlete_index)

    return bands, membership


def main(argv: Sequence[str]) -> int:
    if len(argv) != 4:
        print('Aufruf: solver.py INPUT_JSON STATUS_JSON RESULT_JSON', file=sys.stderr)
        return 2

    input_path, status_path, result_path = argv[1:4]

    try:
        job = load_job(input_path)
        layout = [int(slot) for slot in job.get('layout', [])]
        athletes = normalize_athletes(job.get('athletes', []))
        competition_year = int(job.get('competitionYear', time.localtime().tm_year))
        condition = str(job.get('condition', 'open'))
        condition_config = dict(job.get('conditionConfig', {}) or {})

        if not layout:
            raise ValueError('Die Staffelaufstellung ist leer.')
        if not athletes:
            raise ValueError('Es wurden keine Athleten übergeben.')
        if len(athletes) < len(layout):
            raise ValueError('Nicht genügend Athleten für alle Staffelpositionen.')

        for athlete in athletes:
            if len(athlete.times) < 4:
                raise ValueError(f'Athlet {athlete.name} muss vier Zeiten haben.')

        slot_candidates: List[List[Tuple[int, int]]] = []
        for style in layout:
            candidates = [(athlete.times[style], athlete_index) for athlete_index, athlete in enumerate(athletes)]
            candidates.sort(key=lambda item: item[0])
            slot_candidates.append(candidates)

        slot_order = sorted(range(len(layout)), key=lambda index: len(slot_candidates[index]))
        total_leaves = max(1, permutation_count(len(athletes), len(layout)))
        ages = [age_of(athlete.birth_year, competition_year) for athlete in athletes]
        genders = [athlete.gender for athlete in athletes]
        best_time = math.inf
        best_assignment: Optional[List[int]] = None
        nodes_explored = 0
        last_update = 0.0
        start_time = time.monotonic()
        status_lock = threading.Lock()
        stop_event = threading.Event()
        status_state = {
            'phase': 'preparing',
            'progress': 6.0,
            'message': 'Kandidatenlisten werden vorbereitet.',
        }

        band_targets, _membership = build_band_info(condition_config, athletes, competition_year)
        band_counts = [0] * len(band_targets)

        max_age_sum = int(condition_config.get('maxAgeSum', 0)) if condition == 'max_age_sum' else 0
        male_max_age_sum = int(condition_config.get('maleMaxAgeSum', 0)) if condition == 'gender_age_sum' else 0
        female_max_age_sum = int(condition_config.get('femaleMaxAgeSum', 0)) if condition == 'gender_age_sum' else 0

        def elapsed_ms() -> int:
            return int((time.monotonic() - start_time) * 1000)

        def status_payload(done: bool = False, extra: Optional[dict] = None) -> dict:
            with status_lock:
                state = dict(status_state)
            progress = float(state['progress'])
            if not done and state['phase'] in {'preparing', 'searching'}:
                progress = max(progress, min(95.0, 6.0 + (elapsed_ms() / 1000.0) * 0.25))
            payload = {
                'done': done,
                'phase': state['phase'],
                'progress': round(progress, 2),
                'message': state['message'],
                'nodesExplored': nodes_explored,
                'elapsedMs': elapsed_ms(),
                'elapsedLabel': format_time(elapsed_ms()),
            }
            if not done:
                payload['message'] = f"{payload['message']} · Laufzeit {payload['elapsedLabel']}"
            if extra:
                payload.update(extra)
            return payload

        def status_update(phase: str, progress: float, message: str) -> None:
            with status_lock:
                status_state['phase'] = phase
                status_state['progress'] = progress
                status_state['message'] = message
            write_json(status_path, status_payload(False))

        def heartbeat() -> None:
            while not stop_event.wait(1.0):
                write_json(status_path, status_payload(False))

        heartbeat_thread = threading.Thread(target=heartbeat, daemon=True)
        heartbeat_thread.start()
        status_update('vorbereitung', 6.0, 'Kandidatenlisten werden vorbereitet.')

        def remaining_band_capacity(used: List[bool]) -> List[int]:
            capacity = [0] * len(band_targets)
            for athlete_index, athlete in enumerate(athletes):
                if used[athlete_index]:
                    continue
                athlete_age = ages[athlete_index]
                for band_index, band in enumerate(band_targets):
                    if band['minAge'] <= athlete_age <= band['maxAge']:
                        capacity[band_index] += 1
            return capacity

        def lower_bound(remaining_slots: List[int], used: List[bool]) -> int:
            estimate = 0
            for slot_index in remaining_slots:
                best = None
                for time_ms, athlete_index in slot_candidates[slot_index]:
                    if not used[athlete_index]:
                        best = time_ms
                        break
                if best is None:
                    return math.inf
                estimate += best
            return estimate

        def recurse(order_index: int, used: List[bool], chosen_by_slot: List[Optional[int]], current_time: int, age_sum: int, male_age_sum: int, female_age_sum: int, male_count: int, female_count: int) -> None:
            nonlocal best_time, best_assignment, nodes_explored, last_update

            nodes_explored += 1
            if current_time >= best_time:
                return

            if condition == 'balanced_gender':
                remaining_slots = len(layout) - order_index
                if abs(male_count - female_count) > remaining_slots + 1:
                    return

            if condition == 'max_age_sum' and max_age_sum > 0 and age_sum > max_age_sum:
                return

            if condition == 'gender_age_sum':
                if male_max_age_sum > 0 and male_age_sum > male_max_age_sum:
                    return
                if female_max_age_sum > 0 and female_age_sum > female_max_age_sum:
                    return

            if band_targets:
                capacity = remaining_band_capacity(used)
                for band_index, target in enumerate(band_targets):
                    if band_counts[band_index] > target['count']:
                        return
                    if band_counts[band_index] + capacity[band_index] < target['count']:
                        return

            remaining_slots = slot_order[order_index:]
            optimistic = current_time + lower_bound(remaining_slots, used)
            if optimistic >= best_time:
                return

            now = time.monotonic()
            if now - last_update >= 0.4:
                progress = min(95.0, 10.0 + 85.0 * (nodes_explored / total_leaves))
                status_message = f'Es werden {nodes_explored:,} Knoten geprüft. Beste bekannte Zeit: {format_time(int(best_time)) if math.isfinite(best_time) else "n/a"}.'
                status_update(
                    'searching',
                    progress,
                    status_message,
                )
                last_update = now

            if order_index == len(slot_order):
                best_time = current_time
                best_assignment = chosen_by_slot.copy()
                return

            slot_index = slot_order[order_index]
            for time_ms, athlete_index in slot_candidates[slot_index]:
                if used[athlete_index]:
                    continue

                used[athlete_index] = True
                chosen_by_slot[slot_index] = athlete_index

                next_age_sum = age_sum + ages[athlete_index]
                next_male_age_sum = male_age_sum + (ages[athlete_index] if genders[athlete_index] == 'male' else 0)
                next_female_age_sum = female_age_sum + (ages[athlete_index] if genders[athlete_index] == 'female' else 0)
                next_male_count = male_count + (1 if genders[athlete_index] == 'male' else 0)
                next_female_count = female_count + (1 if genders[athlete_index] == 'female' else 0)

                touched_bands: List[int] = []
                if band_targets:
                    athlete_age = ages[athlete_index]
                    for band_index, band in enumerate(band_targets):
                        if band['minAge'] <= athlete_age <= band['maxAge']:
                            band_counts[band_index] += 1
                            touched_bands.append(band_index)

                recurse(
                    order_index + 1,
                    used,
                    chosen_by_slot,
                    current_time + time_ms,
                    next_age_sum,
                    next_male_age_sum,
                    next_female_age_sum,
                    next_male_count,
                    next_female_count,
                )

                for band_index in touched_bands:
                    band_counts[band_index] -= 1
                chosen_by_slot[slot_index] = None
                used[athlete_index] = False

        recurse(0, [False] * len(athletes), [None] * len(layout), 0, 0, 0, 0, 0, 0)
        stop_event.set()
        heartbeat_thread.join(timeout=2.0)

        if best_assignment is None:
            result = {
                'ok': False,
                'status': 'no_solution',
                'message': 'Es konnte keine gültige Staffelzuordnung gefunden werden.',
                'nodesExplored': nodes_explored,
                'elapsedMs': elapsed_ms(),
                'elapsedLabel': format_time(elapsed_ms()),
            }
            write_json(status_path, {
                'done': True,
                'phase': 'finished',
                'progress': 100,
                'message': result['message'],
                'nodesExplored': nodes_explored,
                'elapsedMs': elapsed_ms(),
                'elapsedLabel': format_time(elapsed_ms()),
            })
            write_json(result_path, result)
            return 0

        assignments = []
        for slot_index, athlete_index in enumerate(best_assignment):
            athlete = athletes[athlete_index]
            style = layout[slot_index]
            time_ms = athlete.times[style]
            assignments.append({
                'slot': slot_index,
                'style': style,
                'styleName': STYLE_NAMES.get(style, str(style)),
                'athleteIndex': athlete_index,
                'athlete': {
                    'name': athlete.name,
                    'gender': athlete.gender,
                    'birthYear': athlete.birth_year,
                    'age': ages[athlete_index],
                },
                'timeMs': time_ms,
                'time': format_time(time_ms),
            })

        result = {
            'ok': True,
            'status': 'done',
            'message': 'Optimale Zuordnung gefunden.',
            'totalTimeMs': int(best_time),
            'totalTime': format_time(int(best_time)),
            'elapsedMs': elapsed_ms(),
            'elapsedLabel': format_time(elapsed_ms()),
            'nodesExplored': nodes_explored,
            'assignments': assignments,
        }

        write_json(status_path, {
            'done': True,
            'phase': 'finished',
            'progress': 100,
            'message': result['message'],
            'nodesExplored': nodes_explored,
            'bestTimeMs': int(best_time),
            'elapsedMs': result['elapsedMs'],
            'elapsedLabel': result['elapsedLabel'],
        })
        write_json(result_path, result)
        stop_event.set()
        heartbeat_thread.join(timeout=2.0)
        return 0
    except Exception as exc:
        stop_event.set()
        write_json(status_path, {
            'done': True,
            'phase': 'error',
            'progress': 100,
            'message': str(exc),
            'elapsedMs': elapsed_ms() if 'elapsed_ms' in locals() else 0,
            'elapsedLabel': format_time(elapsed_ms()) if 'elapsed_ms' in locals() else '0:00,00',
        })
        write_json(result_path, {
            'ok': False,
            'status': 'error',
            'message': str(exc),
            'elapsedMs': elapsed_ms() if 'elapsed_ms' in locals() else 0,
            'elapsedLabel': format_time(elapsed_ms()) if 'elapsed_ms' in locals() else '0:00,00',
        })
        print(str(exc), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main(sys.argv))