<?php
/**
 * Sequence Manager
 *
 * Handles sequence configuration lookup and progression logic.
 */

require_once __DIR__ . '/../shared/SequenceDatabase.php';

class SequenceManager
{
    private array $sequences;
    private array $pluginIds;
    private array $pluginListIds;

    public function __construct(array $sequences, array $pluginListIds = [])
    {
        $this->sequences = $sequences;
        $this->pluginIds = array_keys($sequences);
        $this->pluginListIds = $pluginListIds;
    }

    /**
     * Create a SequenceManager by loading configuration from SQLite database
     */
    public static function fromDatabase(string $dataDir = '/data'): self
    {
        $db = new SequenceDatabase($dataDir);
        $sequences = $db->getSequencesAsConfig();
        $pluginListIds = $db->getProductListIds();
        return new self($sequences, $pluginListIds);
    }

    /**
     * Get list_id for a plugin
     */
    public function getListId(string $pluginId): ?int
    {
        return $this->pluginListIds[$pluginId] ?? null;
    }

    /**
     * Get all plugin list_id mappings
     */
    public function getPluginListIds(): array
    {
        return $this->pluginListIds;
    }

    /**
     * Get all configured plugin IDs
     */
    public function getPluginIds(): array
    {
        return $this->pluginIds;
    }

    /**
     * Get a sequence for a plugin and type
     */
    public function getSequence(string $pluginId, string $type): ?array
    {
        return $this->sequences[$pluginId][$type] ?? null;
    }

    /**
     * Get a specific step by stage name
     */
    public function getStep(string $pluginId, string $stage): ?array
    {
        // Determine type from stage (FA-14: pass pluginId for efficient lookup)
        $type = $this->getTypeFromStage($stage, $pluginId);
        if (!$type) {
            return null;
        }

        $sequence = $this->getSequence($pluginId, $type);
        if (!$sequence) {
            return null;
        }

        foreach ($sequence as $step) {
            if ($step['stage'] === $stage) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Get the next step after the current stage
     */
    public function getNextStep(string $pluginId, string $stage): ?array
    {
        // FA-14: pass pluginId for efficient lookup
        $type = $this->getTypeFromStage($stage, $pluginId);
        if (!$type) {
            return null;
        }

        $sequence = $this->getSequence($pluginId, $type);
        if (!$sequence) {
            return null;
        }

        $foundCurrent = false;
        foreach ($sequence as $step) {
            if ($foundCurrent) {
                return $step;
            }
            if ($step['stage'] === $stage) {
                $foundCurrent = true;
            }
        }

        return null; // No more steps - sequence complete
    }

    /**
     * Get the first step of a sequence type
     */
    public function getFirstStep(string $pluginId, string $type): ?array
    {
        $sequence = $this->getSequence($pluginId, $type);
        return $sequence[0] ?? null;
    }

    /**
     * Determine sequence type from stage name (FA-14: dynamic type detection)
     *
     * Searches all configured sequences to find which type contains the given stage.
     * Falls back to prefix matching for backward compatibility.
     */
    public function getTypeFromStage(string $stage, ?string $pluginId = null): ?string
    {
        // If pluginId provided, search that plugin's sequences for the stage
        if ($pluginId !== null && isset($this->sequences[$pluginId])) {
            foreach ($this->sequences[$pluginId] as $type => $steps) {
                foreach ($steps as $step) {
                    if ($step['stage'] === $stage) {
                        return $type;
                    }
                }
            }
        }

        // Fallback: search all plugins for the stage
        foreach ($this->sequences as $pid => $types) {
            foreach ($types as $type => $steps) {
                foreach ($steps as $step) {
                    if ($step['stage'] === $stage) {
                        return $type;
                    }
                }
            }
        }

        // Last resort: try prefix matching for backward compatibility
        $underscorePos = strrpos($stage, '_');
        if ($underscorePos !== false) {
            $prefix = substr($stage, 0, $underscorePos);
            // Check if this prefix exists as a type in any plugin
            foreach ($this->sequences as $pid => $types) {
                if (isset($types[$prefix])) {
                    return $prefix;
                }
            }
        }

        return null;
    }

    /**
     * Calculate the next send date from a delay in days
     */
    public function calculateNextDate(int $delayDays): string
    {
        $date = new DateTime('now', new DateTimeZone('UTC'));
        $date->modify("+{$delayDays} days");
        return $date->format('c'); // ISO 8601 format
    }

    /**
     * Calculate a short delay (in minutes) for retrying unconfirmed subscribers
     */
    public function calculateNextDateMinutes(int $delayMinutes): string
    {
        $date = new DateTime('now', new DateTimeZone('UTC'));
        $date->modify("+{$delayMinutes} minutes");
        return $date->format('c'); // ISO 8601 format
    }

    /**
     * Check if a stage is a terminal stage (no more steps after)
     */
    public function isTerminalStage(string $pluginId, string $stage): bool
    {
        return $this->getNextStep($pluginId, $stage) === null;
    }

    /**
     * Check if a stage indicates the sequence is complete or stopped
     * FA-18: Added 'deleted' for subscribers removed from Listmonk
     */
    public function isInactiveStage(string $stage): bool
    {
        return in_array($stage, ['complete', 'stopped', 'error', 'deleted', 'none', 'imported', '']);
    }

    /**
     * Get all stages for a plugin (for debugging/display)
     * FA-14: Now iterates over all dynamic types instead of hardcoded list
     */
    public function getAllStages(string $pluginId): array
    {
        $stages = [];
        if (!isset($this->sequences[$pluginId])) {
            return $stages;
        }

        // Iterate over all configured types for this plugin
        foreach ($this->sequences[$pluginId] as $type => $steps) {
            foreach ($steps as $step) {
                $stages[] = $step['stage'];
            }
        }
        return $stages;
    }

    /**
     * Get all configured types for a plugin (FA-14)
     */
    public function getTypes(string $pluginId): array
    {
        if (!isset($this->sequences[$pluginId])) {
            return [];
        }
        return array_keys($this->sequences[$pluginId]);
    }
}
