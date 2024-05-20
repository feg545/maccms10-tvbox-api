<?php
namespace app\api\controller;
use think\Controller;
use think\Cache;

class Tvbox extends Base
{
    var $_param;

    public function __construct()
    {
        parent::__construct();
        $this->_param = input('','','trim,urldecode');
    }

    public function index()
    {
        if($GLOBALS['config']['api']['vod']['status'] != 1){
            echo 'closed';
            exit;
        }

        if($GLOBALS['config']['api']['vod']['charge'] == 1) {
            $h = $_SERVER['REMOTE_ADDR'];
            if (!$h) {
                echo lang('api/auth_err');
                exit;
            }
            else {
                $auth = $GLOBALS['config']['api']['vod']['auth'];
                $this->checkDomainAuth($auth);
            }
        }

        $cache_time = intval($GLOBALS['config']['api']['vod']['cachetime']);
        $cach_name = $GLOBALS['config']['app']['cache_flag']. '_'.'api_vod_'.md5(http_build_query($this->_param));
        $html = Cache::get($cach_name);
        if(empty($html) || $cache_time==0) {
            $where = [];
            if (!empty($this->_param['ids'])) {
                $where['vod_id'] = ['in', $this->_param['ids']];
            }else{
                //点击一级分类时，未进行筛选时，增加默认条件
                if(empty($this->_param['f']) and $this->_param['ac'] == 'detail' and !empty($this->_param['t'])){
                    $defYear = $GLOBALS['config']['extra']['tvbox_default_year'];
                    $defArea = $GLOBALS['config']['extra']['tvbox_default_area'];
                    if(!empty($defYear)){
                        $this->_param['year'] = $defYear;
                    }
                    if(!empty($defArea)){
                        $this->_param['area'] = $defArea;
                    }
                }
            }
            // if (!empty($GLOBALS['config']['api']['vod']['typefilter'])) {
            //     $where['type_id'] = ['in', $GLOBALS['config']['api']['vod']['typefilter']];
            // }
            
            // if (empty($GLOBALS['config']['api']['vod']['typefilter']) || strpos($GLOBALS['config']['api']['vod']['typefilter'], $this->_param['t']) !== false) {
                
            // }
            
            //有二级分类时，精确搜索
            if (!empty($this->_param['st'])) {
                $where['type_id'] = $this->_param['st'];
            }elseif (!empty($this->_param['t'])) {
                //频道id，一级分类
                $ids = $this->getChildIds($this->_param['t']);
                $where['type_id'] = ['in', $ids];
            }

            //地区
            if (!empty($this->_param['area'])) {
                $where['vod_area'] = ['like', '%' . $this->_param['area'] . '%'];
            }

            // 支持isend参数，是否完结
            if (isset($this->_param['isend'])) {
                $where['vod_isend'] = $this->_param['isend'] == 1 ? 1 : 0;
            }
            if (!empty($this->_param['h'])) {
                $todaydate = date('Y-m-d', strtotime('+1 days'));
                $tommdate = date('Y-m-d H:i:s', strtotime('-' . $this->_param['h'] . ' hours'));

                $todayunix = strtotime($todaydate);
                $tommunix = strtotime($tommdate);

                $where['vod_time'] = [['gt', $tommunix], ['lt', $todayunix]];
            }
            if (!empty($this->_param['wd'])) {
                $where['vod_name'] = ['like', '%' . $this->_param['wd'] . '%'];
            }
            // 增加年份筛选 https://github.com/magicblack/maccms10/issues/815
            if (!empty($this->_param['year'])) {
                $param_year = trim($this->_param['year']);
                if (strlen($param_year) == 4) {
                    $year = intval($param_year);
                } elseif (strlen($param_year) == 9) {
                    $start = (int)substr($param_year, 0, 4);
                    $end = (int)substr($param_year, 5, 4);
                    if ($start > $end) {
                        $tmp_num = $end;
                        $end = $start;
                        $start = $tmp_num;
                    }
                    $tmp_arr = [];
                    $start = max($start, 1900);
                    $end = min($end, date('Y') + 3);
                    for ($i = $start; $i <= $end; $i++) {
                        $tmp_arr[] = $i;
                    }
                    $year = join(',', $tmp_arr);
                }
                $where['vod_year'] = ['in', explode(',', $year)];
            }

            if (empty($GLOBALS['config']['api']['vod']['from']) && !empty($this->_param['from']) && strlen($this->_param['from']) > 2) {
                $GLOBALS['config']['api']['vod']['from'] = $this->_param['from'];
            }
            // 采集播放组支持多个播放器
            // https://github.com/magicblack/maccms10/issues/888
            if (!empty($GLOBALS['config']['api']['vod']['from'])) {
                $vod_play_from_list = explode(',', trim($GLOBALS['config']['api']['vod']['from']));
                $vod_play_from_list = array_unique($vod_play_from_list);
                $vod_play_from_list = array_filter($vod_play_from_list);
                if (!empty($vod_play_from_list)) {
                    $where['vod_play_from'] = ['or'];
                    foreach ($vod_play_from_list as $vod_play_from) {
                        array_unshift($where['vod_play_from'], ['like', '%' . trim($vod_play_from) . '%']);
                    }
                }
            }
            if (!empty($GLOBALS['config']['api']['vod']['datafilter'])) {
                $where['_string'] .= ' ' . $GLOBALS['config']['api']['vod']['datafilter'];
            }
            if (empty($this->_param['pg'])) {
                $this->_param['pg'] = 1;
            }
            $pagesize = $GLOBALS['config']['api']['vod']['pagesize'];
            if (!empty($this->_param['pagesize']) && $this->_param['pagesize'] > 0) {
                $pagesize = min((int)$this->_param['pagesize'], 100);
            }

            // $sort_direction = !empty($this->_param['sort_direction']) && $this->_param['sort_direction'] == 'asc' ? 'asc' : 'desc';
            $order = 'vod_time desc';
            if(!empty($this->_param['order_by'])){
                $order = $this->_param['order_by'] . ' desc';
            }

            $field = 'vod_id,vod_name,type_id,"" as type_name,vod_en,vod_time,vod_remarks,vod_play_from,vod_time';

            if ($this->_param['ac'] == 'videolist' || $this->_param['ac'] == 'detail') {
                $field = '*';
            }
            $res = model('vod')->listData($where, $order, $this->_param['pg'], $pagesize, 0, $field, 0);


            if ($this->_param['at'] == 'xml') {
                $html = $this->vod_xml($res);
            } else {
                $html = json_encode($this->vod_json($res),JSON_UNESCAPED_UNICODE);
            }
            $html = mac_filter_tags($html);
            if($cache_time>0) {
                Cache::set($cach_name, $html, $cache_time);
            }
        }
        // https://github.com/magicblack/maccms10/issues/818 影片的播放量+1
        if (
            isset($this->_param['ac']) && $this->_param['ac'] == 'detail' && 
            !empty($this->_param['ids']) && (int)$this->_param['ids'] == $this->_param['ids'] && 
            !empty($GLOBALS['config']['api']['vod']['detail_inc_hits'])
        ) {
            model('Vod')->fieldData(['vod_id' => (int)$this->_param['ids']], ['vod_hits' => ['inc', 1]]);
        }
        echo $html;
        exit;
    }

