# shift-calendar(日本語版)
6人の出勤記録を管理するためのシンプルなWebシフトカレンダーアプリです。PHP、HTML、JavaScriptで作成されています。日本の祝日、出勤管理、月次出勤集計に対応しています。

---

# 日本語README概要

```markdown
# Shift Calendar

6人分の出勤記録を管理するためのシンプルなWebシフトカレンダーアプリです。

このアプリは、月ごとのシフト作成、出勤確認、出勤回数集計、日本の祝日や土日の色分けに対応しています。

## 機能

- 前月20日から入力月の末日までを表示
- 日本の土日と祝日を色分け
- 6人分の出勤チェック欄
- 人物を縦、日付を横にした表示
- 表示期間内の出勤回数集計
- 前月20日から当月20日までの出勤回数集計
- `shift_data.json`に保存

## 動作条件

- PHP 7.4以上、またはPHP 8.x
- データ保存用に書き込み可能なディレクトリ
- UTF-8ファイルエンコーディング

## ファイル構成

- `index.php` または `shift.php`: 本体アプリ
- `shift_data.json`: 出勤データ保存用
- `holiday_cache.json`: 祝日キャッシュ用

## 使い方

1. アプリのファイルをUTF-8で保存します。
2. PHPが動作するサーバー環境にファイルを配置します。
3. ローカル開発サーバーを起動します。

```bash
php -S 127.0.0.1:8000

# shift-calendar
A simple web-based shift calendar app for managing 6-person attendance records. Built with PHP, HTML, and JavaScript. It supports Japanese holidays, attendance tracking, and monthly attendance totals.

# Shift Calendar

A simple web-based shift calendar application for managing attendance records for 6 people.

This app is useful for creating monthly shift schedules, checking attendance, viewing attendance counts, and supporting Japanese public holidays and weekend coloring.

## Features

- Displays from the 20th of the previous month to the end of the selected month
- Color-coded weekends and Japanese public holidays
- Attendance checkboxes for 6 people
- Vertical people list with horizontal date columns
- Attendance totals for the displayed period
- Attendance totals from the 20th of the previous month to the 20th of the current month
- Saves data to `shift_data.json`

## Requirements

- PHP 7.4 or later, or PHP 8.x
- A writable directory for saving data
- UTF-8 file encoding

## Files

- `index.php` or `shift.php`: main application file
- `shift_data.json`: saved attendance data
- `holiday_cache.json`: cached Japanese holiday data

## Usage

1. Save the application files with UTF-8 encoding.
2. Place the application in a directory served by a PHP server.
3. Run a local PHP development server:

```bash
php -S 127.0.0.1:8000
