<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Integration;

use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\CustomObjectsBundle\Integration\CustomObjectsIntegration;
use PHPUnit\Framework\TestCase;

class CustomObjectsIntegrationTest extends TestCase
{
    private function integration(): CustomObjectsIntegration
    {
        // the constructor takes a fixed list of core services, not needed here
        return (new \ReflectionClass(CustomObjectsIntegration::class))->newInstanceWithoutConstructor();
    }

    public function testItIsTheTileOfThePlugin(): void
    {
        $integration = $this->integration();

        $this->assertSame('CustomObjects', $integration->getName());
        $this->assertSame('Custom Objects', $integration->getDisplayName());
        $this->assertSame('none', $integration->getAuthenticationType());
        $this->assertSame([], $integration->getRequiredKeyFields(), 'no keys: the modal only shows the change log');
    }

    public function testTheChangeLogIsTheCustomNoteOfTheFirstTab(): void
    {
        $note = $this->integration()->getFormNotes('custom');

        $this->assertSame('@CustomObjects/Integration/_changelog.html.twig', $note['template']);
        $this->assertTrue($note['custom'], 'core only draws the note when custom is set');
        $this->assertNotEmpty($note['parameters']['days']);
    }

    public function testTheOtherSectionsKeepCoresNotes(): void
    {
        $this->assertSame(['', 'info'], $this->integration()->getFormNotes('authorization'));
    }

    /** The integration without core's services: reading its keys (what core does next) is cut out. */
    private function integrationWithoutKeys(): CustomObjectsIntegration
    {
        return new class() extends CustomObjectsIntegration {
            public function __construct()
            {
            }

            public function getDecryptedApiKeys($entity = false)
            {
                return [];
            }
        };
    }

    public function testANewIntegrationIsBornPublishedButASavedOneKeepsItsState(): void
    {
        $new = new Integration();
        $this->integrationWithoutKeys()->setIntegrationSettings($new);
        $this->assertTrue($new->getIsPublished(), 'a new row is turned on');

        $saved = new class() extends Integration {
            public function getId(): int
            {
                return 25;
            }
        };
        $saved->setIsPublished(false);
        $this->integrationWithoutKeys()->setIntegrationSettings($saved);
        $this->assertFalse($saved->getIsPublished(), 'a saved row is left as it is');
    }

    public function testTheTileOfTheIntegrationHasTheSameIconAsThePlugin(): void
    {
        // core looks for Assets/img/<integration name in lower case>.png for the tile of an integration
        $dir = __DIR__.'/../../../Assets/img/';

        $this->assertFileExists($dir.strtolower(CustomObjectsIntegration::NAME).'.png');
        $this->assertSame(md5_file($dir.'icon.png'), md5_file($dir.'customobjects.png'));
    }

    public function testItIsRegisteredWithCoresServicesInTheConfig(): void
    {
        $config = require __DIR__.'/../../../Config/config.php';
        $entry  = $config['services']['integrations']['mautic.integration.customobjects'];

        $this->assertSame(CustomObjectsIntegration::class, $entry['class']);
        $this->assertCount(16, $entry['arguments'], 'AbstractIntegration takes 16 services');
    }
}
