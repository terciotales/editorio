<?php

declare(strict_types=1);

namespace Editorio\Common;

final class Assets
{
    /**
     * @var array<string,array{script:string,asset:string,style?:string}>
     */
    private const ENTRIES = [
        'editorio-app' => [
            'script' => 'bundle/js/index.js',
            'asset' => 'bundle/js/index.asset.php',
            'style' => 'bundle/css/index.css',
        ],
        'editorio-sources' => [
            'script' => 'bundle/modules/sources/index.js',
            'asset' => 'bundle/modules/sources/index.asset.php',
            'style' => 'bundle/modules/sources/index.css',
        ],
        'editorio-draft' => [
            'script' => 'bundle/modules/draft/index.js',
            'asset' => 'bundle/modules/draft/index.asset.php',
            'style' => 'bundle/modules/draft/index.css',
        ],
        'editorio-review' => [
            'script' => 'bundle/modules/review/index.js',
            'asset' => 'bundle/modules/review/index.asset.php',
            'style' => 'bundle/modules/review/index.css',
        ],
    ];

    public function register_hooks(): void
    {
        add_action('init', [$this, 'register']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor']);
    }

    public function register(): void
    {
        foreach (self::ENTRIES as $handle => $entry) {
            $this->register_entry($handle, $entry);
        }
    }

    public function enqueue_admin(): void
    {
        $this->enqueue_registered_entries();
    }

    public function enqueue_editor(): void
    {
        $this->enqueue_registered_entries();
    }

    /**
     * @param array{script:string,asset:string,style?:string} $entry
     */
    private function register_entry(string $handle, array $entry): void
    {
        $asset = $this->read_asset_metadata($entry['asset']);
        $script_file_path = EDITORIO_PLUGIN_PATH . $entry['script'];

        if (file_exists($script_file_path)) {
            wp_register_script(
                $handle,
                EDITORIO_PLUGIN_URL . $entry['script'],
                $asset['dependencies'],
                $asset['version'],
                true
            );
        }

        if (! isset($entry['style'])) {
            return;
        }

        $style_file_path = EDITORIO_PLUGIN_PATH . $entry['style'];
        if (! file_exists($style_file_path)) {
            return;
        }

        wp_register_style(
            $handle,
            EDITORIO_PLUGIN_URL . $entry['style'],
            [],
            $asset['version']
        );
    }

    /**
     * @return array{dependencies:array<int,string>,version:string}
     */
    private function read_asset_metadata(string $asset_relative_path): array
    {
        $asset = [
            'dependencies' => [],
            'version' => EDITORIO_VERSION,
        ];

        $asset_file_path = EDITORIO_PLUGIN_PATH . $asset_relative_path;
        if (! file_exists($asset_file_path)) {
            return $asset;
        }

        $asset_data = require $asset_file_path;
        if (is_array($asset_data)) {
            $asset = array_merge($asset, $asset_data);
        }

        return $asset;
    }

    private function enqueue_registered_entries(): void
    {
        foreach (array_keys(self::ENTRIES) as $handle) {
            if (wp_script_is($handle, 'registered')) {
                wp_enqueue_script($handle);
            }

            if (wp_style_is($handle, 'registered')) {
                wp_enqueue_style($handle);
            }
        }
    }
}
