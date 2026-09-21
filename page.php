<?php
/**
 * The Slate — shared chrome for the account pages.
 *
 * The brand fonts are packed inside the app bundle and are not available as
 * standalone files, so these pages use system fonts with the same colours,
 * weights and uppercase treatment as the app itself.
 */

declare(strict_types=1);

function slate_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function slate_page_head(string $title): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'");
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= slate_h($title) ?> — The Slate</title>
<style>
  :root {
    --black: #1D242B; --accent: #56C4E5; --white: #FFFFFF;
    --gray-5: #F4F5F6; --gray-15: #DDE0E3; --gray-30: #9BA3AB; --gray-40: #7B848D;
    --gray-50: #5C666F; --red: #D2492A;
    --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    background: var(--gray-5); color: var(--black); min-height: 100vh;
    display: flex; align-items: center; justify-content: center; padding: 32px 20px;
    font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
  }
  .card {
    background: var(--white); border: 1px solid var(--gray-15);
    width: 100%; max-width: 420px; padding: 36px 32px;
  }
  .card.wide { max-width: 860px; }
  .eyebrow {
    font-family: var(--mono); font-size: 11px; letter-spacing: 0.14em;
    text-transform: uppercase; color: var(--gray-30); margin-bottom: 8px;
  }
  h1 {
    font-size: 30px; font-weight: 900; line-height: 0.95; letter-spacing: -0.02em;
    text-transform: uppercase; margin-bottom: 22px;
  }
  h2 {
    font-size: 17px; font-weight: 900; letter-spacing: -0.01em;
    text-transform: uppercase; margin: 30px 0 12px;
  }
  label { display: block; margin-bottom: 16px; }
  label > span {
    display: block; font-family: var(--mono); font-size: 10px; letter-spacing: 0.09em;
    text-transform: uppercase; color: var(--gray-30); margin-bottom: 5px;
  }
  input[type=text], input[type=password], input[type=number] {
    width: 100%; font-size: 15px; font-weight: 600; color: var(--black);
    border: 1px solid var(--gray-15); background: var(--white); padding: 9px 10px;
  }
  input:focus { outline: none; border-color: var(--black); }
  button, .btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    height: 40px; padding: 0 18px; border: none; background: var(--accent); color: var(--white);
    font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
    cursor: pointer; text-decoration: none;
  }
  button:hover { opacity: 0.88; }
  button.ghost, .btn.ghost {
    background: transparent; color: var(--gray-50); border: 1px solid var(--gray-15);
  }
  button.danger { background: var(--red); }
  button.small, .btn.small { height: 30px; padding: 0 10px; font-size: 11px; }
  .row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
  .note { font-size: 13px; color: var(--gray-40); margin-top: 18px; }
  .note a { color: var(--black); font-weight: 700; }
  .msg { border: 1px solid var(--gray-15); padding: 12px 14px; font-size: 14px; margin-bottom: 20px; }
  .msg.bad { border-color: var(--red); color: var(--red); }
  .msg.good { border-color: var(--accent); }
  .hint { font-size: 12px; color: var(--gray-30); margin-top: -10px; margin-bottom: 16px; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { text-align: left; padding: 9px 10px; border-bottom: 1px solid var(--gray-15); }
  th {
    font-family: var(--mono); font-size: 10px; letter-spacing: 0.09em;
    text-transform: uppercase; color: var(--gray-30); font-weight: 500;
  }
  code {
    font-family: var(--mono); font-size: 13px; background: var(--gray-5);
    padding: 2px 6px; border: 1px solid var(--gray-15); word-break: break-all;
  }
  .topbar {
    display: flex; justify-content: space-between; align-items: baseline;
    gap: 16px; flex-wrap: wrap; margin-bottom: 4px;
  }
</style>
</head>
<body>
    <?php
}

function slate_page_foot(): void
{
    echo "</body>\n</html>\n";
}
