<?php
/**
 * 网站设置
 *
 *
 *
 **by 好商城V3 www.haoid.cn 运营版*/

defined('InShopNC') or exit('Access Invalid!');
class relationControl extends BaseHomeControl{
    public $fans = array();
	public function __construct(){
		parent::__construct();
	}

    /*
     * 测试关系形成
     */
    public function indexOp(){
        $member_model =  Model('member');
        $member_id = 5;
        $member  = $member_model->getMemberInfo(array('member_id'=>$member_id));
        $relation_model =  Model('relation');
        //$user  = MappingFans();
        $this->fans = $member;
        //生成关系逻辑
        $relation =  $relation_model->getMemberRelation($member_id);
        $model = array(
            'id'=>$member['member_id'],
            'openid'=>'',
            'uniacid'=>0,
            'acid'=>0,
            'gradeid'=>0,
            'parentid'=>$member['inviter_id'],
            'rankid'=>0,
            'agentid'=>0,
            'depth'=>0,
            'path'=>'',
            'threepath'=>'',
            'agentpath'=>'',
            'agentthreepath'=>'',
            'agentdepth'=>0,
            'updatetime'=>time(),
        );
        if(empty($relation))
        {
            $model['createtime'] = time();
            $relation_model->addRelation($model);
        }
        //一度人脉数
        $count =  $relation_model->findOneCon($member_id);
        $model['degree1'] = $count;
        if($model['parentid']>0)
        {
            $model['path'] = '['.$model['parentid'].']';
            $model['depth'] = 1;
            self::lookup($model);
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

        //更新关系数据
        $relation_model->editRelation(array('id'=>$member_id),$model);
        //更新代理等级
        $data  = array(
            'agentid'=>$model['agentid']
        );
        $relation_model->updateMember($member_id,$data);

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

    /**
     * 查找上层并统计人数
     * @param array $model 引用对象
     * @return bool
     */
    public function lookup(&$model)
    {
        $member_model =  Model('member');
        $fans  = $member_model->getMemberInfo(array('member_id'=>$this->fans['inviter_id']));
        if($fans['rankid']>0){
            $model['agentpath'] = '['.$fans['fanid'].']'.$model['agentpath'];
            $model['agentdepth']++;
            if(!$model['agentid']){
                $model['agentid'] = $fans['fanid'];
            }
        }
        if(!$fans || !$fans['inviter_id']) return false;
        $parent = new self;
        $parent->fans = $fans;
        $model['path'] = '['.$fans['inviter_id'].']'.$model['path'];
        $model['depth']++;
        $parent->lookup($model);
    }

}






