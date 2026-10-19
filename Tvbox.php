<?php
namespace app\api\controller;
use think\Cache;

class Tvbox extends Base
{
    private const FILTER_KEYS = ['st', 'year', 'area', 'h', 'isend', 'order_by'];
    private const SORT_OPTIONS = [
        'vod_douban_score' => '按豆瓣评分',
        'vod_hits' => '按点击数',
        'vod_pubdate' => '按上映时间',
        'vod_year' => '按年度',
        'vod_time' => '按更新时间',
    ];

    public $_param;

    public function __construct()
    {
        parent::__construct();
        // HTTP 参数已经解码，再次 urldecode 会破坏搜索词中的 + 和 %。
        $this->_param = $this->normalizeParams(input('', '', 'trim'));
    }

    private function normalizeParams(array $params): array
    {
        $filters = isset($params['f']) && is_string($params['f'])
            ? json_decode($params['f'], true) : null;
        if (is_array($filters)) {
            foreach (self::FILTER_KEYS as $key) {
                // 兼容影视的 JSON 筛选；TVBox 的显式平铺参数优先，包括空值。
                if (!array_key_exists($key, $params)
                    && isset($filters[$key]) && is_scalar($filters[$key])) {
                    $params[$key] = trim((string)$filters[$key]);
                }
            }
        }
        foreach (self::FILTER_KEYS as $key) {
            if (isset($params[$key]) && !is_scalar($params[$key])) {
                unset($params[$key]);
            }
        }
        return $params + ['ac' => '', 'at' => ''];
    }

    private function applyDefaultFilters(): void
    {
        if (!empty($this->_param['ids']) || $this->_param['ac'] !== 'detail'
            || empty($this->_param['t']) || !empty($this->_param['f'])) {
            return;
        }
        foreach (['year', 'area'] as $key) {
            $default = $GLOBALS['config']['extra']['tvbox_default_' . $key] ?? '';
            if (!array_key_exists($key, $this->_param) && $default !== '') {
                $this->_param[$key] = $default;
            }
        }
    }

    private function parseYears($value): array
    {
        if (!is_scalar($value)) {
            return [];
        }
        $value = trim((string)$value);
        if (preg_match('/^\d{4}$/D', $value)) {
            return [$value];
        }
        if (!preg_match('/^(\d{4})[-,](\d{4})$/D', $value, $match)) {
            return [];
        }
        $start = max(1900, min((int)$match[1], (int)$match[2]));
        $end = min((int)date('Y') + 3, max((int)$match[1], (int)$match[2]));
        return $start <= $end ? array_map('strval', range($start, $end)) : [];
    }

