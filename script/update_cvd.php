<?php

declare(strict_types=1);

/**
 * Download official ClamAV CVD files into storage/clamav_db.
 *
 *   php scripts/update_cvd.php
 *   php scripts/update_cvd.php --main
 */

require_once dirname(__DIR__) . '/config/bootstrap.php';

$includeMain = in_array('--main', $argv, true);

try {
    $saved = lex_cvd_download_official($includeMain);
    fwrite(STDOUT, 'Downloaded: ' . implode(', ', $saved) . PHP_EOL);
    fwrite(STDOUT, 'Saved in ' . lex_clamav_database_dir() . PHP_EOL);
    fwrite(STDOUT, "Do not open the .cvd files in Word/Notepad. Open README.txt or Admin → System Settings.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
