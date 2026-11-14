<?php
/**
 * HTML layout for every customer e-mail (rendered by LicenseMailer::renderHtml()).
 *
 * Table-based, inline CSS, no external images/fonts/tracking. The only <style> block is
 * the dark-mode media query, which clients that ignore it simply skip. Colours follow the
 * landing: accent #8957e5 (light) / #a98aec (dark); text is never pure white.
 *
 * Variables (set by the caller, all RAW strings, escaped here with $e()):
 *   $c       content model (see LicenseMailer::build())
 *   $e       callable string->string, htmlspecialchars ENT_QUOTES|ENT_HTML5
 *   $href    callable string->?string, returns the URL only when it is https://
 *
 * @var array<string,mixed> $c
 * @var callable $e
 * @var callable $href
 */

$font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
$mono = "SFMono-Regular,Menlo,Consolas,'Liberation Mono',monospace";

$p = fn (string $html): string =>
    '<p class="ff-text" style="margin:0 0 16px;font:15px/1.6 ' . $font . ';color:#2b2f36;">' . $html . '</p>';
$codeBox = fn (string $html): string =>
    '<div class="ff-code" style="margin:8px 0 0;padding:10px 12px;background:#f1eafb;border:1px solid #e0d3f6;border-radius:6px;font:13px/1.5 '
    . $mono . ';color:#3b2a66;word-break:break-all;white-space:pre-wrap;">' . $html . '</div>';

