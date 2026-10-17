<?php
header('Content-Type: text/html; charset=UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', '1');

date_default_timezone_set('Asia/Tokyo');

// ここを自分の名前・6人分にしてください。
$people = array(
    0 => '山田 太郎',
    1 => '佐藤 一郎',
    2 => '鈴木 二郎',
    3 => '高橋 三郎',
    4 => '伊藤 四郎',
    5 => '松本 五郎',
);

// 保存ファイル。このフォルダに書き込み権限が必要です。
$dataFile = dirname(__FILE__).'/shift_data.json';
$holidayCacheFile = dirname(__FILE__).'/holiday_cache.json';

// 日本の祝日APIを使う場合は true。
// オフライン環境などでエラーが出る場合は falseにしてください。
$useRemoteHolidays = true;


function esc($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}


function validateInt($value, $min, $max)
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


function requestedInt($name, $min, $max)
{
    if (isset($_POST[$name])) {
        $value = validateInt($_POST[$name], $min, $max);
        if ($value !== null) {
            return $value;
        }
    }

    if (isset($_GET[$name])) {
        $value = validateInt($_GET[$name], $min, $max);
        if ($value !== null) {
            return $value;
        }
    }

    return 0;
}


function getYearMonth()
{
    $year = requestedInt('year', 1900, 2100);
    $month = requestedInt('month', 1, 12);

    if (!checkdate($month, 1, $year)) {
        $year = (int)date('Y');
        $month = (int)date('n');
    }

    return array($year, $month);
}


function buildRange($year, $month)
{
    // 入力月の前月20日から、入力月の末日まで
    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $prev = $first->modify('-1 month');
    $start = $prev->setDate((int)$prev->format('Y'), (int)$prev->format('n'), 20);
    $end = $first->modify('last day of this month');

    // 前月20日から当月20日まで
    $payStart = $start;
    $payEnd = $first->setDate((int)$year, (int)$month, 20);

    return array($start, $end, $payStart, $payEnd);
}


function staticHolidays()
{
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

    $keys = array();
    foreach ($dates as $date) {
        $keys[$date] = true;
    }

    return $keys;
}


function fetchUrl($url, $timeout = 3)
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


function getHolidayKeys()
{
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }

    $keys = staticHolidays();

    global $holidayCacheFile, $useRemoteHolidays;

    if (!$useRemoteHolidays || !is_string($holidayCacheFile) || $holidayCacheFile === '') {
        return $keys;
    }

    $ttl = 86400 * 30;
    $raw = '';
    $staleRaw = '';

    if (is_readable($holidayCacheFile)) {
        $cacheRaw = @file_get_contents($holidayCacheFile);
        if (is_string($cacheRaw)) {
            $staleRaw = $cacheRaw;
            $age = time() - (int)@filemtime($holidayCacheFile);
            if ($age < $ttl) {
                $raw = $cacheRaw;
            }
        }
    }

    if ($raw === '') {
        // 日本の祝日API
        $url = 'https://holidays-jp.github.io/api/v1/date.json';
        $raw = fetchUrl($url, 3);

        if (is_string($raw) && $raw !== '') {
            @file_put_contents($holidayCacheFile, $raw, LOCK_EX);
        }

        if ((!is_string($raw) || $raw === '') && $staleRaw !== '') {
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
                    $keys[$date] = true;
                }
            }
        }
    }

    return $keys;
}


function attendanceFieldName($date, $pid)
{
    return 'att_' . preg_replace('/[^0-9]/', '', $date) . '_' . (int)$pid;
}


function loadAttendance($file)
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


function saveAttendance($file, $data)
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


list($year, $month) = getYearMonth();
list($start, $end, $payStart, $payEnd) = buildRange($year, $month);

$attendance = loadAttendance($dataFile);

