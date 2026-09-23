<?php
/**
 * 等宽终端文本排版
 *
 * 中文等全角字符占 2 列、ASCII 占 1 列，不能直接用 str_pad() 补空格 ——
 * 它按字节数计算，含中文的列会整体错位。
 *
 * bin/start.ps1 的 Format-Pad 处理的是同一个问题，两处口径需保持一致。
 *
 * 兼容 PHP 8.2 ~ 8.5
 */

namespace GatewayPush\Console;

/**
 * 等宽终端文本排版
 *
 * 中文占 2 列、ASCII 占 1 列，按显示宽度对齐；与 bin/start.ps1 的 Format-Pad 口径一致。
 */
final class Text
{
    /**
     * 字符串在等宽终端下的显示宽度
     *
     * @param string $text
     *
     * @return int
     */
    public static function displayWidth(string $text): int
    {
        $width  = 0;
        $length = strlen($text);

        for ($i = 0; $i < $length;) {
            $byte = ord($text[$i]);
            if ($byte < 0x80) {          // ASCII
                $width++;
                $i++;
            } elseif ($byte < 0xE0) {    // 2 字节序列（拉丁扩展等），按窄字符计
                $width++;
                $i     += 2;
            } else {                     // 3 / 4 字节序列（CJK 等），按宽字符计
                $width += 2;
                $i     += $byte < 0xF0 ? 3 : 4;
            }
        }

        return $width;
    }

    /**
     * 按显示宽度右侧补空格
     *
     * @param string $text
     * @param int    $width
     *
     * @return string
     */
    public static function pad(string $text, int $width): string
    {
        $pad = $width - self::displayWidth($text);

        return $pad > 0 ? $text . str_repeat(' ', $pad) : $text;
    }
}
