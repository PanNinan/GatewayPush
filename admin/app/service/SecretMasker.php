<?php

declare(strict_types=1);

namespace app\service;

/**
 * 敏感值脱敏（P5）—— 纯函数，零 IO。
 *
 * 口径：**前 4 后 4 保留，中间以 `****` 打码**；长度 < 8 时全掩（不足分两半）；
 * 空值原样返回（语义是「未配置」，打成 `****` 反而制造「已配置」的假象）。
 *
 * 用途：密钥状态展示（`/api/ops/config` 与运维页）。展示「密钥长什么样」
 * 是为了让人能对上「我改的是不是这一把」—— 全掩成 `****` 时多把密钥无法区分，
 * 就失去了脱敏展示的意义；但保留的字符要足够少，不能反向缩小穷举空间。
 *
 * 与主项目 `Auditor::redact()` 的分工：那是**审计侧**的宁枉勿纵（子串命中即整键打码），
 * 本类是**展示侧**的定向脱敏（调用方明确知道自己要展示什么）。
 */
final class SecretMasker
{
    /** 打码段（长度固定，不随原文长度变化 —— 避免泄露原文长度） */
    private const MASK = '****';

    /**
     * 脱敏一个敏感值
     *
     * @param string $value
     *
     * @return string
     */
    public static function mask(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $len = strlen($value);
        if ($len < 8) {
            return self::MASK;
        }

        return substr($value, 0, 4) . self::MASK . substr($value, -4);
    }
}
