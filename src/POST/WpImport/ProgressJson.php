<?php

namespace Acms\Plugins\WPImport\POST\WpImport;

use Acms\Services\Facades\Application;
use Acms\Services\Facades\Common;
use ACMS_POST;

class ProgressJson extends ACMS_POST
{
    public function post()
    {
        $logger = Application::make('common.logger');
        assert($logger instanceof \Acms\Services\Common\Logger);
        $logger->setDestinationPath(CACHE_DIR . 'wp-import-progress.json');
        $output = [
            'message' => 'No log found',
            'status' => 'notfound',
        ];

        $json = json_encode($logger->getJson());
        if ($json !== false) {
            $output = json_decode($json, true);
        }
        Common::responseJson($output);
    }
}
