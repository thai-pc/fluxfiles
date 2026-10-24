<?php
// Stand-in for an operator's self-hosted image model (the AI module's
// provider=http contract: multipart {op, image} in → image bytes out).
// bg_remove → transparent background, upscale → 2×, smart_crop → centre square.
$op = (string) ($_POST['op'] ?? '');
$src = @imagecreatefromstring((string) @file_get_contents($_FILES['image']['tmp_name'] ?? ''));
if ($src === false) { http_response_code(400); echo 'bad image'; return; }
$w = imagesx($src); $h = imagesy($src);
if ($op === 'upscale') {
    $out = imagecreatetruecolor($w * 2, $h * 2);
    imagecopyresampled($out, $src, 0, 0, 0, 0, $w * 2, $h * 2, $w, $h);
} elseif ($op === 'smart_crop') {
    $s = min($w, $h); $out = imagecreatetruecolor($s, $s);
    imagecopy($out, $src, 0, 0, intdiv($w - $s, 2), intdiv($h - $s, 2), $s, $s);
} else {
    $out = imagecreatetruecolor($w, $h); imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopy($out, $src, intdiv($w, 4), intdiv($h, 4), intdiv($w, 4), intdiv($h, 4), intdiv($w, 2), intdiv($h, 2));
}
header('Content-Type: image/png');
imagepng($out);
