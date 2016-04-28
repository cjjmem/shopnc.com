<?php
/**
 * 会员模型 v3-b12
 *
 *
 *
 *
 * by 33hao 好商城V3  www.haoid.cn 开发
 */
defined('InShopNC') or exit('Access Invalid!');


class relationModel extends Model {
    public function __construct()
    {
        parent::__construct('member_relation');
    }

    /**
     * 代理自动升级
     * @param int $uniacid
     */
    public static function agentUpgrade($uniacid=0){

        if($uniacid>0){
            $accounts[$uniacid] = uni_fetch($uniacid);
            $modules[$uniacid] = pdo_fetch("SELECT * FROM " . tablename('uni_account_modules') . " WHERE module='mf_distribution' and  uniacid=:uniacid",array(':uniacid'=>$uniacid));
        }
        else{
            $accounts = pdo_fetchall("SELECT * FROM " . tablename('uni_account') . " WHERE  `disable`=0  ORDER BY `uniacid` DESC", array(), 'uniacid');
            $modules = pdo_fetchall("SELECT * FROM " . tablename('uni_account_modules') . " WHERE module='mf_distribution'",array(),'uniacid');
        }

        foreach($accounts as $account){
            $setting =  @iunserializer($modules[$account['uniacid']]['settings']);

            if(empty($setting['globalagentupgrade1']) && empty($setting['globalagentupgrade2']) && empty($setting['globalagentupgrade3']))
                continue;
            switch($setting['globalagentupgradecondition']){
                case 1:
                    self::sales($account['uniacid'],$setting);
                    break;
                default:
                    self::people($account['uniacid'],$setting);
                    break;
            }
            self::rebuild($account['uniacid']);
        }


    }

    /**
     * 按团队人数升级
     * @param int $uniacid 主号
     * @param array $setting 设置参数
     */
    public static function people($uniacid, $setting){

        $sql = "SELECT id,uniacid,rankid,(SELECT count(*) from ".tablename('dist_relation')." r2 WHERE r2.gradeid>=2 and r2.path LIKE  CONCAT(r.path,'[',r.id,']','%') ) as fancount from ".tablename('dist_relation')." r where gradeid>=2 and uniacid=:uniacid  HAVING fancount>=:fancount";
        $fans_all = pdo_fetchall($sql, array(':uniacid'=>$uniacid,':fancount'=>$setting['globalagentupgrade3']));

        $ranks = setting_user('rank',$uniacid);
        foreach($fans_all as $fans){
            $rank = 0;
            if(!empty($setting['globalagentupgrade1']) && $fans['fancount'] >= $setting['globalagentupgrade1']){
                if($fans['rankid']==1)
                    continue;
                $rank = 1;
            }
            elseif(!empty($setting['globalagentupgrade2']) && $fans['fancount'] >= $setting['globalagentupgrade2']){
                if($fans['rankid']==2)
                    continue;
                $rank = 2;
            }
            elseif(!empty($setting['globalagentupgrade3']) && $fans['fancount'] >= $setting['globalagentupgrade3']){
                if($fans['rankid']==3)
                    continue;
                $rank = 3;
            }
            if($rank == 0 || ($fans['rankid']>0 && $fans['rankid']<=$rank)){
                WeUtility::logging('relation agent sales upgrade', '粉丝原等级比现等级低 fanid=' . $fans['id'] .',fancount='.$fans['fancount'] . ',rank=' . $fans['rankid']. ',torank=' . $rank,'upgrade');
                continue;
            }
            WeUtility::logging('relation agent people upgrade', 'fanid='.$fans['id'].',fancount='.$fans['fancount']. ',rank=' . $fans['rankid'].',torank='.$rank,'upgrade');
//            echo 'fanid='.$fans['id'].',fancount='.$fans['fancount'].',rank='.$rank.'<br>';

            pdo_update('mc_mapping_fans',array('rankid'=>$rank), array('fanid'=>$fans['id']));
            pdo_update('dist_relation',array('rankid'=>$rank), array('id'=>$fans['id']));

            //升级日志
            $log = array(
                'type'=>1,//代理团队人数升级
                'uniacid' => $fans['uniacid'],
                'acid' => $fans['acid'],
                'fanid' => $fans['fanid'],
                'gradeid' => $fans['gradeid'],
                'rankid' => $fans['rankid'],
                'agentid' => $fans['agentid'],
                'createtime' => TIMESTAMP,
            );

            $fans = pdo_fetch("SELECT * FROM " . tablename('mc_mapping_fans') . " WHERE fanid=:fanid",array(':fanid'=>$fans['id']));
            $relation = self::generate($fans);

            $log['upgradeid'] = $relation['upgradeid'];
            $log['toagentid'] = $relation['agentid'];
            $log['torankid'] = $rank;
            $log['remark'] = $ranks[$log['rankid']] . '变为' . $ranks[$rank];

            pdo_insert('mc_upgrade_record', $log);


            //升级推送
            $content2="升级时间：".date("Y-m-d H:i:s")."\n";
            $content='【代理升级提醒】\n'.$content2.'\n 恭喜你升级成为了'.$ranks[$rank];

            Sendmsg::model()->sendtextmsg($fans['openid'],$content,$fans['acid']);

        }
    }