    private function getOrder(): string
    {
        $field = $this->_param['order_by'] ?? '';
        // 排序列不能作为普通查询值绑定，只允许接口提供的固定字段。
        return (is_string($field) && isset(self::SORT_OPTIONS[$field]) ? $field : 'vod_time') . ' desc';
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

        $this->applyDefaultFilters();
        $cache_time = intval($GLOBALS['config']['api']['vod']['cachetime']);
        $cach_name = $GLOBALS['config']['app']['cache_flag']. '_'.'api_tvbox_compat_v2_'.md5(http_build_query($this->_param));
        $html = Cache::get($cach_name);
        if(empty($html) || $cache_time==0) {
            $where = [];
            if (!empty($this->_param['ids'])) {
                $where['vod_id'] = ['in', $this->_param['ids']];
            }
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
            if (isset($this->_param['isend']) && in_array((string)$this->_param['isend'], ['0', '1'], true)) {
                $where['vod_isend'] = (int)$this->_param['isend'];
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
            $years = $this->parseYears($this->_param['year'] ?? '');
            if ($years) {
                $where['vod_year'] = ['in', $years];
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
                $where['_string'] = $GLOBALS['config']['api']['vod']['datafilter'];
            }
            if (empty($this->_param['pg'])) {
                $this->_param['pg'] = 1;
            }
            $pagesize = $GLOBALS['config']['api']['vod']['pagesize'];
            if (!empty($this->_param['pagesize']) && $this->_param['pagesize'] > 0) {
                $pagesize = min((int)$this->_param['pagesize'], 100);
            }

            $order = $this->getOrder();

            // A cover lets FongMi display results without its category-filtered detail fallback.
            $field = 'vod_id,vod_name,type_id,"" as type_name,vod_en,vod_time,vod_remarks,vod_play_from,vod_pic,vod_year';

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
        $groups = explode('$$$', $urls);
        $allowed = array_map('trim', explode(',', $from));
        foreach (explode('$$$', $froms) as $index => $source) {
            if (!isset($groups[$index]) || $source === ''
                || ($from !== '' && !in_array($source, $allowed, true))) {
                continue;
            }
            $res_xml .= '<dd flag="' . $this->xmlText($source) . '">' . $this->xmlCdata($groups[$index]) . '</dd>';
            $res_json[$source] = $groups[$index];
        }
        return $flag == 'xml' ? $res_xml : $res_json;
    }

    public function sortArray($a, $b){
        return $a['type_sort'] <=> $b['type_sort'];
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
        $detail = in_array($this->_param['ac'], ['detail', 'videolist'], true);
        // 详情只需按 ID 查找；输出分类时才排序，并保留分类键。
        if (!$detail) {
            uasort($type_list, [$this, 'sortArray']);
        }

        foreach($res['list'] as &$v){
            $v['type_name'] = $type_list[$v['type_id']]['type_name'] ?? '';
            $v['vod_time'] = date('Y-m-d H:i:s',$v['vod_time']);

            $v['vod_pic'] = $this->pictureUrl($v['vod_pic'] ?? '');

            if (!empty($GLOBALS['config']['api']['vod']['from'])) {
                $v = $this->filterPlaySources($v, $detail);
            }
            $v['vod_play_from'] = implode('$$$', $this->convertPlayerName(explode('$$$', $v['vod_play_from'])));
        }
        unset($v);

        if (!$detail) {
            $class = [];
            $typefilter  = explode(',',$GLOBALS['config']['api']['vod']['typefilter']);

            foreach ($type_list as $v) {
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

                $type_extend = $type_list[$type_id]['type_extend'] ?? [];
                //分类扩展配置：年代 -->全局年代
                $yearConfig = $type_extend['year'] ?? '';
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
                $areaConfig = $type_extend['area'] ?? '';
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
                $sortValues = [];
                foreach (self::SORT_OPTIONS as $field => $label) {
                    $sortValues[] = ['n' => $label, 'v' => $field];
                }
                $typefilter[] = ['key' => 'order_by', 'name' => '排序', 'value' => $sortValues];
                $filters[$type_id]=$typefilter;
            }
            $res['filters'] = $filters;
        }

        return $res;
    }

    private function filterPlaySources(array $vod, bool $detail): array
    {
        $sources = explode('$$$', $vod['vod_play_from']);
        $allowed = array_unique(array_filter(array_map('trim', explode(',', $GLOBALS['config']['api']['vod']['from'])), 'strlen'));
        $indexes = [];
        foreach ($allowed as $source) {
            $index = array_search($source, $sources, true);
            if ($index !== false) {
                $indexes[] = $index;
            }
        }
        $fields = $detail ? ['vod_play_from', 'vod_play_url', 'vod_play_server', 'vod_play_note'] : ['vod_play_from'];
        foreach ($fields as $field) {
            $values = explode('$$$', $vod[$field] ?? '');
            $selected = [];
            foreach ($indexes as $index) {
                $selected[] = $values[$index] ?? '';
            }
            $vod[$field] = implode('$$$', $selected);
        }
        return $vod;
    }

    public function convertPlayerName($players){
        $player_list = config('vodplayer');
        $result = [];
        foreach($players as $p){
            $name = $player_list[$p]['show'] ?? '';
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
        foreach($res['list'] as $v){
            $xml .= '<video>';
            $xml .= '<last>'.date('Y-m-d H:i:s',$v['vod_time']).'</last>';
            $xml .= '<id>'.$v['vod_id'].'</id>';
            $xml .= '<tid>'.$v['type_id'].'</tid>';
            $xml .= '<name>'.$this->xmlCdata($v['vod_name']).'</name>';
            $xml .= '<type>'.$this->xmlText($type_list[$v['type_id']]['type_name'] ?? '').'</type>';
            $v['vod_pic'] = $this->pictureUrl($v['vod_pic'] ?? '');

            if($this->_param['ac']=='videolist' || $this->_param['ac']=='detail'){
                $tempurl = $this->vod_url_deal($v["vod_play_url"],$v["vod_play_from"],$GLOBALS['config']['api']['vod']['from'],'xml');

                foreach (['pic'=>'vod_pic', 'lang'=>'vod_lang', 'area'=>'vod_area', 'year'=>'vod_year', 'state'=>'vod_serial'] as $tag=>$field) {
                    $xml .= '<'.$tag.'>'.$this->xmlText($v[$field] ?? '').'</'.$tag.'>';
                }
                foreach (['note'=>'vod_remarks', 'actor'=>'vod_actor', 'director'=>'vod_director'] as $tag=>$field) {
                    $xml .= '<'.$tag.'>'.$this->xmlCdata($v[$field] ?? '').'</'.$tag.'>';
                }
                $xml .= '<dl>'.$tempurl.'</dl>';
                $xml .= '<des>'.$this->xmlCdata($v['vod_content'] ?? '').'</des>';
            }
            else {
                if ($GLOBALS['config']['api']['vod']['from'] != ''){
                    $xml .= '<dt>' . $this->xmlText($GLOBALS['config']['api']['vod']['from']) . '</dt>';
                }
                else{
                    $xml .= '<dt>' . $this->xmlText(str_replace('$$$', ',', $v['vod_play_from'])) . '</dt>';
                }
                $xml .= '<note>' . $this->xmlCdata($v['vod_remarks']) . '</note>';
            }
            $xml .= '</video>';
        }
        $xml .= '</list>';

        //视频列表结束

        if($this->_param['ac'] != 'videolist' && $this->_param['ac']!='detail') {
            //分类列表开始
            $xml .= "<class>";
            $typefilter  = explode(',',$GLOBALS['config']['api']['vod']['typefilter']);
            foreach ($type_list as $v) {
                if($v['type_mid']==1) {
                    if (!empty($GLOBALS['config']['api']['vod']['typefilter'])){
                        if(in_array($v['type_id'],$typefilter)) {
                            $xml .= '<ty id="' . $v['type_id'] . '">' . $this->xmlText($v['type_name']) . '</ty>';
                        }
                    }
                    else {
                        $xml .= '<ty id="' . $v['type_id'] . '">' . $this->xmlText($v['type_name']) . '</ty>';
                    }
                }
            }
            $xml .= "</class>";
            //分类列表结束

        }
        $xml .= "</rss>";
        return $xml;
    }

    private function xmlText($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function xmlCdata($value): string
    {
        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', (string)$value) . ']]>';
    }

    private function pictureUrl($picture): string
    {
        if (strpos($picture, 'mac:') === 0) {
            return $this->getImgUrlProtocol('vod') . substr($picture, 4);
        }
        if ($picture !== '' && strpos($picture, 'http') !== 0 && strpos($picture, '//') !== 0) {
            return ($GLOBALS['config']['api']['vod']['imgurl'] ?? '') . $picture;
        }
        return $picture;
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
