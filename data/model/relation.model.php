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
     * 注册生成关系链
     * @param array $member
     */
    public function index($member){
        self::generate($member);
    }


    /**
     * 取得会员详细信息（优先查询缓存）
     * 如果未找到，则缓存所有字段
     * @param int $member_id
     * @param string $field 需要取得的缓存键值, 例如：'*','member_name,member_sex'
     * @return array
     */
    public function getMemberRelation($memeberid) {
        return $this->table('member_relation')->where(array('id'=>$memeberid))->find();
    }


    /**
     * 查看一度人脉数
     * @param int $member_id
     * @return array
     */
    public function findOneCon($memberid) {
        return $this->table('member')->where(array('inviter_id'=>$memberid))->count();
    }



    /**
     * 更新会员代理等级
     * @param int $member_id
     * @return array
     */
    public function updateMember($memberid,$data) {
        return $this->table('member')->where(array('member_id'=>$memberid))->update($data);
    }


    /**
     * 编辑关系
     * @param array $condition
     * @param array $data
     */
    public function editRelation($condition, $data) {
        $update = $this->table('member_relation')->where($condition)->update($data);
        return $update;
    }


    /**
     * 添加会员关系信息
     * @param int $member_id
     * @return array
     */
    public function addRelation($data) {
        return $this->table('member_relation')->insert($data);
    }




}