    /**
     * 按团队销售额升级
     * @param int $uniacid 主号
     * @param array $setting 设置参数
     */
    public static function sales($uniacid,$setting){
        $settings = uni_setting($uniacid, array( 'distribution'));
        $distribution = $settings['distribution'] ? iunserializer($settings['distribution']) : array('commisionlevel'=>1,'commisionmodel'=>1);

        pdo_query('TRUNCATE '.tablename('dist_order_sum'));
        switch($distribution['commisionmodel'])
        {
            case 2://PV分账
                pdo_query('REPLACE INTO '.tablename('dist_order_sum').' SELECT fanid,uniacid,sum(goodspv) as total from '.tablename('dist_order').' where `status` in (1,2,3,4) and uniacid='.$uniacid.' GROUP BY uniacid,fanid ');
                break;
            default://销售额分账
                pdo_query('REPLACE INTO '.tablename('dist_order_sum').' SELECT fanid,uniacid,sum(goodsprice) as total from '.tablename('dist_order').' where `status` in (1,2,3,4) and uniacid='.$uniacid.' GROUP BY uniacid,fanid ');
                break;
        }
        $order_all = pdo_fetchall("SELECT * from ".tablename('dist_order_sum'), array(),'fanid');
        $sum = array();
        foreach($order_all as  $order)
        {
            $relation = pdo_fetch("SELECT * FROM " . tablename('dist_relation') . " WHERE id=:fanid", array(':fanid' => $order['fanid']));
            if(empty($relation['path']))continue;
            $path = self::path2Array($relation['path']);//当前粉丝的上级路径关系
            if(empty($path))continue;

            foreach($path as $pid)
                $sum[$pid] += $order['total'];
        }

        $ranks = setting_user('rank',$uniacid);

        foreach($sum as $fanid=> $total) {
            $fans = pdo_fetch("SELECT * FROM " . tablename('mc_mapping_fans') . " WHERE fanid=:fanid", array(':fanid' => $fanid));
            $rank = 0;
            if (!empty($setting['globalagentupgrade1']) && $total >= $setting['globalagentupgrade1']) {
                if ($fans['rankid'] == 1)
                    continue;
                $rank = 1;
            } elseif (!empty($setting['globalagentupgrade2']) && $total >= $setting['globalagentupgrade2']) {
                if ($fans['rankid'] == 2)
                    continue;
                $rank = 2;
            } elseif (!empty($setting['globalagentupgrade3']) && $total >= $setting['globalagentupgrade3']) {
                if ($fans['rankid'] == 3)
                    continue;
                $rank = 3;
            }
            if($rank == 0 || ($fans['rankid']>0 && $fans['rankid']<=$rank)){
                WeUtility::logging('relation agent sales upgrade', '粉丝原等级比现等级低 fanid=' . $fanid .',total='.$total . ',rank=' . $fans['rankid']. ',torank=' . $rank,'upgrade');
                continue;
            }
            WeUtility::logging('relation agent sales upgrade', 'fanid=' . $fanid .',total='.$total .',rank=' . $fans['rankid']. ',torank=' . $rank,'upgrade');
//            echo 'fanid=' . $fanid .',total='.$total . ',rank=' . $rank.'<br>';

            pdo_update('mc_mapping_fans', array('rankid' => $rank), array('fanid' => $fanid));
            pdo_update('dist_relation', array('rankid' => $rank), array('id' => $fanid));

            //升级日志
            $log = array(
                'type' => 2,//代理销售额升级
                'uniacid' => $fans['uniacid'],
                'acid' => $fans['acid'],
                'fanid' => $fans['fanid'],
                'gradeid' => $fans['gradeid'],
                'rankid' => $fans['rankid'],
                'agentid' => $fans['agentid'],
                'createtime' => TIMESTAMP,
            );


            $fans['rankid'] = $rank;
            $relation = self::generate($fans);

            $log['upgradeid'] = $relation['upgradeid'];
            $log['toagentid'] = $relation['agentid'];
            $log['torankid'] = $rank;
            $log['remark'] = $ranks[$log['rankid']] . '变为' . $ranks[$rank];
            pdo_insert('mc_upgrade_record', $log);


            //升级推送
            $content2="升级时间：".date("Y-m-d H:i:s")."\n";
            $content='【代理升级提醒】\n'.$content2.'\n 恭喜你升级成为了'.$ranks[$rank];

            Sendmsg::model()->sendtextmsg($fans['openid'],$content,$fans['acid']);
        }
    }
    /**
     * 改变推荐关系,上级等级也相应改变
     * @param $fanid 当前更换的粉丝ID
     * @param $parentid 当前粉丝上级推荐人
     * @param $acid 公众号
     * @return int 状态码:1成功,0参数错误,2当前与上级不能同一人,3上级等级小于店长(3),4当前ID在上级路径中
     */
    public static function change($fanid,$parentid,$acid)
    {
        global $_W,$_GPC;
        if(!$fanid || !$parentid || $fanid==$parentid || !$acid) return 0;

        $parent = pdo_fetch("SELECT * FROM " . tablename('mc_mapping_fans') . " WHERE fanid =:fanid and uniacid=:uniacid and acid=:acid ",array(':uniacid' => $_W['uniacid'],':acid' => $acid,':fanid' => $parentid));
        if(!$parent || $parent['gradeid']<3)return 3;

        $fan = pdo_fetch("SELECT * FROM " . tablename('mc_mapping_fans') . " WHERE fanid =:fanid and uniacid=:uniacid and acid=:acid ",array(':uniacid' => $_W['uniacid'],':acid' => $acid,':fanid' => $fanid));

        if(!$fan ||  $fan['parentid'] == $parent['fanid']) return 2;

        //判断代理等级
        if($fan['rankid'] > $parent['rankid'])return 5;

        $relation = pdo_fetch("SELECT * FROM ".tablename('dist_relation')."  WHERE id = :id", array(':id' => $fanid));
        $parent_relation = pdo_fetch("SELECT * FROM ".tablename('dist_relation')."  WHERE id = :id", array(':id' => $parentid));
        $path = self::path2Array($parent_relation['path']);//要更换的上级粉丝的上级路径关系



        if(in_array($fanid, $path))return 4;

        pdo_update('mc_mapping_fans', array('parentid'=>$parentid), array('fanid' => $fanid));
        pdo_update('dist_relation', array('parentid'=>$parentid), array('id' => $fanid));


        //最少量方式,重建当前粉丝
        $fan['parentid'] = $parentid;
        $new_relation = self::generate($fan);

        //旧关系下所有下级关系路径重建
        $all_fans = pdo_fetchall("SELECT * FROM " . tablename('mc_mapping_fans') . " WHERE fanid in (SELECT id FROM " . tablename('dist_relation') ." WHERE path like '".$relation['path']."[".$fanid."]%')");

        foreach($all_fans as $fans)
        {
            self::generate($fans);
        }

        $log = array(
            'fanid'=>$fan['fanid'],
            'openid'=>$fan['openid'],
            'uniacid'=>$fan['uniacid'],
            'gradeid'=>$fan['gradeid'],
            'parentid'=>$relation['parentid'],
            'toparentid'=>$parentid,
            'rankid'=>$fan['rankid'],
            'agentid'=>$fan['agentid'],
            'acid'=>$fan['acid'],
            'reason'=> $_GPC['reason'],
            'createtime'=>time(),
            'updatetime'=>time(),
        );
        pdo_insert('dist_relation_log', $log);

        //当前粉丝店长以上级别时其上层等级相应变动
        if($fan['gradeid']>=3 && $parent['gradeid']<=$fan['gradeid']){
            self::upgrade($fan, $parent,$path);
        }

        return 1;
    }

