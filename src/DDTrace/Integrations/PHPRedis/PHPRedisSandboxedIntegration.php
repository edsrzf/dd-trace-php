<?php

namespace DDTrace\Integrations\PHPRedis;

use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

class PHPRedisSandboxedIntegration extends SandboxedIntegration
{
    const NAME = 'phpredis';

    const NOT_SET = '__DD_NOT_SET__';
    const CMD_MAX_LEN = 1000;
    const VALUE_TOO_LONG_MARK = '...';
    const VALUE_MAX_LEN = 100;
    const VALUE_PLACEHOLDER = "?";

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Add instrumentation to PDO requests
     */
    public function init()
    {
        $integration = $this;

        $traceConnectOpen = function (SpanData $span, $args) {
            PHPRedisSandboxedIntegration::enrichSpan($span);
            $span->meta[Tag::TARGET_HOST] = (isset($args[0]) && \is_string($args[0])) ? $args[0] : '127.0.0.1';
            $span->meta[Tag::TARGET_PORT] = (isset($args[1]) && \is_numeric($args[1])) ? $args[1] : 6379;
        };
        \DDTrace\trace_method('Redis', 'connect', $traceConnectOpen);
        \DDTrace\trace_method('Redis', 'pconnect', $traceConnectOpen);
        \DDTrace\trace_method('Redis', 'open', $traceConnectOpen);
        \DDTrace\trace_method('Redis', 'popen', $traceConnectOpen);

        self::obfuscableSimpleMethod('close');
        self::obfuscableSimpleMethod('auth');
        self::obfuscableSimpleMethod('ping');
        self::obfuscableSimpleMethod('echo');
        self::obfuscableSimpleMethod('bgRewriteAOF');
        self::obfuscableSimpleMethod('bgSave');
        self::obfuscableSimpleMethod('flushAll');
        self::obfuscableSimpleMethod('flushDb');
        self::obfuscableSimpleMethod('save');

        \DDTrace\trace_method('Redis', 'select', function (SpanData $span, $args) {
            PHPRedisSandboxedIntegration::enrichSpan($span);
            if (isset($args[0]) && \is_numeric($args[0])) {
                $span->meta['db.index'] = $args[0];
            }
        });

        // Obfuscable methods: see https://github.com/DataDog/datadog-agent/blob/master/pkg/trace/obfuscate/redis.go
        self::obfuscableKeyValuesMethod('append');
        self::obfuscableKeyValuesMethod('decr');
        self::obfuscableKeyValuesMethod('decrBy');
        self::obfuscableKeyValuesMethod('get');
        self::obfuscableKeyValuesMethod('getBit');
        self::obfuscableKeyValuesMethod('getRange');
        self::obfuscableKeyValuesMethod('getSet');
        self::obfuscableKeyValuesMethod('incr');
        self::obfuscableKeyValuesMethod('incrBy');
        self::obfuscableKeyValuesMethod('incrByFloat');
        self::obfuscableKeyValuesMethod('set');
        self::obfuscableKeyValuesMethod('setBit');
        self::obfuscableKeyValuesMethod('setEx');
        self::obfuscableKeyValuesMethod('pSetEx');
        self::obfuscableKeyValuesMethod('setNx');
        self::obfuscableKeyValuesMethod('setRange');
        self::obfuscableKeyValuesMethod('strLen');

        return SandboxedIntegration::LOADED;
    }

    public static function enrichSpan(SpanData $span, $method = null)
    {
        $span->service = 'phpredis';
        $span->type = Type::CACHE;
        if (null !== $method) {
            // method names for internal functions are lowered so we need to explitly set them if we want to have the
            // proper case.
            $span->name = $span->resource = "Redis.$method";
        }
    }

    public static function obfuscableSimpleMethod($method)
    {
        \DDTrace\trace_method('Redis', $method, function (SpanData $span, $args) use ($method) {
            PHPRedisSandboxedIntegration::enrichSpan($span, $method);
        });
    }

    public static function obfuscableKeyValuesMethod($method)
    {
        \DDTrace\trace_method('Redis', $method, function (SpanData $span, $args) use ($method) {
            PHPRedisSandboxedIntegration::enrichSpan($span, $method);
            $span->meta[Tag::REDIS_RAW_COMMAND] = PHPRedisSandboxedIntegration::obfuscateArgs($method, $args);
        });
    }

    public static function obfuscateArgs($command, $args)
    {
        $rawCommandParts = [ $command ];

        // Based on logic in pyhton tracer:
        // https://github.com/DataDog/dd-trace-py/blob/0d7e7cb38216acb0c8b29f0ae1318d25bc160123/ddtrace/contrib/redis/util.py#L25
        $totalArgsLength = 0;
        foreach ($args as $arg) {
            if ($totalArgsLength > self::CMD_MAX_LEN) {
                break;
            }

            $partValue = null;

            if (\is_string($arg)) {
                $partValue = $arg;
            } elseif (\is_numeric($arg)) {
                $partValue = (string)$arg;
            } elseif (\is_null($arg)) {
                $partValue = 'null';
            } else {
                $rawCommandParts[] = self::VALUE_PLACEHOLDER;
                continue;
            }

            $len = strlen($partValue);
            if ($len > self::VALUE_MAX_LEN) {
                $partValue = substr($partValue, 0, self::VALUE_MAX_LEN) . self::VALUE_TOO_LONG_MARK;
            }
            if ($totalArgsLength + $len > self::CMD_MAX_LEN) {
                $partValue = substr($partValue, 0, self::CMD_MAX_LEN) . self::VALUE_TOO_LONG_MARK;
            }

            $rawCommandParts[] = $partValue;
            $totalArgsLength += strlen($partValue);
        }

        return \implode(' ', $rawCommandParts);
    }
}
