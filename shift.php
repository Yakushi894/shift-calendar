<?php
header('Content-Type: text/html; charset=UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', '1');

date_default_timezone_set('Asia/Tokyo');

/*
 * =====================================================================
 * 設定
 * =====================================================================
 */

// ここを自分の名前・人数分にしてください。
// 配列のキーは内部IDとして shift_data.json に保存されます。
// キー番号を変えると過去データと紐づかなくなるので注意してください。
$people = array(
    0 => '山田 太郎',
    1 => '佐藤 一郎',
    2 => '鈴木 二郎',
    3 => '高橋 三郎',
    4 => '伊藤 四郎',
    5 => '松本 五郎',
);

// 保存ファイル。このフォルダに書き込み権限が必要です。
$dataFile = __DIR__ . '/shift_data.json';
$holidayCacheFile = __DIR__ . '/holiday_cache.json';

// 日本の祝日APIを使う場合は true。
// オフライン環境などでエラーが出る場合は false にしてください。
$useRemoteHolidays = true;
$holidayApiUrl = 'https://holidays-jp.github.io/api/v1/date.json';
$holidayCacheTtl = 86400 * 30;


/*
 * =====================================================================
 * ユーティリティ
 * =====================================================================
 */

function e($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}


function parseInteger($value, $min, $max)
{
    if (!is_scalar($value)) {
        return null;
    }

    $value = trim((string)$value);

    if (!preg_match('/^[-+]?\d+$/', $value)) {
        return null;
    }

    $value = (int)$value;

    if ($value < $min || $value > $max) {
        return null;
    }

    return $value;
}


function requestInteger($name, $min, $max)
{
    $value = null;

    if (isset($_POST[$name])) {
        $value = parseInteger($_POST[$name], $min, $max);
    }

    if ($value === null && isset($_GET[$name])) {
        $value = parseInteger($_GET[$name], $min, $max);
    }

    return $value === null ? 0 : $value;
}


function resolveYearMonth()
{
    $year = requestInteger('year', 1900, 2100);
    $month = requestInteger('month', 1, 12);

    if (!checkdate($month, 1, $year)) {
        $year = (int)date('Y');
        $month = (int)date('n');
    }

    return array($year, $month);
}


function buildPeriods($year, $month)
{
    // 入力月の前月20日から、入力月の末日まで
    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $prev = $first->modify('-1 month');

    $start = $prev->setDate((int)$prev->format('Y'), (int)$prev->format('n'), 20);
    $end = $first->modify('last day of this month');

    // 前月20日から当月20日まで
    $payStart = $start;
    $payEnd = $first->setDate((int)$year, (int)$month, 20);

    return array(
        'start' => $start,
        'end' => $end,
        'payStart' => $payStart,
        'payEnd' => $payEnd,
    );
}


/*
 * =====================================================================
 * 祝日
 * =====================================================================
 */

function knownStaticHolidays()
{
    static $holidaySet = null;

    if ($holidaySet !== null) {
        return $holidaySet;
    }

    // 主な2024年 - 2026年の祝日・振替休日・国民の休日
    $dates = array(
        // 2024年
        '2024-01-01',
        '2024-01-08',
        '2024-02-11',
        '2024-02-12',
        '2024-02-23',
        '2024-03-20',
        '2024-04-29',
        '2024-05-03',
        '2024-05-04',
        '2024-05-05',
        '2024-05-06',
        '2024-07-15',
        '2024-08-11',
        '2024-08-12',
        '2024-09-16',
        '2024-09-22',
        '2024-09-23',
        '2024-10-14',
        '2024-11-03',
        '2024-11-04',
        '2024-11-23',

        // 2025年
        '2025-01-01',
        '2025-01-13',
        '2025-02-11',
        '2025-02-23',
        '2025-02-24',
        '2025-03-20',
        '2025-04-29',
        '2025-05-03',
        '2025-05-04',
        '2025-05-05',
        '2025-05-06',
        '2025-07-21',
        '2025-08-11',
        '2025-09-15',
        '2025-09-23',
        '2025-10-13',
        '2025-11-03',
        '2025-11-23',
        '2025-11-24',

        // 2026年
        '2026-01-01',
        '2026-01-12',
        '2026-02-11',
        '2026-02-23',
        '2026-03-20',
        '2026-04-29',
        '2026-05-03',
        '2026-05-04',
        '2026-05-05',
        '2026-05-06',
        '2026-07-20',
        '2026-08-11',
        '2026-09-21',
        '2026-09-22',
        '2026-09-23',
        '2026-10-12',
        '2026-11-03',
        '2026-11-23'
    );

    $holidaySet = array();

    foreach ($dates as $date) {
        $holidaySet[$date] = true;
    }

    return $holidaySet;
}


