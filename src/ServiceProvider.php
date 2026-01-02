<?php

declare(strict_types=1);

namespace Acms\Plugins\WPImport;

use ACMS_App;
use Acms\Services\Facades\Application as Container;
use Acms\Services\Common\InjectTemplate;
use Acms\Services\Common\HookFactory;

class ServiceProvider extends ACMS_App
{
    /**
     * @var string
     */
    public $version = '0.0.0';

    /**
     * @var string
     */
    public $name = 'WPImport';

    /**
     * @var string
     */
    public $author = 'uidev1116';

    /**
     * @var bool
     */
    public $module = false;

    /**
     * @var bool|string
     */
    public $menu = 'wp_import_index';

    /**
     * @var string
     */
    public $desc = 'WordPress のデータを a-blog cms にインポートするための拡張アプリです。';

    public function __construct()
    {
    }

    /**
     * サービスの初期処理
     */
    public function init()
    {
        /**
         * Inject Template
         */
        $inject = InjectTemplate::singleton();
        if (ADMIN === 'app_' . $this->menu) {
            $inject->add('admin-topicpath', PLUGIN_DIR . $this->name . '/template/admin/topicpath.html');
            $inject->add('admin-main', PLUGIN_DIR . $this->name . '/template/admin/main.html');
        }
    }

    /**
     * インストールする前の環境チェック処理
     *
     * @return bool
     */
    public function checkRequirements()
    {
        return true;
    }

    /**
     * インストールするときの処理
     * データベーステーブルの初期化など
     *
     * @return void
     */
    public function install()
    {
    }

    /**
     * アンインストールするときの処理
     * データベーステーブルの始末など
     *
     * @return void
     */
    public function uninstall()
    {
    }

    /**
     * アップデートするときの処理
     *
     * @return bool
     */
    public function update()
    {
        return true;
    }

    /**
     * 有効化するときの処理
     *
     * @return bool
     */
    public function activate()
    {
        return true;
    }

    /**
     * 無効化するときの処理
     *
     * @return bool
     */
    public function deactivate()
    {
        return true;
    }
}
