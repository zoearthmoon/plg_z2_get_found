<?php
defined('_JEXEC') or die ;
jimport('joomla.plugin.plugin');

class plgZ2Z2_Sp_Seach extends JPlugin
{
    function onZ2QueryDataItems($option=array())
    {
        if (!class_exists('Z2Cart'))
        {
            JError::raiseWarning(100,'ERROR z2_sp_seach 010' );
            return FALSE;
        }
        
        //$option     =& $option['option'];
        $addSelect  =& $option['addSelect'];
        $addJoin    =& $option['addJoin'];
        $addWhere   =& $option['addWhere'];
        $groupBy    =& $option['groupBy'];
        $noFeatured =& $option['noFeatured'];
        $order      =& $option['order'];
        $sort       =& $option['sort'];
        $limit      =& $option['limit'];
        
        //20150527 zoearth 針對搜尋處理
        $sW  = Z2HelperUtilities::getSearch('sW');//搜尋文字
        Z2HelperUtilities::setState('sW',$sW);
        
        //$cIs = Z2HelperUtilities::getSearch('cIs');//分類ID
        $sb = Z2HelperUtilities::getSearch('sb');
        $sb = in_array($sb,array('sort','cost','create')) ? $sb:'sort';//排序BY
        //20150528 zoearth 設定到值中
        Z2HelperUtilities::setState('sb',$sb);
        $sa  = (Z2HelperUtilities::getSearch('sa') == 'DESC' ? 'DESC':'ASC');//排序ASC
        //20150528 zoearth 設定到值中
        Z2HelperUtilities::setState('sa',$sa);
        
        //$li  = JRequest::getVar('li');//limit
        //$st  = JRequest::getVar('st');//page start
        
        $db      = Z2HelperQueryData::getDB();
        $nowLang = Z2HelperLang::getNowLang();
        /*
        $query = "SELECT ".($getTotal == TRUE ? 'SQL_CALC_FOUND_ROWS':'')." i.* $addSelect
            FROM #__z2_items AS i $addJoin
            WHERE 
            i.trash=0
            $addWhere
            ".($groupBy ? "GROUP BY i.id":"")."
            ORDER BY 
            ".($noFeatured ? '':"i.featured DESC,")."
            $order $sort ";
        */
        
        //價格排序
        if ($sb == 'cost')
        {
            $addJoin .= " LEFT JOIN #__z2_cart_product AS cp ON ( cp.itemId = i.id AND cp.language = ".$db->quote($nowLang)." ) ";
            $order    = 'cp.cost';
            $sort     = $sa;
        }
        //20150702 zoearth 預設排序
        else if ($sb == 'sort')
        {
            $order    = 'i.ordering';
            $sort     = $sa;
        }
        else if ($sb == 'create')
        {
            $order    = 'i.created';
            $sort     = $sa;
        }

        //文字搜尋(必須帶入標籤)
        $sW = JString::trim($sW);
        if ($sW != '')
        {
            require_once JPATH_ROOT.DS.'administrator'.DS.'components'.DS.'com_z2'.DS.'helpers'.DS.'searchEncode.php';
            $searchKey = Z2HelperSearchEncode::toSearchKey($sW);
            $addWhere .= " MATCH (lang.extra_fields_search) AGAINST ('".$searchKey."' IN BOOLEAN MODE) ";
        }
        
        //20150709 zoearth 修改過濾附加欄位功能
        $eIsArray = array(1=>'eIs',2=>'fIs',3=>'gIs');
        foreach ($eIsArray as $ekey=>$ename)
        {
            $eV = Z2HelperUtilities::getSearch($ename);//產品分類(附加欄位)
            Z2HelperUtilities::setState($ename,$eV);
            
            $ptExtId = $this->params->get('ptExtId_'.$ekey);
            if ($ptExtId > 0)
            {
                //20150709 zoearth
                if (preg_match_all('/([0-9]{1,})/',$eV,$match) && isset($match[1]) && count($match[1]) > 0 )
                {
                    $alias = 'spe'.$ptExtId;
                    $goWhereSql = Z2HelperExtendField::sayExtendSearchWhere($ptExtId,$alias,$match[1]);
                    if ($goWhereSql)
                    {
                        $addJoin  .= " LEFT JOIN #__z2_extra_fields_value AS $alias ON ( $alias.itemId = i.id AND $alias.fieldId = ".$ptExtId." AND $alias.language = ".$db->quote(Z2HelperLang::getNowLang())." ) ";
                        $addWhere .= ' AND '.$goWhereSql;
                    }
                }
            }
        }
        
        //20150727 zoearth 過濾購買規格
        $bps = Z2HelperUtilities::getSearch('bps');//產品分類(附加欄位)
        Z2HelperUtilities::setState('bps',$bps);
        if (preg_match_all('/([0-9]{1,})/',$bps,$match) && isset($match[1]) && count($match[1]) > 0 )
        {
            $addJoin  .= " LEFT JOIN #__z2_cart_product_option AS bps ON ( bps.itemId = i.id AND bps.language = ".$db->quote(Z2HelperLang::getNowLang())." ) ";
            $addWhere .= ' AND bps.optionId IN ('.implode(',',$match[1]).')';
        }
    }
    
