<?php
/**
 * Presets Storage
 *
 * Stores and retrieves query presets from a JSON file
 */

class Presets
{
    private string $filePath;
    private array $presets;

    public function __construct(string $dataDir = '/var/www/html/data')
    {
        $this->filePath = rtrim($dataDir, '/') . '/presets.json';
        $this->load();
    }

    /**
     * Load presets from file
     */
    private function load(): void
    {
        if (file_exists($this->filePath)) {
            $content = file_get_contents($this->filePath);
            $this->presets = json_decode($content, true) ?? [];
        } else {
            // Initialize with default presets
            $this->presets = $this->getDefaultPresets();
            $this->save();
        }
    }

    /**
     * Save presets to file
     */
    private function save(): bool
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents(
            $this->filePath,
            json_encode($this->presets, JSON_PRETTY_PRINT)
        ) !== false;
    }

    /**
     * Get default presets
     * Note: Product status (free/trial/premium) is now in SQLite, not Listmonk attributes.
     * For product targeting, use Listmonk's list filter when creating campaigns.
     */
    private function getDefaultPresets(): array
    {
        return [
            [
                'id' => 'marketing-opted-in',
                'name' => 'All Marketing Opted-In',
                'query' => "subscribers.attribs->>'marketing_allowed' = 'true'",
                'conditions' => [
                    ['attribute' => 'marketing_allowed', 'operator' => '=', 'value' => 'true', 'conjunction' => 'AND']
                ]
            ],
            [
                'id' => 'marketing-not-set',
                'name' => 'Marketing Not Set',
                'query' => "(subscribers.attribs->>'marketing_allowed' IS NULL OR subscribers.attribs->>'marketing_allowed' = '')",
                'conditions' => [
                    ['attribute' => 'marketing_allowed', 'operator' => 'IS NULL', 'value' => '', 'conjunction' => 'AND']
                ]
            ],
            [
                'id' => 'has-country',
                'name' => 'Has Country Set',
                'query' => "(subscribers.attribs->>'country' IS NOT NULL AND subscribers.attribs->>'country' != '')",
                'conditions' => [
                    ['attribute' => 'country', 'operator' => 'IS NOT NULL', 'value' => '', 'conjunction' => 'AND']
                ]
            ],
        ];
    }

    /**
     * Get all presets
     */
    public function getAll(): array
    {
        return $this->presets;
    }

    /**
     * Get a preset by ID
     */
    public function get(string $id): ?array
    {
        foreach ($this->presets as $preset) {
            if ($preset['id'] === $id) {
                return $preset;
            }
        }
        return null;
    }

    /**
     * Add or update a preset
     */
    public function save_preset(string $name, string $query, array $conditions = []): array
    {
        $id = $this->generateId($name);

        // Check if preset with same ID exists
        $existingIndex = null;
        foreach ($this->presets as $index => $preset) {
            if ($preset['id'] === $id) {
                $existingIndex = $index;
                break;
            }
        }

        $preset = [
            'id' => $id,
            'name' => $name,
            'query' => $query,
            'conditions' => $conditions
        ];

        if ($existingIndex !== null) {
            $this->presets[$existingIndex] = $preset;
        } else {
            $this->presets[] = $preset;
        }

        $this->save();
        return $preset;
    }

    /**
     * Delete a preset by ID
     */
    public function delete(string $id): bool
    {
        foreach ($this->presets as $index => $preset) {
            if ($preset['id'] === $id) {
                array_splice($this->presets, $index, 1);
                return $this->save();
            }
        }
        return false;
    }

    /**
     * Generate a URL-friendly ID from a name
     */
    private function generateId(string $name): string
    {
        $id = strtolower($name);
        $id = preg_replace('/[^a-z0-9]+/', '-', $id);
        $id = trim($id, '-');
        return $id;
    }

    /**
     * Reset to default presets
     */
    public function resetToDefaults(): void
    {
        $this->presets = $this->getDefaultPresets();
        $this->save();
    }
}
