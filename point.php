#!/usr/bin/env php
<?php

$isInteractive = false;
$dbPath = null;

function addCommand(callable $function, ?callable $validator, bool $availableInInteractive, string $help)
{
    return [
        'f' => $function,
        'v' => $validator,
        'i' => $availableInInteractive,
        'h' => $help,
    ];
}

$floatArgsValidator = static function (?string $x, ?string $y, ?string $z) {
    if ($x === null) {
        throw new InvalidArgumentException('X не должен быть NULL');
    }

    if ($y === null) {
        throw new InvalidArgumentException('Y не должен быть NULL');
    }

    if ($z === null) {
        throw new InvalidArgumentException('Z не должен быть NULL');
    }

    $floatX = (float)$x;
    $floatY = (float)$y;
    $floatZ = (float)$z;

    if ((string)$floatX !== $x) {
        throw new InvalidArgumentException("некорректное значение X: {$x}");
    }

    if ((string)$floatY !== $y) {
        throw new InvalidArgumentException("некорректное значение Y: {$y}");
    }

    if ((string)$floatZ !== $z) {
        throw new InvalidArgumentException("некорректное значение Z: {$z}");
    }
};

function makeKey(float $x, float $y, float $z)
{
    return "{$x}_{$y}_{$z}";
}

$commands = [
    'help' => addCommand(
        static function () {
            global $argv, $commands;

            echo <<<USAGE
Usage: {$argv[0]} PATH [COMMAND [ARG [ARG...]]]
PATH: путь к базе данных
COMMAND (только в аргументах при запуске):
USAGE;

            foreach ($commands as $command => $attributes) {
                echo "\t{$attributes['h']}" . PHP_EOL;
            }

            echo PHP_EOL . PHP_EOL;
        },
        null,
        false,
        'help: вывести справку'
    ),
    'listen' => addCommand(
        static function (string $socket) {
            global $isInteractive;

            $isInteractive = true;

            $errno = 0;
            $errstr = '';
            $sh = stream_socket_server("unix://$socket", $errno, $errstr);

            if ($errno !== 0) {
                throw new RuntimeException($errstr);
            }

            if ($sh === false) {
                throw new RuntimeException("не удалось создать сокет {$socket}");
            }

            pcntl_async_signals(true);
            pcntl_signal(SIGINT, static function () use ($sh, $socket) {
                socket_close($sh);
                fclose($sh);
                unlink($socket);
            });

            while ($conn = @stream_socket_accept($sh)) {
                $input = fgets($conn);

                [$command, $args] = parseInput($input);

                try {
                    $result = runCommand($command, $args);
                } catch (Throwable $e) {
                    $result = 'ERR:' . $e->getMessage();
                }

                fwrite($conn, $result . PHP_EOL);

                fclose($conn);
            }
        },
        static function (string $socket) {
            if (file_exists($socket)) {
                throw new RuntimeException("файл сокета {$socket} уже существует");
            }

            $dirName = dirname($socket);
            if (!file_exists($dirName) || !is_dir($dirName)) {
                throw new RuntimeException("родительская директория сокета {$socket} не существует");
            }

            if (!is_writable($dirName)) {
                throw new RuntimeException("родительская директория сокета {$socket} не доступна для записи");
            }
        },
        false,
        'listen SOCKET: запустить сервер для обработки команд'
    ),
    'chk' => addCommand(
        static function (float $x, float $y, float $z) {
            $db = readDb();

            $key = makeKey($x, $y, $z);

            return (int)isset($db[$key]);
        },
        $floatArgsValidator,
        true,
        'chk X Y Z: проверить, что точка существует'
    ),
    'set' => addCommand(
        static function (float $x, float $y, float $z) {
            $db = readDb();
            $key = makeKey($x, $y, $z);

            $db[$key] = [$x, $y, $z];

            syncDb($db);

            return 1;
        },
        $floatArgsValidator,
        true,
        'set X Y Z: добавить точку без проверки существования'
    ),
    'clr' => addCommand(
        static function (float $x, float $y, float $z) {
            $db = readDb();
            $key = makeKey($x, $y, $z);

            unset($db[$key]);

            syncDb($db);

            return 1;
        },
        $floatArgsValidator,
        true,
        'clr X Y Z: удалить точку без проверки существования'
    ),
    'add' => addCommand(
        static function (float $x, float $y, float $z) {
            $db = readDb();
            $key = makeKey($x, $y, $z);

            if (isset($db[$key])) {
                return 0;
            }

            $db[$key] = [$x, $y, $z];

            syncDb($db);

            return 1;
        },
        $floatArgsValidator,
        true,
        'add X Y Z: добавить точку только если её нет в БД'
    ),
    'del' => addCommand(
        static function (float $x, float $y, float $z) {
            $db = readDb();
            $key = makeKey($x, $y, $z);

            if (!isset($db[$key])) {
                return 0;
            }

            unset($db[$key]);

            syncDb($db);

            return 1;
        },
        $floatArgsValidator,
        true,
        'del X Y Z: удалить точку только если её нет в БД'
    ),
    'flush' => addCommand(
        static function () {
            syncDb([]);

            return 1;
        },
        null,
        true,
        'flush: очистить БД'
    ),
];

