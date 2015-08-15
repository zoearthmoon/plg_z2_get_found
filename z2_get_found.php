<?php
defined('_JEXEC') or die ;
jimport('joomla.plugin.plugin');

class plgSystemZ2_Get_Found extends JPlugin
{
    //20150612 zoearth 設定DB(新增需要的表單)
    var $nowDBKey = 1;
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
        
        //取得分類
        $foundDatas = Z2HelperQueryData::getItems(array('category'=>$foundCat[0]));
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
            $foundMax = $foundMax != 0 ? $foundMax:10;
            $foundMin = $foundMin != 0 ? $foundMin:10;
            
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
            $html = curl_exec($ch);

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
            
			//$today = date("Y-m-d");
			$today = '2015-08-13';
			
            //是否有今天價格
            if (!isset($newFData[$today]))
            {
                continue;
            }
			
            //比對資料(新增者必須取得漲跌)(舊的比對有修改再更新寫入)
            foreach ($newFData as $year=>$v)
            {
                $reNewAdd = FALSE;
                //比對
                if (isset($nowFData[$year]))
                {
                    if ($nowFData[$year]['cost'] != $newFData[$year]['cost'] || $nowFData[$year]['change'] != $newFData[$year]['change'] )
                    {
                        $reNewAdd = TRUE;
                    }
                }
                else
                {
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
			
            if ($todayChange >= $foundMax || $todayChange <= (0 - $foundMin) )
            {
                $eHtml .= '<tr>';
                $eHtml .= '<td>'.$item['name'].'</td>';
                $eHtml .= '<td>'.$today.'</td>';
                $eHtml .= '<td>'.$foundBuy.'</td>';
                $eHtml .= '<td>'.$todayCost.'</td>';
                if ($todayChange > 0 )
                {
                    $eHtml .= '<td color="#CC0000" ><h2>↑'.$todayChange.' % </h2></td>';
                }
                else
                {
                    $eHtml .= '<td color="#009933" ><h2>↓'.$todayChange.' % </h2></td>';
                }
				$eHtml .= '<td>漲'.$foundMax.'%/跌'.$foundMin.'%</td>';
                $eHtml .= '</tr>';
            }
        }
        //迴圈結束
        curl_close($ch);
        
        //寄信
        if ($eHtml != '' )
        {
            $eHtml = '<table border="1" style="width:100%"><tr>
			<td>基金</td><td>日期</td>
			<td>目標</td><td>目前</td><td>漲跌</td><td>目標漲跌</td></tr>'.$eHtml.'</table>';
            $this->sendEmail($eHtml);
			echo $eHtml;
        }
		
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