    public function vod_url_deal($urls,$froms,$from,$flag)
    {
        $res_xml = '';
        $res_json = [];
        $arr1 = explode("$$$",$urls); $arr1count = count($arr1);
        $arr2 = explode("$$$",$froms); $arr2count = count($arr2);
        for ($i=0;$i<$arr2count;$i++){
            if ($arr1count >= $i){
                if($from!=''){
                    if($arr2[$i]==$from || str_contains($from, $arr2[$i])){
                        $res_xml .=  '<dd flag="'. $arr2[$i] .'"><![CDATA[' . $arr1[$i]. ']]></dd>';
                        $res_json[$arr2[$i]] = $arr1[$i];
                    }
                }
                else{
                    $res_xml .=  '<dd flag="'. $arr2[$i] .'"><![CDATA[' . $arr1[$i]. ']]></dd>';
                    $res_json[$arr2[$i]] = $arr1[$i];
                }
            }
        }
        $res = str_replace(array(chr(10),chr(13)),array('','#'),$res_xml);
        return $flag=='xml' ? $res_xml : $res_json;
    }

    public function sortArray($a, $b){
        if($a['type_sort'] == $b['type_sort']){
            return 0;
        }
        return $a['type_sort'] > $b['type_sort'] ? 1 : -1;
    }

    private function getChildIds($pid){
        $type_list = model('Type')->getCache('type_list');
        $result = [];
        foreach($type_list as $v){
            if($v['type_id'] == $pid or $v['type_pid'] == $pid){
                $result[] = $v['type_id'];
            }
        }
        return $result;
    }

