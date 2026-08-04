<?php

function wp_png_chunk(string $type, string $data): string
{
    $payload = $type . $data;
    return pack('N', strlen($data)) . $payload . pack('N', crc32($payload));
}

function wp_qr_matrix_to_png(array $matrix, int $scale = 2, int $margin = 4): string
{
    $modules = count($matrix);
    if ($modules === 0) {
        throw new RuntimeException('Empty QR matrix');
    }

    $size = ($modules + ($margin * 2)) * $scale;
    $raw = '';
    for ($y = 0; $y < $size; $y++) {
        $moduleY = intdiv($y, $scale) - $margin;
        $row = "\x00";
        for ($x = 0; $x < $size; $x++) {
            $moduleX = intdiv($x, $scale) - $margin;
            $dark = $moduleY >= 0
                && $moduleY < $modules
                && $moduleX >= 0
                && $moduleX < $modules
                && ($matrix[$moduleY][$moduleX] ?? '0') === '1';
            $row .= $dark ? "\x00" : "\xFF";
        }
        $raw .= $row;
    }

    $signature = "\x89PNG\r\n\x1a\n";
    $header = pack('NNC5', $size, $size, 8, 0, 0, 0, 0);
    $compressed = function_exists('gzcompress')
        ? gzcompress($raw, 9)
        : wp_zlib_store($raw);

    return $signature
        . wp_png_chunk('IHDR', $header)
        . wp_png_chunk('IDAT', $compressed)
        . wp_png_chunk('IEND', '');
}

function wp_zlib_store(string $data): string
{
    $output = "\x78\x01";
    $length = strlen($data);
    for ($offset = 0; $offset < $length; $offset += 65535) {
        $block = substr($data, $offset, 65535);
        $blockLength = strlen($block);
        $final = ($offset + $blockLength) >= $length ? "\x01" : "\x00";
        $output .= $final
            . pack('v', $blockLength)
            . pack('v', 0xFFFF - $blockLength)
            . $block;
    }
    return $output . pack('N', wp_adler32($data));
}

function wp_adler32(string $data): int
{
    $first = 1;
    $second = 0;
    $length = strlen($data);
    for ($index = 0; $index < $length; $index++) {
        $first = ($first + ord($data[$index])) % 65521;
        $second = ($second + $first) % 65521;
    }
    return ($second << 16) | $first;
}
