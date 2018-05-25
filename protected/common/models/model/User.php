<?php
namespace app\common\models\model;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

class User extends BaseModel
{

    const STATUS_INACTIVE=0;
    const STATUS_ACTIVE=10;
    const STATUS_BANED=20;
    const ARR_STATUS = [
        self::STATUS_INACTIVE => '未激活',
        self::STATUS_ACTIVE => '正常',
        self::STATUS_BANED => '已禁用',
    ];

    const GROUP_ADMIN = 10;
    const GROUP_AGENT = 20;
    const GROUP_MERCHANT = 30;
    const ARR_GROUP = [
        self::GROUP_ADMIN => '管理员',
        self::GROUP_MERCHANT => '商户',
        self::GROUP_AGENT => '代理',
    ];

    const ARR_GROUP_EN = [
        self::GROUP_ADMIN => 'admin',
        self::GROUP_MERCHANT => 'merchant',
        self::GROUP_AGENT => 'agent',
    ];
    const DEFAULT_RECHARGE_RATE = 0.6;
    const DEFAULT_REMIT_FEE = 0.6;

    public static function tableName()
    {
        return '{{%users}}';
    }

    public function behaviors() {
        return [TimestampBehavior::className(),];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            ['status','default','value'=>self::STATUS_INACTIVE],
            ['status','in','range'=>[self::STATUS_ACTIVE,self::STATUS_INACTIVE,self::STATUS_BANED]],
        ];
    }

    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        if ($insert){
            //按规则生成uid,(2位分组id+1位是否主账号+当前规则下数据库最大值区3位之后)+10-500随机数,且总长度中6位以上
            $uidPrefix = ($this->group_id<10?99:$this->group_id).''.intval($this->isMainAccount());
            $parentMerchantIdStr = $this->isMainAccount()?"AND parent_merchant_id=0":"AND parent_merchant_id>?0";
            $maxPrefixId = Yii::$app->db->createCommand("SELECT id from ".User::tableName()." WHERE group_id={$this->group_id} $parentMerchantIdStr ORDER BY id DESC LIMIT 1")
                ->queryScalar();
            if($maxPrefixId>1000){
                $maxPrefixId = substr($maxPrefixId,3);
            }
            if($maxPrefixId<1000)  $maxPrefixId = mt_rand(1000,1500);
            $this->id = intval($uidPrefix.$maxPrefixId)+mt_rand(10,500);
        }

        return true;
    }

    public function getPaymentInfo()
    {
        return $this->hasOne(UserPaymentInfo::className(), ['user_id'=>'id']);
    }

    public static function findActive($id){
        return static::findOne(['id'=>$id,'status'=>self::STATUS_ACTIVE]);
    }

    public static function getUserByMerchantId($id){
        $user = static::findOne(['app_id'=>$id,'status'=>self::STATUS_ACTIVE]);

        return $user;
    }

    public static function findByUsername($username){
        return static::findOne(['username'=>$username,'status'=>self::STATUS_ACTIVE]);
    }

    public function getAllParentAgentId()
    {
        return empty($this->all_parent_agent_id)?[]:json_decode($this->all_parent_agent_id,true);
    }

    public function getParentAgent()
    {
        return $this->hasOne(User::className(), ['id'=>'parent_agent_id']);
    }
    /*
    * 获取状态描述
    *
    * @param int $status 状态ID
    * @return string
    * @author bootmall@gmail.com
    */
    public static function getStatusStr($status)
    {
        return self::ARR_STATUS[$status]??'-';
    }

    /*
    * 获取分组描述
    *
    * @param int $groupId 分组ID
    * @return string
    * @author bootmall@gmail.com
    */
    public static function getGroupStr($groupId)
    {
        return self::ARR_GROUP[$groupId]??'-';
    }

    /*
    * 获取分组英文描述
     *
    * @param int $groupId 分组ID
    * @return string
    * @author bootmall@gmail.com
    */
    public static function getGroupEnStr($groupId)
    {
        return self::ARR_GROUP_EN[$groupId]??'';
    }

    /**
     * 注销
     */
    public function logOut()
    {
        $this->access_token = '';
        $this->save();
    }

    public function getTags()
    {
        return $this->hasMany(Tag::className(), ['id' => 'object_id'])
            ->viaTable(TagRelation::tableName(), ['tag_id' => 'id']);
    }

    /*
     * 根据uid获取他的标签
     *
     * @param int $uid 用户UID
     */
    public static function getTagsArr($uid)
    {
        $tags = (new \yii\db\Query())->from(TagRelation::tableName().' r')
            ->select(['t.id', 't.name'])
            ->leftJoin(Tag::tableName().' t', 't.id=r.tag_id')
        ->where(['r.object_type' => 1,'r.object_id'=>$uid])
            ->all();
        return $tags;
//        $sql = "select t.* form ".Tag::tableName()." t,".TagRelation::tableName()." r WHERE t.id=r.tag_id AND r.object_type=1 AND t.id={$uid}";
    }
    /**
     * 根据uids获取他们的上级代理的用户名
     */
    public static function getParentUserName($uids)
    {
        return self::find()->where(['in','id',$uids])->select('id,username')->asArray()->all();
    }

    /**
     * 设置用户基础角色
     */
    public function setBaseRole()
    {
        $auth    = Yii::$app->authManager;
        $baseRole = $auth->getRole(AuthItem::ROLE_USER_BASE);
        $auth->revoke($baseRole, $this->id);
        $auth->assign($baseRole, $this->id);
    }

    /**
     * 设置用户分组对应角色(含基础权限)
     */
    public function setGroupRole()
    {
        $groupStr = self::getGroupEnStr($this->group_id);
        $auth    = Yii::$app->authManager;
        //主账户才授予分组权限,子账户需要主账户单独赋予角色
        if($groupStr && $this->isMainAccount()){
            $baseRole = $auth->getRole($groupStr);
            $auth->revoke($baseRole, $this->id);
            $auth->assign($baseRole, $this->id);
        }
        $this->setBaseRole();

    }


    /**
     * 获取代理
     * 切换上级 获取出款费率，收款费率 比需要切换上级代理的商户费率 小的
     */
    public static function getAgentAll($agentIds,$methods,$remit_fee)
    {
        $allAgentIds = [];
        $paymethodInfoQuery = UserPaymentInfo::find();
        $paymethodInfoQuery->andWhere(['<=','remit_fee',$remit_fee]);
        $paymethodInfoQuery->andWhere(['not in','user_id',$agentIds]);
        $paymethodInfoQuery->select('user_id');
        $paymethodInfo = $paymethodInfoQuery->asArray()->all();
        if($paymethodInfo){
            foreach ($paymethodInfo as $key => $val){
                $allAgentIds[$key] = $val['user_id'];
            }
        }
        if(empty($allAgentIds)){
            return $allAgentIds;
        }else{
            $filter = ['and',['in','merchant_id',$allAgentIds]];
            $minFeeFilter = ['or'];
            foreach ($methods as $key => $val){
                $minFeeFilter[]=["and","method_id={$key}","fee_rate<={$val}"];
            }
            $filter[] = $minFeeFilter;
            $merchantRechargeMethods = (new Query())->select('count(id) as total,merchant_id')->from(MerchantRechargeMethod::tableName())->where($filter)->groupBy('merchant_id')->all();
            if($merchantRechargeMethods){
                $allAgentIds = [];
                foreach ($merchantRechargeMethods as $key => $val){
                    if($val['total'] == count($methods)){
                        $allAgentIds[$key] = $val['merchant_id'];
                    }
                }
            }
//            var_dump($allAgentIds);die;
            if(empty($allAgentIds)){
                return $allAgentIds;
            }else{
                return self::find()->where(['in','id',$allAgentIds])->andWhere(['group_id' => 20])->select('id,username')->asArray()->all();
            }
        }
    }

    /**
     * 是否是商户账户
     */
    public function isMerchant()
    {
        return $this->group_id==self::GROUP_MERCHANT;
    }

    /**
     * 是否是代理账户
     */
    public function isAgent()
    {
        return $this->group_id==self::GROUP_AGENT;
    }

    /**
     * 是否是主账号
     */
    public function isMainAccount()
    {
        return $this->parent_merchant_id==0;
    }

    /**
     * 获取主账号
     */
    public function getMainAccount()
    {
        if($this->parent_merchant_id==0){
            return $this;
        }else{
            self::findOne(['id'=>$this->parent_merchant_id]);
        }
    }

    /**
     * 获取代理的所有下级账户
     *
     * @param $uid 用户ID
     * @return array
     */
    public static function getAllAgentChildren($uid)
    {
        $query = User::find();
        $query->andWhere(['like','all_parent_agent_id',','.$uid.',']);
        $query->orWhere(['like','all_parent_agent_id','['.$uid.']']);
        $query->orWhere(['like','all_parent_agent_id','['.$uid.',']);
        $query->orWhere(['like','all_parent_agent_id',','.$uid.']']);
        $query->select('all_parent_agent_id');
        $children = $query->all();

        return $children;
    }
}