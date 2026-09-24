<?php
/**
 * admin 配置 —— translation。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

/**
 * Multilingual configuration
 */
return [
    // Default language
    'locale' => 'zh_CN',
    // Fallback language
    'fallback_locale' => ['zh_CN', 'en'],
    // Folder where language files are stored
    'path' => base_path() . '/resource/translations',
];
