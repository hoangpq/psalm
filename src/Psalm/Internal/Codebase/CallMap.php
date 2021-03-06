<?php
namespace Psalm\Internal\Codebase;

use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Type;
use Psalm\Storage\FunctionLikeParameter;

/**
 * @internal
 *
 * Gets values from the call map array, which stores data about native functions and methods
 */
class CallMap
{
    const PHP_MAJOR_VERSION = 7;
    const PHP_MINOR_VERSION = 3;

    /**
     * @var ?int
     */
    private static $loaded_php_major_version = null;
    /**
     * @var ?int
     */
    private static $loaded_php_minor_version = null;

    /**
     * @var array<array<string,string>>|null
     */
    private static $call_map = null;

    /**
     * @param  string $function_id
     *
     * @return array|null
     * @psalm-return array<int, array<int, FunctionLikeParameter>>|null
     */
    public static function getParamsFromCallMap($function_id)
    {
        $call_map = self::getCallMap();

        $call_map_key = strtolower($function_id);

        if (!isset($call_map[$call_map_key])) {
            return null;
        }

        $call_map_functions = [];
        $call_map_functions[] = $call_map[$call_map_key];

        for ($i = 1; $i < 10; ++$i) {
            if (!isset($call_map[$call_map_key . '\'' . $i])) {
                break;
            }

            $call_map_functions[] = $call_map[$call_map_key . '\'' . $i];
        }

        $function_type_options = [];

        foreach ($call_map_functions as $call_map_function_args) {
            array_shift($call_map_function_args);

            $function_types = [];

            /** @var string $arg_name - key type changed with above array_shift */
            foreach ($call_map_function_args as $arg_name => $arg_type) {
                $by_reference = false;
                $optional = false;
                $variadic = false;

                if ($arg_name[0] === '&') {
                    $arg_name = substr($arg_name, 1);
                    $by_reference = true;
                }

                if (substr($arg_name, -1) === '=') {
                    $arg_name = substr($arg_name, 0, -1);
                    $optional = true;
                }

                if (substr($arg_name, 0, 3) === '...') {
                    $arg_name = substr($arg_name, 3);
                    $variadic = true;
                }

                $param_type = $arg_type
                    ? Type::parseString($arg_type)
                    : Type::getMixed();

                $function_types[] = new FunctionLikeParameter(
                    $arg_name,
                    $by_reference,
                    $param_type,
                    null,
                    null,
                    $optional,
                    false,
                    $variadic
                );
            }

            $function_type_options[] = $function_types;
        }

        return $function_type_options;
    }

    /**
     * @param  string  $function_id
     *
     * @return Type\Union
     */
    public static function getReturnTypeFromCallMap($function_id)
    {
        $call_map_key = strtolower($function_id);

        $call_map = self::getCallMap();

        if (!isset($call_map[$call_map_key])) {
            throw new \InvalidArgumentException('Function ' . $function_id . ' was not found in callmap');
        }

        if (!$call_map[$call_map_key][0]) {
            return Type::getMixed();
        }

        $call_map_type = Type::parseString($call_map[$call_map_key][0]);

        if ($call_map_type->isNullable()) {
            $call_map_type->from_docblock = true;
        }

        return $call_map_type;
    }

    /**
     * Gets the method/function call map
     *
     * @return array<string, array<int|string, string>>
     * @psalm-suppress MixedInferredReturnType as the use of require buggers things up
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedTypeCoercion
     * @psalm-suppress MixedReturnStatement
     */
    public static function getCallMap()
    {
        $codebase = ProjectAnalyzer::getInstance()->getCodebase();
        $analyzer_major_version = $codebase->php_major_version;
        $analyzer_minor_version = $codebase->php_minor_version;

        if (self::$call_map !== null
            && $analyzer_major_version === self::$loaded_php_major_version
            && $analyzer_minor_version === self::$loaded_php_minor_version
        ) {
            return self::$call_map;
        }

        /** @var array<string, array<int|string, string>> */
        $call_map = require(__DIR__ . '/../CallMap.php');

        self::$call_map = [];

        foreach ($call_map as $key => $value) {
            $cased_key = strtolower($key);
            self::$call_map[$cased_key] = $value;
        }

        if ($analyzer_minor_version < self::PHP_MINOR_VERSION) {
            for ($i = self::PHP_MINOR_VERSION; $i > $analyzer_minor_version; $i--) {
                /**
                 * @var array{
                 *     old: array<string, array<int|string, string>>,
                 *     new: array<string, array<int|string, string>>
                 * }
                 * @psalm-suppress UnresolvableInclude
                 */
                $diff_call_map = require(__DIR__ . '/../CallMap_7' . $i . '_delta.php');

                foreach ($diff_call_map['new'] as $key => $_) {
                    $cased_key = strtolower($key);
                    unset(self::$call_map[$cased_key]);
                }

                foreach ($diff_call_map['old'] as $key => $value) {
                    $cased_key = strtolower($key);
                    self::$call_map[$cased_key] = $value;
                }
            }
        }

        self::$loaded_php_major_version = $analyzer_major_version;
        self::$loaded_php_minor_version = $analyzer_minor_version;

        return self::$call_map;
    }

    /**
     * @param   string $key
     *
     * @return  bool
     */
    public static function inCallMap($key)
    {
        return isset(self::getCallMap()[strtolower($key)]);
    }
}
