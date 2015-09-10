<?php
function getGoogleChart($options)
{
    $inOption = array();
    $inOption['chxtX'] = isset($options['chxtX']) ? $options['chxtX']:750;
    $inOption['chxtY'] = isset($options['chxtY']) ? $options['chxtY']:350;
    $option['chs'] = $inOption['chxtX'].'x'.$inOption['chxtY'];
    
    //整理資料
    $max = 0;
    $min = 0;
    if (is_array($options['mData']) && count($options['mData']) > 0 )
    {
        foreach ($options['mData'] as $date=>$cost)
        {
            $max = max($max,$cost);
            $min = min($min,$cost);
            $options['chdGo'][]   = $cost;
            $inOption['chxlX'][] = $date;
        }
    }
    $max = (floor($max/10)*10)+10;
    $min = (floor($min/10)*10);
    $preY = (($max - $min)/10);
    for ($sy=$min;$sy<=$max;$sy+=$preY)
    {
        $inOption['chxlY'][] = $sy;
    }
    
    //$inOption['chxlX'] = (isset($options['chxlX']) && is_array($options['chxlX'])) ? $options['chxlX']:FALSE;
    //$inOption['chxlY'] = (isset($options['chxlY']) && is_array($options['chxlY'])) ? $options['chxlY']:FALSE;
    $option['chxl'] = '';
    $option['chxl'] .= '0:|'.implode('|',$inOption['chxlX']).'|';//橫線單位
    $option['chxl'] .= '1:|'.implode('|',$inOption['chxlY']).'';//直線單位
    
    //https://chart.googleapis.com/chart?cht=lxy&chs=300x325&chd=t:10,20,40,80,90,95,99|20,30,40,50,60,70,80|-1|5,10,22,35,85&chco2=3072F3,ff0000,00aaaa&chls=2,4,1&chm=s,000000,0,-1,5|s,000000,1,-1,5&chdl=Ponies|Unicorns&chdlp=t&chem=y;s=ec;d=br,cht,p,chd,t:10@,20@,30@,IJKNUWUWYdnswz047977315533zy1246872tnkgcaZQONHCECAAAAEII,chls,3@,6@,3@|5,chs,150x90,chdl,Shire@|Welsh@|Clydesdale,chf,bg@,s@
    $option['chtt'] = isset($options['title']) ? $options['title']:'test';//(上方標題)
    $option['chd']  = 't:'.implode(',',$options['chdGo']);//(線樣式)
    $option['chds'] = $min.','.$max;//(線樣式)
    $option['chdl'] = urlencode('淨值');//(名稱)
    $option['chco'] = '5131C9';//(線顏色)
    $option['chxt'] = 'x,y';//(線顏色)
    $option['chxs'] = '0,000000,12,-1|1,000000,12,-1';//(橫線直線的位置)
    $option['chf'] = 'bg,s,FFFFFF|c,s,FFFFFF';//(背景顏色.外面與裡面)
    
    $option['chm'] = 'N,000000,0,-1,11|s,000000,0,-1,5|s,000000,1,-1,5';
    
    $option['chg'] = (100/(count($inOption['chxlX'])-1)).',10,10,2,0,0';//
    $option['chts'] = '000000,12';//(上方標題位置與大小)
    $option['max'] = $max;

    $url = '';
    foreach ($option as $key=>$v)
    {
        $url .= '&'.$key.'='.$v;
    }
    
    echo '<img src="https://chart.googleapis.com/chart?cht=ls'.$url.'">';
    
}

$options = array();
$options['title'] = 'XX基金';

$options['mData'] = array();
$cc = rand(11,20);
for ($i=1;$i<=$cc;$i++)
{
    $options['mData']['9/'.$i] = rand(1,30);
}

getGoogleChart($options);


/*
                https://chart.googleapis.com/chart
                http://chart.apis.google.com/chart?
                cht=ls&
                chs=160x160&
                chd=s:GMSYfA(線樣式)&
                chdl=RR(名稱)&
                chco=5131C9(線顏色)&
                chxt=x,y&
                chxl=0:|2015-08-15|2015-08-16|2015-08-17|1:|-10|-5|0|5|10&(橫線與直線的單位標示)
                chxs=0,000000,8,-1|1,000000,8,-1&(橫線直線的位置)
                chf=bg,s,FFFFFF|c,s,FFFFFF&(背景顏色.外面與裡面)
                chg=10,10,5,2,0,0&
                chtt=100&(上方標題)
                chts=000000,12&(上方標題位置與大小)
                max=10&(可能不用?)
                agent=hohli.com(可能不用?)
*/

?>