function downloadString($url, $timeout = 3)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);

        if ($ch !== false) {
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3
            ));

            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if (is_string($res) && $res !== '' && ($code == 0 || ($code >= 200 && $code < 300))) {
                return $res;
            }
        }
    }

    $ctx = stream_context_create(array(
        'http' => array(
            'timeout' => $timeout,
            'follow_location' => 1
        )
    ));

    $res = @file_get_contents($url, false, $ctx);

    return is_string($res) ? $res : '';
}


function loadHolidays()
{
    static $holidaySet = null;

    if ($holidaySet !== null) {
        return $holidaySet;
    }

    $holidaySet = knownStaticHolidays();

    global $holidayCacheFile, $holidayApiUrl, $useRemoteHolidays, $holidayCacheTtl;

    if (!$useRemoteHolidays || !is_string($holidayCacheFile) || $holidayCacheFile === '') {
        return $holidaySet;
    }

    $raw = '';
    $staleRaw = '';

    if (is_readable($holidayCacheFile)) {
        $cacheRaw = @file_get_contents($holidayCacheFile);

        if (is_string($cacheRaw)) {
            $staleRaw = $cacheRaw;
            $age = time() - (int)@filemtime($holidayCacheFile);

            if ($age < $holidayCacheTtl) {
                $raw = $cacheRaw;
            }
        }
    }

    if ($raw === '') {
        $raw = downloadString($holidayApiUrl, 3);

        if ($raw !== '') {
            @file_put_contents($holidayCacheFile, $raw, LOCK_EX);
        }

        if ($raw === '' && $staleRaw !== '') {
            $raw = $staleRaw;
        }
    }

    if (is_string($raw) && strlen($raw) >= 3 && substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $raw = substr($raw, 3);
    }

    if (is_string($raw)) {
        $raw = trim($raw);
    }

    if (is_string($raw) && $raw !== '') {
        $json = json_decode($raw, true);

        if (is_array($json)) {
            foreach ($json as $date => $name) {
                $date = (string)$date;

                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $holidaySet[$date] = true;
                }
            }
        }
    }

    return $holidaySet;
}


/*
 * =====================================================================
 * 勤怠データ
 * =====================================================================
 */

function attendanceField($date, $personId)
{
    return 'att_' . preg_replace('/\D/', '', $date) . '_' . (int)$personId;
}


function readJsonFile($file)
{
    if (!is_readable($file)) {
        return array();
    }

    $raw = @file_get_contents($file);

    if (!is_string($raw) || $raw === '') {
        return array();
    }

    if (strlen($raw) >= 3 && substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $raw = substr($raw, 3);
    }

    $raw = trim($raw);

    if ($raw === '') {
        return array();
    }

    $decoded = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return array();
    }

    return is_array($decoded) ? $decoded : array();
}


function writeJsonFile($file, $data)
{
    $json = json_encode($data);

    if ($json === false) {
        return false;
    }

    $dir = dirname($file);

    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    return @file_put_contents($file, $json, LOCK_EX) !== false;
}


