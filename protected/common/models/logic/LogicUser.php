<?php
namespace app\common\models\logic;

use app\common\models\model\Financial;
use app\common\models\model\User;
use app\components\Macro;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

class LogicUser
{
    protected $user = null;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /*
     * 更新账户余额
     *
     * decimal $amount 更新金额
     * int $eventType 导致更新的事件类型
     * string $eventId 导致更新的事件唯一ID
     * decimal $eventAmount 事件本身金额
     * string $clientIp 客户端IP
     * string $bak 备注
     * int $opUid 操作者UID
     * int $opUsername 操作者用户名
     *
     */
    public function changeUserBalance($amount, $eventType, $eventId, $eventAmount, $clientIp='', $bak='', $opUid=0, $opUsername=''){
        bcscale(6);
        Yii::debug([__FUNCTION__.' '.$this->user->id.','.$amount.','.$eventType.','.$eventId]);
        if(empty($this->user) || $amount==0){
            Yii::debug('user or amount empty'.$this->user->id.','.$amount.','.$eventType.','.$eventId);
            return false;
        }
        if($amount<0 && $this->user->balance<abs($amount)){
            Yii::warning("changeUserBalance balance not enough: uid:{$this->user->id},{$amount},{$eventType},{$eventId}");
            throw new \Exception("余额不足",Macro::ERR_BALANCE_NOT_ENOUGH);
        }

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $financial = Financial::findOne(['event_id'=>$eventId,'event_type'=>$eventType,'uid'=>$this->user->id]);
            if(!$financial){
                Yii::info("changeUserBalance: uid:{$this->user->id},{$amount},{$eventType},{$eventId}");
//                var_dump("changeUserBalance: uid:{$this->user->id},{$amount},{$eventType},{$eventId}");
//                throw new \Exception('账户余额已经完成变动，请勿重复修改。');

                //写入账变日志
                $financial                        = new Financial();
                $financial->uid                   = $this->user->id;
                $financial->username              = $this->user->username;
                $financial->event_id              = $eventId;
                $financial->event_type            = $eventType;
                $financial->event_amount          = $eventAmount;
                $financial->amount                = $amount;
                $financial->balance               = bcadd($this->user->balance, $amount);
                $financial->balance_before        = $this->user->balance;
                $financial->frozen_balance        = $this->user->frozen_balance;
                $financial->frozen_balance_before = $this->user->frozen_balance;
                $financial->created_at            = time();
                $financial->client_ip             = $clientIp;
                $financial->created_at            = $clientIp;
                $financial->bak                   = $bak;
                $financial->op_uid                = $opUid;
                $financial->status                = Financial::STATUS_UNFINISHED;
                $financial->op_username           = $opUsername;
                $financial->all_parent_agent_id   = $this->user->all_parent_agent_id;
                $financial->save();

                Yii::info("changeUserBalance: uid:{$this->user->id},{$amount},{$financial->balance_before},{$financial->balance}");

            }else{
                Yii::warning("changeUserBalance already has record: uid:{$this->user->id},{$amount},{$eventType},{$eventId}");
            }

            if($financial->status == Financial::STATUS_UNFINISHED){
                //更新账户余额
                $this->user->updateCounters(['balance' => $amount]);

                //更新账变状态
                $financial->status = Financial::STATUS_FINISHED;
                if (!$financial->update()) {
                    $msg = '帐变记录更新失败: '.$eventType.':'.$eventId;
                    Yii::error($msg);
                    throw new \Exception($msg);
                }
            }else{
                Yii::warning("changeUserFrozenBalance already done: uid:{$this->user->id},{$amount},{$eventType},{$eventId}");
            }

            $transaction->commit();
            return true;
        } catch(\Exception $e) {
            $transaction->rollBack();
            throw $e;
            return false;
        } catch(\Throwable $e) {
            $transaction->rollBack();
            throw $e;
            return false;
        }
    }

    /*
     * 更新账户冻结余额
     *
     * decimal $amount 更新的冻结金额
     * int $eventType 导致更新的事件类型
     * string $eventId 导致更新的事件唯一ID
     * decimal $eventAmount 事件本身金额
     * string $clientIp 客户端IP
     * string $bak 备注
     * int $opUid 操作者UID
     * int $opUsername 操作者用户名
     *
     */
    public function changeUserFrozenBalance($amount, $eventType, $eventId, $eventAmount, $clientIp='', $bak='', $opUid=0, $opUsername=''){
        bcscale(6);
        Yii::debug([__FUNCTION__.' '.$this->user->id.','.$amount.','.$eventType.','.$eventId]);
        if(empty($this->user) || $amount==0){
            Yii::debug('user or amount empty'.$this->user->id.','.$amount.','.$eventType.','.$eventId);
            return false;
        }
        //冻结金额，冻结字段+，余额字段-
        if($amount>0 && $this->user->balance<$amount){
            Yii::warning("changeUserFrozenBalance balance not enough: uid:{$this->user->id},{$amount},{$eventType},{$eventId}");
            throw new \Exception("余额不足",Macro::ERR_BALANCE_NOT_ENOUGH);
        }
        //解冻金额，冻结字段-，余额字段+
        if($amount<0 && $this->user->frozen_balance<abs($amount)){
            Yii::warning("changeUserFrozenBalance balance not enough: uid:{$this->user->id},{$amount},{$eventType},{$eventId}");
            throw new \Exception("冻结余额小余要解冻的金额",Macro::ERR_BALANCE_NOT_ENOUGH);
        }
        $usableAmount = 0-$amount;

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $financial = Financial::findOne(['event_id'=>$eventId,'event_type'=>$eventType,'uid'=>$this->user->id]);
            if(!$financial){
                Yii::info("changeUserFrozenBalance: uid:{$this->user->id},{$amount},{$eventType},{$eventId}");
                //                var_dump("changeUserBalance: uid:{$this->user->id},{$amount},{$eventType},{$eventId}");
                //                throw new \Exception('账户余额已经完成变动，请勿重复修改。');

                //写入账变日志
                $financial                        = new Financial();
                $financial->uid                   = $this->user->id;
                $financial->username              = $this->user->username;
                $financial->event_id              = $eventId;
                $financial->event_type            = $eventType;
                $financial->event_amount          = $eventAmount;
                $financial->amount                = $usableAmount;
                $financial->frozen_balance        = bcadd($this->user->frozen_balance, $amount);
                $financial->frozen_balance_before = $this->user->frozen_balance;
                $financial->balance               = bcadd($this->user->balance, $usableAmount);
                $financial->balance_before        = $this->user->balance;
                $financial->created_at            = time();
                $financial->client_ip             = $clientIp;
                $financial->created_at            = $clientIp;
                $financial->bak                   = $bak;
                $financial->op_uid                = $opUid;
                $financial->status                = Financial::STATUS_UNFINISHED;
                $financial->op_username           = $opUsername;
                $financial->all_parent_agent_id   = $this->user->all_parent_agent_id;
                $financial->save();
            } else {
                Yii::warning("changeUserFrozenBalance already has record: uid:{$this->user->id},{$amount},{$eventType},{$eventId}");
            }

            if ($financial->status == Financial::STATUS_UNFINISHED) {

                //更新账户余额
                $this->user->updateCounters(['balance' => $usableAmount]);
                $this->user->updateCounters(['frozen_balance' => $amount]);

                //更新账变状态
                $financial->status = Financial::STATUS_FINISHED;
                if (!$financial->update()) {
                    $msg = '帐变记录更新失败: '.$eventType.':'.$eventId;
                    Yii::error($msg);
                    throw new \Exception($msg);
                }
            }else{
                Yii::warning("changeUserFrozenBalance already done: uid:{$this->user->id},{$amount},{$eventType},{$eventId}");
            }

            $transaction->commit();
            return true;
        } catch(\Exception $e) {
            $transaction->rollBack();
            throw $e;
            return false;
        } catch(\Throwable $e) {
            $transaction->rollBack();
            throw $e;
            return false;
        }
    }
}