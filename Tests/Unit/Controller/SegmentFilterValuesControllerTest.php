<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Controller;

use MauticPlugin\CustomObjectsBundle\Controller\SegmentFilterValuesController;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Helper\SegmentFilterValues;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class SegmentFilterValuesControllerTest extends TestCase
{
    public function testItAnswersTheValuesOfTheField(): void
    {
        $values = $this->createMock(SegmentFilterValues::class);
        $values->expects($this->once())->method('find')->with('cmf_14', 'ab', 30)->willReturn(['abc']);

        $response = (new SegmentFilterValuesController())->valuesAction(new Request(['field' => 'cmf_14', 'q' => 'ab', 'limit' => '30']), $values);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['values' => ['abc']], json_decode((string) $response->getContent(), true));
    }

    public function testNoPermissionIs403(): void
    {
        $values = $this->createMock(SegmentFilterValues::class);
        $values->method('find')->willThrowException(new ForbiddenException('view'));

        $response = (new SegmentFilterValuesController())->valuesAction(new Request(['field' => 'cmf_14']), $values);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['values' => []], json_decode((string) $response->getContent(), true));
    }

    public function testAnUnknownFieldIs404(): void
    {
        $values = $this->createMock(SegmentFilterValues::class);
        $values->method('find')->willThrowException(new NotFoundException());

        $response = (new SegmentFilterValuesController())->valuesAction(new Request(['field' => 'email']), $values);

        $this->assertSame(404, $response->getStatusCode());
    }
}
