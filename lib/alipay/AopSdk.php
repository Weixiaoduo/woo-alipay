<?php

if (!defined("AOP_SDK_WORK_DIR")) {
    define("AOP_SDK_WORK_DIR", "/tmp/");
}

if (!defined("AOP_SDK_DEV_MODE")) {
    define("AOP_SDK_DEV_MODE", false);
}

require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'aop' . DIRECTORY_SEPARATOR . 'AopClient.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'aop' . DIRECTORY_SEPARATOR . 'AopCertClient.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'aop' . DIRECTORY_SEPARATOR . 'AopEncrypt.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'aop' . DIRECTORY_SEPARATOR . 'SignData.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'aop' . DIRECTORY_SEPARATOR . 'EncryptParseItem.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'aop' . DIRECTORY_SEPARATOR . 'EncryptResponseData.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'aop' . DIRECTORY_SEPARATOR . 'AopCertification.php';