<?php
/**
 * admin · 模型 —— Test。
 *
 * GatewayPush 管理后台（webman + webman/admin）自有源码。
 */

namespace app\model;

use support\Model;

/**
 * webman 骨架示例模型（保留以兼容默认路由习惯；业务表不用它）。
 */
class Test extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'test';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';
}
