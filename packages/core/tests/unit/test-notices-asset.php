<?php
declare(strict_types=1);

/**
 * The About dialog links assets/THIRD-PARTY-NOTICES.txt (plain text, so browsers show
 * it instead of downloading a .md). It must stay byte-identical to the
 * THIRD-PARTY-NOTICES.md next to it. Fix: cp THIRD-PARTY-NOTICES.md assets/THIRD-PARTY-NOTICES.txt
 * (the WordPress build writes its own CodeMirror-free variant, see build-wordpress.sh).
 */

$core = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32m✓\033[0m {$name}\n"; }
    else { $fail++; echo "  \033[31m✗\033[0m {$name} {$detail}\n"; }
}

echo "Notices asset matches THIRD-PARTY-NOTICES.md\n";
$md = (string) @file_get_contents($core . '/THIRD-PARTY-NOTICES.md');
$txt = @file_get_contents($core . '/assets/THIRD-PARTY-NOTICES.txt');
check('source exists', $md !== '');
check('asset exists', $txt !== false);
check('asset is identical', $txt === $md, '(run: cp packages/core/THIRD-PARTY-NOTICES.md packages/core/assets/THIRD-PARTY-NOTICES.txt)');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
