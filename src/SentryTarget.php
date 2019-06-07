<?php

namespace yii2sentry2\sentry;

use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Sentry\Context\UserContext;
use Sentry\Integration\RequestIntegration;
use Sentry\Options;
use Sentry\Severity;
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
        Hub::setCurrent(new Hub($this->client));
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
            $scope = new Scope();
            $scope->setLevel($this->getSeveretyFromYiiLoggerLevel($level));
            $scope->setExtra('DateTime', date('Y-m-d H:i:s', $timestamp));
            $scope->setTag('category', $category);

            if ($dataFromLogger instanceof \Throwable) {
                $this->client->captureException($dataFromLogger, $scope);
                continue;
            }

            if (is_array($dataFromLogger) && $dataFromLogger['msg'] instanceof \Throwable) {
                $exception = $dataFromLogger['msg'];
                unset($dataFromLogger['msg']);
                foreach ($dataFromLogger as $key => $extraData) {
                    $scope->setExtra($key, $extraData);
                }
                $this->client->captureException($exception, $scope);
                continue;
            }

            $dataToBeLogged = [];

            if (is_array($dataFromLogger)) {
                if (isset($dataFromLogger['msg'])) {
                    $dataToBeLogged['message'] = $dataFromLogger['msg'];
                    unset($dataFromLogger['msg']);
                }

                if (isset($dataFromLogger['tags'])) {
                    $oldTags = [];
                    if(isset($dataToBeLogged['tags'])) {
                        $oldTags = $dataToBeLogged['tags'];
                    }

                    $dataToBeLogged['tags'] = ArrayHelper::merge($oldTags, $dataFromLogger['tags']);
                    unset($dataFromLogger['tags']);
                }

                foreach ($dataFromLogger as $key => $value) {
                    $scope->setExtra($key, $value);
                }
            } else {
                $dataToBeLogged['message'] = $dataFromLogger;
            }

            if ($this->context) {
                $scope->setExtra('context', parent::getContextMessage());
            }

            $userContext = new UserContext();

            try {
                $userContext->setId(\Yii::$app->user->id);
            } catch (\Throwable $t) {}

            $scope->setUser($userContext->toArray());

            $dataToBeLogged['extra']['traces'] = $traces;

            $this->client->captureEvent($dataToBeLogged, $scope);
        }
    }

    /**
     * @param $level
     * @return Severity
     * @throws \InvalidArgumentException
     */
    private function getSeveretyFromYiiLoggerLevel($level)
    {
        return new Severity(static::getLevelName($level));
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
}
