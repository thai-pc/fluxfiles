<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

use FluxFiles\LicenseServer\Client\Mask;

final class Csv
{
    public static function encode(iterable $rows, ?int &$redactions=null): string
    {
        $redactions=0;
        $file=fopen('php://temp','w+');fwrite($file,"\xEF\xBB\xBF");
        foreach ($rows as $row) {
            $cells=[];
            foreach ($row as $value) {
                $cell=(string)($value??'');if (preg_match(Mask::KEY,$cell)) { $cell=preg_replace(Mask::KEY,'[redacted]',$cell);$redactions++; }
                if (preg_match('/^[=+\-@\t\r]/',$cell)) { $cell="'".$cell; }
                $cells[]=$cell;
            }
            fputcsv($file,$cells,',','"','');
        }
        rewind($file);$out=stream_get_contents($file);fclose($file);return (string)$out;
    }
}