function buildCalendarRows($start, $end, array $holidaySet, array $weekLabels)
{
    $rows = array();
    $d = $start;

    while ($d <= $end) {
        $date = $d->format('Y-m-d');
        $dow = (int)$d->format('w');
        $isHoliday = isset($holidaySet[$date]);

        $className = '';

        if ($isHoliday) {
            $className = 'holiday';
        } elseif ($dow === 0) {
            $className = 'sunday';
        } elseif ($dow === 6) {
            $className = 'saturday';
        }

        $label = $d->format('m/d');

        if ($d->format('d') === '01' || $d->format('d') === '20') {
            $label = $d->format('y/m/d');
        }

        $rows[] = array(
            'date' => $date,
            'dow' => $dow,
            'isHoliday' => $isHoliday,
            'className' => $className,
            'label' => $label,
            'title' => $date
        );

        $d = $d->modify('+1 day');
    }

    return $rows;
}


function countAttendance(array $calendar, array $people, array $attendance, $periodStart, $periodEnd)
{
    $counts = array();
    $periodCounts = array();

    foreach ($people as $personId => $name) {
        $counts[$personId] = 0;
        $periodCounts[$personId] = 0;
    }

    foreach ($calendar as $row) {
        $date = $row['date'];

        if (!isset($attendance[$date]) || !is_array($attendance[$date])) {
            continue;
        }

        $inPeriod = ($date >= $periodStart && $date <= $periodEnd);

        foreach (array_keys($people) as $personId) {
            if (isset($attendance[$date][$personId]) && !empty($attendance[$date][$personId])) {
                $counts[$personId]++;

                if ($inPeriod) {
                    $periodCounts[$personId]++;
                }
            }
        }
    }

    return array($counts, $periodCounts);
}


function saveAttendanceFromRequest(array $calendar, array $people, array $attendance, $dataFile)
{
    $newAttendance = $attendance;

    foreach ($calendar as $row) {
        $date = $row['date'];

        if (!isset($newAttendance[$date]) || !is_array($newAttendance[$date])) {
            $newAttendance[$date] = array();
        }

        foreach (array_keys($people) as $personId) {
            $field = attendanceField($date, $personId);

            if (isset($_POST[$field]) && !empty($_POST[$field])) {
                $newAttendance[$date][$personId] = true;
            } else {
                unset($newAttendance[$date][$personId]);
            }
        }

        if (empty($newAttendance[$date])) {
            unset($newAttendance[$date]);
        }
    }

    if (writeJsonFile($dataFile, $newAttendance)) {
        return array(
            'isError' => false,
            'message' => '保存しました。'
        );
    }

    return array(
        'isError' => true,
        'message' => '保存に失敗しました。shift_data.jsonと同じフォルダに書き込み権限が必要です。'
    );
}


/*
 * =====================================================================
 * メイン処理
 * =====================================================================
 */

list($year, $month) = resolveYearMonth();
$period = buildPeriods($year, $month);

$start = $period['start'];
$end = $period['end'];
$payStart = $period['payStart'];
$payEnd = $period['payEnd'];

$weekLabels = array('日', '月', '火', '水', '木', '金', '土');

$holidays = loadHolidays();
$calendar = buildCalendarRows($start, $end, $holidays, $weekLabels);

$attendance = readJsonFile($dataFile);

$saveMessage = '';
$saveMessageIsError = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postedYear = isset($_POST['year']) ? parseInteger($_POST['year'], 1900, 2100) : null;
    $postedMonth = isset($_POST['month']) ? parseInteger($_POST['month'], 1, 12) : null;

    if ($postedYear === $year && $postedMonth === $month) {
        $result = saveAttendanceFromRequest($calendar, $people, $attendance, $dataFile);
        $saveMessage = $result['message'];
        $saveMessageIsError = $result['isError'];

        if (!$saveMessageIsError) {
            $attendance = readJsonFile($dataFile);
        }
    } else {
        $saveMessage = '表示期間と送信内容が一致しません。年・月を表示してから入力してください。';
        $saveMessageIsError = true;
    }
}

