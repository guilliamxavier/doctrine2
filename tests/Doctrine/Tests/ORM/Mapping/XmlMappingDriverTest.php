<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\Tests\Models\DDC117\DDC117Translation;
use Doctrine\Tests\Models\DDC3293\DDC3293User;
use Doctrine\Tests\Models\DDC3293\DDC3293UserPrefixed;
use Doctrine\Tests\Models\DDC889\DDC889Class;
use Doctrine\Tests\Models\Generic\SerializationModel;
use Doctrine\Tests\Models\ValueObjects\Name;
use Doctrine\Tests\Models\ValueObjects\Person;
use const DIRECTORY_SEPARATOR;
use const PATHINFO_FILENAME;
use function array_filter;
use function array_keys;
use function array_map;
use function count;
use function fnmatch;
use function glob;
use function iterator_to_array;
use function libxml_clear_errors;
use function libxml_get_errors;
use function libxml_use_internal_errors;
use function pathinfo;
use function preg_quote;
use function sprintf;
use function strtr;
use function trim;

class XmlMappingDriverTest extends AbstractMappingDriverTest
{
    protected function loadDriver()
    {
        return new XmlDriver(__DIR__ . DIRECTORY_SEPARATOR . 'xml');
    }

    public function testClassTableInheritanceDiscriminatorMap() : void
    {
        $mappingDriver = $this->loadDriver();

        $class = new ClassMetadata(CTI::class, $this->metadataBuildingContext);

        $mappingDriver->loadMetadataForClass(CTI::class, $class, $this->metadataBuildingContext);

        $expectedMap = [
            'foo' => CTIFoo::class,
            'bar' => CTIBar::class,
            'baz' => CTIBaz::class,
        ];

        self::assertCount(3, $class->discriminatorMap);
        self::assertEquals($expectedMap, $class->discriminatorMap);
    }

    /**
     * @expectedException \Doctrine\ORM\Cache\Exception\CacheException
     * @expectedExceptionMessage Entity association field "Doctrine\Tests\ORM\Mapping\XMLSLC#foo" not configured as part of the second-level cache.
     */
    public function testFailingSecondLevelCacheAssociation() : void
    {
        $mappingDriver = $this->loadDriver();

        $class = new ClassMetadata(XMLSLC::class, $this->metadataBuildingContext);

        $mappingDriver->loadMetadataForClass(XMLSLC::class, $class, $this->metadataBuildingContext);
    }

    public function testIdentifierWithAssociationKey() : void
    {
        $driver  = $this->loadDriver();
        $em      = $this->getTestEntityManager();
        $factory = new ClassMetadataFactory();

        $em->getConfiguration()->setMetadataDriverImpl($driver);
        $factory->setEntityManager($em);

        $class = $factory->getMetadataFor(DDC117Translation::class);

        self::assertEquals(['language', 'article'], $class->identifier);
        self::assertArrayHasKey('article', iterator_to_array($class->getDeclaredPropertiesIterator()));

        $association = $class->getProperty('article');

        self::assertTrue($association->isPrimaryKey());
    }

    /**
     * @group embedded
     */
    public function testEmbeddableMapping() : void
    {
        $class = $this->createClassMetadata(Name::class);

        self::assertTrue($class->isEmbeddedClass);
    }

    /**
     * @group embedded
     * @group DDC-3293
     * @group DDC-3477
     * @group DDC-1238
     */
    public function testEmbeddedMappingsWithUseColumnPrefix() : void
    {
        $factory = new ClassMetadataFactory();
        $em      = $this->getTestEntityManager();

        $em->getConfiguration()->setMetadataDriverImpl($this->loadDriver());
        $factory->setEntityManager($em);

        self::assertEquals(
            '__prefix__',
            $factory->getMetadataFor(DDC3293UserPrefixed::class)
                ->embeddedClasses['address']['columnPrefix']
        );
    }

    /**
     * @group embedded
     * @group DDC-3293
     * @group DDC-3477
     * @group DDC-1238
     */
    public function testEmbeddedMappingsWithFalseUseColumnPrefix() : void
    {
        $factory = new ClassMetadataFactory();
        $em      = $this->getTestEntityManager();

        $em->getConfiguration()->setMetadataDriverImpl($this->loadDriver());
        $factory->setEntityManager($em);

        self::assertFalse(
            $factory->getMetadataFor(DDC3293User::class)
                ->embeddedClasses['address']['columnPrefix']
        );
    }

    /**
     * @group embedded
     */
    public function testEmbeddedMapping() : void
    {
        $class = $this->createClassMetadata(Person::class);

        self::assertEquals(
            [
                'name' => [
                    'class'          => Name::class,
                    'columnPrefix'   => 'nm_',
                    'declaredField'  => null,
                    'originalField'  => null,
                    'declaringClass' => $class,
                ],
            ],
            $class->embeddedClasses
        );
    }

    /**
     * @group DDC-1468
     *
     * @expectedException \Doctrine\Common\Persistence\Mapping\MappingException
     * @expectedExceptionMessage Invalid mapping file 'Doctrine.Tests.Models.Generic.SerializationModel.dcm.xml' for class 'Doctrine\Tests\Models\Generic\SerializationModel'.
     */
    public function testInvalidMappingFileException() : void
    {
        $this->createClassMetadata(SerializationModel::class);
    }

