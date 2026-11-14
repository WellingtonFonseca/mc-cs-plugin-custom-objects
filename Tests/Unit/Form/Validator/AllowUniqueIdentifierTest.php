<?php

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Form\Validator;

use MauticPlugin\CustomObjectsBundle\Form\Validator\Constraints\AllowUniqueIdentifier;
use MauticPlugin\CustomObjectsBundle\Form\Validator\Constraints\AllowUniqueIdentifierValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('area:co-objects')]
#[Group('area:co-items')]
#[Group('risk:FLUXO')]
class AllowUniqueIdentifierTest extends TestCase
{
    /**
     * @var AllowUniqueIdentifier
     */
    private $constraint;

    protected function setUp(): void
    {
        $this->constraint = new AllowUniqueIdentifier();
    }

    public function testValidatedBy(): void
    {
        $this->assertSame(AllowUniqueIdentifierValidator::class, $this->constraint->validatedBy());
    }

    public function testGetTargets(): void
    {
        $this->assertSame(AllowUniqueIdentifier::CLASS_CONSTRAINT, $this->constraint->getTargets());
    }
}