list($counts, $periodCounts) = countAttendance(
    $calendar,
    $people,
    $attendance,
    $payStart->format('Y-m-d'),
    $payEnd->format('Y-m-d')
);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>シフトカレンダー</title>
<style>
:root {
    --bg: #f5f7fb;
    --panel: #ffffff;
    --text: #111827;
    --muted: #4b5563;
    --border: #cbd5e1;
    --border-strong: #94a3b8;
    --accent: #1d4ed8;
    --accent-hover: #1e40af;
    --success: #16a34a;
    --success-bg: #ecfdf5;
    --success-border: #34d399;
    --success-text: #065f46;
    --error-bg: #fef2f2;
    --error-border: #ef4444;
    --error-text: #991b1b;
    --holiday: #fff7cc;
    --holiday-text: #8a5a00;
    --sun: #ffe4e6;
    --sun-text: #991b1b;
    --sat: #dbeafe;
    --sat-text: #1e3a8a;
}

* {
    box-sizing: border-box;
}

html {
    -webkit-text-size-adjust: 100%;
}

body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI",
        Roboto, "Hiragino Kaku Gothic ProN", "Yu Gothic", Meiryo, sans-serif;
    line-height: 1.45;
    font-size: 14px;
}

.app {
    max-width: 1400px;
    margin: 0 auto;
    padding: 16px;
}

.card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 14px;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.07);
    margin-bottom: 14px;
}

h1 {
    margin: 0 0 10px;
    font-size: 20px;
    line-height: 1.3;
}

h2 {
    margin: 0 0 8px;
    font-size: 16px;
}

p {
    margin: 6px 0;
}

.muted {
    color: var(--muted);
    font-size: 12px;
}

.alert {
    border-radius: 10px;
    padding: 10px 12px;
    margin: 10px 0;
    font-size: 13px;
}

.alert.success {
    background: var(--success-bg);
    border: 1px solid var(--success-border);
    color: var(--success-text);
}

.alert.error {
    background: var(--error-bg);
    border: 1px solid var(--error-border);
    color: var(--error-text);
}

.controls {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 10px;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 110px;
}

.field label {
    font-size: 12px;
    color: var(--muted);
}

input[type="number"],
select,
button,
.btn {
    border: 1px solid var(--border-strong);
    border-radius: 10px;
    padding: 8px 10px;
    font-size: 14px;
    min-height: 40px;
    background: #fff;
    color: var(--text);
}

input[type="number"] {
    width: 120px;
}

select {
    width: 110px;
}

button,
.btn {
    cursor: pointer;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    white-space: nowrap;
}

.btn-primary {
    background: var(--accent);
    color: #fff;
    border-color: var(--accent);
}

.btn-primary:hover {
    background: var(--accent-hover);
    border-color: var(--accent-hover);
}

.btn-secondary {
    background: #e2e8f0;
    color: #0f172a;
    border-color: #cbd5e1;
}

.btn-secondary:hover {
    background: #cbd5e1;
}

button:disabled {
    opacity: 0.7;
    cursor: wait;
}

input:focus-visible,
select:focus-visible,
button:focus-visible,
.btn:focus-visible {
    outline: 3px solid #93c5fd;
    outline-offset: 2px;
}

.info-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 16px;
    font-size: 13px;
}

.info-item {
    min-width: 240px;
}

.info-item strong {
    font-size: 12px;
    color: var(--muted);
    display: block;
}

.legend {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 10px;
    font-size: 12px;
    color: var(--muted);
}

.legend span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.swatch {
    width: 14px;
    height: 14px;
    display: inline-block;
    border: 1px solid var(--border-strong);
    border-radius: 4px;
}

.swatch.holiday {
    background: var(--holiday);
}

.swatch.sunday {
    background: var(--sun);
}

