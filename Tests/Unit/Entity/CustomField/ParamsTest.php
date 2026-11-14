<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Entity\CustomField;

use MauticPlugin\CustomObjectsBundle\Entity\CustomField\Params;
use PHPUnit\Framework\Attributes\Group;

#[Group('area:co-objects')]
#[Group('risk:DADOS')]
class ParamsTest extends \PHPUnit\Framework\TestCase
{
    public function testConstructorAndToArray()
    {
        $placeholder = 'placeholder';

        $paramsArray = [
            'placeholder' => $placeholder,
        ];

        $params = new Params($paramsArray);

        $this->assertSame($placeholder, $params->getPlaceholder());
        $this->assertSame($paramsArray, $params->__toArray());
    }

    public function testToArrayRemovingFalseAndNullValues()
    {
        $placeholder = null;

        $paramsArray = [
            'placeholder' => $placeholder,
        ];

        $params = new Params($paramsArray);

        $this->assertSame(array_filter($paramsArray), $params->__toArray());
    }

    public function testGettersSetters()
    {
        $placeholder = 'placeholder';

        $params = new Params();

        $params->setPlaceholder($placeholder);
        $this->assertSame($placeholder, $params->getPlaceholder());
    }
}