    public function vod_json($res)
    {
        $type_list = model('Type')->getCache('type_list');
        //排序
        usort($type_list, function ($a, $b){
            if($a['type_sort'] == $b['type_sort']){
                return 0;
            }
            return $a['type_sort'] > $b['type_sort'] ? 1 : -1;
        });

        foreach($res['list'] as $k=>&$v){
            $type_info = $type_list[$v['type_id']];
            $v['type_name'] = $type_info['type_name'];
            $v['vod_time'] = date('Y-m-d H:i:s',$v['vod_time']);

            if(substr($v["vod_pic"],0,4)=="mac:"){
                $v["vod_pic"] = str_replace('mac:',$this->getImgUrlProtocol('vod'), $v["vod_pic"]);
            }
            elseif(!empty($v["vod_pic"]) && substr($v["vod_pic"],0,4)!="http" && substr($v["vod_pic"],0,2)!="//"){
                $v["vod_pic"] = $GLOBALS['config']['api']['vod']['imgurl'] . $v["vod_pic"];
            }

            if ($this->_param['ac']=='videolist' || $this->_param['ac']=='detail') {
                // 如果指定返回播放组，则只返回对应播放组的播放数据
                // https://github.com/magicblack/maccms10/issues/957
                if (!empty($GLOBALS['config']['api']['vod']['from'])) {
                    // 准备数据，逐个处理
                    $arr_from = explode('$$$', $v['vod_play_from']);
                    $arr_url = explode('$$$', $v['vod_play_url']);
                    $arr_server = explode('$$$', $v['vod_play_server']);
                    $arr_note = explode('$$$', $v['vod_play_note']);
                    $vod_play_from_list = explode(',', trim($GLOBALS['config']['api']['vod']['from']));
                    $vod_play_from_list = array_unique($vod_play_from_list);
                    $vod_play_from_list = array_filter($vod_play_from_list);
                    $vod_play_url_list = [];
                    $vod_play_server_list = [];
                    $vod_play_note_list = [];
                    foreach ($vod_play_from_list as $vod_play_from_index => $vod_play_from) {
                        $key = array_search($vod_play_from, $arr_from);
                        if ($key === false) {
                            unset($vod_play_from_list[$vod_play_from_index]);
                            continue;
                        }
                        $vod_play_url_list[] = $arr_url[$key];
                        $vod_play_server_list[] = $arr_server[$key];
                        $vod_play_note_list[] = $arr_note[$key];
                    }
                    $vod_play_from_list = convertPlayerName($vod_play_from_list);
                    $res['list'][$k]['vod_play_from'] = join(',', $vod_play_from_list);
                    $res['list'][$k]['vod_play_url'] = join('$$$', $vod_play_url_list);
                    $res['list'][$k]['vod_play_server'] = join('$$$', $vod_play_server_list);
                    $res['list'][$k]['vod_play_note'] = join('$$$', $vod_play_note_list);
                }
            } else {
                if (!empty($GLOBALS['config']['api']['vod']['from'])) {
                    // 准备数据，逐个处理
                    $arr_from = explode('$$$', $v['vod_play_from']);
                    $vod_play_from_list = explode(',', trim($GLOBALS['config']['api']['vod']['from']));
                    $vod_play_from_list = array_unique($vod_play_from_list);
                    $vod_play_from_list = array_filter($vod_play_from_list);
                    foreach ($vod_play_from_list as $vod_play_from_index => $vod_play_from) {
                        $key = array_search($vod_play_from, $arr_from);
                        if ($key === false) {
                            unset($vod_play_from_list[$vod_play_from_index]);
                            continue;
                        }
                    }
                    $vod_play_from_list = convertPlayerName($vod_play_from_list);
                    $res['list'][$k]['vod_play_from'] = join(',', $vod_play_from_list);
                } else {
                   // $arr_from = explode('$$$', $v['vod_play_from']);
                    // $vod_play_from_list = convertPlayerName($arr_from);
                    //$res['list'][$k]['vod_play_from'] = '';//join(',', $vod_play_from_list);
                }
            }

            //显示播放器名称
            $arr_from = explode('$$$', $v['vod_play_from']);
            $vod_play_from_names = $this->convertPlayerName($arr_from);
            $res['list'][$k]['vod_play_from'] =join('$$$', $vod_play_from_names);
        }

        if($this->_param['ac']!='videolist' && $this->_param['ac']!='detail') {
            $class = [];
            $typefilter  = explode(',',$GLOBALS['config']['api']['vod']['typefilter']);

            foreach ($type_list as $k=>&$v) {
                if (!empty($GLOBALS['config']['api']['vod']['typefilter'])){
                    if(in_array($v['type_id'],$typefilter)) {
                        $class[] = ['type_id' => $v['type_id'], 'type_pid' => $v['type_pid'], 'type_name' => $v['type_name'],
                        'type_sort' => $v['type_sort']];
                    }
                }
                else {
                    //取顶级分类
                    if($v['type_pid'] == 0 and $v['type_status'] == 1){
                    $class[] = ['type_id' => $v['type_id'], 'type_pid' => $v['type_pid'], 'type_name' => $v['type_name'],
                        'type_sort' => $v['type_sort']];
                    }
                }
            }
            $res['class'] = $class;
        }
        
        //export filters for TVBox
        if(empty($this->_param['ac'])){
            $filters = [];
            foreach ($class as $cls) {
                $typefilter = [];
                $type_id = $cls['type_id'];
                $subtypes = [];
                //二级分类
                foreach($type_list as $t){
                    if($t['type_pid'] == $type_id){
                        $subtypes[] = ['n'=>$t['type_name'], 'v'=>$t['type_id']];
                    }
                }
                if(!empty($subtypes)){
                    $typefilter[] = ['key'=>'st', 'name'=>'分类', 'value'=>$subtypes];
                }

                $type_extend = $type_list[$type_id]['type_extend'];
                //分类扩展配置：年代 -->全局年代
                $yearConfig = $type_extend['year'];
                if(empty($yearConfig)){
                    $yearConfig = $GLOBALS['config']['app']['vod_extend_year'];
                }
                if(!empty($yearConfig)){
                    $yearArray = explode(',', $yearConfig);
                    $yearValue = [];
                    foreach ($yearArray as $i){
                        $yearValue[] = ['n'=>$i, 'v'=>$i];
                    }
                    $typefilter[] = ['key'=>'year', 'name'=>'年份', 'value'=>$yearValue];
                }
               
                //分类扩展配置：地区 -->全局地区
                $areaConfig = $type_extend['area'];
                if(empty($areaConfig)){
                    $areaConfig = $GLOBALS['config']['app']['vod_extend_area'];
                }
                if(!empty($areaConfig)){
                    $areaArray = explode(',', $areaConfig);
                    $areaValue = [];
                    foreach ($areaArray as $i){
                        $areaValue[] = ['n'=>$i, 'v'=>$i];
                    }
                    $typefilter[] = ['key'=>'area', 'name'=>'地区', 'value'=>$areaValue];
                }

                $typefilter[] = ['key'=>'h','name'=>'更新', 'value'=> [['n'=>'今日', 'v'=>'24'],['n'=>'三天内', 'v'=>'72'],['n'=>'一周内', 'v'=>'168'],['n'=>'半月内', 'v'=>'360'],['n'=>'一月内', 'v'=>'720']]];
                $typefilter[] = ['key'=>'isend','name'=>'完结', 'value'=> [['n'=>'已完结', 'v'=>'1'],['n'=>'未完结', 'v'=>'0']]];
                $typefilter[] = ['key'=>'order_by','name'=>'排序', 'value'=> [['n'=>'按豆瓣评分', 'v'=>'vod_douban_score']
                    ,['n'=>'按点击数', 'v'=>'vod_hits'],['n'=>'按上映时间', 'v'=>'vod_pubdate'],['n'=>'按年度', 'v'=>'vod_year'],['n'=>'按更新时间', 'v'=>'vod_time']]];
                $filters[$type_id]=$typefilter;
            }
            $res['filters'] = $filters;
        }

        return $res;
    }

