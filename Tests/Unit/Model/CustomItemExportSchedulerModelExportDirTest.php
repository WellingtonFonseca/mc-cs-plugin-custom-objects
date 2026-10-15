<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Model;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemExportSchedulerModel;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Where the export files go: the custom_item_export_dir setting, or media/files/temp of the install when the
 * setting is empty (MAUTIC_CUSTOM_ITEM_EXPORT_DIR unset resolves to null, and the file used to be written at the
 * root of the disk). The folder is created when missing.
 */
class CustomItemExportSchedulerModelExportDirTest extends TestCase
{
    private CoreParametersHelper&MockObject $parameters;

    private PathsHelper&MockObject $paths;

    private CustomItemExportSchedulerModel $model;

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp        = sys_get_temp_dir().'/custom-objects-export-'.bin2hex(random_bytes(4));
        $this->parameters = $this->createMock(CoreParametersHelper::class);
        $this->paths      = $this->createMock(PathsHelper::class);

        $reflection  = new \ReflectionClass(CustomItemExportSchedulerModel::class);
        $this->model = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('pathsHelper')->setValue($this->model, $this->paths);
        $reflection->getParentClass()->getProperty('coreParametersHelper')->setValue($this->model, $this->parameters);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp.'/files/temp')) {
            rmdir($this->tmp.'/files/temp');
            rmdir($this->tmp.'/files');
        }
        if (is_dir($this->tmp)) {
            rmdir($this->tmp);
        }
    }

    public function testUsesTheSettingWhenItIsSet(): void
    {
        $this->parameters->method('get')->with('custom_item_export_dir')->willReturn($this->tmp);
        $this->paths->expects($this->never())->method('getSystemPath');

        $this->assertSame($this->tmp, $this->model->getExportDir());
        $this->assertDirectoryExists($this->tmp);
    }

    /**
     * @dataProvider emptySettings
     */
    public function testFallsBackToTheMediaFolderWhenTheSettingIsEmpty(?string $setting): void
    {
        $this->parameters->method('get')->with('custom_item_export_dir')->willReturn($setting);
        $this->paths->method('getSystemPath')->with('media', true)->willReturn($this->tmp);

        $this->assertSame($this->tmp.'/files/temp', $this->model->getExportDir());
        $this->assertDirectoryExists($this->tmp.'/files/temp');
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function emptySettings(): iterable
    {
        yield 'null (env var not set)' => [null];
        yield 'empty string' => [''];
    }
}