    /**
     * @param string $xmlMappingFile
     * @dataProvider dataValidSchema
     * @group DDC-2429
     * @group 6389
     */
    public function testValidateXmlSchema($xmlMappingFile) : void
    {
        self::assertTrue($this->doValidateXmlSchema($xmlMappingFile));
    }

    /**
     * @param string   $xmlMappingFile
     * @param string[] $errorMessageRegexes
     * @dataProvider dataValidSchemaInvalidMappings
     * @group 6389
     */
    public function testValidateXmlSchemaWithInvalidMapping($xmlMappingFile, $errorMessageRegexes) : void
    {
        $savedUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $validationResult = $this->doValidateXmlSchema($xmlMappingFile);

            self::assertFalse($validationResult, 'Invalid XML mapping should not pass XSD validation.');

            /** @var \LibXMLError[] $errors */
            $errors = libxml_get_errors();

            self::assertCount(count($errorMessageRegexes), $errors);
            foreach ($errorMessageRegexes as $i => $errorMessageRegex) {
                self::assertRegExp($errorMessageRegex, trim($errors[$i]->message));
            }
        } finally {
            // Restore previous setting
            libxml_clear_errors();
            libxml_use_internal_errors($savedUseErrors);
        }
    }

    /**
     * @param string $xmlMappingFile
     * @return bool
     */
    private function doValidateXmlSchema($xmlMappingFile) : bool
    {
        $xsdSchemaFile = __DIR__ . '/../../../../../doctrine-mapping.xsd';
        $dom           = new \DOMDocument('1.0', 'UTF-8');

        $dom->load($xmlMappingFile);

        return $dom->schemaValidate($xsdSchemaFile);
    }

    public static function dataValidSchema()
    {
        $list    = self::getAllXmlMappingPaths();
        $invalid = self::getInvalidXmlMappingMap();

        $list = array_filter($list, function ($item) use ($invalid) {
            $matchesInvalid = false;
            foreach ($invalid as $filenamePattern => $unused) {
                if (fnmatch($filenamePattern, pathinfo($item, PATHINFO_FILENAME))) {
                    $matchesInvalid = true;
                    break;
                }
            }

            return ! $matchesInvalid;
        });

        return array_map(function ($item) {
            return [$item];
        }, $list);
    }

    public static function dataValidSchemaInvalidMappings() : array
    {
        $list    = self::getAllXmlMappingPaths();
        $invalid = self::getInvalidXmlMappingMap();

        $map = [];
        foreach ($invalid as $filenamePattern => $errorMessageRegexes) {
            $foundItems = array_filter($list, function ($item) use ($filenamePattern) {
                return fnmatch($filenamePattern, pathinfo($item, PATHINFO_FILENAME));
            });

            if (count($foundItems) > 0) {
                foreach ($foundItems as $foundItem) {
                    $map[$foundItem] = $errorMessageRegexes;
                }
            } else {
                throw new \RuntimeException(sprintf('Found no XML mapping with filename pattern "%s".', $filenamePattern));
            }
        }

        return array_map(function ($item, $errorMessageRegexes) {
            return [$item, $errorMessageRegexes];
        }, array_keys($map), $map);
    }

    /**
     * @return string[]
     */
    private static function getAllXmlMappingPaths() : array
    {
        return glob(__DIR__ . '/xml/*.xml');
    }

    /**
     * @return array<string, string[]> ($filenamePattern => $errorMessageRegexes)
     */
    private static function getInvalidXmlMappingMap() : array
    {
        $namespaced = function ($name) {
            return sprintf('{%s}%s', 'http://doctrine-project.org/schemas/orm/doctrine-mapping', $name);
        };

        $invalid = [
            'Doctrine.Tests.Models.DDC889.DDC889Class.dcm' => [
                sprintf("Element '%s': This element is not expected.", $namespaced('class')),
            ],
        ];

        foreach ([
            'fqcn' => ['custom-id-generator', 'class'],
        ] as $type => [$element, $attribute]) {
            $errorMessagePrefix = sprintf("Element '%s', attribute '%s': ", $namespaced($element), $attribute);

            $invalid[sprintf('pattern-%s-invalid-*', $type)] = [
                $errorMessagePrefix . "[facet 'pattern'] The value '%s' is not accepted by the pattern '%s'.",
                $errorMessagePrefix . sprintf("'%%s' is not a valid value of the atomic type '%s'.", $namespaced($type)),
            ];
        }

        // Convert basic sprintf-style formats to PCRE patterns
        return array_map(function ($errorMessageFormats) {
            return array_map(function ($errorMessageFormat) {
                return '/^' . strtr(preg_quote($errorMessageFormat, '/'), [
                        '%%' => '%',
                        '%s' => '.*',
                    ]) . '$/s';
            }, $errorMessageFormats);
        }, $invalid);
    }

    /**
     * @group DDC-889
     * @expectedException \Doctrine\Common\Persistence\Mapping\MappingException
     * @expectedExceptionMessage Invalid mapping file 'Doctrine.Tests.Models.DDC889.DDC889Class.dcm.xml' for class 'Doctrine\Tests\Models\DDC889\DDC889Class'.
     */
    public function testinvalidEntityOrMappedSuperClassShouldMentionParentClasses() : void
    {
        $this->createClassMetadata(DDC889Class::class);
    }
}

class CTI
{
    public $id;
}

class CTIFoo extends CTI
{
}
class CTIBar extends CTI
{
}
class CTIBaz extends CTI
{
}

class XMLSLC
{
    public $foo;
}
class XMLSLCFoo
{
    public $id;
}
