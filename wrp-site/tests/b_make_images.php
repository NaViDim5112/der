<?php
// Тестовые картинки для загрузки аватара и обложки: php tests/b_make_images.php <папка>
$dir = rtrim($argv[1] ?? (__DIR__ . '/tmp'), '/');
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

function grad($w, $h, $c1, $c2, $alpha = false)
{
    $im = imagecreatetruecolor($w, $h);
    if ($alpha) {
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    }
    for ($x = 0; $x < $w; $x++) {
        $t = $x / max(1, $w - 1);
        $r = (int)($c1[0] + ($c2[0] - $c1[0]) * $t);
        $g = (int)($c1[1] + ($c2[1] - $c1[1]) * $t);
        $b = (int)($c1[2] + ($c2[2] - $c1[2]) * $t);
        imageline($im, $x, $alpha ? (int)($h * .15) : 0, $x, $alpha ? (int)($h * .85) : $h - 1, imagecolorallocate($im, $r, $g, $b));
    }
    $white = imagecolorallocate($im, 255, 255, 255);
    imagefilledellipse($im, (int)($w / 2), (int)($h / 2), (int)(min($w, $h) * .5), (int)(min($w, $h) * .5), $white);
    imagestring($im, 5, 10, 10, 'WRP TEST ' . $w . 'x' . $h, $white);
    return $im;
}

imagepng(grad(400, 300, [255, 90, 54], [120, 40, 200], true), $dir . '/avatar.png');
imagejpeg(grad(1200, 800, [20, 120, 220], [250, 200, 40]), $dir . '/photo.jpg', 90);
imagegif(grad(180, 180, [40, 200, 120], [10, 40, 90]), $dir . '/small.gif');
imagewebp(grad(600, 600, [230, 30, 120], [30, 30, 30]), $dir . '/pic.webp', 90);
imagejpeg(grad(2400, 700, [255, 154, 46], [255, 45, 120]), $dir . '/cover.jpg', 90);
imagejpeg(grad(300, 80, [100, 100, 100], [200, 200, 200]), $dir . '/tiny-cover.jpg', 90);
file_put_contents($dir . '/fake.png', "<?php echo 'not an image'; ?>\n");
// Больше 2 МБ (шум плохо сжимается)
$im = imagecreatetruecolor(1600, 1600);
for ($i = 0; $i < 400000; $i++) {
    imagesetpixel($im, mt_rand(0, 1599), mt_rand(0, 1599), mt_rand(0, 0xFFFFFF));
}
imagepng($im, $dir . '/big.png', 0);
foreach (glob($dir . '/*') as $f) {
    echo basename($f), ' ', filesize($f), "\n";
}
