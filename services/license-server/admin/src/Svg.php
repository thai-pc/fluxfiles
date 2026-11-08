<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Admin;

final class Svg
{
    private static ?I18n $i18n=null;
    public static function labels(I18n $i18n): void { self::$i18n=$i18n; }
    private static function t(string $key): string { self::$i18n??=new I18n();return self::$i18n->t($key); }
    private static int $serial=0;
    private static function h(string $value): string { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
    private static function n(float $value): string { return (string)round(is_finite($value)?$value:0,3); }

    private static function start(string $title,string $fallback,int $height=250,string $class=''): string
    {
        $id='chart-'.++self::$serial;
        return '<h3 class="chart-title">'.self::h($title).'</h3><svg class="chart '.$class.'" viewBox="0 0 760 '.$height.'" role="img" aria-labelledby="'.$id.'-title '.$id.'-desc"><title id="'.$id.'-title">'.self::h($title).'</title><desc id="'.$id.'-desc">'.self::h($fallback).'</desc>';
    }
    private static function table(array $rows,string $fallback): string
    {
        if ($rows===[]) { return '<p class="empty">'.self::h(self::t('empty_stats')).'</p>'; }
        $out='<details><summary>'.self::h($fallback).'</summary><div class="table-wrap"><table><thead><tr>';$cols=$rows?array_keys($rows[0]):[];
        foreach ($cols as $col) { $out.='<th>'.self::h(self::t((string)$col)).'</th>'; }$out.='</tr></thead><tbody>';
        foreach ($rows as $row) { $out.='<tr>';foreach ($cols as $col) { $value=$row[$col]??'';$out.='<td data-label="'.self::h(self::t((string)$col)).'">'.self::h(self::display($row,$col)).'</td>'; }$out.='</tr>'; }
        return $out.'</tbody></table></div></details>';
    }
    private static function display(array $row,string $key): string
    {
        $value=$row[$key]??'';
        if (is_numeric($value) && isset($row['currency']) && in_array($key,View::moneyFields(),true)) { return View::money((int)$value,$row['currency']); }
        return is_scalar($value)?(string)$value:'';
    }
    public static function bar(array $rows,string $label,string $value,string $title,string $fallback='Data table'): string { return self::render($rows,$label,$value,$title,$fallback,false,false); }
    public static function stackedBar(array $rows,string $label,string $value,string $title,string $fallback='Data table'): string { return self::render($rows,$label,$value,$title,$fallback,false,true); }
    public static function line(array $rows,string $label,string $value,string $title,string $fallback='Data table'): string { return self::render($rows,$label,$value,$title,$fallback,true,false); }
    public static function sparkline(array $rows,string $label,string $value,string $title,string $fallback='Data table'): string { return self::render($rows,$label,$value,$title,$fallback,true,false,'sparkline'); }

    private static function render(array $rows,string $label,string $value,string $title,string $fallback,bool $line,bool $stack,string $class=''): string
    {
        $groups=[];$series=[];
        foreach ($rows as $row) { $name=(string)($row[$label]??'');$plan=(string)($row['plan']??'');$number=(float)($row[$value]??0);$groups[$name][$plan]=($groups[$name][$plan]??0)+(is_finite($number)?$number:0);$series[$plan]=true; }
        $high=0.0;$low=0.0;
        foreach ($groups as $group) {
            $high=max($high,$stack?array_sum(array_filter($group,static fn($n):bool=>$n>0)):max(array_values($group)));
            $low=min($low,$stack?array_sum(array_filter($group,static fn($n):bool=>$n<0)):min(array_values($group)));
        }
        if ($high===$low) { $high=$low+1; }
        $y=static fn(float $number):float=>195-($number-$low)/($high-$low)*170;
        $zero=$y(0);$step=640/max(1,count($groups));$palette=array_flip(array_keys($series));$svg=self::start($title,$fallback,250,$class);
        $svg.='<path class="axis" d="M95 15V195 M95 '.self::n($zero).'H750"/>';
        foreach (array_unique([$low,($high+$low)/2,$high]) as $tick) {
            $row=[$value=>(int)$tick];if (isset($rows[0]['currency'])) { $row['currency']=$rows[0]['currency']; }
            $svg.='<text class="axis-label y-axis" text-anchor="end" x="88" y="'.self::n($y((float)$tick)+4).'">'.self::h(self::display($row,$value)).'</text>';
        }
        $i=0;$points=[];
        foreach ($groups as $name=>$group) {
            $x=100+$i*$step;$positive=0.0;$negative=0.0;
            if ($line) {
                $number=(float)array_sum($group);$pointY=$y($number);$points[]=self::n($x).','.self::n($pointY);
                $svg.='<circle class="s1" cx="'.self::n($x).'" cy="'.self::n($pointY).'" r="3"><title>'.self::h($name.': '.$number).'</title></circle>';
            } else {
                foreach ($group as $plan=>$number) {
                    $bottom=$stack?($number>=0?$positive:$negative):0.0;$top=$bottom+$number;
                    if ($number>=0) { $positive=$top; } else { $negative=$top; }
                    $rectY=min($y($bottom),$y($top));$height=abs($y($top)-$y($bottom));
                    $svg.='<rect class="s'.(($palette[$plan]??0)%6+1).($number<0?' negative':'').'" x="'.self::n($x).'" y="'.self::n($rectY).'" width="'.self::n(max(1,$step-8)).'" height="'.self::n($height).'"><title>'.self::h($name.' '.$plan.': '.$number).'</title></rect>';
                }
            }
            if ($i%max(1,(int)ceil(count($groups)/8))===0) { $svg.='<text class="axis-label" x="'.self::n($x).'" y="225">'.self::h((string)$name).'</text>'; }$i++;
        }
        if ($line) { $svg.='<polyline class="chart-line" points="'.implode(' ',$points).'"/>'; }
        $legend='';if ($stack) {
            $legend='<ul class="chart-legend">';foreach ($palette as $name=>$index) { $legend.='<li><svg viewBox="0 0 12 12" aria-hidden="true"><rect class="s'.($index%6+1).'" width="12" height="12"/></svg>'.self::h(self::t((string)$name)).'</li>'; }$legend.='</ul>';
        }
        return $svg.'</svg>'.$legend.self::table($rows,$fallback);
    }

    public static function horizontal(array $rows,string $label,string $value,string $title,string $fallback='Data table'): string
    {
        $height=max(80,35+count($rows)*26);$max=1;foreach ($rows as $row) { $max=max($max,(float)($row[$value]??0)); }
        $svg=self::start($title,$fallback,$height);
        foreach (array_values($rows) as $i=>$row) { $name=(string)($row[$label]??'');$n=max(0,(float)($row[$value]??0));$y=20+$i*26;
            $svg.='<text class="axis-label" x="5" y="'.($y+14).'">'.self::h($name).'</text><rect class="s'.($i%6+1).'" x="185" y="'.$y.'" width="'.self::n($n/$max*550).'" height="18"><title>'.self::h($name.': '.$n).'</title></rect>';
        }
        return $svg.'</svg>'.self::table($rows,$fallback);
    }

    public static function heatTable(array $rows,string $label,string $value,string $title,string $fallback='Data table'): string
    {
        $groups=[];foreach ($rows as $row) { $groups[(string)($row[$label]??'')][max(0,min(12,(int)($row['month']??0)))]=(float)($row[$value]??0); }
        $height=max(70,55+count($groups)*28);$svg=self::start($title,$fallback,$height);
        for($m=0;$m<=12;$m++) { $svg.='<text class="axis-label" x="'.(112+$m*48).'" y="20">'.$m.'</text>'; }
        $i=0;foreach ($groups as $name=>$months) {
            $y=32+$i*28;$svg.='<text class="axis-label" x="5" y="'.($y+16).'">'.self::h($name).'</text>';
            for($m=0;$m<=12;$m++) { $rate=$months[$m]??null;$level=$rate===null?0:min(5,max(0,(int)ceil($rate/20)));$x=100+$m*48;
                $svg.='<rect class="heat-'.$level.'" x="'.$x.'" y="'.$y.'" width="45" height="24"><title>'.self::h($name.' +'.$m.': '.($rate===null?'—':$rate.'%')).'</title></rect>';
                $svg.='<text class="heat-label heat-text-'.$level.'" x="'.($x+3).'" y="'.($y+16).'">'.self::h($rate===null?'—':self::n($rate).'%').'</text>';
            }$i++;
        }
        return $svg.'</svg>'.self::table($rows,$fallback);
    }
}