.swatch.saturday {
    background: var(--sat);
}

.table-wrap {
    overflow: auto;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior-x: contain;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--panel);
    margin-top: 8px;
}

table {
    border-collapse: separate;
    border-spacing: 0;
    width: max-content;
    min-width: 100%;
    background: var(--panel);
}

th,
td {
    border-right: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
    padding: 6px;
    text-align: center;
    vertical-align: middle;
    white-space: nowrap;
    font-size: 12px;
    min-height: 36px;
    background: #fff;
}

thead th {
    background: #f8fafc;
    font-weight: 600;
}

.name-col,
.name-cell {
    position: sticky;
    left: 0;
    z-index: 10;
    min-width: 190px;
    width: 190px;
    text-align: left;
    padding: 8px;
    white-space: normal;
    overflow-wrap: anywhere;
    box-shadow: 1px 0 0 var(--border);
}

thead .name-col {
    z-index: 30;
    background: #f8fafc;
}

.name-cell {
    background: var(--panel);
}

.day-col {
    min-width: 46px;
}

.holiday-col {
    min-width: 26px;
}

.check-cell {
    padding: 0;
    min-width: 46px;
}

.holiday,
th.holiday,
td.holiday,
td.check-cell.holiday {
    background: var(--holiday);
    color: var(--holiday-text);
}

.sunday,
th.sunday,
td.sunday,
td.check-cell.sunday {
    background: var(--sun);
    color: var(--sun-text);
}

.saturday,
th.saturday,
td.saturday,
td.check-cell.saturday {
    background: var(--sat);
    color: var(--sat-text);
}

.cell {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 34px;
    min-width: 38px;
    padding: 0;
    cursor: pointer;
    background: transparent;
}

.cell:hover {
    background: rgba(29, 78, 216, 0.08);
}

input[type="checkbox"] {
    -webkit-appearance: none;
    appearance: none;
    width: 22px;
    height: 22px;
    margin: 0;
    border: 2px solid var(--border-strong);
    border-radius: 6px;
    background: #fff;
    position: relative;
    cursor: pointer;
    transition: background 0.12s ease, border-color 0.12s ease, transform 0.12s ease;
}

input[type="checkbox"]:checked {
    background: var(--success);
    border-color: var(--success);
}

input[type="checkbox"]::after {
    content: "";
    position: absolute;
    left: 27%;
    top: 5%;
    width: 30%;
    height: 58%;
    border: solid #fff;
    border-width: 0 3px 3px 0;
    transform: rotate(45deg) scale(0.9);
    opacity: 0;
}

input[type="checkbox"]:checked::after {
    opacity: 1;
    transform: rotate(45deg) scale(1);
}

input[type="checkbox"]:focus-visible {
    outline: 3px solid #93c5fd;
    outline-offset: 2px;
}

.save-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    margin-top: 10px;
}

.summary-card {
    overflow: auto;
}

.summary-table {
    width: 100%;
    max-width: 900px;
}

.summary-table th,
.summary-table td {
    min-width: 150px;
    padding: 10px;
}

.summary-table .name-col,
.summary-table .name-cell {
    position: static;
    min-width: 150px;
    width: auto;
    box-shadow: none;
}

body.has-save-bar {
    padding-bottom: 0;
}