    /**
     * 上层等级相应变动
     * @param $fan
     * @param $parent
     * @param $path 当前粉丝上级的路径关系反转的数组array(47,24,21,20)
     */
    public static function upgrade($fan, $parent, $path)
    {
        $grades = setting_user('grade',$parent['uniacid']);
        $gradeid = $fan['gradeid']+1;
        pdo_update('mc_mapping_fans', array('gradeid'=>$gradeid), array('fanid' => $parent['fanid']));
        pdo_update('dist_relation', array('gradeid'=>$gradeid), array('id' => $parent['fanid']));
        //添加升级记录
        $grade =array(
            'fanid'=>$parent['fanid'],
            'gradeid'=> $parent['gradeid'],
            'uniacid' => $parent['uniacid'],
            'acid' => $parent['acid'],
            'upgradeid'=>$gradeid,
            'createtime'=>TIMESTAMP,
            'remark'=>"升级为".$grades[$gradeid],
        );
        pdo_insert('mc_upgrade_record', $grade);


        //升级推送
        $content2="升级时间：".date("Y-m-d H:i:s")."\n";
        $content='【会员升级提醒】\n'.$content2.'\n 恭喜你升级成为了'.$grades[$gradeid];

        Sendmsg::model()->sendtextmsg($fan['openid'],$content,$fan['acid']);

        foreach($path as $pid){
            $gradeid ++;
            $root = pdo_fetch("SELECT * FROM " . tablename('mc_mapping_fans') . " WHERE fanid =:fanid and uniacid=:uniacid and acid=:acid ",array(':uniacid' => $fan['uniacid'],':acid' => $fan['acid'],':fanid' => $pid));

            if($root['gradeid']>=$gradeid || $root['gradeid']>=6)break;
            pdo_update('mc_mapping_fans', array('gradeid'=>$gradeid), array('fanid' => $root['fanid']));
            pdo_update('dist_relation', array('gradeid'=>$gradeid), array('id' => $root['fanid']));
            //添加升级记录
            $grade =array(
                'fanid'=>$root['fanid'],
                'gradeid'=> $root['gradeid'],
                'uniacid' => $root['uniacid'],
                'acid' => $root['acid'],
                'upgradeid'=>$gradeid,
                'createtime'=>TIMESTAMP,
                'remark'=>"升级为".$grades[$gradeid],
            );
            pdo_insert('mc_upgrade_record', $grade);


            //升级推送
            $content2="升级时间：".date("Y-m-d H:i:s")."\n";
            $content='【会员升级提醒】\n'.$content2.'\n 恭喜你升级成为了'.$grades[$gradeid];

            Sendmsg::model()->sendtextmsg($root['openid'],$content,$root['acid']);
        }
    }
    /**
     * 生成六度人脉关系
     * @fans 当前粉丝数组
     * @return model
     */
    public static function generate($fans)
    {
        if(!$fans || !$fans['fanid'])
            return false;
//        WeUtility::logging('Relation start', $fans);
        $user = new MappingFans();
        $user->fans = $fans;
        //获取粉丝关系表记录
//        $model = pdo_fetch("SELECT * FROM ".tablename('dist_relation')."  WHERE id = :id", array(':id' => $fans['fanid']));

        $model = array(
            'id'=>$fans['fanid'],
            'openid'=>$fans['openid'],
            'uniacid'=>$fans['uniacid'],
            'acid'=>$fans['acid'],
            'gradeid'=>intval($fans['gradeid']),
            'parentid'=>$fans['parentid'],
            'rankid'=>$fans['rankid'],
            'agentid'=>0,
//            'total'=>0,
            'depth'=>0,
            'path'=>'',
            'threepath'=>'',
            'agentpath'=>'',
            'agentthreepath'=>'',
            'agentdepth'=>0,
            'updatetime'=>time(),
        );
        if(!pdo_fetchcolumn("SELECT id FROM ".tablename('dist_relation')."  WHERE id = :id", array(':id' => $fans['fanid'])))
        {
            $model['createtime'] = time();
            pdo_insert('dist_relation', $model, true);
        }

        //一度人脉数
        $count = pdo_fetch('select count(*) as counter from '.tablename('mc_mapping_fans').' where parentid=:parentid', array(':parentid' => $fans['fanid']));
        $model['degree1'] = $count['counter'];
//        if($fans['rankid']>0){
//            $model['agentid'] = $fans['fanid'];
//        }
        if($model['parentid']>0)
        {
            $model['path'] = '['.$model['parentid'].']';
            $model['depth'] = 1;
            $user->lookup($model);
        }

        $three = self::path2Array($model['path']);
        if(count($three)<=3)
            $model['threepath'] = $model['path'];
        else
            $model['threepath'] = '['.$three[2].']'.'['.$three[1].']'.'['.$three[0].']';


        $three = self::path2Array($model['agentpath']);
        if(count($three)<=3)
            $model['agentthreepath'] = $model['agentpath'];
        else
            $model['agentthreepath'] = '['.$three[2].']'.'['.$three[1].']'.'['.$three[0].']';


//        WeUtility::logging('relation $model', $model);
        pdo_update('dist_relation', $model, array('id' => $fans['fanid']));
        pdo_query('UPDATE ' . tablename('mc_mapping_fans') . " SET agentid=".intval($model['agentid'])." WHERE fanid=:fanid", array('fanid' => $fans['fanid']));
        return $model;
    }