$saveMessage = '';

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedYear = isset($_POST['year']) ? validateInt($_POST['year'], 1900, 2100) : null;
    $postedMonth = isset($_POST['month']) ? validateInt($_POST['month'], 1, 12) : null;

    if ($postedYear === $year && $postedMonth === $month) {
        $newAttendance = $attendance;
        $d = $start;

        while ($d <= $end) {
            $date = $d->format('Y-m-d');

            if (!isset($newAttendance[$date]) || !is_array($newAttendance[$date])) {
                $newAttendance[$date] = array();
            }

            foreach (array_keys($people) as $pid) {
                $field = attendanceFieldName($date, $pid);

                if (isset($_POST[$field]) && !empty($_POST[$field])) {
                    $newAttendance[$date][$pid] = true;
                } else {
                    unset($newAttendance[$date][$pid]);
                }
            }

            if (empty($newAttendance[$date])) {
                unset($newAttendance[$date]);
            }

            $d = $d->modify('+1 day');
        }

        if (saveAttendance($dataFile, $newAttendance)) {
            $saveMessage = '保存しました。';
        } else {
            $saveMessage = '保存に失敗しました。shift_data.jsonと同じフォルダに書き込み権限が必要です。';
        }

        $attendance = loadAttendance($dataFile);
    } else {
        $saveMessage = '表示期間と送信内容が一致しません。年・月を表示してから入力してください。';
    }
}

$personIds = array_keys($people);
$holidaySet = getHolidayKeys();

$weekLabels = array('日', '月', '火', '水', '木', '金', '土');

$counts = array();
$periodCounts = array();
foreach ($personIds as $pid) {
    $counts[$pid] = 0;
    $periodCounts[$pid] = 0;
}

$dateList = array();

$periodStart = $payStart->format('Y-m-d');
$periodEnd = $payEnd->format('Y-m-d');

