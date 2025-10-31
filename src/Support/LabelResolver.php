<?php

namespace Look\EloquentCypher\Support;

class LabelResolver
{
    /**
     * The label prefix to apply.
     */
    protected ?string $prefix = null;

    /**
     * Create a new label resolver instance.
     */
    public function __construct(?string $prefix = null)
    {
        $this->prefix = $prefix;
    }

    /**
     * Set the label prefix.
     */
    public function setPrefix(?string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    /**
     * Get the current label prefix.
     */
    public function getPrefix(): ?string
    {
        return $this->prefix;
    }

    /**
     * Qualify a label with the configured prefix.
     */
    public function qualify(string $label): string
    {
        if (empty($this->prefix)) {
            return $label;
        }

        // Don't double-prefix if already prefixed
        if (str_starts_with($label, $this->prefix)) {
            return $label;
        }

        return $this->prefix.$label;
    }

    /**
     * Remove the prefix from a qualified label.
     */
    public function unqualify(string $label): string
    {
        if (empty($this->prefix)) {
            return $label;
        }

        if (str_starts_with($label, $this->prefix)) {
            return substr($label, strlen($this->prefix));
        }

        return $label;
    }

    /**
     * Check if a label is qualified with the current prefix.
     */
    public function isQualified(string $label): bool
    {
        if (empty($this->prefix)) {
            return false;
        }

        return str_starts_with($label, $this->prefix);
    }
}