    public function convertPlayerName($players){
        $player_list = config('vodplayer');
        $result = [];
        foreach($players as $p){
            $name = $player_list[$p]['show'];
            if(empty($name)){
                $result[] = $p;
            }else{
                $result[] = $name;
            }
        }
        return $result;
    }

    public function vod_xml($res)
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>';
        $xml .= '<rss version="5.1">';
        $type_list = model('Type')->getCache('type_list');

        //视频列表开始
        $xml .= '<list page="'.$res['page'].'" pagecount="'.$res['pagecount'].'" pagesize="'.$res['limit'].'" recordcount="'.$res['total'].'">';
        foreach($res['list'] as $k=>&$v){
            $type_info = $type_list[$v['type_id']];
            $xml .= '<video>';
            $xml .= '<last>'.date('Y-m-d H:i:s',$v['vod_time']).'</last>';
            $xml .= '<id>'.$v['vod_id'].'</id>';
            $xml .= '<tid>'.$v['type_id'].'</tid>';
            $xml .= '<name><![CDATA['.$v['vod_name'].']]></name>';
            $xml .= '<type>'.$type_info['type_name'].'</type>';
            if(substr($v["vod_pic"],0,4)=="mac:"){
                $v["vod_pic"] = str_replace('mac:',$this->getImgUrlProtocol('vod'), $v["vod_pic"]);
            }
            elseif(!empty($v["vod_pic"]) && substr($v["vod_pic"],0,4)!="http"  && substr($v["vod_pic"],0,2)!="//"){
                $v["vod_pic"] = $GLOBALS['config']['api']['vod']['imgurl'] . $v["vod_pic"];
            }

            if($this->_param['ac']=='videolist' || $this->_param['ac']=='detail'){
                $tempurl = $this->vod_url_deal($v["vod_play_url"],$v["vod_play_from"],$GLOBALS['config']['api']['vod']['from'],'xml');

                $xml .= '<pic>'.$v["vod_pic"].'</pic>';
                $xml .= '<lang>'.$v['vod_lang'].'</lang>';
                $xml .= '<area>'.$v['vod_area'].'</area>';
                $xml .= '<year>'.$v['vod_year'].'</year>';
                $xml .= '<state>'.$v['vod_serial'].'</state>';
                $xml .= '<note><![CDATA['.$v['vod_remarks'].']]></note>';
                $xml .= '<actor><![CDATA['.$v['vod_actor'].']]></actor>';
                $xml .= '<director><![CDATA['.$v['vod_director'].']]></director>';
                $xml .= '<dl>'.$tempurl.'</dl>';
                $xml .= '<des><![CDATA['.$v['vod_content'].']]></des>';
            }
            else {
                if ($GLOBALS['config']['api']['vod']['from'] != ''){
                    $xml .= '<dt>' . $GLOBALS['config']['api']['vod']['from'] . '</dt>';
                }
                else{
                    $xml .= '<dt>' . str_replace('$$$', ',', $v['vod_play_from']) . '</dt>';
                }
                $xml .= '<note><![CDATA[' . $v['vod_remarks'] . ']]></note>';
            }
            $xml .= '</video>';
        }
        $xml .= '</list>';