@media (max-width: 767px) {
    .app {
        padding: 10px;
    }

    .card {
        border-radius: 10px;
        padding: 10px;
        margin-bottom: 10px;
    }

    h1 {
        font-size: 18px;
    }

    h2 {
        font-size: 15px;
    }

    body {
        font-size: 13px;
    }

    .controls {
        gap: 8px;
    }

    .field {
        min-width: calc(50% - 8px);
    }

    input[type="number"],
    select,
    button,
    .btn {
        width: 100%;
        min-height: 44px;
        font-size: 15px;
        border-radius: 10px;
    }

    input[type="number"],
    select {
        width: 100%;
    }

    .info-item {
        min-width: 100%;
    }

    th,
    td {
        padding: 0;
        font-size: 11px;
        min-height: 34px;
    }

    .name-col,
    .name-cell {
        min-width: 92px;
        width: 92px;
        font-size: 11px;
        padding: 5px;
        line-height: 1.25;
    }

    .day-col,
    .check-cell {
        min-width: 30px;
    }

    .holiday-col {
        min-width: 22px;
    }

    .cell {
        min-width: 30px;
        min-height: 34px;
    }

    input[type="checkbox"] {
        width: 24px;
        height: 24px;
    }

    .save-bar {
        position: sticky;
        bottom: 0;
        z-index: 50;
        background: var(--panel);
        border-top: 1px solid var(--border);
        padding: 10px;
        margin: 10px -10px -10px;
        box-shadow: 0 -4px 12px rgba(15, 23, 42, 0.08);
    }

    .save-bar .btn-primary {
        flex: 1 1 auto;
        min-width: 180px;
        min-height: 46px;
    }

    .summary-table,
    .summary-table th,
    .summary-table td {
        min-width: 0;
        font-size: 12px;
    }

    .summary-table .name-col,
    .summary-table .name-cell {
        min-width: 90px;
    }
}

@media (max-width: 420px) {
    .name-col,
    .name-cell {
        min-width: 82px;
        width: 82px;
        font-size: 10px;
    }

    .day-col,
    .check-cell {
        min-width: 26px;
    }

    .cell {
        min-width: 26px;
        min-height: 32px;
    }

    input[type="checkbox"] {
        width: 22px;
        height: 22px;
    }
}
</style>
</head>
<body>

