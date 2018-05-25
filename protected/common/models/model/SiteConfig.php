<?php
namespace app\common\models\model;

use app\components\Macro;
use Yii;
use yii\db\ActiveRecord;

class SiteConfig extends BaseModel
{
    public static function getDb()
    {
        return \Yii::$app->db;
    }

    public static function tableName()
    {
        return '{{%site_config}}';
    }

    /**
     * 获取完成的缓存key
     *
     * @param $key
     *
     * @return string
     */
    protected static function getCacheKey($key)
    {
        return 'site_config.'.$key;
    }

    /**
     *
     * 从缓存中获取站点配置
     *
     * @param strint $key 配置项key
     *
     * @return string
     */
    public static function cacheGetContent($key)
    {
        $content = Yii::$app->cache->hget(Macro::CACHE_HSET_SITE_CONFIG,$key);
        if(!$content){
            $config = self::findOne(['title'=>$key]);
            if($config){
                $content = $config->content;
                Yii::$app->cache->hset(Macro::CACHE_HSET_SITE_CONFIG,$key,$content);
            }
        }
        $content=$content??'';

        return $content;
    }

    /**
     *
     * 从缓存中获取所有站点配置
     *
     * @return string
     */
    public static function cacheGetAll()
    {
        $redisContent = Yii::$app->cache->hgetall(Macro::CACHE_HSET_SITE_CONFIG);
        $content = [];
        if($redisContent){
            foreach ($redisContent as $k=>$v){
                if($k==0) continue;

                $content[$redisContent[$k-1]] = $v;
            }
        }

        return $content;
    }

    public function setContent($content)
    {
        $this->content = $content;
        Yii::$app->cache->hset(Macro::CACHE_HSET_SITE_CONFIG,$this->title,$content);
    }
}