$d = $start;
while ($d <= $end) {
    $date = $d->format('Y-m-d');
    $dow = (int)$d->format('w');
    $isHoliday = isset($holidaySet[$date]);

    $colClass = '';
    if ($isHoliday) {
        $colClass = 'holiday';
    } elseif ($dow === 0) {
        $colClass = 'sunday';
    } elseif ($dow === 6) {
        $colClass = 'saturday';
    }

    $label = $d->format('m/d');
    if ($d->format('d') === '01' || $d->format('d') === '20') {
        $label = $d->format('y/m/d');
    }

    $dateList[] = array(
        'date' => $date,
        'dow' => $dow,
        'isHoliday' => $isHoliday,
        'colClass' => $colClass,
        'label' => $label,
        'title' => $date
    );

    $inPeriod = ($date >= $periodStart && $date <= $periodEnd);

    if (isset($attendance[$date]) && is_array($attendance[$date])) {
        foreach ($personIds as $pid) {
            if (isset($attendance[$date][$pid]) && !empty($attendance[$date][$pid])) {
                $counts[$pid]++;

                if ($inPeriod) {
                    $periodCounts[$pid]++;
                }
            }
        }
    }

    $d = $d->modify('+1 day');
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>シフトカレンダー</title>
<style>
body {
  margin: 12px;
  font-family: "MS Gothic", "Meiryo", sans-serif;
  background: #ffffff;
  color: #333333;
}
h1 {
  font-size: 18px;
  margin-bottom: 8px;
}
h2 {
  font-size: 15px;
  margin-top: 18px;
}
p, .small {
  font-size: 12px;
  color: #444444;
}
input[type="number"] {
  width: 80px;
  padding: 3px;
}
button {
  margin-left: 6px;
  padding: 6px 10px;
  cursor: pointer;
}
.message {
  margin: 10px 0;
  padding: 6px;
  border: 1px solid #339933;
  background: #e8ffe8;
  font-size: 13px;
}
.wrap {
  overflow-x: auto;
  margin-top: 8px;
}
table {
  border-collapse: collapse;
  margin: 8px 0;
}
th, td {
  border: 1px solid #aaaaaa;
  padding: 2px;
  text-align: center;
  font-size: 12px;
  white-space: nowrap;
}
.name-col,
.name-cell {
  position: sticky;
  left: 0;
  z-index: 30;
  background: #ffffff;
  min-width: 150px;
  width: 150px;
  text-align: left;
}
.day-col {
  min-width: 34px;
}
.holiday-col {
  min-width: 16px;
}
input[type="checkbox"] {
  width: 13px;
  height: 13px;
}
th.holiday,
td.holiday,
th.sunday,
td.sunday {
  background: #ffe3e3;
  color: #aa0000;
}
th.saturday,
td.saturday {
  background: #e7f2ff;
  color: #003366;
}
.summary-table {
  min-width: 600px;
}
.summary-table th,
.summary-table td {
  min-width: 120px;
}
</style>
</head>
<body>

<h1>シフトカレンダー</h1>

<?php if ($saveMessage !== ''): ?>
<div class="message"><?php echo esc($saveMessage); ?></div>
<?php endif; ?>

<form method="get" action="">
  <label>年 <input type="number" name="year" min="1900" max="2100" value="<?php echo esc($year); ?>"></label>
  <label>月 <input type="number" name="month" min="1" max="12" value="<?php echo esc($month); ?>"></label>
  <button type="submit">表示</button>
</form>

<h2>表示期間 <?php echo esc($start->format('Y-m-d')); ?> - <?php echo esc($end->format('Y-m-d')); ?></h2>
<p class="small">
  集計期間: <?php echo esc($payStart->format('Y-m-d')); ?> - <?php echo esc($payEnd->format('Y-m-d')); ?>
</p>

<p class="small">
  人物が縦、日付が横です。
  右へ横スクロールしてください。
</p>

<form id="attendance-form" method="post" action="">
  <input type="hidden" name="year" value="<?php echo esc($year); ?>">
  <input type="hidden" name="month" value="<?php echo esc($month); ?>">

  <div class="wrap">
  <table class="shift-table">
    <thead>
      <tr>
        <th rowspan="3" class="name-col">名前</th>
        <?php foreach ($dateList as $info): ?>
        <?php
        $thClass = 'day-col';
        if ($info['colClass'] !== '') {
            $thClass .= ' ' . $info['colClass'];
        }
        ?>
        <th class="<?php echo esc($thClass); ?>">
          <span title="<?php echo esc($info['title']); ?>">
            <?php echo esc($info['label']); ?>
          </span>
        </th>
        <?php endforeach; ?>
      </tr>

      <tr>
        <?php foreach ($dateList as $info): ?>
        <?php
        $thClass = 'day-col';
        if ($info['colClass'] !== '') {
            $thClass .= ' ' . $info['colClass'];
        }
        ?>
        <th class="<?php echo esc($thClass); ?>">
          <?php echo esc($weekLabels[$info['dow']]); ?>
        </th>
        <?php endforeach; ?>
      </tr>

      <tr>
        <?php foreach ($dateList as $info): ?>
        <?php
        $thClass = 'day-col holiday-col';
        if ($info['colClass'] !== '') {
            $thClass .= ' ' . $info['colClass'];
        }
        ?>
        <th class="<?php echo esc($thClass); ?>">
          <?php if ($info['isHoliday']): ?>
            祝
          <?php else: ?>
            &nbsp;
          <?php endif; ?>
        </th>
        <?php endforeach; ?>
      </tr>
    </thead>

    <tbody>
      <?php foreach ($people as $pid => $name): ?>
      <tr>
        <th scope="row" class="name-cell"><?php echo esc($name); ?></th>

        <?php foreach ($dateList as $info): ?>
        <?php
        $field = attendanceFieldName($info['date'], $pid);

        $checked = false;
        if (
            isset($attendance[$info['date']]) &&
            is_array($attendance[$info['date']]) &&
            isset($attendance[$info['date']][$pid]) &&
            !empty($attendance[$info['date']][$pid])
        ) {
            $checked = true;
        }

        $tdClass = 'check-cell';
        if ($info['colClass'] !== '') {
            $tdClass .= ' ' . $info['colClass'];
        }

        $checkedAttr = $checked ? ' checked="checked"' : '';
        ?>
        <td class="<?php echo esc($tdClass); ?>">
          <input
            type="checkbox"
            name="<?php echo esc($field); ?>"
            value="1"
            <?php echo $checkedAttr; ?>
            aria-label="<?php echo esc($info['title'] . ' ' . $name); ?>"
          >
        </td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <button type="submit">保存</button>
  <span class="small">チェック後、必ず保存してください。</span>
</form>

<h2>出勤回数</h2>
<div class="wrap">
<table class="summary-table">
  <thead>
    <tr>
      <th class="name-col">名前</th>
      <th>表示期間内の出勤回数</th>
      <th>前月20日から当月20日までの出勤回数</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($people as $pid => $name): ?>
    <tr>
      <th scope="row" class="name-cell"><?php echo esc($name); ?></th>
      <td><?php echo esc($counts[$pid]); ?></td>
      <td><?php echo esc($periodCounts[$pid]); ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<script type="text/javascript">
var shiftChanged = false;

document.addEventListener('change', function (e) {
  if (e.target && e.target.type === 'checkbox') {
    shiftChanged = true;
  }
});

document.addEventListener('submit', function (e) {
  if (e.target && e.target.id === 'attendance-form') {
    shiftChanged = false;
  }
});

window.addEventListener('beforeunload', function (e) {
  if (shiftChanged) {
    e.preventDefault();
    e.returnValue = '';
  }
});
</script>

</body>
</html>