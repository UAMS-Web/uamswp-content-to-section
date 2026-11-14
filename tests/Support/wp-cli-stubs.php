<?php

/**
 * Stand-ins for the WP-CLI surface the plugin's commands call.
 *
 * Only the Unit bootstrap loads this file. WP_CLI the constant stays undefined in
 * the Unit suite (the main file registers its command only when it is set), so
 * these declare the class and the three helper functions without switching WP-CLI on.
 * Each call is recorded in WP_CLI::$calls, in order, for a test to read.
 */

declare(strict_types=1);

namespace {
    /**
     * Raised in place of the process exit WP_CLI::error() performs.
     */
    class UAMS_C2S_Test_CLI_Halt extends RuntimeException {}

    class WP_CLI
    {
        /** @var list<array{string,mixed}> Calls in order: method name, argument. */
        public static array $calls = [];

        public static function reset(): void
        {
            self::$calls = [];
        }

        /** @return list<string> The argument of every call to one method. */
        public static function messages(string $method): array
        {
            return array_values(array_map(
                static fn (array $call): string => (string) $call[1],
                array_filter(self::$calls, static fn (array $call): bool => $call[0] === $method)
            ));
        }

        public static function line(mixed $text = ''): void
        {
            self::$calls[] = ['line', $text];
        }

        public static function log(string $text): void
        {
            self::$calls[] = ['log', $text];
        }

        public static function success(string $text): void
        {
            self::$calls[] = ['success', $text];
        }

        public static function warning(string $text): void
        {
            self::$calls[] = ['warning', $text];
        }

        public static function error(string $text): never
        {
            self::$calls[] = ['error', $text];

            throw new UAMS_C2S_Test_CLI_Halt($text);
        }
    }
}

namespace WP_CLI\Utils {
    use cli\progress\Bar;

    function get_flag_value(array $assoc_args, string $flag, mixed $default = null): mixed
    {
        return $assoc_args[$flag] ?? $default;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @param  list<string>  $fields
     */
    function format_items(string $format, array $items, array $fields): void
    {
        \WP_CLI::$calls[] = ['format_items', [$format, $items, $fields]];
    }

    function make_progress_bar(string $message, int $count): object
    {
        $bar = new Bar($message, $count);

        \WP_CLI::$calls[] = ['progress_bar', $bar];

        return $bar;
    }
}

namespace cli\progress {
    /**
     * Records what a progress bar is asked to do. The plugin ticks and finishes
     * only a real bar (WP-CLI hands back a no-op object when output is quiet),
     * so the test double has to be one.
     */
    class Bar
    {
        public int $ticks = 0;

        public bool $finished = false;

        public function __construct(public string $message, public int $count) {}

        public function tick(): void
        {
            $this->ticks++;
        }

        public function finish(): void
        {
            $this->finished = true;
        }
    }
}
