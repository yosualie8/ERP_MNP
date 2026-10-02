<?php

if (! function_exists('rp')) {
    /** Angka rupiah dengan titik ribuan, tanpa "Rp". Nol ditampilkan "-" bila $strip = true. */
    function rp(int|float|null $n, bool $strip = false): string
    {
        if ($n === null || ($strip && (int) $n === 0)) {
            return $strip ? '-' : '0';
        }

        return number_format((float) $n, 0, ',', '.');
    }
}
