<?php

namespace yii2sentry2\sentry;

use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Sentry\Integration\RequestIntegration;
use Sentry\Options;
use Sentry\State\Hub;
use Sentry\State\Scope;
use yii\helpers\ArrayHelper;
use yii\log\Logger;
use yii\log\Target;

/**
 * SentryTarget records log messages in a Sentry.
 *
 * @see https://sentry.io
 */
class SentryTarget extends Target
{
    /**
     * @var string Sentry client key.
     */
    public $dsn;
    /**
     * @var array Options of the \Raven_Client.
     */
    public $clientOptions = [];
    /**
     * @var bool Write the context information. The default implementation will dump user information, system variables, etc.
     */
    public $context = true;
    /**
     * @var callable Callback function that can modify extra's array
     */
    public $extraCallback;
    /**
     * @var ClientInterface
     */
    protected $client;

    public $ignoreServerPort = false;

    /**
     * @inheritdoc
     */
    public function collect($messages, $final)
    {
        if (!isset($this->client)) {
            $this->initClient();
        }

        parent::collect($messages, $final);
    }

    private function initClient()
    {
        $clientBuilder = ClientBuilder::create(
            [
                'dsn' => $this->dsn,
                'integrations' => [
                    new RequestIntegration(
                        new Options(['send_default_pii' => true])
                    )
                ]
            ] + $this->clientOptions
        );
        $this->client = $clientBuilder->getClient();
        Hub::setCurrent(new Hub($this->client));var_dump(Hub::getCurrent());
    }

    /**
     * @inheritdoc
     */
    protected function getContextMessage()
    {
        return '';
    }

    /**
     * @inheritdoc
     */
    public function export()
    {
        foreach ($this->messages as $message) {
            /** @var $dataFromLogger mixed|string|\Throwable etc. */
            /** @var $level integer */
            /** @var $level integer */
            /** @var $category string */
            /** @var $timestamp float */
            /** @var $traces array (debug backtrace) */
            list($dataFromLogger, $level, $category, $timestamp, $traces) = $message;
            $dataToBeLogged = [
                'level' => static::getLevelName($level),
                'timestamp' => $timestamp,
                'tags' => ['category' => $category]
            ];

            $scope = $this->createScopeFromArray($dataToBeLogged);
            if ($dataFromLogger instanceof \Throwable) {
                $dataToBeLogged = $this->runExtraCallback($dataFromLogger, $dataToBeLogged);
                $this->client->captureException($dataFromLogger, $scope);
                continue;
            }

            if (is_array($dataFromLogger) && $dataFromLogger['msg'] instanceof \Throwable) {
                $dataToBeLogged = $this->runExtraCallback($dataFromLogger, $dataToBeLogged);
                $this->client->captureException($dataFromLogger['msg'], $scope);
                continue;
            }

            if (is_array($dataFromLogger)) {
                if (isset($dataFromLogger['msg'])) {
                    $dataToBeLogged['message'] = $dataFromLogger['msg'];
                    unset($dataFromLogger['msg']);
                }

                if (isset($dataFromLogger['tags'])) {
                    $dataToBeLogged['tags'] = ArrayHelper::merge($dataToBeLogged['tags'], $dataFromLogger['tags']);
                    unset($dataFromLogger['tags']);
                }

                $dataToBeLogged['extra'] = $dataFromLogger;
            } else {
                $dataToBeLogged['message'] = $dataFromLogger;
            }

            if ($this->context) {
                $dataToBeLogged['extra']['context'] = parent::getContextMessage();
            }

            $dataToBeLogged['extra']['traces'] = $traces;

            $dataToBeLogged = $this->runExtraCallback($dataFromLogger, $dataToBeLogged);
            $this->client->captureEvent($dataToBeLogged);
        }
    }

    /**
     * Calls the extra callback if it exists
     *
     * @param mixed $dataFromLogger
     * @param array $dataToBeLogged
     * @return array
     */
    public function runExtraCallback($dataFromLogger, $dataToBeLogged)
    {
        if (is_callable($this->extraCallback)) {
            $dataToBeLogged['extra'] = call_user_func($this->extraCallback, $dataFromLogger, $dataToBeLogged['extra'] ? $dataToBeLogged['extra'] : []);
        }

        return $dataToBeLogged;
    }

    /**
     * Returns the text display of the specified level for the Sentry.
     *
     * @param integer $level The message level, e.g. [[LEVEL_ERROR]], [[LEVEL_WARNING]].
     * @return string
     */
    public static function getLevelName($level)
    {
        static $levels = [
            Logger::LEVEL_ERROR => 'error',
            Logger::LEVEL_WARNING => 'warning',
            Logger::LEVEL_INFO => 'info',
            Logger::LEVEL_TRACE => 'debug',
            Logger::LEVEL_PROFILE_BEGIN => 'debug',
            Logger::LEVEL_PROFILE_END => 'debug',
        ];

        return isset($levels[$level]) ? $levels[$level] : 'error';
    }

    /**
     * @param array $scopeArray
     * @return Scope
     */
    private function createScopeFromArray(array $scopeArray)
    {
        $scope = new Scope();
        foreach ($scopeArray as $key => $val) {
            $scope->setExtra($key, $val);
        }
        return $scope;
    }
}