    /**
     * 重建关系,不清空表
     * @param int $uniacid 主号,0为全部重建
     * @param int $page 页码
     * @param int $size 记录数,大于表示按页重建
     */
    public static function rebuild($uniacid = 0,$page=1,$size=0){
//        pdo_query('SET AUTOCOMMIT = 0 ');
        $sql = 'UPDATE ' . tablename('mc_mapping_fans') . " SET agentid=0 ";
        if($uniacid>0)
            $sql .= ' WHERE uniacid='.$uniacid;
        pdo_query($sql);

        $sql = "SELECT * FROM " . tablename('mc_mapping_fans');
        if($uniacid>0)
            $sql .= ' WHERE uniacid='.$uniacid;
        $sql .= ' ORDER BY `fanid` ';
        if($size>0)
            $sql .= " LIMIT ".($page - 1) * $size.','.$size;

        $fans_all = pdo_fetchall($sql);
        foreach($fans_all as $fans){
            self::generate($fans);
        }

    }

    /**
     * 初始化关系,清空关系表,生成六度人脉关系
     * @param int $cal 0不初始化,1初始化六度人脉
     * @return bool
     */
    public static function initialize($cal = 0)
    {

        $all_fans = pdo_fetchall("SELECT * FROM " . tablename('mc_mapping_fans'));
        foreach($all_fans as $fans)
        {
            self::generate($fans);
        }


        if($cal)
            self::initDegree();

        return true;
    }

