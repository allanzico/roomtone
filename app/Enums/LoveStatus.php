<?php

namespace App\Enums;

/** Stage 1's verdict on one thing a guest loves. */
enum LoveStatus: string
{
    case Pending = 'pending';
    /** Read as a Qloo entity or a city, with a stored pool of artists. */
    case Resolved = 'resolved';
    /** Nothing fitted, Qloo rejected the pick twice, or it was too personal or garbled to read. */
    case Unresolved = 'unresolved';
}
