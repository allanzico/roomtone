<?php

namespace App\Enums;

enum SetItemStatus: string
{
    case Queued = 'queued';
    case Playing = 'playing';
    case Played = 'played';
}