function runCommand(string $name, array $args = [])
{
    global $commands, $isInteractive;

    if (!isset($commands[$name])) {
        throw new RuntimeException("команда {$name} не найдена");
    }

    if ($isInteractive && $commands[$name]['i'] === false) {
        throw new RuntimeException("команда {$name} не доступна в интерактивном режиме");
    }

    if ($commands[$name]['v']) {
        $commands[$name]['v'](...$args);
    }

    return $commands[$name]['f'](...$args);
}

function parseInput(string $input): array
{
    $input = trim($input);
    $commandAndArgs = explode(' ', $input);
    $command = array_shift($commandAndArgs);

    return [strtolower($command), $commandAndArgs];
}

function runInteractive()
{
    global $isInteractive;

    $isInteractive = true;

    echo 'Welcome to interactive mode. Press Ctrl+D to exit' . PHP_EOL;

    while (true) {
        $line = readline('> ');
        if ($line === false) {
            echo PHP_EOL;
            break;
        }

        try {
            [$command, $args] = parseInput($line);

            echo runCommand($command, $args) . PHP_EOL;
        } catch (Throwable $e) {
            die('ERR: ' . $e->getMessage() . PHP_EOL);
        }
    }
}

function readDb(): array
{
    global $dbPath;

    if (!file_exists($dbPath)) {
        return [];
    }

    if (!is_file($dbPath)) {
        throw new RuntimeException("{$dbPath} не указывает на файл");
    }

    if (!is_readable($dbPath)) {
        throw new RuntimeException("файл {$dbPath} недоступен для чтения");
    }

    if (filesize($dbPath) > 0) {
        $fh = fopen($dbPath, 'rb');

        if ($fh === false) {
            throw new RuntimeException("ошибка при открытии файла {$dbPath} на чтение");
        }

        if (!flock($fh, LOCK_SH)) {
            throw new RuntimeException("ошибка при запросе shared-lock для файла {$dbPath}");
        }

        if (!rewind($fh)) {
            throw new RuntimeException("ошибка при перемотке указателя файла {$dbPath}");
        }

        $rawDb = '';
        while (!feof($fh)) {
            $rawDb .= fread($fh, 1024);
        }

        flock($fh, LOCK_UN);
        fclose($fh);

        if ($rawDb === false) {
            throw new RuntimeException("ошибка при чтении файла {$dbPath}");
        }

        $db = unserialize($rawDb, ['allowed_classes' => false]);
    } else {
        $db = [];
    }

    if (!is_array($db)) {
        throw new RuntimeException("файл {$dbPath} поврежден и не является валидным файлом БД");
    }

    return $db;
}

function syncDb(array $db)
{
    global $dbPath;

    $fh = fopen($dbPath, 'wb');
    if ($fh === false) {
        throw new RuntimeException("ошибка при открытии файла {$dbPath} на чтение");
    }

    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        throw new RuntimeException("ошибка при запросе exclusive-lock для файла {$dbPath}");
    }

    if (fwrite($fh, serialize($db)) === false) {
        flock($fh, LOCK_UN);
        fclose($fh);
        throw new RuntimeException("ошибка записи в файл {$dbPath}");
    }
}

function main()
{
    global $argc, $argv, $dbPath;

    if ($argc < 2) {
        runCommand('help');

        return;
    }

    $dbPath = $argv[1];

    if ($argc === 2) {
        runInteractive();
    } else {
        $input = $argv;
        array_shift($input);
        array_shift($input);

        [$command, $args] = parseInput(implode(' ', $input));

        try {
            echo runCommand($command, $args) . PHP_EOL;
        } catch (Throwable $e) {
            echo 'ERR: ' . $e->getMessage() . PHP_EOL;
        }
    }
}

main();
