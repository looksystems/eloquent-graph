<?php

namespace Look\EloquentCypher\Concerns;

trait GraphSoftDeletes
{
    /**
     * Force delete the model from the database.
     * This overrides the SoftDeletes trait's forceDelete method to work with Neo4j.
     */
    public function forceDelete()
    {
        // Fire the force deleting event
        if ($this->fireModelEvent('forceDeleting') === false) {
            return false;
        }

        // Fire the deleting event (needed for soft-deleted models)
        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        // Set the flag that we're force deleting
        $this->forceDeleting = true;

        // Perform the actual deletion
        $deleted = $this->performDeleteOnModel();

        // Reset the flag
        $this->forceDeleting = false;

        if ($deleted) {
            // Fire the deleted event
            $this->fireModelEvent('deleted', false);

            // Fire the force deleted event
            $this->fireModelEvent('forceDeleted', false);
        }

        return (bool) $deleted;
    }
}
