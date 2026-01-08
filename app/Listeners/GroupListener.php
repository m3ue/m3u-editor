<?php

namespace App\Listeners;

use App\Models\Group;

class GroupListener
{
    /**
     * Create the event listener.
     */
    public function __construct(
        public Group $group,
    ) {
        //
    }
}
