<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class SpectrocoinCancelModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess(): void
    {
        Tools::redirect('index.php?controller=order&step=1');
    }
}