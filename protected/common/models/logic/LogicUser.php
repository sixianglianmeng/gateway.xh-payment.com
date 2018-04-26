<?php
namespace app\common\models\logic;

use app\common\models\model\Financial;
use app\common\models\model\User;
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
     * string $clientIp 客户端IP
     * string $bak 备注
     * int $opUid 操作者UID
     * int $opUsername 操作者用户名
     *
     */
    public function changeUserBalance($amount, $eventType, $eventId, $clientIp='', $bak='', $opUid=0, $opUsername=''){
        if(empty($this->user)){
            return false;
        }
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
//            $db->createCommand($sql1)->execute();
//            $db->createCommand($sql2)->execute();
            // ... executing other SQL statements ...

            $financial = Financial::findOne(['event_id'=>$eventId,'event_type'=>$eventType,'uid'=>$this->user->id]);
            if(!$financial){
//                throw new \Exception('账户余额已经完成变动，请勿重复修改。');

                //写入账变日志
                $financial              = new Financial();
                $financial->uid         = $this->user->id;
                $financial->event_id    = $eventId;
                $financial->event_type  = $eventType;
                $financial->amount      = $amount;
                $financial->balance     = bcadd($this->user->balance, $amount);
                $financial->created_at  = time();
                $financial->client_ip   = time();
                $financial->created_at  = $clientIp;
                $financial->bak         = $bak;
                $financial->op_uid      = $opUid;
                $financial->status      = Financial::STATUS_UNFINISHED;
                $financial->op_username = $opUsername;
                $financial->save();

                //更新账户余额
                $this->user->updateCounters(['balance' => $amount]);

                //更新账变状态
                $financial->status = Financial::STATUS_FINISHED;
                $financial->save();
            }else{
                Yii::warning("changeUserBalance already changed: uid:{$this->user->id},{$amount},{$eventType},{$eventId}");
            }

            $transaction->commit();
            return true;
        } catch(\Exception $e) {
            $transaction->rollBack();
//            throw $e;
            return false;
        } catch(\Throwable $e) {
            $transaction->rollBack();
//            throw $e;
            return false;
        }
    }
}