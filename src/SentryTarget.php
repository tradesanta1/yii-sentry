<?php

namespace yii2sentry2\sentry;

use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Sentry\Context\Context;
use Sentry\Context\TagsContext;
use Sentry\Severity;
use Sentry\State\Scope;
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
        $clientBuilder = ClientBuilder::create(['dsn' => $this->dsn] + $this->clientOptions);
        $this->client = $clientBuilder->getClient();
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

            if ($dataFromLogger instanceof \Throwable) {
                $dataToBeLogged = $this->runExtraCallback($dataFromLogger, $dataToBeLogged);
                $this->client->captureException($dataFromLogger, $dataToBeLogged);
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

    private function isHttpRequest()
    {
        return isset($_SERVER['REQUEST_METHOD']) && PHP_SAPI !== 'cli';
    }

    private function getHttpRequestData()
    {
        $headers = array();

        foreach ($_SERVER as $key => $value) {
            if (0 === strpos($key, 'HTTP_')) {
                $header_key =
                    str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$header_key] = $value;
            } elseif (in_array($key, array('CONTENT_TYPE', 'CONTENT_LENGTH')) && $value !== '') {
                $header_key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $key))));
                $headers[$header_key] = $value;
            }
        }

        $result = array(
            'method' => self::_server_variable('REQUEST_METHOD'),
            'url' => $this->get_current_url(),
            'query_string' => self::_server_variable('QUERY_STRING'),
        );

        // dont set this as an empty array as PHP will treat it as a numeric array
        // instead of a mapping which goes against the defined Sentry spec
        if (!empty($_POST)) {
            $result['data'] = $_POST;
        } elseif (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') === 0) {
            $raw_data = $this->getInputStream() ?: false;
            if ($raw_data !== false) {
                $result['data'] = (array) json_decode($raw_data, true) ?: null;
            }
        }
        if (!empty($_COOKIE)) {
            $result['cookies'] = $_COOKIE;
        }
        if (!empty($headers)) {
            $result['headers'] = $headers;
        }

        return $result;
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
     * @param Scope $data
     * @param string $tags
     * @throws \InvalidArgumentException
     */
    private function updateTagsInScope(Scope $data, array $tags)
    {
        $getTags = function () {
            $this->tags;
        };

        /** @var TagsContext $t */
        $t = $getTags->call($data);
        if ($t === null) $t = new TagsContext();
        $t->merge($tags);
    }
}
