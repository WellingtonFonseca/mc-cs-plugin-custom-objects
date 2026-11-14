<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Exception;

use MauticPlugin\CustomObjectsBundle\Exception\InvalidCustomObjectFormatListException;
use PHPUnit\Framework\Attributes\Group;

#[Group('area:co-objects')]
#[Group('risk:DADOS')]
class InvalidCustomObjectFormatListExceptionTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        $exception = new InvalidCustomObjectFormatListException('test');
        $this->assertSame(
            "'test' is not a valid custom object list format.",
            $exception->getMessage()
        );
    }
}
