<?php
/**
 * report 动作 —— 数据上报：按主题累加计数
 *
 * 注意通道差异（config/actions.php 声明）：
 *   WS  —— 同步回执（正常结算）；
 *   UDP —— **静默不回执**，cb 只能等本地超时（ok=false, code=10001）。
 * 客户端不做通道判断，UDP 侧语义由调用方自行知晓。
 *
 * 兼容 PHP 8.1 ~ 8.5
 */

namespace GatewayPush\Client\Service;

final class ReportApi extends AbstractApi
{
    /**
     * @param string        $topic 主题名（字母数字与 _ : . - ，1~64 字符）
     * @param int           $count 累加计数，1~10000（默认 1）
     * @param mixed         $value 附加 JSON 值（可空）
     * @param callable|null $cb    function (bool $ok, array $data, ?array $error): void
     * @return string 本请求 seq
     */
    public function report($topic, $count = 1, $value = null, $cb = null)
    {
        return $this->call('report', array(
            'topic' => (string)$topic,
            'count' => (int)$count,
            'value' => $value,
        ), $cb);
    }
}