        //视频列表结束

        if($this->_param['ac'] != 'videolist' && $this->_param['ac']!='detail') {
            //分类列表开始
            $xml .= "<class>";
            $typefilter  = explode(',',$GLOBALS['config']['api']['vod']['typefilter']);
            foreach ($type_list as $k=>&$v) {
                if($v['type_mid']==1) {
                    if (!empty($GLOBALS['config']['api']['vod']['typefilter'])){
                        if(in_array($v['type_id'],$typefilter)) {
                            $xml .= "<ty id=\"" . $v["type_id"] . "\">" . $v["type_name"] . "</ty>";
                        }
                    }
                    else {
                        $xml .= "<ty id=\"" . $v["type_id"] . "\">" . $v["type_name"] . "</ty>";
                    }
                }
            }
            unset($rs);
            $xml .= "</class>";
            //分类列表结束

        }
        $xml .= "</rss>";
        return $xml;
    }

    private function checkDomainAuth($auth)
    {
        $ip = mac_get_client_ip();
        $auth_list = ['127.0.0.1'];
        if (!empty($auth)) {
            foreach (explode('#', $auth) as $domain) {
                $domain = trim($domain);
                $auth_list[] = $domain;
                if (!mac_string_is_ip($domain)) {
                    $auth_list[] = gethostbyname($domain);
                }
            }
            $auth_list = array_unique($auth_list);
            $auth_list = array_filter($auth_list);
        }
        if (!in_array($ip, $auth_list)) {
            echo lang('api/auth_err');
            exit;
        }
    }

    private function getImgUrlProtocol($key)
    {
        $default = (isset($GLOBALS['config']['upload']['protocol']) ? $GLOBALS['config']['upload']['protocol'] : 'http') . ':';
        if (!isset($GLOBALS['config']['api'][$key]['imgurl'])) {
            return $default;
        }
        if (substr($GLOBALS['config']['api'][$key]['imgurl'], 0, 5) == 'https') {
            return 'https:';
        }
        return $default;
    }
}