<div class="app">

  <header class="card">
    <h1>シフトカレンダー</h1>

    <?php if ($saveMessage !== ''): ?>
      <div class="alert <?= $saveMessageIsError ? 'error' : 'success' ?>">
        <?php echo e($saveMessage); ?>
      </div>
    <?php endif; ?>

    <form method="get" action="" class="controls">
      <div class="field">
        <label for="year-input">年</label>
        <input
          id="year-input"
          name="year"
          type="number"
          min="1900"
          max="2100"
          value="<?php echo e($year); ?>"
          inputmode="numeric"
          required
        >
      </div>

      <div class="field">
        <label for="month-select">月</label>
        <select id="month-select" name="month" required>
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?php echo $m; ?>" <?php echo ((int)$month === $m) ? 'selected' : ''; ?>>
              <?php echo $m; ?>月
            </option>
          <?php endfor; ?>
        </select>
      </div>

      <button type="submit" class="btn btn-primary">表示</button>

      <a class="btn btn-secondary" href="?year=<?php echo e(date('Y')); ?>&month=<?php echo e(date('n')); ?>">
        今月へ
      </a>
    </form>
  </header>

  <section class="card">
    <h2>表示期間</h2>

    <div class="info-grid">
      <div class="info-item">
        <strong>表示期間</strong>
        <?php echo e($start->format('Y-m-d')); ?>
        -
        <?php echo e($end->format('Y-m-d')); ?>
      </div>

      <div class="info-item">
        <strong>集計期間</strong>
        <?php echo e($payStart->format('Y-m-d')); ?>
        -
        <?php echo e($payEnd->format('Y-m-d')); ?>
      </div>
    </div>

    <p class="muted">
      スマホでは横スクロールになります。左の名前列は固定されています。
    </p>

    <div class="legend">
      <span><i class="swatch holiday"></i>祝日</span>
      <span><i class="swatch sunday"></i>日曜</span>
      <span><i class="swatch saturday"></i>土曜</span>
    </div>
  </section>

  <form id="attendance-form" method="post" action="" autocomplete="off">
    <input type="hidden" name="year" value="<?php echo e($year); ?>">
    <input type="hidden" name="month" value="<?php echo e($month); ?>">

    <section class="card">
      <h2>出勤入力</h2>

      <div class="table-wrap">
        <table class="shift-table">
          <thead>
            <tr>
              <th rowspan="3" class="name-col">名前</th>

              <?php foreach ($calendar as $row): ?>
                <th class="day-col <?php echo e($row['className']); ?>">
                  <span title="<?php echo e($row['title']); ?>">
                    <?php echo e($row['label']); ?>
                  </span>
                </th>
              <?php endforeach; ?>
            </tr>

            <tr>
              <?php foreach ($calendar as $row): ?>
                <th class="day-col <?php echo e($row['className']); ?>">
                  <?php echo e($weekLabels[$row['dow']]); ?>
                </th>
              <?php endforeach; ?>
            </tr>

            <tr>
              <?php foreach ($calendar as $row): ?>
                <th class="day-col holiday-col <?php echo e($row['className']); ?>">
                  <?php if ($row['isHoliday']): ?>
                    祝
                  <?php else: ?>
                    &nbsp;
                  <?php endif; ?>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>

          <tbody>
            <?php foreach ($people as $personId => $personName): ?>
              <?php $personId = (int)$personId; ?>

              <tr>
                <th scope="row" class="name-cell">
                  <?php echo e($personName); ?>
                </th>

                <?php foreach ($calendar as $row): ?>
                  <?php
                    $fieldName = attendanceField($row['date'], $personId);

                    $checked = false;

                    if (
                        isset($attendance[$row['date']]) &&
                        is_array($attendance[$row['date']]) &&
                        isset($attendance[$row['date']][$personId]) &&
                        !empty($attendance[$row['date']][$personId])
                    ) {
                        $checked = true;
                    }

                    $tdClass = 'check-cell';

                    if ($row['className'] !== '') {
                        $tdClass .= ' ' . $row['className'];
                    }
                  ?>

                  <td class="<?php echo e($tdClass); ?>">
                    <label class="cell">
                      <input
                        type="checkbox"
                        name="<?php echo e($fieldName); ?>"
                        value="1"
                        aria-label="<?php echo e($row['title'] . ' / ' . $personName); ?>"
                        <?php echo $checked ? 'checked="checked"' : ''; ?>
                      >
                    </label>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="save-bar">
        <button type="submit" class="btn btn-primary">保存</button>
        <span class="muted">チェック後、必ず保存してください。</span>
      </div>
    </section>
  </form>

  <section class="card summary-card">
    <h2>出勤回数</h2>

    <div class="table-wrap">
      <table class="summary-table">
        <thead>
          <tr>
            <th class="name-col">名前</th>
            <th>表示期間内の出勤回数</th>
            <th>前月20日から当月20日までの出勤回数</th>
          </tr>
        </thead>

        <tbody>
          <?php foreach ($people as $personId => $personName): ?>
            <?php $personId = (int)$personId; ?>

            <tr>
              <th scope="row" class="name-cell">
                <?php echo e($personName); ?>
              </th>

              <td>
                <?php echo isset($counts[$personId]) ? e($counts[$personId]) : 0; ?>
              </td>

              <td>
                <?php echo isset($periodCounts[$personId]) ? e($periodCounts[$personId]) : 0; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

</div>

<script>
(function () {
  "use strict";

  var form = document.getElementById("attendance-form");
  var hasChanges = false;

  document.addEventListener("change", function (event) {
    if (event.target && event.target.type === "checkbox") {
      hasChanges = true;
    }
  });

  if (form) {
    form.addEventListener("submit", function () {
      hasChanges = false;

      var submitButton = form.querySelector('button[type="submit"]');

      if (submitButton && !submitButton.disabled) {
        submitButton.disabled = true;
        submitButton.textContent = "保存中...";
      }
    });
  }

  window.addEventListener("beforeunload", function (event) {
    if (hasChanges) {
      event.preventDefault();
      event.returnValue = "";
    }
  });
})();
</script>

</body>
</html>
