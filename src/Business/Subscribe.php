<?php
/**
 * 订阅关系管理（主题 <-> 用户 双向索引）
 *
 * ---------------------------------------------------------------------
 * 数据结构
 * ---------------------------------------------------------------------
 *   subscribe:uid:{uid}       Set   该用户订阅的主题集合（正向：查「我订阅了什么」）
 *   subscribe:topic:{topic}   Set   该主题的订阅者 uid 集合（反向：查「该推给谁」）
 *
 * 双向索引的原因：两个方向的查询都是高频路径，且写入频率远低于读取。
 * 若只存单向，则「按主题广播」需要全量扫描所有 uid 的集合，不可接受。
 *
 * 一致性：add / remove 均在两个方向上同步维护，不引入分布式事务
 * （单机部署下单进程写路径足够；集群场景需补幂等重试，见文档待办）。
 *
 * ---------------------------------------------------------------------
 * 与推送的关系
 * ---------------------------------------------------------------------
 * 本类只维护订阅关系，不负责投递。广播投递见 Push::enqueueTopic()，
 * 二者解耦后，订阅关系也可被外部系统（HTTP 接口 / 运维脚本）单独消费。
 *
 * 兼容 PHP 8.0 ~ 8.5
 */

namespace GatewayPush\Business;

use GatewayPush\Common\Logger;
use GatewayPush\Common\RedisClient;

class Subscribe
{
    /** 反向索引：主题 -> 订阅者 uid 集合 */
    const KEY_TOPIC = 'subscribe:topic:';

    /** 正向索引：uid -> 已订阅主题集合 */
    const KEY_UID = 'subscribe:uid:';

    /**
     * 订阅配置
     *
     * @var array
     */
    protected static $config = array(
        'enable'             => true,
        'ttl'                => 0,     // 订阅关系过期时间（秒），0 = 永不过期
        'max_topics_per_uid' => 100,   // 单用户订阅主题数上限，0 = 不限
    );

    /**
     * 初始化
     *
     * @param array $config app.subscribe
     * @return void
     */
    public static function init(array $config)
    {
        self::$config = array_merge(self::$config, $config);
    }

    /**
     * 功能是否启用
     *
     * @return bool
     */
    public static function enabled()
    {
        return !empty(self::$config['enable']);
    }

    /* ---------------------------------------------------------------------
     | 写入
     --------------------------------------------------------------------- */

    /**
     * 订阅主题
     *
     * 先写正向索引再写反向索引：正向写入后立即做数量校验，超限则回滚，
     * 避免把超出配额的主题写进反向索引造成「能被广播到、但用户看不到订阅」的脏状态。
     *
     * @param string        $uid
     * @param string        $topic
     * @param callable|null $cb function(bool $ok, string $msg)
     * @return void
     */
    public static function add($uid, $topic, callable $cb = null)
    {
        $uid   = (string)$uid;
        $topic = (string)$topic;

        if (!self::enabled() || $uid === '' || $topic === '') {
            if ($cb) {
                call_user_func($cb, false, '订阅功能未启用或参数为空');
            }
            return;
        }

        $uidKey   = self::KEY_UID . $uid;
        $topicKey = self::KEY_TOPIC . $topic;
        $max      = (int)self::$config['max_topics_per_uid'];

        RedisClient::sAdd($uidKey, $topic, function ($added) use ($uid, $topic, $uidKey, $topicKey, $max, $cb) {
            if (!is_int($added)) {
                Logger::error('订阅写入失败', array('uid' => $uid, 'topic' => $topic, 'stage' => 'uid_index'));
                if ($cb) {
                    call_user_func($cb, false, '订阅写入失败');
                }
                return;
            }

            $task = function () use ($uid, $topic, $uidKey, $topicKey, $cb) {
                RedisClient::sAdd($topicKey, $uid, function ($added) use ($uid, $topic, $uidKey, $topicKey, $cb) {
                    if (!is_int($added)) {
                        Logger::error('订阅写入失败', array('uid' => $uid, 'topic' => $topic, 'stage' => 'topic_index'));
                        // 回滚正向索引，避免两个方向不一致
                        RedisClient::sRem($uidKey, $topic);
                        if ($cb) {
                            call_user_func($cb, false, '订阅写入失败');
                        }
                        return;
                    }

                    self::applyTtl(array($uidKey, $topicKey));

                    Logger::info('订阅成功', array('uid' => $uid, 'topic' => $topic));
                    if ($cb) {
                        call_user_func($cb, true, '');
                    }
                });
            };

            if ($max <= 0) {
                $task();
                return;
            }

            // 配额校验：超限时撤销本次写入（并发下最坏结果是少放行几条，不影响正确性）
            RedisClient::sCard($uidKey, function ($count) use ($uid, $topic, $uidKey, $max, $task, $cb) {
                if (is_int($count) && $count > $max) {
                    RedisClient::sRem($uidKey, $topic, function () use ($uid, $max, $cb) {
                        Logger::warn('订阅主题数超出上限，已拒绝', array('uid' => $uid, 'max' => $max));
                        if ($cb) {
                            call_user_func($cb, false, '订阅主题数超出上限 ' . $max);
                        }
                    });
                    return;
                }
                $task();
            });
        });
    }