$ctaUrl = isset($c['button']) ? $href((string) $c['button'][1]) : null;
$wpUrl = isset($c['wp']) ? $href((string) $c['wp']['url']) : null;
$siteUrl = $href((string) $c['site']);
$baseUrl = $href((string) $c['base']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title><?= $e((string) $c['subject']) ?></title>
<style>
@media (prefers-color-scheme: dark) {
  .ff-bg { background-color:#0f1115 !important; }
  .ff-card { background-color:#1a1d23 !important; border-color:#2a2f38 !important; }
  .ff-text { color:#d5d9df !important; }
  .ff-head { color:#e6e8eb !important; }
  .ff-muted { color:#98a0ab !important; }
  .ff-code { background-color:#12151a !important; border-color:#2f3541 !important; color:#d8c9fa !important; }
  .ff-accent { color:#a98aec !important; }
  .ff-rule { border-color:#2a2f38 !important; }
}
@media only screen and (max-width:620px) { .ff-wrap { width:100% !important; } .ff-pad { padding:20px !important; } }
</style>
</head>
<body class="ff-bg" style="margin:0;padding:0;background-color:#f4f2f8;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f4f2f8;"><?= $e((string) $c['preheader']) ?></div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="ff-bg" style="background-color:#f4f2f8;">
<tr><td align="center" style="padding:24px 12px;">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" class="ff-wrap" style="width:600px;max-width:600px;">
    <tr><td style="padding:0 4px 16px;font:700 22px/1.2 <?= $font ?>;letter-spacing:-0.3px;"><span class="ff-accent" style="color:#8957e5;">Flux</span><span class="ff-muted" style="color:#6b7280;">Files</span></td></tr>
    <tr><td class="ff-card ff-pad" style="background-color:#ffffff;border:1px solid #e5e1ee;border-radius:10px;padding:32px;">
      <h1 class="ff-head" style="margin:0 0 20px;font:700 22px/1.3 <?= $font ?>;color:#1f2328;"><?= $e((string) $c['heading']) ?></h1>
<?php foreach ((array) ($c['intro'] ?? []) as $para): ?>
      <?= $p($e((string) $para)) . "\n" ?>
<?php endforeach; ?>
<?php if (isset($c['key'])): ?>
      <div class="ff-muted" style="margin:24px 0 6px;font:600 12px/1.4 <?= $font ?>;letter-spacing:1px;text-transform:uppercase;color:#6b7280;">Plan</div>
      <div class="ff-head" style="margin:0 0 16px;font:700 18px/1.3 <?= $font ?>;color:#1f2328;"><?= $e((string) $c['plan']) ?></div>
      <div class="ff-muted" style="margin:0 0 6px;font:600 12px/1.4 <?= $font ?>;letter-spacing:1px;text-transform:uppercase;color:#6b7280;">Your licence key</div>
      <div class="ff-code" style="margin:0 0 24px;padding:14px 16px;background:#f1eafb;border:1px solid #e0d3f6;border-radius:6px;font:14px/1.5 <?= $mono ?>;color:#3b2a66;word-break:break-all;overflow-wrap:anywhere;"><?= $e((string) $c['key']) ?></div>
<?php endif; ?>
<?php if (!empty($c['steps'])): ?>
      <h2 class="ff-head" style="margin:0 0 12px;font:700 16px/1.3 <?= $font ?>;color:#1f2328;">Next steps</h2>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<?php foreach ((array) $c['steps'] as $i => $s): ?>
        <tr>
          <td valign="top" width="32" style="padding:0 0 16px;"><div class="ff-accent" style="width:24px;height:24px;border-radius:12px;border:1px solid #8957e5;text-align:center;font:700 13px/24px <?= $font ?>;color:#8957e5;"><?= (int) $i + 1 ?></div></td>
          <td valign="top" style="padding:0 0 16px;">
            <?= $p($e((string) $s['text'])) ?>
<?php if (isset($s['code'])): ?>
            <?= $codeBox($e((string) $s['code'])) . "\n" ?>
<?php endif; ?>
          </td>
        </tr>
<?php endforeach; ?>
      </table>
<?php endif; ?>
<?php foreach ((array) ($c['notes'] ?? []) as $para): ?>
      <?= $p($e((string) $para)) . "\n" ?>
<?php endforeach; ?>
<?php if ($wpUrl !== null): ?>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 16px;"><tr><td class="ff-code" style="padding:14px 16px;background:#f1eafb;border:1px solid #e0d3f6;border-radius:6px;font:14px/1.6 <?= $font ?>;color:#3b2a66;">
        <strong>WordPress:</strong> get the FluxFiles Pro plugin here (paste the key above):<br>
        <a href="<?= $e($wpUrl) ?>" class="ff-accent" style="color:#6d3fc7;font-weight:600;"><?= $e($wpUrl) ?></a><br>
        <span style="font-size:13px;"><?= $e((string) $c['wp']['text']) ?></span>
      </td></tr></table>
<?php endif; ?>
<?php if ($ctaUrl !== null): ?>
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 8px;"><tr><td style="border-radius:6px;background-color:#8957e5;">
        <a href="<?= $e($ctaUrl) ?>" style="display:inline-block;padding:12px 22px;font:600 15px/1 <?= $font ?>;color:#f8f5ff;text-decoration:none;border-radius:6px;"><?= $e((string) $c['button'][0]) ?></a>
      </td></tr></table>
<?php endif; ?>
<?php foreach ((array) ($c['closing'] ?? []) as $para): ?>
      <?= $p($e((string) $para)) . "\n" ?>
<?php endforeach; ?>
    </td></tr>
    <tr><td class="ff-pad" style="padding:20px 8px;font:13px/1.6 <?= $font ?>;color:#6b7280;">
      <p class="ff-muted" style="margin:0 0 8px;color:#6b7280;">Questions? Reply to this email or write to <a href="mailto:<?= $e((string) $c['contact']) ?>" class="ff-accent" style="color:#6d3fc7;"><?= $e((string) $c['contact']) ?></a>. Replies reach a real person.</p>
      <p class="ff-muted" style="margin:0 0 8px;color:#6b7280;">You are receiving this email because <?= $e((string) $c['reason']) ?> with this address (<?= $e((string) $c['to']) ?>).</p>
      <p class="ff-muted" style="margin:0;color:#6b7280;">FluxFiles by thai-pc<?php if ($baseUrl !== null): ?> &middot; <a href="<?= $e($baseUrl) ?>" class="ff-accent" style="color:#6d3fc7;"><?= $e($baseUrl) ?></a><?php endif; ?></p>
    </td></tr>
  </table>
</td></tr>
</table>
</body>
</html>