    //附加欄位頁面
    function onZ2GetItemlist($option=array())
    {
        if (!class_exists('Z2Cart'))
        {
            JError::raiseWarning(100,'ERROR z2_sp_seach 094' );
            return FALSE;
        }
        
        //20150527 zoearth 取得產品分類
        $catIds = Z2Cart::getProCategorys();
        $catIds = array_merge($catIds,Z2HelperQueryData::getSubCategories($catIds));
        
        //20150528 zoearth 只在產品分類列表啟用
        if (!(JRequest::getVar('option') == 'com_z2' && JRequest::getVar('view') == 'itemlist' && in_array(JRequest::getVar('id'), $catIds) ))
        {
            return FALSE;
        }
        
        //20150527 zoearth 載入頁面用JS
        JHtml::script(Juri::root().'plugins/z2/z2_sp_seach/z2_sp_seach.js');
        
        $optionP =& $option['option'];
        $optionP['callPlg']  = TRUE;
        
        $doc = JFactory::getDocument();
        $doc->addScriptDeclaration('var productUrl = "'.Z2Cart::getProURL().'";');
        
        //修改分類
        $cIs = Z2HelperUtilities::getSearch('cIs');//分類ID
        
        //取得分類ID
        $cid = JRequest::getVar('id');
        if ($cid > 0 && JRequest::getVar('view') == 'itemlist' && !in_array($cid,Z2Cart::getProCategory()))
        {
            $nowCids = Z2HelperQueryData::getSubCategories($cid);
            $nowCids[] = $cid;
            $cIs = implode('|', $nowCids);
        }
        
        Z2HelperUtilities::setState('cIs',$cIs);
        
        //允許修改limit
        $useLi = $this->params->get('useLi');
        if ($useLi == '1')
        {
            $li  = Z2HelperUtilities::getSearch('li');//limit
            $li  = in_array($li,array(9,12,24,48)) ? $li:$this->params->get('defLi');;
            Z2HelperUtilities::setState('li',$li);
            //修改limit
            if ($li)
            {
                $optionP['limit'] = (int)$li;
            }
        }
        
        //$st  = JRequest::getVar('start');//page start
        if (JString::trim($cIs) != '')
        {
            if (preg_match_all('/([0-9]{1,})/',$cIs,$match) && isset($match[1]) && count($match[1]) > 0 && count($catIds) > 0 )
            {
                //print_r($match);
                //$sCatids   = Z2HelperQueryData::getSubCategories($match[1]);
                $newCatIds = array_intersect($catIds,$match[1]);
                if (count($newCatIds) > 0 )
                {
                    $optionP['category']   = 0;
                    $optionP['categories'] = $newCatIds;
                }
            }
        }
        
        //修改頁數
        if ($st > 0 )
        {
            //$option['start'] = (int)$st;
        }
    }
}