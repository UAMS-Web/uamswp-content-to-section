# Filters

Written against v1.1.0.

Four filters change what the plugin scans and how it converts. Add them from a theme or a must-use plugin, so they are in place before a conversion runs.

| Filter | Default | Purpose |
|---|---|---|
| `uams_c2s_default_args` | see below | Change the default conversion options. |
| `uams_c2s_field_pairs` | three pairs | Change the Content field to Section field mapping. |
| `uams_c2s_wysiwyg_field` | `content_content` | Point at a different body field. |
| `uams_c2s_statuses` | five statuses | Change which post statuses are scanned. |

## uams_c2s_default_args

Receives the default arguments: `include_geo` (`false`), `demote_headings` (`false`), `max_heading` (`2`) and `list_format` (`v3`). Options saved on the Tools screen and flags passed on the command line still override what this returns. See [Conversion options](Conversion-options.md).

```php
add_filter('uams_c2s_default_args', function (array $defaults): array {
    $defaults['demote_headings'] = true;

    return $defaults;
});
```

## uams_c2s_field_pairs

Receives Content field name to Section field name pairs:

```php
[
    'content_heading' => 'section_heading',
    'content_hide_heading' => 'section_hide_heading',
    'content_background_color' => 'section_background_color',
]
```

## uams_c2s_wysiwyg_field

Receives the name of the Content Block field whose HTML becomes the inner blocks, `content_content` by default.

## uams_c2s_statuses

Receives the post statuses the scan covers: `publish`, `draft`, `pending`, `future` and `private`. A status requested on the Tools screen or with `--post_status` can only narrow this list, never widen it.

```php
add_filter('uams_c2s_statuses', fn (array $statuses): array => ['draft']);
```
