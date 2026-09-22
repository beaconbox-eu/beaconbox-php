<?php

declare(strict_types=1);

namespace BeaconBox\Resource;

use BeaconBox\Transport;

abstract class BaseResource
{
    public function __construct(protected readonly Transport $transport)
    {
    }
}
