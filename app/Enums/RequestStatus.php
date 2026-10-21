<?php

namespace App\Enums;

enum RequestStatus: string
{
    case Pending = 'pending';
    /** Tracks were placed next in the set. */
    case Done = 'done';
    /** Read, but nothing to steer by (no genre or mood), or no guest had a matching track. */
    case NoChange = 'no_change';
    case Failed = 'failed';
}