    /**
     * 初始化六度人脉
     */
    public static function initDegree()
    {
        $relation = tablename('dist_relation');
        $relation_copy = tablename('dist_relation_copy');
        for($i=1;$i<6;$i++)
        {
            pdo_query('TRUNCATE '.$relation_copy);
            pdo_query('insert into '.$relation_copy.' select * from '.$relation);
            pdo_query('update '.$relation.' set degree'.($i+1).'=(SELECT IFNULL(sum(degree'.$i.'),0) from '.$relation_copy.' where '.$relation_copy.'.parentid='.$relation.'.id)');
            pdo_query('update '.$relation.' set total=(select IFNULL(count(*),0) from '.$relation_copy.' where '.$relation_copy.'.path like CONCAT('.$relation.".path,'[',{$relation}.id,']','%'))");

        }

    }

    /**
     * 返回路径IN查询顺序
     * @param $path 粉丝上层路径
     * @param $reverse 0不反转,1反转,即关系[20][21][24][47], 20推21推24推47再推当前粉丝,不反转数组array(20,21,24,47),反转即将数组array(47,24,21,20)
     * @return array
     */
    public static function path2Array($path,$reverse=1)
    {
        if(!$path)return array();
        $arr = json_decode(str_replace('][',',',$path));
        if($reverse)
            return array_reverse($arr);
        else
            return $arr;
    }



}