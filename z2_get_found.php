<?php
defined('_JEXEC') or die ;
jimport('joomla.plugin.plugin');

class plgSystemZ2_Get_Found extends JPlugin
{
    //20150612 zoearth 設定DB(新增需要的表單)
    var $nowDBKey = 1;
	
	var $keyDate = array(26,6,16);
	
    public function setDB()
    {
        static $installSql;
        $installSql = $this->params->get('installSql');
    
        if ($installSql == $this->nowDBKey)
        {
            return TRUE;
        }
        $plugin = JPluginHelper::getPlugin('system','z2_get_found');
        $setup = json_decode($plugin->params,TRUE);
        //$setup['installSql'];//是否已安裝SQL
    
        //執行安裝並且註記
        if ($setup['installSql'] == $this->nowDBKey)
        {
            $installSql = $this->nowDBKey;
            return TRUE;
        }
    
        $db = Z2HelperQueryData::getDB();
        $query = "
        CREATE TABLE IF NOT EXISTS `#__z2_found_data` (
          `foundKey` varchar(50) NOT NULL DEFAULT '' COMMENT '基金代碼',
          `cost` double(10,3) NOT NULL DEFAULT '0.000' COMMENT '淨值',
          `change` double(6,3) NOT NULL DEFAULT '0.000' COMMENT '漲跌',
          `dateKey` date NOT NULL DEFAULT '0000-00-00' COMMENT '日期',
          `idate` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
          PRIMARY KEY (`foundKey`,`dateKey`),
          KEY `cost` (`cost`),
          KEY `change` (`change`),
          KEY `idate` (`idate`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8 ROW_FORMAT=DYNAMIC COMMENT='基金記錄表';";
        $db->setQuery($query);
        $db->execute();

        //更新設定
        $installSql = $this->nowDBKey;
        $setup['installSql'] = $this->nowDBKey;
        $query = 'UPDATE #__extensions SET params = '.$db->quote(json_encode($setup)).'
                WHERE
                type = "plugin" AND
                element = "'.$plugin->name.'" AND
                folder  = "'.$plugin->type.'" ';
        $db->setQuery($query);
        $db->execute();
    }
    
    function onAfterDispatch()
    {
        date_default_timezone_set('Asia/Taipei');
        set_time_limit(500);
        
        //是否啟用
        if (!(JRequest::getInt('getZoeFoundGo') == 1))
        {
            return FALSE;
        }
        
        //取得設定並且檢查
        $getEmail = $this->params->get('getEmail','zoearthmoon@gmail.com');
        $foundCat = $this->params->get('foundCat');
        
        //檢查email
        if (!Z2HelperUtilities::isMail($getEmail))
        {
            $msg = 'ERROR 022 email 錯誤';
            $this->sendEmail($msg,TRUE);exit();
        }
        
        //檢查設定分類
        if (!(is_array($foundCat) && count($foundCat) > 0 ))
        {
            $msg = 'ERROR 028 分類錯誤';
            $this->sendEmail($msg,TRUE);exit();
        }
        
        //取得分類.依名稱排序
        $foundDatas = Z2HelperQueryData::getItems(array('category'=>$foundCat[0],'limit'=>999,'order'=>'title'));
        if (!(is_array($foundDatas) && count($foundDatas) > 0 ))
        {
            $msg = 'ERROR 037 分類沒有項目';
            $this->sendEmail($msg,TRUE);exit();
        }
        
        //檢查項目的附加欄位是否有與有填寫
        //foundKey:基金代碼(必須檢查不重複)
        //foundUrl:基金網址
        //foundBuy:基金目標買價(判斷標準)
        //foundMax:漲價提醒點
        //foundMin:跌價提醒點
        //pregRule:正規化規則(沒設定則使用預設)
        
        //迴圈
        $this->setDB();
        $db = Z2HelperQueryData::getDB();
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: text/html,application/xhtml+xml"));
        curl_setopt($ch, CURLOPT_ENCODING, "gzip");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; .NET CLR 1.0.3705; .NET CLR 1.1.4322)');
        
        //20160530 zoearth 新增[F] 頂置
        $newFDatas = array();
        foreach ($foundDatas as $item)
        {
            if (strpos($item['name'],'[F]'))
            {
                array_unshift($newFDatas,$item);
            }
            else
            {
                $newFDatas[] = $item;
            }
        }
        
        $eHtml = '';//信件內容
        foreach ($foundDatas as $item)
        {
            if (!(isset($item['extra_fields']['foundKey']) && $item['extra_fields']['foundKey'] != ''))
            {
                $msg = 'ERROR 101 foundKey 沒有設定';
                $this->sendEmail($msg,TRUE);exit();
            }
            if (!(isset($item['extra_fields']['foundUrl']) && Z2HelperUtilities::isUrl($item['extra_fields']['foundUrl'])))
            {
                $msg = 'ERROR 106 foundUrl 沒有設定';
                $this->sendEmail($msg,TRUE);exit();
            }
            $foundKey = $item['extra_fields']['foundKey'];
            $foundUrl = $item['extra_fields']['foundUrl'];
            $foundBuy = isset($item['extra_fields']['foundBuy']) ? (double)$item['extra_fields']['foundBuy']:0;
            
            $foundMax = isset($item['extra_fields']['foundMax']) ? (double)$item['extra_fields']['foundMax']:0;//預設10%
            $foundMin = isset($item['extra_fields']['foundMin']) ? (double)$item['extra_fields']['foundMin']:0;//預設10%
            
            $pregRule = isset($item['extra_fields']['pregRule']) ? $item['extra_fields']['pregRule']:1;
            $pregRule = in_array($pregRule,array(1,2,3)) ? $pregRule:1;
            
            //整理基金目前在資料庫的資料(比對用)
            $query = $db->getQuery(true);
            $query->select('*')
                ->from('#__z2_found_data')
                ->where('foundKey = '.$db->quote($foundKey))
                ->order('dateKey DESC');
            $db->setQuery($query,0,60);
            $rows = $db->loadObjectList();
            
            $nowFData = array();
            foreach ($rows as $row)
            {
                $nowFData[$row->dateKey]['cost']   = $row->cost;
                $nowFData[$row->dateKey]['change'] = $row->change;
            }

            //取得資料
            curl_setopt($ch, CURLOPT_URL, $foundUrl);
            $html       = curl_exec($ch);
            $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            //20150818 zoearth 錯誤處理
            $cc = 0;
            while (in_array($httpStatus,array(500,503)) && $cc <= 5 )
            {
                $cc++;
                sleep(3);
                $html       = curl_exec($ch);
                $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            }
            
            if (in_array($httpStatus,array(500,503)))
            {
                    $msg = 'ERROR 173 CURL錯誤:'.$foundUrl;
                    $this->sendEmail($msg,TRUE);exit();
            }
            
            $newFData = array();
            //解析資料
            if ($pregRule == '1')
            {
                $html = str_replace("\r","",$html);
                $html = str_replace("\n","",$html);
                $html = str_replace('> ','>',$html);
                $html = str_replace('< ','<',$html);
                
                if (!strpos($html,'<font color=#FFFFFF>淨值</font></td><td align=center nowrap=nowrap><font color=#FFFFFF>漲跌比例</font>'))
                {
                    $msg = 'ERROR 156 HTML有誤';
                    $this->sendEmail($msg,TRUE);exit();
                }
                
                $html = explode('<table class="fundpagetable">',$html);
                $html = $html[2];
                $html = explode('</table>',$html);
                $html = $html[0];
                
                //取得基金列表
                preg_match_all('/([0-9]{4}\/[0-9]{2}\/[0-9]{2})/',$html,$match);
                $years = $match[1];

                preg_match_all('/\>([0-9\.\-]{1,})\</',$html,$match);
                $nums  = $match[1];
                
                if ((count($years)*2) != count($nums) )
                {
                    $msg = 'ERROR 167 年份數值數量錯誤:'.count($years).' *2 != '.count($nums).' @ '.$foundUrl;
                    $this->sendEmail($msg,TRUE);exit();
                }
				
                foreach ($years as $key=>$year)
                {
                    $year = str_replace('/','-',$year);
                    $newFData[$year]['cost']   = $nums[($key*2)];//淨值
                    $newFData[$year]['change'] = $nums[($key*2)+1];//漲跌
                }
            }
            
            if (!(is_array($newFData) && count($newFData) > 0 ))
            {
                    $msg = 'ERROR 218 找不到資料newFData:'.$foundUrl;
                    $this->sendEmail($msg,TRUE);exit();
            }
            
			//$today = date("Y-m-d");
            //20150818 zoearth 最近一天有價格
            ksort($newFData);
            $today = key(array_slice($newFData,-1,1,TRUE));
            
            //比對資料(新增者必須取得漲跌)(舊的比對有修改再更新寫入)
            foreach ($newFData as $year=>$v)
            {
                $reNewAdd = FALSE;
                //比對
                if (isset($nowFData[$year]))
                {
                    if ($nowFData[$year]['cost'] != $newFData[$year]['cost'])
                    {
                        $nowFData[$year]['cost'] = $newFData[$year]['cost'];
                        $reNewAdd = TRUE;
                    }
                }
                else
                {
                    $nowFData[$year]['cost'] = $newFData[$year]['cost'];
                    $reNewAdd = TRUE;
                }
                
                //需要更新
                if ($reNewAdd)
                {
                    //刪除舊的
                    $query = $db->getQuery(true);
                    $query->delete('#__z2_found_data')
                        ->where('foundKey = '.$db->quote($foundKey))
                        ->where('dateKey = '.$db->quote($year));
                    $db->setQuery($query);
                    $db->execute();
                    
                    //寫入新的
                    $fdata = array(
                            'foundKey' => $foundKey,//基金代碼
                            'cost'     => $newFData[$year]['cost'],//淨值
                            'change'   => $newFData[$year]['change'],//漲跌
                            'dateKey'  => $year,//日期
                            'idate'    => date('Y-m-d H:i:s'),
                            );
                    $fdata = (object)$fdata;
                    $db->insertObject('#__z2_found_data',$fdata);
                }
            }
			
            //整理資料進入html
            //是否達到寄信條件(漲跌達到目標)
            $todayCost = $newFData[$today]['cost'];
            $todayChange = (($todayCost - $foundBuy)/$foundBuy)*100;
			
			/*
			* 20151017 zoearth 修改條件
			* 因為基金申購日期為6,16,26
			* 所以應當要在 28,8,18 日時列出全部富蘭克林基金
			*/
			$isKeyDay = FALSE;
			if (in_array((int)date('d'),$this->keyDate) && strpos(' '.$item['name'],'富蘭克林'))
			{
				$isKeyDay = TRUE;
			}
			
            if ($isKeyDay || $todayChange >= $foundMax || $todayChange <= (0 - $foundMin) )
            {
                //20160530 zoearth 新增[F] TAG
                $addStyle = '';
                if (strpos($item['name'],'[F]'))
                {
                    $addStyle = ' style="color:#F00;background:#FE0" ';
                }
                
                $eHtml .= '<tr>';
                $eHtml .= '<td rowspan="2" '.$addStyle.' >'.$foundKey.'</td>';
                $eHtml .= '<td '.$addStyle.' ><a href="'.$foundUrl.'" target="_blank">'.$item['name'].'</a></td>';
                $eHtml .= '<td '.$addStyle.' >'.$today.'</td>';
                $eHtml .= '<td '.$addStyle.' >'.$foundBuy.'</td>';
                $eHtml .= '<td '.$addStyle.' >'.$todayCost.'</td>';
                if ($todayChange > 0 )
                {
                    $eHtml .= '<td color="#CC0000" '.$addStyle.' ><h2>↑'.number_format($todayChange, 2,'.','').' % </h2></td>';
                }
                else
                {
                    $eHtml .= '<td color="#009933" '.$addStyle.' ><h2>↓'.number_format($todayChange, 2,'.','').' % </h2></td>';
                }
				$eHtml .= '<td '.$addStyle.' >漲'.$foundMax.'%/跌'.$foundMin.'%</td>';
                $eHtml .= '</tr>';
                $eHtml .= '<tr>';
                $eHtml .= '<td colspan="6" >';
                
                $mData = array();
                krsort($nowFData);
                foreach ($nowFData as $kDate=>$v)
                {
                    $mData[$kDate] = $v['cost'];
                }
                $mData = array_reverse($mData);
                $mData = array_slice($mData,-30,30);
                $imgOP = array(
                    'title' => urlencode($item['name'].':'.date('Y年m月d日')),
                    'mData' => $mData,
                    );
                //20150903 zoearth 新增圖片
                $eHtml .= getGoogleChart($imgOP);
                
                $eHtml .= '</td>';
                $eHtml .= '</tr>';
                
                //20150818 zoearth 新增圖表
                /*
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
                
                //最好禮拜5當天一定會寄送報表
                
            }
        }
        //迴圈結束
        curl_close($ch);
        
        //寄信
        if ($eHtml != '' )
        {
			$addHtml = '';
			if (in_array((int)date('d'),$this->keyDate))
			{
				$addHtml = '<h2>基金變更提醒日'.date('Y年m月d日').'</h2><br>';
			}
            $eHtml = $addHtml.'<table border="1" style="width:100%"><tr>
            <td>代碼</td>
			<td>基金</td><td>日期</td>
			<td>目標</td><td>目前</td><td>漲跌</td><td>目標漲跌</td></tr>'.$eHtml.'</table>';
            $this->sendEmail($eHtml);
			echo $eHtml;
        }
		echo 'DONE!(:D)';
		exit();
    }
    
    protected function sendEmail($html,$isError=FALSE)
    {
        $mailer   = JFactory::getMailer();
        $getEmail = $this->params->get('getEmail','zoearthmoon@gmail.com');
        $mailer->addRecipient($getEmail);//收件者
        
        if (!$isError)
        {
            $title = '[阿任基金通知]'.date('Y年m月d日');
        }
        else
        {
            $title = '[錯誤][阿任基金通知]'.date('Y年m月d日');
        }
        
        $mailer->setSubject($title);//標題
        $mailer->setBody($html);//內容
        
        $mailer->isHTML(true);
        $mailer->Encoding = 'base64';
        
        $send = $mailer->Send();
        if ( $send !== true )
        {
            echo 'Error sending email:' . $send->__toString();
        }
        else
        {
            echo '1';
        }
    }
}

function getGoogleChart($options)
{
    $inOption = array();
    $inOption['chxtX'] = isset($options['chxtX']) ? $options['chxtX']:750;
    $inOption['chxtY'] = isset($options['chxtY']) ? $options['chxtY']:350;
    $option['chs'] = $inOption['chxtX'].'x'.$inOption['chxtY'];
    
    //整理資料
    $max = 0;
    $min = 999;
    if (is_array($options['mData']) && count($options['mData']) > 0 )
    {
        foreach ($options['mData'] as $date=>$cost)
        {
            $max = max($max,$cost);
            $min = min($min,$cost);
            $options['chdGo'][]   = $cost;
            $inOption['chxlX'][] = substr($date,-2,2);
        }
    }
    //$max = (floor($max/10)*10)+10;
    $max = ceil($max);
    //$min = (floor($min/10)*10);
    $min = floor($min);
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
    
    $option['chm'] = 'N,000000,0,-1,13|s,000000,0,-1,5';
    
    $option['chg'] = (100/(count($inOption['chxlX'])-1)).',10,10,2,0,0';//
    $option['chts'] = '000000,12';//(上方標題位置與大小)
    $option['max'] = $max;

    $url = '';
    foreach ($option as $key=>$v)
    {
        $url .= '&'.$key.'='.$v;
    }
    
    return '<img src="https://chart.googleapis.com/chart?cht=ls'.$url.'">';
}