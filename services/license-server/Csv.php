<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

use FluxFiles\LicenseServer\Client\Mask;

final class Csv
{
    public static function encode(array $rows): string
    {
        $file=fopen('php://temp','w+');fwrite($file,"\xEF\xBB\xBF");
        foreach ($rows as $row) {
            $cells=[];
            foreach ($row as $value) {
                $cell=(string)($value??'');Mask::safe($cell);
                if (preg_match('/^[=+\-@\t\r]/',$cell)) { $cell="'".$cell; }
                $cells[]=$cell;
            }
            fputcsv($file,$cells,',','"','');
        }
        rewind($file);$out=stream_get_contents($file);fclose($file);return (string)$out;
    }
}
