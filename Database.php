<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{

    const PATTERN = '/\?(?:a|f|d|#)?|\{.*?\}/';
    const SMALL_PATTERN = '/\?(?:a|f|d|#)?/';

    const SKIP_MESSAGE = 'Skip variative block by method';
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = []): string
    {
        $matchIndex = -1;
        $query =  preg_replace_callback(
            self::PATTERN,
            function (array $matches) use ($args, &$matchIndex) {
                $matchIndex++;
                if (!isset($args[$matchIndex])) {
                    throw new Exception('Не передано значение для модификатора №' . ($matchIndex + 1));
                }
                return $this->replace($matches[0], $args[$matchIndex]);
            },
            $query
        );

        return $query;
    }

    public function skip(): string
    {
        return self::SKIP_MESSAGE;
    }

    private function replace(string $modificator, mixed $value): mixed
    {

        if (mb_substr($modificator, 0, 1) == '{') {
            // Условный блок
            $skipBlock = false;
            $blockString = preg_replace_callback(
                self::SMALL_PATTERN,
                function (array $matches) use ($value, &$skipBlock) {
                    $result = '';
                    if ($value === self::SKIP_MESSAGE) {
                        $skipBlock = true;
                    } else {
                        $result = $this->replace($matches[0], $value);
                    }
                    return $result;
                },
                mb_substr($modificator, 1, -1)
            );

            return $skipBlock ? '' : $blockString;

        }

        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }

        return match ($modificator) {
            '?d' => $this->toInteger($value),
            '?f' => $this->toFloat($value),
            '?a' => $this->toArray($value),
            '?#' => $this->toColumns($value),
            default => $this->toBase($value)
        };

    }

    private function toBase(mixed $value): float|int|string
    {

        if (is_null($value)) {
            return 'NULL';
        }
        if (is_string($value)) {
            return "'{$this->mysqli->escape_string($value)}'";
        }
        if (is_integer($value)) {
            return $this->toInteger($value);
        }
        if (is_float($value)) {
            return $this->toFloat($value);
        }

        throw new Exception('Неверный тип данных');
    }

    private function toInteger(mixed $value): int
    {
        if (is_null($value)) {
            return 'NULL';
        }
        if (!is_integer($value)) {
            throw new Exception('Модификатор ?d ожидает только целые числа');
        }
        return $value;
    }

    private function toFloat(mixed $value): float
    {
        if (is_null($value)) {
            return 'NULL';
        }
        if (!is_float($value)) {
            throw new Exception('Модификатор ?f ожидает только числа с плавающей точкой');
        }
        return $value;
    }

    private function toArray(mixed $value): string
    {
        if (!is_array($value)) {
            throw new Exception('Модификатор ?a ожидает только массив значений');
        }
        if (array_is_list($value)) {
            $list = array_map(function ($item) {
                return $this->toBase($item);
            }, $value);
        } else {
            $list = array_map(function ($key, $item) {
                return $this->toColumns($key) . ' = ' . $this->toBase($item);
            }, array_keys($value), array_values($value));
        }

        return implode(', ', $list);
    }

    private function toColumns(mixed $value): string
    {

        if (is_array($value)) {
            if (!array_is_list($value)) {
                throw new Exception('Модификатор ?# не должен быть ассоциативным массивом');
            }
            $value = array_map(function ($item) {
                if (!is_string($item)) {
                    throw new Exception('Модификатор ?# должен содержать строку или массив строк');
                }
                return "`{$this->mysqli->escape_string($item)}`";
            }, $value);
            return implode(', ', $value);
        }

        if (!is_string($value)) {
            throw new Exception('Модификатор ?# должен содержать строку или массив строк');
        }

        return "`{$this->mysqli->escape_string($value)}`";
    }

}
