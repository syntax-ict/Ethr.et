<?php

declare(strict_types=1);

namespace App\Traits;

trait NeverDelete
{
    public function delete(): never
    {
        throw new \LogicException(static::class.' records cannot be deleted.');
    }

    public function forceDelete(): never
    {
        throw new \LogicException(static::class.' records cannot be deleted.');
    }
}