    /**
     * 取消订阅
     *
     * @param string        $uid
     * @param string        $topic
     * @param callable|null $cb function(bool $ok, string $msg)
     * @return void
     */
    public static function remove($uid, $topic, callable $cb = null)
    {
        $uid   = (string)$uid;
        $topic = (string)$topic;

        if ($uid === '' || $topic === '') {
            if ($cb) {
                call_user_func($cb, false, '参数为空');
            }
            return;
        }

        RedisClient::sRem(self::KEY_UID . $uid, $topic, function ($removed) use ($uid, $topic, $cb) {
            // 正向索引不存在时无需动反向索引，但仍要清理一次以防历史脏数据
            RedisClient::sRem(self::KEY_TOPIC . $topic, $uid, function () use ($uid, $topic, $cb) {
                Logger::info('取消订阅完成', array('uid' => $uid, 'topic' => $topic));
                if ($cb) {
                    call_user_func($cb, true, '');
                }
            });
        });
    }

    /* ---------------------------------------------------------------------
     | 读取
     --------------------------------------------------------------------- */

    /**
     * 查询用户已订阅的主题
     *
     * @param string   $uid
     * @param callable $cb function(array $topics)
     * @return void
     */
    public static function topicsOf($uid, callable $cb)
    {
        RedisClient::sMembers(self::KEY_UID . (string)$uid, function ($topics) use ($cb) {
            call_user_func($cb, is_array($topics) ? array_values($topics) : array());
        });
    }

    /**
     * 查询主题的订阅者（供广播使用）
     *
     * @param string   $topic
     * @param callable $cb function(array $uids)
     * @return void
     */
    public static function subscribers($topic, callable $cb)
    {
        RedisClient::sMembers(self::KEY_TOPIC . (string)$topic, function ($uids) use ($cb) {
            call_user_func($cb, is_array($uids) ? array_values($uids) : array());
        });
    }

    /**
     * 主题订阅者数量
     *
     * @param string   $topic
     * @param callable $cb function(int $count)
     * @return void
     */
    public static function count($topic, callable $cb)
    {
        RedisClient::sCard(self::KEY_TOPIC . (string)$topic, function ($count) use ($cb) {
            call_user_func($cb, is_int($count) ? $count : 0);
        });
    }

    /* ---------------------------------------------------------------------
     | 内部实现
     --------------------------------------------------------------------- */

    /**
     * 按配置为订阅键续期
     *
     * ttl <= 0 表示订阅关系长期有效，不做过期。
     *
     * @param array $keys 裸键名（RedisClient 内部会补全局前缀）
     * @return void
     */
    protected static function applyTtl(array $keys)
    {
        $ttl = (int)self::$config['ttl'];
        if ($ttl <= 0) {
            return;
        }
        foreach ($keys as $key) {
            RedisClient::expire($key, $ttl);
        }
    }
}
