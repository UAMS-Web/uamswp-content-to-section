<?php

/**
 * Minimal stand-ins for the WordPress classes the Unit suite's subjects name.
 *
 * Only the Unit bootstrap loads this file: the Integration suite runs against
 * the real classes. Each stub carries just the members the plugin reads.
 */

declare(strict_types=1);

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            public string $code = '',
            public string $message = '',
            public mixed $data = ''
        ) {}

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (! class_exists('WP_Post')) {
    class WP_Post
    {
        /** @var int|string WordPress documents an int; a numeric string has shown up. */
        public $ID = 0;

        public string $post_content = '';

        public string $post_type = 'post';

        /** @var int|string */
        public $post_parent = 0;

        public string $post_date_gmt = '0000-00-00 00:00:00';

        public string $post_status = 'publish';

        /**
         * @param  array<string,mixed>  $fields  Property values by name.
         */
        public function __construct(array $fields = [])
        {
            foreach ($fields as $name => $value) {
                $this->{$name} = $value;
            }
        }
    }
}
